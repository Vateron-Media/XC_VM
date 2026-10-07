<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\ModuleJob;

/**
 * A module action run in the background (Core\Module\ModuleJob): one at a
 * time, queued by the page and run by `console.php module:job`, its outcome
 * read back from the state file; a job whose process went away is not taken
 * for a running one.
 */
final class ModuleJobTest extends TestCase {
	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', sys_get_temp_dir() . '/xcvm-modjob-' . getmypid() . '/');
		}
		@mkdir(CACHE_TMP_PATH, 0777, true);
		@unlink(ModuleJob::stateFile());
	}

	protected function tearDown(): void {
		@unlink(ModuleJob::stateFile());
		@unlink(ModuleJob::stateFile() . '.lock');
	}

	public function testAQueuedJobIsRunAndRecordsItsMessage(): void {
		$rJob = ModuleJob::start('install', 'watch', static fn(): bool => true);
		$this->assertSame('queued', $rJob['status']);

		$rDone = ModuleJob::run(static fn(array $rJob): string => 'Module installed: ' . $rJob['target']);

		$this->assertSame('done', $rDone['status']);
		$this->assertSame('Module installed: watch', ModuleJob::state()['message']);
		$this->assertFalse(ModuleJob::running());
	}

	public function testWhatTheActionThrowsIsTheFailure(): void {
		ModuleJob::start('uninstall', 'watch', static fn(): bool => true);

		$rDone = ModuleJob::run(static function (): string {
			throw new RuntimeException('still required by plex');
		});

		$this->assertSame(['failed', 'still required by plex'], [$rDone['status'], $rDone['message']]);
	}

	public function testASecondJobWaitsForTheFirst(): void {
		$this->assertNotNull(ModuleJob::start('install', 'watch', static fn(): bool => true));

		$this->assertNull(ModuleJob::start('delete', 'plex', static fn(): bool => true), 'queued, not started yet: still the one job');
	}

	public function testAProcessThatCouldNotStartFailsTheJob(): void {
		$rJob = ModuleJob::start('install', 'watch', static fn(): bool => false);

		$this->assertSame('failed', $rJob['status']);
		$this->assertNotNull(ModuleJob::start('install', 'watch', static fn(): bool => true), 'and does not block the next');
	}

	public function testAQueuedJobWhoseProcessNeverCameIsNotRunning(): void {
		$rJob = ModuleJob::start('install', 'watch', static fn(): bool => true);

		$this->assertTrue(ModuleJob::running($rJob, (int) $rJob['started'] + ModuleJob::START_TIMEOUT - 1));
		$this->assertFalse(ModuleJob::running($rJob, (int) $rJob['started'] + ModuleJob::START_TIMEOUT));
	}

	public function testARunningJobWhoseProcessDiedIsShownFailed(): void {
		// What a process that died mid-action leaves behind.
		file_put_contents(ModuleJob::stateFile(), json_encode(['id' => 'x', 'action' => 'update', 'target' => 'watch', 'status' => 'running', 'pid' => 2147483646, 'started' => time()]));

		$this->assertFalse(ModuleJob::running());
		$this->assertNotNull(ModuleJob::start('install', 'plex', static fn(): bool => true), 'it blocks nothing');
		file_put_contents(ModuleJob::stateFile(), json_encode(['id' => 'x', 'action' => 'update', 'target' => 'watch', 'status' => 'running', 'pid' => 2147483646, 'started' => time()]));
		$this->assertSame('failed', ModuleJob::view()['status']);
	}

	public function testOnlyQueuedJobsAreRun(): void {
		$this->assertNull(ModuleJob::run(static fn(): string => 'nothing queued'));
	}

	public function testAnUnknownActionIsRefused(): void {
		$this->expectException(InvalidArgumentException::class);
		ModuleJob::start('format_disk', 'watch', static fn(): bool => true);
	}
}
