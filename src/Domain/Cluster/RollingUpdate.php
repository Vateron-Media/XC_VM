<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Process\ProcessRunner;

/**
 * A rolling update of the load balancers (Servers → Rolling Update,
 * `console.php cluster:rolling-update`): one at a time to MAIN's release,
 * where Update All Servers signals every server at once. Each load balancer
 * gets the update (NodeActions::update), must be back online on MAIN's
 * version within UPDATE_TIMEOUT and stay online for SOAK; then the next one
 * goes. A load balancer that does not stops the run and leaves the rest as
 * they are. MAIN is updated first, by the admin; proxies run their own
 * release and are not part of it.
 *
 * The run's progress is a JSON file in the cache (state()), read by the page.
 * A cancel takes effect at the next look: the load balancer already updating
 * goes on by itself.
 *
 * @package XC_VM_Domain_Cluster
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class RollingUpdate {
	/** Seconds a load balancer has to come back on MAIN's version. */
	public const UPDATE_TIMEOUT = 1200;

	/** Seconds it must then stay online before the next one goes. */
	public const SOAK = 60;

	/** Seconds between two looks at the servers. */
	public const POLL = 10;

	public static function stateFile(): string {
		return CACHE_TMP_PATH . 'rolling_update.json';
	}

	public static function cancelFile(): string {
		return CACHE_TMP_PATH . 'rolling_update.cancel';
	}

	/**
	 * The load balancers a run updates: enabled and online, not MAIN, not a
	 * proxy (server_type 1), not on $rVersion already; by id.
	 *
	 * @param array<int|string, array<string, mixed>> $rServers ServerRepository::getAll()
	 * @return list<int>
	 */
	public static function targets(array $rServers, string $rVersion): array {
		$rOut = [];
		foreach ($rServers as $rServer) {
			if (empty($rServer['is_main']) && (int) ($rServer['server_type'] ?? 0) === 0 && !empty($rServer['enabled']) && !empty($rServer['server_online']) && (string) ($rServer['xc_vm_version'] ?? '') !== $rVersion) {
				$rOut[] = (int) $rServer['id'];
			}
		}
		sort($rOut);
		return $rOut;
	}

	/** @return array<string, mixed>|null the last run's state */
	public static function state(): ?array {
		$rState = json_decode((string) @file_get_contents(self::stateFile()), true);
		return is_array($rState) ? $rState : null;
	}

	/** Is a run going on: its state says so and its process is alive. */
	public static function running(?array $rState = null): bool {
		$rState ??= self::state();
		return in_array($rState['status'] ?? '', ['starting', 'running'], true) && (int) ($rState['pid'] ?? 0) > 0 && @posix_kill((int) $rState['pid'], 0);
	}

	/** Start a run in the background (the page). False while one is going on or when it could not start. */
	public static function start(): bool {
		if (self::running()) {
			return false;
		}
		@unlink(self::cancelFile());
		self::save(['status' => 'starting', 'version' => XC_VM_VERSION, 'started' => time(), 'pid' => 0, 'nodes' => []]);
		return ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'cluster:rolling-update']);
	}

	public static function cancel(): void {
		touch(self::cancelFile());
	}

	/**
	 * Run it: what the command does, its world passed in.
	 *
	 * @param callable(): array<int|string, array<string, mixed>> $rServers The servers now, as ServerRepository::getAll(true).
	 * @param callable(int): bool $rUpdate Send one load balancer its update (NodeActions::update).
	 * @param callable(int): void $rSleep
	 * @param callable(): int $rNow
	 * @return array<string, mixed> The run's last state.
	 */
	public static function run(string $rVersion, callable $rServers, callable $rUpdate, callable $rSleep, callable $rNow): array {
		$rAll = $rServers();
		$rState = ['status' => 'running', 'version' => $rVersion, 'started' => $rNow(), 'pid' => getmypid(), 'nodes' => []];
		foreach (self::targets($rAll, $rVersion) as $rID) {
			$rState['nodes'][] = ['id' => $rID, 'name' => (string) ($rAll[$rID]['server_name'] ?? ('#' . $rID)), 'state' => 'waiting', 'since' => null, 'error' => ''];
		}
		self::save($rState);
		foreach (array_keys($rState['nodes']) as $i) {
			$rID = $rState['nodes'][$i]['id'];
			if (self::cancelled()) {
				return self::finish($rState, 'cancelled', $rNow());
			}
			self::step($rState, $i, 'updating', $rNow());
			if (!$rUpdate($rID)) {
				return self::fail($rState, $i, 'the update could not be sent', $rNow());
			}
			// Back on MAIN's version, online.
			$rDeadline = $rNow() + self::UPDATE_TIMEOUT;
			while (!self::back($rServers()[$rID] ?? null, $rVersion)) {
				if (self::cancelled()) {
					return self::finish($rState, 'cancelled', $rNow());
				}
				if ($rNow() >= $rDeadline) {
					return self::fail($rState, $i, 'not back online on ' . $rVersion . ' within ' . intdiv(self::UPDATE_TIMEOUT, 60) . ' minutes', $rNow());
				}
				$rSleep(self::POLL);
			}
			// And staying so.
			self::step($rState, $i, 'soaking', $rNow());
			$rUntil = $rNow() + self::SOAK;
			while ($rNow() < $rUntil) {
				$rSleep(self::POLL);
				if (!self::back($rServers()[$rID] ?? null, $rVersion)) {
					return self::fail($rState, $i, 'went offline after its update', $rNow());
				}
			}
			self::step($rState, $i, 'done', $rNow());
		}
		return self::finish($rState, 'done', $rNow());
	}

	/** @param array<string, mixed>|null $rServer */
	private static function back(?array $rServer, string $rVersion): bool {
		return $rServer !== null && !empty($rServer['server_online']) && (string) ($rServer['xc_vm_version'] ?? '') === $rVersion;
	}

	private static function cancelled(): bool {
		return is_file(self::cancelFile());
	}

	/** @param array<string, mixed> $rState */
	private static function step(array &$rState, int $i, string $rStep, int $rNow): void {
		$rState['nodes'][$i]['state'] = $rStep;
		$rState['nodes'][$i]['since'] = $rNow;
		self::save($rState);
	}

	/**
	 * @param array<string, mixed> $rState
	 * @return array<string, mixed>
	 */
	private static function fail(array $rState, int $i, string $rError, int $rNow): array {
		$rState['nodes'][$i]['state'] = 'failed';
		$rState['nodes'][$i]['error'] = $rError;
		return self::finish($rState, 'failed', $rNow);
	}

	/**
	 * @param array<string, mixed> $rState
	 * @return array<string, mixed>
	 */
	private static function finish(array $rState, string $rStatus, int $rNow): array {
		$rState['status'] = $rStatus;
		$rState['finished'] = $rNow;
		self::save($rState);
		@unlink(self::cancelFile());
		return $rState;
	}

	/** @param array<string, mixed> $rState */
	private static function save(array $rState): void {
		$rTemp = self::stateFile() . '.tmp';
		if (file_put_contents($rTemp, (string) json_encode($rState, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
			rename($rTemp, self::stateFile());
		}
	}
}
