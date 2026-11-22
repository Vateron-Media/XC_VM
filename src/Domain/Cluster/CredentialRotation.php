<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\RootCredentials;
use XcVm\Core\Config\SettingsManager;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * MAIN's Redis password rotation for the nodes that still use it (plan,
 * section 10, step 3; ADR 0004, Phase 9): `cluster:rotate-credentials`.
 *
 * Until lockdown every legacy and hybrid load balancer sends `AUTH
 * <redis_password>` and its SQL login to MAIN in cleartext, so a sniffer of
 * that era holds both. A rotation gives MAIN a new Redis password and pushes
 * it to the nodes that still connect — every load balancer whose cluster node is not in
 * mode 2 (targets()):
 *
 * Every target must take signed root commands. Before MAIN changes anything,
 * the password is SEALed to each node's box key (RootCredentials); an
 * unenrolled or unpinned node refuses the whole rotation.
 *
 * Redis: the new password is *added* to Redis' `default` user (`ACL SETUSER`
 * — `CONFIG` is renamed away, RedisConfigHardening), so both work while the
 * nodes move; MAIN's own config.enc, `settings.redis_password` and
 * `requirepass` in redis.conf take the new one at once. `finish()` removes the
 * old one (by its SHA-256, so MAIN never keeps it) once every command node
 * has acked, or with --force. The job's state lives in `cluster_meta`.
 *
 * The DB password is not rotated here: `cluster:rotate-db-password`
 * (DbPassword) does it through xcvm_core, and sends it to the nodes sealed
 * the same way (`node.root rotate_db`).
 */
final class CredentialRotation {
	use DatabaseAware;

	/** The Redis rotation's state while it is open. */
	public const META_REDIS = 'credential_rotation.redis';

	/** When the last Redis rotation ended. */
	public const DONE_REDIS = 'redis_rotated_at';

	public const REDIS_PORT = 6379;

	/** @var callable(list<string>): mixed */
	private $rRedis;

	/** @var callable(string, int, string): bool */
	private $rRedisConfig;

	private string $rRedisConf;

	/**
	 * @param (callable(list<string>): mixed)|null        $rRedis       a raw command on MAIN's Redis
	 * @param (callable(string, int, string): bool)|null  $rRedisConfig MAIN's config.enc Redis setter
	 * @param string|null                                 $rRedisConf   redis.conf
	 */
	public function __construct(?callable $rRedis = null, ?callable $rRedisConfig = null, ?string $rRedisConf = null) {
		$this->rRedis = $rRedis ?? static function (array $rArgs): mixed {
			$rConn = RedisManager::instance();
			return $rConn instanceof \Redis ? $rConn->rawCommand(...$rArgs) : false;
		};
		$this->rRedisConfig = $rRedisConfig ?? static fn(string $rHost, int $rPort, string $rAuth): bool => method_exists('XC_VM', 'config_set_redis') && \XC_VM::config_set_redis($rHost, $rPort, $rAuth);
		$this->rRedisConf = $rRedisConf ?? (defined('MAIN_HOME') ? MAIN_HOME . 'bin/redis/redis.conf' : '');
	}

	/**
	 * The nodes a rotation is pushed to: every load balancer (`servers`,
	 * server_type 0, not MAIN) whose cluster node is not in mode 2, with its
	 * node row when it has one.
	 *
	 * @return array<int, array<string, mixed>|null> server id => cluster_nodes row or null
	 */
	public static function targets(): array {
		self::db()->query('SELECT `id` FROM `servers` WHERE `server_type` = 0 AND `is_main` = 0 ORDER BY `id`;');
		$rIDs = array_map(static fn(array $rRow): int => (int) $rRow['id'], self::db()->get_rows());
		$rOut = [];
		foreach ($rIDs as $rID) {
			try {
				$rNode = NodeRegistry::byServer($rID);
			} catch (\Throwable) {
				$rNode = null; // no cluster tables: every node is legacy
			}
			if ($rNode !== null && (int) $rNode['mode'] >= 2 && in_array($rNode['state'], ['active', 'quarantined'], true)) {
				continue;
			}
			$rOut[$rID] = $rNode;
		}
		return $rOut;
	}

	/**
	 * Start a Redis rotation. Returns the job: its id and how each target was
	 * reached (`command`, or `unsent` with why after the preflight passed).
	 *
	 * @return array{id: string, nodes: array<int, array{via: string, detail?: string}>}
	 */
	public function redis(?string $rNew = null, ?int $rNow = null): array {
		$rNow ??= ClusterClock::now();
		if (ClusterMeta::get(self::META_REDIS) !== null) {
			throw new \RuntimeException('a Redis rotation is open: finish it first (cluster:rotate-credentials redis --finish)');
		}
		$rOld = $this->redisPassword();
		$rNew ??= bin2hex(random_bytes(32));
		if ($rOld === '' || hash_equals($rOld, $rNew)) {
			throw new \RuntimeException('no Redis password to rotate, or the same one');
		}
		if ($this->rRedisConf !== '' && is_file($this->rRedisConf) && !is_writable($this->rRedisConf)) {
			throw new \RuntimeException($this->rRedisConf . ' is not writable: run as root');
		}
		if (empty(SettingsManager::get('cluster_api_enabled'))) {
			throw new \RuntimeException('Redis rotation requires the cluster API');
		}
		$rHost = self::mainHost();
		$rActions = [];
		$rBlocked = [];
		foreach (self::targets() as $rServerID => $rNode) {
			if (!CommandBus::acceptsRoot($rNode)) {
				$rBlocked[] = $rServerID;
				continue;
			}
			$rActions[$rServerID] = [
				'action' => 'rotate_redis',
				'host' => $rHost,
				'port' => self::REDIS_PORT,
				'auth_sealed' => RootCredentials::seal((string) $rNode['node_box_pub'], (string) $rNode['node_uuid'], $rNew),
			];
		}
		if ($rBlocked !== []) {
			throw new \RuntimeException('Redis rotation requires signed root commands for nodes: ' . implode(', ', $rBlocked));
		}
		// Both passwords open Redis from here until finish().
		if (!self::redisOk(($this->rRedis)(['ACL', 'SETUSER', 'default', 'on', '>' . $rNew]))) {
			throw new \RuntimeException('Redis refused ACL SETUSER');
		}
		if (!($this->rRedisConfig)('127.0.0.1', self::REDIS_PORT, $rNew)) {
			($this->rRedis)(['ACL', 'SETUSER', 'default', '<' . $rNew]);
			throw new \RuntimeException('MAIN\'s config.enc was not written (config_set_redis); the new password was withdrawn');
		}
		self::db()->query('UPDATE `settings` SET `redis_password` = ?;', $rNew);
		$this->writeRequirepass($rNew);

		$rJob = ['id' => bin2hex(random_bytes(8)), 'old_sha' => hash('sha256', $rOld), 'started_at' => $rNow, 'nodes' => []];
		foreach ($rActions as $rServerID => $rAction) {
			try {
				$rSent = NodeActions::send($rServerID, $rAction);
				$rJob['nodes'][$rServerID] = $rSent ? ['via' => 'command'] : ['via' => 'unsent', 'detail' => 'not queued (see the cluster log)'];
			} catch (\Throwable $rE) {
				$rJob['nodes'][$rServerID] = ['via' => 'unsent', 'detail' => substr($rE->getMessage(), 0, 120)];
			}
		}
		ClusterMeta::set(self::META_REDIS, (string) json_encode($rJob, JSON_UNESCAPED_SLASHES));
		ClusterAudit::log('cluster.redis_rotation', null, ['id' => $rJob['id'], 'step' => 'started', 'nodes' => array_map(static fn(array $rN): string => $rN['via'], $rJob['nodes'])], 'cli');
		return ['id' => $rJob['id'], 'nodes' => $rJob['nodes']];
	}

	/**
	 * The open Redis rotation, each node with where its command stands
	 * (`acked`, `failed`, `queued`/`delivered`, or `unsent`); null when none
	 * is open. Old jobs may still contain a legacy `signal` entry.
	 *
	 * @return array{id: string, started_at: int, nodes: array<int, array{via: string, state: string, detail: string}>}|null
	 */
	public static function redisStatus(): ?array {
		$rJob = json_decode((string) ClusterMeta::get(self::META_REDIS), true);
		if (!is_array($rJob) || !is_array($rJob['nodes'] ?? null)) {
			return null;
		}
		$rOut = [];
		foreach ($rJob['nodes'] as $rServerID => $rNode) {
			$rState = $rNode['via'] === 'signal' ? 'sent' : ($rNode['via'] === 'unsent' ? 'unsent' : 'queued');
			$rDetail = (string) ($rNode['detail'] ?? '');
			if ($rNode['via'] === 'command') {
				self::db()->query("SELECT `state`, `result` FROM `cluster_commands` WHERE `server_id` = ? AND `type` = 'node.root' AND `action` = 'rotate_redis' AND `created_at` >= ? ORDER BY `seq` DESC LIMIT 1;", (int) $rServerID, (int) $rJob['started_at']);
				$rRow = self::db()->num_rows() > 0 ? self::db()->get_row() : null;
				$rState = $rRow === null ? 'expired' : (string) $rRow['state'];
				$rDetail = $rRow === null ? '' : substr(trim((string) $rRow['result']), 0, 120);
			}
			$rOut[(int) $rServerID] = ['via' => (string) $rNode['via'], 'state' => $rState, 'detail' => $rDetail];
		}
		return ['id' => (string) $rJob['id'], 'started_at' => (int) $rJob['started_at'], 'nodes' => $rOut];
	}

	/**
	 * Close the Redis rotation: the old password leaves Redis. Refused while a
	 * node reached by command has not acked it, or one was not reached at
	 * all, unless $rForce — that node would lose Redis. Legacy open jobs may
	 * contain a signals row, which only the operator's --force can answer for.
	 *
	 * @return list<int> the nodes that held it up (empty when it was closed)
	 */
	public function finishRedis(bool $rForce, ?int $rNow = null): array {
		$rStatus = self::redisStatus();
		$rJob = json_decode((string) ClusterMeta::get(self::META_REDIS), true);
		if ($rStatus === null || !is_array($rJob)) {
			throw new \RuntimeException('no Redis rotation is open');
		}
		$rPending = [];
		foreach ($rStatus['nodes'] as $rServerID => $rNode) {
			if ($rNode['state'] !== 'acked') {
				$rPending[] = $rServerID;
			}
		}
		if ($rPending !== [] && !$rForce) {
			return $rPending;
		}
		if (!self::redisOk(($this->rRedis)(['ACL', 'SETUSER', 'default', '!' . (string) $rJob['old_sha']]))) {
			throw new \RuntimeException('Redis refused to drop the old password');
		}
		self::db()->query('DELETE FROM `cluster_meta` WHERE `name` = ?;', self::META_REDIS);
		ClusterMeta::set(self::DONE_REDIS, (string) ($rNow ?? time()));
		ClusterAudit::log('cluster.redis_rotated', null, ['id' => $rJob['id'], 'forced' => $rPending], 'cli');
		return [];
	}

	private function redisPassword(): string {
		self::db()->query('SELECT `redis_password` FROM `settings` LIMIT 1;');
		$rRow = self::db()->get_row();
		return is_array($rRow) ? (string) ($rRow['redis_password'] ?? '') : '';
	}

	/** `requirepass` in redis.conf, for Redis' next start. */
	private function writeRequirepass(string $rNew): void {
		if ($this->rRedisConf === '' || !is_file($this->rRedisConf)) {
			return;
		}
		$rConf = (string) file_get_contents($this->rRedisConf);
		$rOut = preg_match('/^requirepass .*$/m', $rConf)
			? (string) preg_replace('/^requirepass .*$/m', 'requirepass ' . $rNew, $rConf)
			: rtrim($rConf, "\n") . "\nrequirepass " . $rNew . "\n";
		if (@file_put_contents($this->rRedisConf, $rOut) === false) {
			throw new \RuntimeException('cannot write ' . $this->rRedisConf);
		}
	}

	/** MAIN's address as a load balancer reaches its Redis: its private IP when it has one. */
	private static function mainHost(): string {
		self::db()->query('SELECT `server_ip`, `private_ip` FROM `servers` WHERE `is_main` = 1 LIMIT 1;');
		$rRow = self::db()->get_row();
		$rHost = is_array($rRow) ? trim((string) (!empty($rRow['private_ip']) ? $rRow['private_ip'] : ($rRow['server_ip'] ?? ''))) : '';
		if ($rHost === '') {
			throw new \RuntimeException('MAIN\'s address is not known');
		}
		return $rHost;
	}

	private static function redisOk(mixed $rReply): bool {
		return $rReply === true || (is_string($rReply) && strtoupper($rReply) === 'OK');
	}
}
