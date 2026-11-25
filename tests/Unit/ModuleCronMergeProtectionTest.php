<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ModuleCronSyncCommand;

/** Keep both branches' cron protections when XCSEC-2 and XCSEC-3 are merged. */
final class ModuleCronMergeProtectionTest extends TestCase {
	/** @dataProvider crontabListResults */
	public function testOnlyConfirmedMissingCrontabIsEmpty(int $rCode, string $rOutput, bool $rMissing): void {
		$rMethod = new ReflectionMethod(ModuleCronSyncCommand::class, 'crontabMissing');
		$this->assertSame($rMissing, $rMethod->invoke(null, $rCode, $rOutput));
	}

	public function testSyncKeepsTheSharedLockAndReadFailureGuard(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ModuleCronSyncCommand.php');
		$this->assertStringContainsString('CrontabLock::acquire()', $rSource);
		$this->assertStringContainsString('self::crontabMissing($rCode, $rCurrent)', $rSource);
	}

	public static function crontabListResults(): array {
		return [
			'absent' => [1, "no crontab for xc_vm\n", true],
			'read error' => [1, "cannot read crontab\n", false],
			'success' => [0, '', false],
			'other failure' => [2, "permission denied\n", false],
		];
	}
}
