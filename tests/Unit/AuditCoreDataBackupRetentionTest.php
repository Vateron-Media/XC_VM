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

	/**
	 * Run cron:backups on its schedule in a child PHP whose deploy root is the
	 * throwaway directory: MAIN, started by xc_vm, a backup due, no Dropbox.
	 *
	 * @param array<string,int> $rAges filename => seconds since it was written (negative: ahead of the clock)
	 * @return string[] the backups left afterwards
	 */
	private function leftByTheCron(array $rAges, int $rKeep): array {
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

			namespace {
				define('MAIN_HOME', $argv[1]);
				define('SERVER_ID', 1);
				define('CRONS_TMP_PATH', MAIN_HOME . 'crons/');

				// The dump xcvm_core writes.
				final class XC_VM {
					public static function db_dump(string $rFilename, array $rIgnore): void {
						file_put_contents($rFilename, '-- dump');
					}
				}

				require $argv[2] . 'vendor/autoload.php';
				\XcVm\Core\Config\SettingsManager::set(['automatic_backups' => 'daily', 'last_backup' => 0, 'backups_to_keep' => (int) $argv[3]]);
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
			PHP);
		exec(implode(' ', array_map('escapeshellarg', [...xcvm_test_child_php(), $rScript, $this->rDir, MAIN_HOME, (string) $rKeep])) . ' 2>&1', $rOut, $rCode);
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
}
