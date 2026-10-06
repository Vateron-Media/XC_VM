<?php

use PHPUnit\Framework\TestCase;

/**
 * BackupService::getLocal() lists the local backups oldest first. The backups
 * cron removes what exceeds "Local Backups to Keep" from the front of that
 * list, and the Backups page reverses it to show the newest first, so both
 * depend on the order.
 *
 * MAIN_HOME is fixed for the suite, so the list is read in a child PHP whose
 * MAIN_HOME is a throwaway directory.
 */
final class AuditCoreDataBackupRetentionTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-backups-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'backups', 0777, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * The filenames getLocal() returns, in its order, for backups whose age grows with $rAges.
	 *
	 * @param array<string,int> $rAges filename => seconds since it was written
	 * @return string[]
	 */
	private function listed(array $rAges): array {
		foreach ($rAges as $rName => $rAge) {
			touch($this->rDir . 'backups/' . $rName, 1800000000 - $rAge);
		}
		$rScript = $this->rDir . 'child.php';
		file_put_contents($rScript, '<?php define("MAIN_HOME", ' . var_export($this->rDir, true) . '); require ' . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ';'
			. ' echo json_encode(array_column(\XcVm\Core\Backup\BackupService::getLocal(), "filename"));');
		$rOut = shell_exec(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($rScript) . ' 2>/dev/null');
		$rList = json_decode((string) $rOut, true);
		$this->assertIsArray($rList, (string) $rOut);
		return $rList;
	}

	public function testLocalBackupsAreListedOldestFirst(): void {
		// One backup a day, named by its date as the cron names them.
		foreach ([2, 5, 6, 8, 17, 30] as $rCount) {
			$rAges = [];
			for ($i = 1; $i <= $rCount; $i++) {
				$rAges[sprintf('backup_2027-01-%02d_04:00:00.sql', $i)] = ($rCount - $i) * 86400;
			}
			$this->assertSame(array_keys($rAges), $this->listed($rAges), $rCount . ' backups');
		}
	}

	public function testTheOrderIsByAgeNotByName(): void {
		// A pre-update dump sorts after the scheduled ones by name, whatever its age.
		$this->assertSame(
			['pre_rollback_2.0.0.sql', 'backup_2027-01-02_04:00:00.sql', 'a_restored.sql', 'backup_2027-01-03_04:00:00.sql'],
			$this->listed(['backup_2027-01-03_04:00:00.sql' => 10, 'a_restored.sql' => 20, 'backup_2027-01-02_04:00:00.sql' => 30, 'pre_rollback_2.0.0.sql' => 40])
		);
	}

	public function testWhatExceedsTheLimitIsTheOldest(): void {
		$rAges = [];
		for ($i = 1; $i <= 6; $i++) {
			$rAges[sprintf('backup_2027-01-%02d_04:00:00.sql', $i)] = (6 - $i) * 86400;
		}
		// The cron drops the first count - keep entries: with five kept, the first of January's.
		$this->assertSame(['backup_2027-01-01_04:00:00.sql'], array_slice($this->listed($rAges), 0, 6 - 5));
	}

	/** The stubs' record of what the child called: dumps, imports and Dropbox calls, in order. */
	private function calls(): array {
		return is_file($this->rDir . 'calls.log') ? file($this->rDir . 'calls.log', FILE_IGNORE_NEW_LINES) : [];
	}

	/** Stubs of the xcvm_core dump and import, and of Dropbox, that record each call in calls.log. */
	private const STUBS = <<<'PHP'
		namespace XcVm\Core\Storage {
			final class DropboxClient {
				public function SetBearerToken(array $rToken): void {
				}

				public function GetFiles(): array {
					file_put_contents(MAIN_HOME . 'calls.log', "dropbox:list\n", FILE_APPEND);
					return [];
				}

				public function UploadFile(string $rFilename, string $rPath, bool $rOverwrite = true): object {
					file_put_contents(MAIN_HOME . 'calls.log', "dropbox:upload\n", FILE_APPEND);
					return (object) ['size' => filesize($rFilename)];
				}
			}
		}

		namespace {
			// The dump and import xcvm_core does; a failed dump leaves a partial file, as mysqldump does.
			final class XC_VM {
				public static function db_dump(string $rFilename, array $rIgnore): bool {
					file_put_contents(MAIN_HOME . 'calls.log', 'dump ' . basename($rFilename) . "\n", FILE_APPEND);
					file_put_contents($rFilename, '-- dump');
					return getenv('XCVM_TEST_DUMP') !== 'fail';
				}

				public static function db_restore(string $rFilename): bool {
					file_put_contents(MAIN_HOME . 'calls.log', 'restore ' . basename($rFilename) . "\n", FILE_APPEND);
					return true;
				}
			}
		}
		PHP;

	/**
	 * Run cron:backups on its schedule in a child PHP whose deploy root is the
	 * throwaway directory: MAIN, started by xc_vm, a backup due, no Dropbox.
	 *
	 * @param array<string,int> $rAges filename => seconds since it was written (negative: ahead of the clock)
	 * @param array<string,mixed> $rSettings settings over the defaults
	 * @return string[] the backups left afterwards
	 */
	private function leftByTheCron(array $rAges, int $rKeep, array $rSettings = [], bool $rDumpFails = false): array {
		foreach ($rAges as $rName => $rAge) {
			touch($this->rDir . 'backups/' . $rName, time() - $rAge);
		}
		$rScript = $this->rDir . 'cron.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			namespace XcVm\Cli {
				// The job is started by xc_vm; this child need not be.
				function posix_getpwuid(int $rUid): array {
					return ['name' => 'xc_vm'];
				}
			}

			namespace XcVm\Domain\Server {
				// This server is MAIN.
				final class ServerRepository {
					public static function getAll(): array {
						return [1 => ['is_main' => 1]];
					}
				}
			}

			namespace XcVm\Domain\Backup {
				// No backup target is set up.
				final class BackupTargets {
					public static function enabled(): array {
						return [];
					}
				}
			}

			namespace {
				define('MAIN_HOME', $argv[1]);
				define('SERVER_ID', 1);
				define('CRONS_TMP_PATH', MAIN_HOME . 'crons/');
				define('CONFIG_PATH', MAIN_HOME . 'config/');

				require $argv[2] . 'vendor/autoload.php';
				\XcVm\Core\Config\SettingsManager::set(json_decode($argv[4], true) + ['automatic_backups' => 'daily', 'last_backup' => 0, 'backups_to_keep' => (int) $argv[3]]);
				$db = new class {
					public function query(string $rQuery, mixed ...$rArgs): bool {
						return true;
					}

					public function close_mysql(): bool {
						return true;
					}
				};
				exit((new \XcVm\Cli\CronJobs\BackupsCronJob())->execute([]));
			}
			PHP . "\n" . self::STUBS);
		exec(($rDumpFails ? 'XCVM_TEST_DUMP=fail ' : '') . implode(' ', array_map('escapeshellarg', [...xcvm_test_child_php(), $rScript, $this->rDir, MAIN_HOME, (string) $rKeep, json_encode((object) $rSettings)])) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		return array_values(array_filter(scandir($this->rDir . 'backups'), static fn(string $rName): bool => str_ends_with($rName, '.sql')));
	}

	public function testARunDropsTheOldestBeyondTheLimit(): void {
		$rLeft = $this->leftByTheCron(['backup_2027-01-01_04:00:00.sql' => 3 * 86400, 'backup_2027-01-02_04:00:00.sql' => 2 * 86400, 'backup_2027-01-03_04:00:00.sql' => 86400], 2);
		$this->assertCount(2, $rLeft);
		$this->assertContains('backup_2027-01-03_04:00:00.sql', $rLeft);
		$this->assertNotContains('backup_2027-01-01_04:00:00.sql', $rLeft);
		$this->assertNotContains('backup_2027-01-02_04:00:00.sql', $rLeft);
	}

	public function testARunNeverDropsTheBackupItMade(): void {
		// One kept, and the one already there was written with the clock ahead of now.
		$rLeft = $this->leftByTheCron(['backup_2027-01-01_04:00:00.sql' => -86400], 1);
		$this->assertCount(1, array_diff($rLeft, ['backup_2027-01-01_04:00:00.sql']), 'the backup of this run: ' . implode(', ', $rLeft));
	}

	public function testADumpThatDidNotFinishIsNotKept(): void {
		$rLeft = $this->leftByTheCron(['backup_2027-01-01_04:00:00.sql' => 86400], 1, [], true);
		// The older backup stays and the partial dump is gone.
		$this->assertSame(['backup_2027-01-01_04:00:00.sql'], $rLeft);
		$this->assertCount(1, $this->calls(), implode(', ', $this->calls()));
	}

	public function testDropboxIsAskedForItsListOnlyAfterABackup(): void {
		$this->leftByTheCron([], 0, ['dropbox_remote' => 1, 'last_backup' => time()]);
		$this->assertSame([], $this->calls(), 'nothing due: no dump and no Dropbox call');

		$this->leftByTheCron([], 0, ['dropbox_remote' => 1]);
		$this->assertSame(['dropbox:upload', 'dropbox:list'], array_slice($this->calls(), 1));
	}

	public function testRetentionLeavesTheDumpTakenBeforeARestore(): void {
		$rLeft = $this->leftByTheCron(['pre_restore_2027-01-02_10:00:00.sql' => 30, 'backup_2027-01-01_04:00:00.sql' => 86400], 1);
		$this->assertContains('pre_restore_2027-01-02_10:00:00.sql', $rLeft);
		$this->assertNotContains('backup_2027-01-01_04:00:00.sql', $rLeft);
		$this->assertCount(2, $rLeft);
	}

	/**
	 * BackupService::restore() in a child PHP whose deploy root is the throwaway directory.
	 *
	 * @return array{0: ?bool, 1: string[]} what restore() returned, and the backups left
	 */
	private function restored(string $rFile, bool $rForce, bool $rDumpFails = false): array {
		$rScript = $this->rDir . 'restore.php';
		file_put_contents($rScript, "<?php\n" . self::STUBS . "\n" . <<<'PHP'
			namespace {
				define('MAIN_HOME', $argv[1]);
				require $argv[2] . 'vendor/autoload.php';
				echo json_encode(\XcVm\Core\Backup\BackupService::restore(MAIN_HOME . 'backups/' . $argv[3], (bool) $argv[4]));
			}
			PHP);
		exec(($rDumpFails ? 'XCVM_TEST_DUMP=fail ' : '') . implode(' ', array_map('escapeshellarg', [...xcvm_test_child_php(), $rScript, $this->rDir, MAIN_HOME, $rFile, $rForce ? '1' : '0'])) . ' 2>&1', $rOut, $rCode);
		$this->assertSame(0, $rCode, implode("\n", $rOut));
		return [json_decode(implode('', $rOut), true), array_values(array_filter(scandir($this->rDir . 'backups'), static fn(string $rName): bool => str_ends_with($rName, '.sql')))];
	}

	public function testARestoreDumpsTheLiveDatabaseFirstAndLeavesItsSourceAlone(): void {
		$rSource = $this->rDir . 'backups/backup_2027-01-01_04:00:00.sql';
		file_put_contents($rSource, str_repeat('-- the backup', 100));
		touch($rSource, 1800000000);

		[$rResult, $rLeft] = $this->restored('backup_2027-01-01_04:00:00.sql', false);

		$this->assertTrue($rResult);
		$this->assertCount(2, $this->calls());
		$this->assertMatchesRegularExpression('/^dump pre_restore_\d{4}-\d\d-\d\d_\d\d:\d\d:\d\d\.sql$/', $this->calls()[0]);
		$this->assertSame('restore backup_2027-01-01_04:00:00.sql', $this->calls()[1]);
		$this->assertCount(2, $rLeft);
		clearstatcache();
		$this->assertSame(str_repeat('-- the backup', 100), file_get_contents($rSource));
		$this->assertSame(1800000000, filemtime($rSource));
	}

	public function testARestoreIsRefusedWhenTheLiveDatabaseCannotBeDumpedUnlessForced(): void {
		file_put_contents($this->rDir . 'backups/backup_2027-01-01_04:00:00.sql', '-- the backup');

		[$rResult, $rLeft] = $this->restored('backup_2027-01-01_04:00:00.sql', false, true);
		$this->assertNull($rResult);
		$this->assertCount(1, $this->calls(), 'no import: ' . implode(', ', $this->calls()));
		$this->assertSame(['backup_2027-01-01_04:00:00.sql'], $rLeft, 'no partial pre_restore file');

		[$rResult] = $this->restored('backup_2027-01-01_04:00:00.sql', true, true);
		$this->assertTrue($rResult);
		$this->assertSame('restore backup_2027-01-01_04:00:00.sql', $this->calls()[1]);
	}
}
