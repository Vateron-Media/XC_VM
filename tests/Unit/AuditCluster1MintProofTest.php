<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Config\StreamSecret;
use XcVm\Domain\Cluster\ConnectionAdmission;
use XcVm\Domain\Cluster\ConnectionIngest;
use XcVm\Domain\Cluster\ConnectionLimits;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;
use XcVm\Tests\Support\InstallSchema;

/**
 * The line a node names (ADR 0004, design 1, B): MAIN puts a proof of each
 * viewer mint in the token, the node's record carries it back, and MAIN
 * binds the line of `conn_admit` and of a record's first entry to it.
 * Under `cluster_conn_binding` = `observe` nothing changes for a viewer and
 * the unproven are counted; under `enforce`, for a node whose records prove
 * their mints, an unproven record is refused and dropped from the node's
 * registry, and a conn_admit without a proof reserves and cuts nothing.
 */
final class AuditCluster1MintProofTest extends TestCase {
	private const T = 1800000000;

	private const SECRET = 'stream-secret-now';

	private TestDb $rDb;

	private int $rNow = self::T;

	/** @var list<array{0: int, 1: string}> the conn.close {remove: true} each refused record queued */
	private array $rClosed = [];

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-binding-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir);
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` int, `state` varchar(16), `mode` int, `flows` int, `gen` int)');
		$this->rDb->exec("INSERT INTO `cluster_nodes` VALUES (5, 'active', 2, 74, 3), (6, 'active', 2, 74, 1)");
		foreach (['cluster_reservations', 'cluster_meta', 'cluster_audit'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$this->rDb->exec((string) $rM[0]);
		$this->rDb->exec('CREATE TABLE `lines` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `max_connections` int, `pair_id` int, `enabled` int, `admin_enabled` int, `exp_date` int)');
		$this->rDb->exec('CREATE TABLE `hmac_keys` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `enabled` int)');
		$this->rDb->exec('INSERT INTO `lines` VALUES (42, 2, NULL, 1, 1, NULL), (43, 2, NULL, 1, 1, NULL)');
		$this->rDb->exec('INSERT INTO `hmac_keys` VALUES (3, 1)');
		DatabaseFactory::set($this->rDb);
		RedisManager::useConnector(static fn() => null);
		SettingsManager::set(['redis_handler' => 0, 'live_streaming_pass' => self::SECRET]);
		StreamSecret::useFile($this->rDir . 'stream_secret.prev');
		ConnectionAdmission::useEnforcer(static function (): void {
		}, fn(): int => $this->rNow);
		ConnectionAdmission::useBinding(function (int $rServerID, string $rUUID): void {
			$this->rClosed[] = [$rServerID, $rUUID];
		}, $this->rDir . 'binding/');
		ConnectionLimits::useQueue($this->rDir . 'limits/');
	}

	protected function tearDown(): void {
		ConnectionAdmission::useEnforcer(null);
		ConnectionAdmission::useBinding(null);
		ConnectionLimits::useQueue(null);
		StreamSecret::useFile(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		RedisManager::useConnector(null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @param array<string, mixed> $rExtra */
	private function token(string $rUUID, int $rNode, array $rExtra = []): array {
		return $rExtra + ['stream_id' => 100, 'uuid' => $rUUID, 'channel_info' => ['redirect_id' => $rNode, 'originator_id' => null], 'user_info' => ['id' => 42, 'max_connections' => 2, 'pair_id' => null]];
	}

	/** @return array<string, mixed> the token as auth.php mints it */
	private function mint(array $rToken, array $rSettings = []): array {
		return ConnectionAdmission::admitToken($rSettings + ['cluster_api_enabled' => 1, 'create_expiration' => 5, 'redis_handler' => 0, 'live_streaming_pass' => self::SECRET], $rToken, '10.0.0.1', 'VLC');
	}

	/** The record's `mint`, as the node's PHP writes it from the token (ConnectionTracker::openRecord). */
	private function mintOf(array $rToken): string {
		return $rToken['uuid'] . '.' . $rToken['prf']['iat'] . '.' . $rToken['prf']['p'];
	}

	/** @return array<string, mixed> a node's record of a line's TS viewer */
	private function record(string $rUUID, int $rLine = 42, ?string $rMint = null): array {
		return ['uuid' => $rUUID, 'user_id' => $rLine, 'stream_id' => 100, 'container' => 'ts', 'user_ip' => '10.0.0.1', 'user_agent' => 'VLC', 'date_start' => self::T, 'hls_end' => 0] + ($rMint === null ? [] : ['mint' => $rMint]);
	}

	/** @return array<string, int> uuid => line of the store's rows */
	private function stored(): array {
		$this->rDb->query('SELECT `uuid`, `user_id` FROM `lines_live` ORDER BY `activity_id`');
		return array_column(array_map(static fn(array $rRow): array => [(string) $rRow['uuid'], (int) $rRow['user_id']], $this->rDb->get_rows()), 1, 0);
	}

	private function reservations(): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_reservations`');
		return (int) $this->rDb->get_row()['n'];
	}

	private function forNode(array $rRequest, int $rServerID = 5): ?array {
		return ConnectionAdmission::forNode(['cluster_api_enabled' => 1, 'create_expiration' => 5, 'redis_handler' => 0], $rServerID, $rRequest);
	}

	private function prove(int $rServerID = 5): void {
		$rToken = $this->mint($this->token('proof' . $rServerID, $rServerID));
		$this->assertTrue(ConnectionIngest::upsert($rServerID, $this->record('proof' . $rServerID, 42, $this->mintOf($rToken))));
		ConnectionAdmission::flushBinding();
	}

	public function testEveryViewerTokenCarriesAProofForItsUuidIdentityAndNodeAlone(): void {
		$rToken = $this->mint($this->token(str_repeat('a', 32), 5));
		$this->assertSame(['iat', 'p'], array_keys($rToken['prf'] ?? []), 'the token carries the proof of its mint');
		$this->assertSame(self::T, $rToken['prf']['iat']);
		$rMint = $this->mintOf($rToken);
		$this->assertSame(0, ConnectionAdmission::verifyMint($rMint, '42', 5));
		$this->assertNull(ConnectionAdmission::verifyMint($rMint, '43', 5), 'another line');
		$this->assertNull(ConnectionAdmission::verifyMint($rMint, '42', 6), 'another node');
		$this->assertNull(ConnectionAdmission::verifyMint(str_repeat('b', 32) . substr($rMint, 32), '42', 5), 'another uuid');
		$this->assertNull(ConnectionAdmission::verifyMint($rToken['uuid'] . '.' . (self::T + 1) . '.' . $rToken['prf']['p'], '42', 5), 'another time');
		// Without a limit, an HMAC identity, behind a proxy: every viewer mint.
		$rUnlimited = $this->mint($this->token('u1', 5, ['user_info' => ['id' => 42, 'max_connections' => 0]]));
		$this->assertSame(0, ConnectionAdmission::verifyMint($this->mintOf($rUnlimited), '42', 5));
		$rHmac = $this->mint($this->token('h1', 5, ['hmac_id' => 3, 'identifier' => 'dev', 'user_info' => ['id' => null, 'max_connections' => 1]]));
		$this->assertSame(0, ConnectionAdmission::verifyMint($this->mintOf($rHmac), '3_dev', 5));
		$rProxied = $this->mint($this->token('p1', 99, ['channel_info' => ['redirect_id' => 99, 'originator_id' => 6]]));
		$this->assertSame(0, ConnectionAdmission::verifyMint($this->mintOf($rProxied), '42', 6), 'the originator records the viewer');
		$this->assertArrayNotHasKey('prf', $this->mint($this->token('n1', 5), ['cluster_api_enabled' => 0]));
		// No bound at the events, the token's life plus PAD_SEC at conn_admit.
		$this->rNow = self::T + 86400;
		$this->assertSame(86400, ConnectionAdmission::verifyMint($rMint, '42', 5));
		$this->assertNull(ConnectionAdmission::verifyMint($rMint, '42', 5, 15));
	}

	public function testAProofUnderTheReplacedSecretVerifiesInsideItsWindowOnly(): void {
		$rMint = $this->mintOf($this->mint($this->token('r1', 5)));
		SettingsManager::set(['redis_handler' => 0, 'live_streaming_pass' => 'stream-secret-new']);
		$this->assertNull(ConnectionAdmission::verifyMint($rMint, '42', 5), 'no window recorded');
		file_put_contents($this->rDir . 'stream_secret.prev', json_encode(['value' => self::SECRET, 'valid_until' => time() + 600]));
		$this->assertSame(0, ConnectionAdmission::verifyMint($rMint, '42', 5));
		file_put_contents($this->rDir . 'stream_secret.prev', json_encode(['value' => self::SECRET, 'valid_until' => time() - 1]));
		touch($this->rDir . 'stream_secret.prev', time() + 5);
		clearstatcache();
		$this->assertNull(ConnectionAdmission::verifyMint($rMint, '42', 5), 'past the window');
	}

	public function testUnderEnforceAConnAdmitWithoutAValidProofReservesAndCutsNothing(): void {
		SettingsManager::set(['redis_handler' => 0, 'live_streaming_pass' => self::SECRET, ConnectionAdmission::BINDING => 'enforce']);
		$this->prove();
		$rToken = $this->mint($this->token('t0', 5));
		$this->rDb->exec('DELETE FROM `cluster_reservations`');
		$rOld = $this->mintOf($rToken);
		$rOther = $this->mintOf($this->mint($this->token('t9', 5, ['user_info' => ['id' => 43, 'max_connections' => 2]])));
		$rElsewhere = $this->mintOf($this->mint($this->token('t8', 6)));
		$this->rDb->exec('DELETE FROM `cluster_reservations`');
		foreach ([null, $rOther, $rElsewhere] as $i => $rMint) {
			$rOut = $this->forNode(['uuid' => 'v' . $i, 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.1', 'ua' => 'VLC'] + ($rMint === null ? [] : ['mint' => $rMint]));
			$this->assertTrue($rOut['admit'], 'admitted: the line\'s own refusals only');
		}
		$this->rNow = self::T + 16;
		$this->assertTrue($this->forNode(['uuid' => 'v3', 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.1', 'ua' => 'VLC', 'mint' => $rOld])['admit']);
		$this->assertSame(0, $this->reservations(), 'no proof, another line\'s, another node\'s or an old one: nothing reserved');
		$this->assertSame([], glob($this->rDir . 'limits/*.json') ?: [], 'and no cut queued');
		// A line auth.php would refuse is still refused.
		$this->rDb->exec('UPDATE `lines` SET `enabled` = 0 WHERE `id` = 43');
		$this->assertSame('DISABLED', $this->forNode(['uuid' => 'v4', 'line_id' => 43, 'stream_id' => 100, 'ip' => '', 'ua' => ''])['reason'] ?? null);
		// The proof of this viewer's mint: reserved and cut, as before.
		$this->rNow = self::T + 15;
		$this->assertTrue($this->forNode(['uuid' => 'v5', 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.1', 'ua' => 'VLC', 'mint' => $rOld])['admit']);
		$this->assertSame(1, $this->reservations());
		$this->assertCount(1, glob($this->rDir . 'limits/*.json') ?: []);
	}

	public function testUnderObserveAConnAdmitWithoutAProofIsAnsweredAsBeforeAndCounted(): void {
		$this->prove();
		$this->assertTrue($this->forNode(['uuid' => 'w1', 'line_id' => 42, 'stream_id' => 100, 'ip' => '10.0.0.1', 'ua' => 'VLC'])['admit']);
		$this->assertSame(1, $this->reservations(), 'reserved as before');
		$this->assertCount(1, glob($this->rDir . 'limits/*.json') ?: [], 'and its cut queued');
		ConnectionAdmission::flushBinding();
		$this->assertSame(1, ConnectionAdmission::bindingCounts(5)['admit_unproven'] ?? null);
	}

	public function testAFirstEntryWithoutAProofIsStoredUnderObserveAndRefusedUnderEnforceOnceTheNodeProves(): void {
		// observe: stored and counted, and one audit line for the minute.
		$this->assertTrue(ConnectionIngest::upsert(5, $this->record('x1')));
		ConnectionAdmission::flushBinding();
		$this->assertSame(['x1' => 42], $this->stored());
		$this->assertSame(1, ConnectionAdmission::bindingCounts(5)['unproven'] ?? null);
		$this->rDb->query("SELECT `server_id`, `event`, `detail` FROM `cluster_audit` WHERE `event` = 'conn.unproven'");
		$rAudit = $this->rDb->get_rows();
		$this->assertCount(1, $rAudit);
		$this->assertSame(5, (int) $rAudit[0]['server_id']);
		$this->assertSame(['unproven' => 1, 'admit_unproven' => 0, 'proven' => 0, 'age_max' => 0, 'mode' => 'observe'], json_decode($rAudit[0]['detail'], true));
		$this->assertTrue(ConnectionIngest::upsert(5, $this->record('x2')));
		ConnectionAdmission::flushBinding();
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `cluster_audit` WHERE `event` = 'conn.unproven'");
		$this->assertSame(1, (int) $this->rDb->get_row()['n'], 'one line a minute at most');
		$this->assertSame(2, ConnectionAdmission::bindingCounts(5)['unproven']);

		// enforce, but the node has shown no proof (an older panel or agent): as observe.
		SettingsManager::set(['redis_handler' => 0, 'live_streaming_pass' => self::SECRET, ConnectionAdmission::BINDING => 'enforce']);
		$this->assertTrue(ConnectionIngest::upsert(5, $this->record('x3')));
		ConnectionAdmission::flushBinding();
		$this->assertArrayHasKey('x3', $this->stored());
		$this->assertSame([], $this->rClosed);

		// Its first proof marks it for its enrolment; from then on an unproven record is refused, its close queued.
		$this->prove();
		$this->rDb->query("SELECT `value` FROM `cluster_meta` WHERE `name` = 'conn_proven.5'");
		$this->assertSame('3', (string) $this->rDb->get_row()['value'], 'the gen of its enrolment');
		$this->assertFalse(ConnectionIngest::upsert(5, $this->record('x4')));
		$this->assertFalse(ConnectionIngest::upsert(5, $this->record('x5', 43, $this->mintOf($this->mint($this->token('x5', 5))))), 'a proof of another line');
		ConnectionAdmission::flushBinding();
		$this->assertArrayNotHasKey('x4', $this->stored());
		$this->assertArrayNotHasKey('x5', $this->stored());
		$this->assertSame([[5, 'x4'], [5, 'x5']], $this->rClosed, 'the node\'s registry drops them: conn.close {remove: true}');
		// Records the store already holds are not asked again; another node is not enforced.
		$this->assertTrue(ConnectionIngest::upsert(5, ['hls_end' => 1] + $this->record('x1')));
		$this->assertTrue(ConnectionIngest::upsert(6, $this->record('y1')));
		// A new enrolment starts it again.
		$this->rDb->exec('UPDATE `cluster_nodes` SET `gen` = 4 WHERE `server_id` = 5');
		ConnectionAdmission::useBinding(fn(int $rS, string $rU) => $this->rClosed[] = [$rS, $rU], $this->rDir . 'binding/');
		$this->assertTrue(ConnectionIngest::upsert(5, $this->record('x6')));
	}

	public function testAProofOfAnyAgeIsAcceptedAtTheEvents(): void {
		SettingsManager::set(['redis_handler' => 0, 'live_streaming_pass' => self::SECRET, ConnectionAdmission::BINDING => 'enforce']);
		$this->prove();
		$rMint = $this->mintOf($this->mint($this->token('z1', 5)));
		// A record a snapshot brings back after an orphan purge, a day later.
		$this->rNow = self::T + 86400;
		ConnectionAdmission::useBinding(fn(int $rS, string $rU) => $this->rClosed[] = [$rS, $rU], $this->rDir . 'binding/');
		$this->assertSame(['applied' => 1, 'removed' => 1, 'dropped' => 0], \XcVm\Domain\Cluster\ConnectionSnapshot::apply(5, [$this->record('z1', 42, $rMint)]));
		$this->assertSame(['z1' => 42], $this->stored());
		ConnectionAdmission::flushBinding();
		$this->assertSame(86400, ConnectionAdmission::bindingCounts(5)['age_max'] ?? null);
	}

	public function testAnUpdateCannotChangeARecordsOwner(): void {
		$this->assertTrue(ConnectionIngest::upsert(5, $this->record('o1', 42, $this->mintOf($this->mint($this->token('o1', 5))))));
		$this->assertTrue(ConnectionIngest::upsert(5, ['user_agent' => 'Kodi'] + $this->record('o1', 43)));
		$this->assertSame(['o1' => 42], $this->stored(), 'the owner it first entered with');
		$this->rDb->query("SELECT `user_agent`, `hmac_id` FROM `lines_live` WHERE `uuid` = 'o1'");
		$this->assertSame(['Kodi', null], array_values($this->rDb->get_row()), 'the rest of the record follows');
		$this->assertTrue(ConnectionIngest::upsert(5, ['user_id' => null, 'hmac_id' => 3, 'hmac_identifier' => 'dev'] + $this->record('o1')));
		$this->assertSame(['o1' => 42], $this->stored());
	}

	public function testTheSettingIsInstalledAndMigratedAsObserve(): void {
		$this->assertStringContainsString("`cluster_conn_binding` varchar(8) DEFAULT 'observe',", InstallSchema::table('settings'));
		$this->assertStringContainsString("ADD COLUMN IF NOT EXISTS `cluster_conn_binding` varchar(8) DEFAULT 'observe'", InstallSchema::migration('071_add_cluster_conn_binding'));
	}
}
