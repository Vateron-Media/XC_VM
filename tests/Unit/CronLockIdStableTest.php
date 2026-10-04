<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessManager;

/**
 * Cron lock ids no longer depend on live_streaming_pass. The old name hashed
 * the secret, so rotating it (the cluster plan rotates it twice) let a second
 * instance of every cron start beside one still running under the old name.
 */
final class CronLockIdStableTest extends TestCase {

	private string $rDir;

	protected function setUp(): void {
		if (!defined('CRONS_TMP_PATH')) {
			define('CRONS_TMP_PATH', sys_get_temp_dir() . '/xcvm_crons_' . getmypid() . '/');
		}
		$this->rDir = CRONS_TMP_PATH;
		@mkdir($this->rDir, 0775, true);
	}

	protected function tearDown(): void {
		foreach (glob($this->rDir . '*') ?: [] as $rFile) {
			@unlink($rFile);
		}
	}

	public function testLockPathIsStablePerClassAndIgnoresTheSecret(): void {
		$rA = ProcessManager::cronLockPath('XcVm\\Cli\\CronJobs\\ServersCronJob');
		$this->assertSame($rA, ProcessManager::cronLockPath('XcVm\\Cli\\CronJobs\\ServersCronJob'));
		$this->assertNotSame($rA, ProcessManager::cronLockPath('XcVm\\Cli\\CronJobs\\CacheCronJob'));
		$this->assertStringStartsWith(CRONS_TMP_PATH, $rA);
		$this->assertNotSame(
			ProcessManager::legacyCronLockPath('X', 'secret-one'),
			ProcessManager::legacyCronLockPath('X', 'secret-two'),
			'the legacy name is exactly what a rotation used to break'
		);
	}

	public function testNoCronLockIsDerivedFromTheStreamingSecret(): void {
		$rRoot = MAIN_HOME;
		foreach (['Cli/CronTrait.php', 'Cli/CronJobs/RootSignalsCronJob.php', 'Cli/Commands/ServerDiagnoseCommand.php'] as $rFile) {
			$rSource = (string) file_get_contents($rRoot . $rFile);
			$this->assertStringContainsString('ProcessManager::cronLockPath(', $rSource, $rFile);
			$this->assertStringNotContainsString("md5(Encryption::generateUniqueCode(SettingsManager::get('live_streaming_pass')", $rSource, $rFile);
		}
	}

	public function testAStaleOrMissingLegacyLockDoesNotBlock(): void {
		$rLegacy = ProcessManager::legacyCronLockPath('X', 'pass');
		ProcessManager::exitIfCronLockHeld($rLegacy); // absent: returns
		file_put_contents($rLegacy, '999999999');      // pid that cannot exist
		ProcessManager::exitIfCronLockHeld($rLegacy);
		$this->addToAssertionCount(1);
	}
}
