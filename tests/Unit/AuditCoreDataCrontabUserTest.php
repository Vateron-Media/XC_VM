<?php

use PHPUnit\Framework\TestCase;

/**
 * The jobs of the `crontab` table become the xc_vm user's crontab, one
 * `console.php cron:<filename>` line each and no sudo
 * (LegacyInitializer::generateCron, ReplicaApply::crontabText), and xc_vm has
 * no sudoers entry. A job only root may start would never run from its
 * schedule, so none of them asks for root; root's own jobs are the ones
 * StartupCommand::installRootCrontab() lists.
 *
 * The table is checked by reading the jobs' source, so that holds for a user
 * check written with CronTrait's helpers or the posix functions, not for one
 * spelled some other way. cron:maxmind, which the installer and the update
 * also start as root, is started for real.
 */
final class AuditCoreDataCrontabUserTest extends TestCase {
	public function testNoJobOfTheXcVmCrontabAsksForRoot(): void {
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		$this->assertSame(1, preg_match('/INSERT INTO `crontab` \(`id`, `filename`, `time`, `enabled`, `role`\) VALUES\s*(.+?);\n/s', $rSql, $rInsert));
		preg_match_all("/\(\d+, '([a-z_]+)',/", $rInsert[1], $rRows);
		$rScheduled = $rRows[1];
		$this->assertContains('maxmind', $rScheduled);

		$rChecked = [];
		foreach (glob(MAIN_HOME . 'Cli/CronJobs/*.php') ?: [] as $rFile) {
			$rSource = (string) file_get_contents($rFile);
			if (preg_match("/return 'cron:([a-z_]+)';/", $rSource, $rName) !== 1 || !in_array($rName[1], $rScheduled, true)) {
				continue;
			}
			$rChecked[] = $rName[1];
			$this->assertFalse(str_contains($rSource, 'assertRunAsRoot()'), 'cron:' . $rName[1] . ' is started by the xc_vm crontab');
			// A job that looks at who started it accepts xc_vm, through CronTrait's check.
			if (preg_match('/posix_get(e?uid|pwuid)|assertRunAs/', $rSource) === 1) {
				$this->assertTrue(str_contains($rSource, 'assertRunAsXcVm()'), 'cron:' . $rName[1] . ' checks its user some other way');
			}
		}
		$this->assertContains('maxmind', $rChecked);
		$this->assertGreaterThan(20, count($rChecked));
	}

	/**
	 * cron:maxmind in a child PHP that says it was started by user $rName
	 * (id $rUid), on a Monday with the country database in place: nothing
	 * is due, so a job that starts skips and fetches nothing.
	 *
	 * @return array{0: int, 1: string} exit code, output
	 */
	private function maxmindStartedBy(int $rUid, string $rName): array {
		$rScript = sys_get_temp_dir() . '/xcvm-maxmind-user-' . bin2hex(random_bytes(4)) . '.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			namespace XcVm\Cli {
				function posix_geteuid(): int {
					return (int) $GLOBALS['argv'][2];
				}

				function posix_getpwuid(int $rUid): array {
					return ['name' => $GLOBALS['argv'][3]];
				}
			}

			namespace XcVm\Cli\CronJobs {
				function posix_geteuid(): int {
					return (int) $GLOBALS['argv'][2];
				}

				function date(string $rFormat): string {
					return '1';
				}

				function is_file(string $rPath): bool {
					return true;
				}
			}

			namespace {
				require $argv[1] . 'vendor/autoload.php';
				exit((new \XcVm\Cli\CronJobs\MaxMindCronJob())->execute([]));
			}
			PHP);
		exec(implode(' ', array_map('escapeshellarg', [PHP_BINARY, $rScript, MAIN_HOME, (string) $rUid, $rName])) . ' 2>&1', $rOut, $rCode);
		unlink($rScript);
		return [$rCode, implode("\n", $rOut)];
	}

	public function testTheGeoIpJobStartsForXcVmAndForRoot(): void {
		// xc_vm: its schedule. root: the installer and the update.
		foreach ([[1000, 'xc_vm'], [0, 'root']] as [$rUid, $rName]) {
			[$rCode, $rOut] = $this->maxmindStartedBy($rUid, $rName);
			$this->assertSame(0, $rCode, $rName . ': ' . $rOut);
			$this->assertStringContainsString('Skipping MaxMind update', $rOut, $rName);
		}

		[$rCode, $rOut] = $this->maxmindStartedBy(1001, 'www-data');
		$this->assertSame(1, $rCode, $rOut);
		$this->assertStringContainsString('Please run as XC_VM!', $rOut);
		$this->assertStringNotContainsString('GeoIP (MaxMind)', $rOut);
	}

	public function testRootsOwnJobsAreNotInThatTable(): void {
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		foreach (['root_signals', 'root_mysql'] as $rJob) {
			$this->assertStringNotContainsString("'" . $rJob . "'", $rSql);
		}
	}
}
