<?php

namespace XcVm\Core\Module;

use XcVm\Core\Process\ProcessRunner;

/**
 * One module action run in the background (`console.php module:job`), so the
 * modules page does not hold a request open on the store, GitHub, a module's
 * migrations or the load balancers: the page starts it, then polls state().
 *
 * One at a time: every action rewrites config/modules.php by read-modify-write,
 * and two at once would lose one's changes.
 *
 * The state is a JSON file in the cache, read with file_get_contents (never
 * require: OPcache would hand each PHP-FPM master its own stale copy).
 *
 * @package XC_VM_Core_Module
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ModuleJob {
	public const ACTIONS = ['install', 'update', 'uninstall', 'delete', 'rollback', 'renew_license', 'store_install', 'upload_install', 'check_updates'];

	/** Seconds a queued job may wait for its process before it counts as never started. */
	public const START_TIMEOUT = 30;

	public static function stateFile(): string {
		return CACHE_TMP_PATH . 'module_job.json';
	}

	/** @return array<string, mixed>|null the last job */
	public static function state(): ?array {
		$rState = json_decode((string) @file_get_contents(self::stateFile()), true);
		return is_array($rState) ? $rState : null;
	}

	/**
	 * Is a job going on: queued and its process not yet due, or running and its
	 * process alive. A process that died leaves "running" behind: not running.
	 *
	 * @param array<string, mixed>|null $rState
	 */
	public static function running(?array $rState = null, ?int $rNow = null): bool {
		$rState ??= self::state();
		$rStatus = (string) ($rState['status'] ?? '');
		if ($rStatus === 'queued') {
			return ($rNow ?? time()) - (int) ($rState['started'] ?? 0) < self::START_TIMEOUT;
		}
		return $rStatus === 'running' && (int) ($rState['pid'] ?? 0) > 0 && @posix_kill((int) $rState['pid'], 0);
	}

	/**
	 * The job as the page shows it: a "running" job whose process is gone, or a
	 * queued one that never started, is reported failed.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function view(): ?array {
		$rState = self::state();
		if ($rState !== null && in_array($rState['status'] ?? '', ['queued', 'running'], true) && !self::running($rState)) {
			$rState['status']  = 'failed';
			$rState['message'] = 'The module action stopped before it finished.';
		}
		return $rState;
	}

	/**
	 * Queue an action and start its process. Null while another job runs.
	 *
	 * @param callable(): bool|null $rLaunch tests: stands in for starting `console.php module:job`
	 * @return array<string, mixed>|null the queued job
	 */
	public static function start(string $rAction, string $rTarget, ?callable $rLaunch = null): ?array {
		if (!in_array($rAction, self::ACTIONS, true)) {
			throw new \InvalidArgumentException('Unknown module action: ' . $rAction);
		}
		// Two clicks at once must not both see "nothing running" and both queue.
		$rLock = @fopen(self::stateFile() . '.lock', 'c');
		if ($rLock !== false) {
			flock($rLock, LOCK_EX);
		}
		try {
			if (self::running()) {
				return null;
			}
			$rJob = ['id' => bin2hex(random_bytes(8)), 'action' => $rAction, 'target' => $rTarget, 'status' => 'queued', 'message' => '', 'pid' => 0, 'started' => time(), 'finished' => 0];
			self::save($rJob);
		} finally {
			if ($rLock !== false) {
				flock($rLock, LOCK_UN);
				fclose($rLock);
			}
		}
		$rLaunch ??= static fn(): bool => ProcessRunner::start([PHP_BIN, MAIN_HOME . 'console.php', 'module:job']);
		if (!$rLaunch()) {
			return self::finish($rJob, 'failed', 'The module action could not be started.');
		}
		return $rJob;
	}

	/**
	 * Run the queued job (the command): mark it running, do $rWork, record the
	 * outcome. Null when nothing is queued.
	 *
	 * @param callable(array<string, mixed>): string $rWork the action; its message, or it throws
	 * @return array<string, mixed>|null the finished job
	 */
	public static function run(callable $rWork): ?array {
		$rJob = self::state();
		if (($rJob['status'] ?? '') !== 'queued') {
			return null;
		}
		$rJob['status'] = 'running';
		$rJob['pid']    = getmypid();
		self::save($rJob);
		try {
			return self::finish($rJob, 'done', $rWork($rJob));
		} catch (\Throwable $e) {
			return self::finish($rJob, 'failed', $e->getMessage());
		}
	}

	/**
	 * @param array<string, mixed> $rJob
	 * @return array<string, mixed>
	 */
	private static function finish(array $rJob, string $rStatus, string $rMessage): array {
		$rJob['status']   = $rStatus;
		$rJob['message']  = $rMessage;
		$rJob['finished'] = time();
		self::save($rJob);
		return $rJob;
	}

	/** @param array<string, mixed> $rJob */
	private static function save(array $rJob): void {
		$rTemp = self::stateFile() . '.tmp';
		if (file_put_contents($rTemp, (string) json_encode($rJob, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX) !== false) {
			rename($rTemp, self::stateFile());
		}
	}
}
