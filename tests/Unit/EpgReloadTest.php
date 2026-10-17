<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Util\Topbar;
use XcVm\Domain\Epg\EpgReload;

final class EpgReloadTest extends TestCase {
	public function testACompetingProcessCannotImportOrReleaseTheHoldersLock(): void {
		$rPath = tempnam(sys_get_temp_dir(), 'epg-lock-');
		try {
			$rRun = function () use ($rPath): int {
				$rCode = 'require $argv[1]; exit(\XcVm\Domain\Epg\EpgReload::run(static function (): int { echo "IMPORT_RAN"; return 42; }, $argv[2]));';
				for ($rAttempt = 0; $rAttempt < 2; $rAttempt++) {
					[$rExit, $rOutput] = ProcessRunner::capture([PHP_BINARY, '-r', $rCode, MAIN_HOME . 'vendor/autoload.php', $rPath]);
					$this->assertSame(0, $rExit);
					$this->assertSame('', $rOutput, 'a competing import must never run');
				}
				return 7;
			};
			$this->assertSame(7, EpgReload::run($rRun, $rPath));
			$this->assertSame(42, EpgReload::run(static fn(): int => 42, $rPath), 'next import can run after release');
		} finally {
			unlink($rPath);
		}
	}

	public function testTheLockIsReleasedAfterAnException(): void {
		$rPath = tempnam(sys_get_temp_dir(), 'epg-lock-');
		try {
			try {
				EpgReload::run(static function (): int { throw new RuntimeException('import failed'); }, $rPath);
				$this->fail('the import exception must propagate');
			} catch (RuntimeException $rError) {
				$this->assertSame('import failed', $rError->getMessage());
			}
			$this->assertSame(0, EpgReload::run(static fn(): int => 0, $rPath));
		} finally {
			unlink($rPath);
		}
	}

	public function testLaunchFailureIsReportedAndArgumentsArePreserved(): void {
		$rCalls = [];
		try {
			ProcessRunner::useRunner(static function (array $rArgv) use (&$rCalls): int {
				$rCalls[] = $rArgv;
				return 127;
			});
			$this->assertFalse(EpgReload::start());
			ProcessRunner::useRunner(static function (array $rArgv) use (&$rCalls): int {
				$rCalls[] = $rArgv;
				return 0;
			});
			$this->assertTrue(EpgReload::start(17));
			$this->assertSame([PHP_BIN, MAIN_HOME . 'console.php', 'cron:epg'], $rCalls[0]);
			$this->assertSame([PHP_BIN, MAIN_HOME . 'console.php', 'cron:epg', '17'], $rCalls[1]);
		} finally {
			ProcessRunner::useRunner(null);
		}
	}

	public function testEpgPermissionShowsReloadWithoutGrantingAddEpg(): void {
		$rSaved = [];
		foreach (['db', 'rUserInfo', 'rPermissions'] as $rKey) {
			$rSaved[$rKey] = $GLOBALS[$rKey] ?? null;
		}
		try {
			$GLOBALS['db'] = new stdClass();
			$GLOBALS['rUserInfo'] = ['id' => 2, 'member_group_id' => 2];
			$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => ['epg']];
			$rLabels = array_column(Topbar::items('epgs'), 'label');
			$this->assertContains('Force Reload', $rLabels);
			$this->assertNotContains('Add EPG', $rLabels);
			$GLOBALS['rPermissions']['advanced'] = ['add_epg'];
			$this->assertNotContains('Force Reload', array_column(Topbar::items('epgs'), 'label'));
		} finally {
			foreach ($rSaved as $rKey => $rValue) {
				if ($rValue === null) {
					unset($GLOBALS[$rKey]);
				} else {
					$GLOBALS[$rKey] = $rValue;
				}
			}
		}
	}

	public function testAjaxReturnsFailureWhenTheLauncherFails(): void {
		$rCode = <<<'PHP'
require $argv[1];
$GLOBALS['db'] = new stdClass();
$GLOBALS['rUserInfo'] = ['id' => 1, 'member_group_id' => 1];
$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
$_SERVER['HTTP_X_REQUESTED_WITH'] = 'XMLHttpRequest';
\XcVm\Core\Process\ProcessRunner::useRunner(static fn(): int => 127);
(new \XcVm\Public\Controllers\Admin\Ajax\EpgAjaxController())->forceEpg();
PHP;
		[$rExit, $rOutput] = ProcessRunner::capture([PHP_BINARY, '-r', $rCode, dirname(__DIR__) . '/bootstrap.php']);
		$this->assertSame(0, $rExit);
		$this->assertSame(['result' => false], json_decode($rOutput, true));
	}
}
