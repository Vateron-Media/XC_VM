<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootMysqlCronJob;
use XcVm\Core\Process\ProcessRunner;

/**
 * cron:root_mysql keeps MariaDB's errors: MariaDB writes the level of an
 * error as `[ERROR]` (its notes and warnings as `[Note]` and `[Warning]`),
 * and such a line becomes an ERROR row of System Logs.
 *
 * System Logs keep (keep_syslog): a line older than it is not stored, so the
 * lines cron:cleanup pruned do not come back from the tail of the log once no
 * newer row is left to say they were read.
 *
 * The child PHP is AuditDecisionMysqlBlockTest's: the job's commands are
 * stand-ins, the rows are real.
 */
final class AuditDecisionMysqlCronTest extends TestCase {
	private const IS_ACTIVE = 'systemctl is-active mariadb 2>/dev/null';
	private const RESTART = 'systemctl restart mariadb 2>&1';
	private const TAIL = 'sudo tail -n 1000 /var/log/syslog | grep mysqld';

	private const REFUSED = "[Warning] Access denied for user 'root'@'203.0.113.7' (using password: YES)";

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-mysql-cron-' . bin2hex(random_bytes(4)) . '/';
		foreach (['crons', 'cache', 'flood'] as $rSub) {
			mkdir($this->rDir . 'tmp/' . $rSub, 0777, true);
		}
		file_put_contents($this->rDir . 'cron.php', <<<'PHP'
			<?php
			namespace XcVm\Cli {
				function posix_geteuid(): int {
					return 0;
				}

				function posix_getpwuid(int $rUid): array {
					return ['name' => 'root'];
				}
			}

			namespace XcVm\Cli\CronJobs {
				// Every command of the job ends here: nothing is started.
				function exec(string $rCommand, &$rOutput = null, &$rCode = null): string {
					$GLOBALS['rRan'][] = $rCommand;
					$rOutput = is_array($rOutput) ? $rOutput : [];
					$rCode = 0;
					if (str_starts_with($rCommand, 'systemctl is-active mariadb')) {
						$rOutput[] = array_shift($GLOBALS['rCase']['mariadb']) ?? 'active';
					} elseif (str_contains($rCommand, '/var/log/syslog')) {
						array_push($rOutput, ...preg_grep('/mysqld/', $GLOBALS['rCase']['syslog']));
					}
					return '';
				}

				// The log's size, the same on every run: it did not grow in between.
				function filesize(string $rPath): int {
					return 1 + strlen(implode("\n", $GLOBALS['rCase']['syslog']));
				}

				function sleep(int $rSeconds): int {
					return 0;
				}
			}

			namespace {
				[, $rAutoload, $rTestDb, $rDir] = $argv;
				ini_set('display_errors', '1');
				date_default_timezone_set('UTC');
				define('MAIN_HOME', $rDir);
				define('SERVER_ID', 1);
				foreach (['CRONS' => 'crons', 'CACHE' => 'cache', 'FLOOD' => 'flood'] as $rName => $rSub) {
					define($rName . '_TMP_PATH', $rDir . 'tmp/' . $rSub . '/');
				}
				require $rAutoload;
				require $rTestDb;
				foreach (\XcVm\Core\Config\ConstantsInitializer::statuses() as $rName => $rValue) {
					define($rName, $rValue);
				}

				$rCase = json_decode((string) file_get_contents($rDir . 'case.json'), true);
				$rRan = [];
				$rServers = [1 => ['server_ip' => '192.0.2.1', 'private_ip' => '', 'whitelist_ips' => '', 'domain_name' => '']];
				$rSettings = [];
				\XcVm\Core\Config\SettingsManager::set(['mysql_sleep_kill' => $rCase['sleep_kill'], 'keep_syslog' => $rCase['keep'], 'live_streaming_pass' => 'pass']);

				// The job's connection: the test server, but for the process list and KILL,
				// which are answered here (the server is shared with other tests).
				$db = new class (new TestDb()) {
					/** @var list<array{0: string, 1: list<mixed>}> */
					public array $rAsked = [];

					private bool $rList = false;

					public function __construct(public TestDb $rReal) {
					}

					public function query($rQuery, ...$rArgs) {
						$this->rList = str_contains($rQuery, '`PROCESSLIST`');
						if ($this->rList || str_starts_with($rQuery, 'KILL ')) {
							$this->rAsked[] = [$rQuery, $rArgs];
							return true;
						}
						return $this->rReal->query($rQuery, ...$rArgs);
					}

					public function get_rows() {
						return $this->rList ? [['id' => 41], ['id' => 42]] : $this->rReal->get_rows();
					}

					public function get_row() {
						return $this->rReal->get_row();
					}
				};
				\XcVm\Infrastructure\Database\DatabaseFactory::set($db->rReal);
				$db->rReal->exec('CREATE TABLE `mysql_syslog` (`id` int NOT NULL AUTO_INCREMENT, `type` varchar(50) DEFAULT NULL, `error` longtext, `username` varchar(64) DEFAULT NULL, `ip` varchar(64) DEFAULT NULL, `database` varchar(64) DEFAULT NULL, `date` int DEFAULT NULL, `server_id` tinyint DEFAULT 1, PRIMARY KEY (`id`))');
				$db->rReal->exec('CREATE TABLE `blocked_ips` (`id` int NOT NULL AUTO_INCREMENT, `ip` varchar(39) DEFAULT NULL, `notes` mediumtext, `date` int DEFAULT NULL, PRIMARY KEY (`id`), UNIQUE KEY `ip_2` (`ip`))');
				foreach ($rCase['rows'] as $rRow) {
					$db->rReal->query('INSERT INTO `mysql_syslog`(`type`, `error`, `username`, `ip`, `date`) VALUES(?, ?, ?, ?, ?)', ...$rRow);
				}

				$rCodes = [];
				ob_start();
				for ($i = 0; $i < $rCase['runs']; $i++) {
					$rCodes[] = (new \XcVm\Cli\CronJobs\RootMysqlCronJob())->execute([]);
				}
				$rSaid = ob_get_clean();

				echo json_encode([
					'codes' => $rCodes,
					'said' => $rSaid,
					'ran' => $rRan,
					'asked' => $db->rAsked,
					'syslog' => $db->rReal->pdo->query('SELECT `type`, `error`, `username`, `ip`, `database`, CAST(`date` AS CHAR) FROM `mysql_syslog` ORDER BY `id`')->fetchAll(PDO::FETCH_NUM),
					'blocked' => $db->rReal->pdo->query('SELECT `ip`, `notes` FROM `blocked_ips` ORDER BY `id`')->fetchAll(PDO::FETCH_NUM),
					'blocks' => array_map('basename', glob(FLOOD_TMP_PATH . '*') ?: []),
				]);
			}
			PHP);
	}

	protected function tearDown(): void {
		foreach (array_merge(glob($this->rDir . 'tmp/*/*') ?: [], glob($this->rDir . '*.*') ?: []) as $rFile) {
			unlink($rFile);
		}
		foreach (['tmp/crons', 'tmp/cache', 'tmp/flood', 'tmp', ''] as $rSub) {
			rmdir($this->rDir . $rSub);
		}
	}

	/** A line of MariaDB's in the system log, as an install whose MariaDB logs there has it. */
	private static function line(int $rTime, string $rText): string {
		return gmdate('M j H:i:s', $rTime) . ' panel mysqld[812]: ' . gmdate('Y-m-d H:i:s', $rTime) . ' 14 ' . $rText;
	}

	/**
	 * cron:root_mysql as root's crontab starts it, `runs` times over.
	 *
	 * @param array{syslog?: list<string>, rows?: list<array{0: string, 1: string, 2: ?string, 3: ?string, 4: int}>, sleep_kill?: int, mariadb?: list<string>, runs?: int} $rCase
	 *        the system log; the System Logs rows already kept; the setting; what `systemctl is-active` answers, call by call
	 * @return array{codes: list<int>, said: string, ran: list<string>, asked: list<array{0: string, 1: list<mixed>}>, syslog: list<list<?string>>, blocked: list<list<string>>, blocks: list<string>}
	 *         exit codes; output; commands; process-list statements; System Logs rows; blocked addresses; the flood guard's files
	 */
	private function cron(array $rCase): array {
		file_put_contents($this->rDir . 'case.json', json_encode($rCase + ['syslog' => [], 'rows' => [], 'sleep_kill' => 0, 'mariadb' => [], 'runs' => 1, 'keep' => 0]));
		[$rCode, $rOut] = ProcessRunner::capture([PHP_BINARY, $this->rDir . 'cron.php', MAIN_HOME . 'vendor/autoload.php', dirname(__DIR__) . '/Support/TestDb.php', $this->rDir], 1 << 20);
		$rResult = json_decode($rOut, true);
		$this->assertIsArray($rResult, 'exit ' . $rCode . ': ' . $rOut);
		return $rResult;
	}

	public function testAnErrorOfMariaDbIsStoredAsAnError(): void {
		$rNow = time();
		// As MariaDB 11.4 writes them: `[ERROR]` in capitals, the others not.
		$rResult = $this->cron(['syslog' => [
			self::line($rNow - 30, "[ERROR] mariadbd: Table './xc_vm/lines' is marked as crashed and should be repaired"),
			self::line($rNow - 20, '[Warning] Could not increase number of max_open_files to more than 32000'),
			self::line($rNow - 10, '[ERROR] Aborting'),
		]]);

		$this->assertSame([0], $rResult['codes'], $rResult['said']);
		$this->assertSame([
			['ERROR', "mariadbd: Table './xc_vm/lines' is marked as crashed and should be repaired", null, null, null, (string) ($rNow - 30)],
			['WARNING', 'Could not increase number of max_open_files to more than 32000', null, null, null, (string) ($rNow - 20)],
			['ERROR', 'Aborting', null, null, null, (string) ($rNow - 10)],
		], $rResult['syslog']);
	}

	public function testALineOlderThanSystemLogsKeepIsNotStoredAgain(): void {
		$rNow = time();
		// Pruned by cron:cleanup: no row is left, the lines are still in the log.
		$rResult = $this->cron(['keep' => 3600, 'syslog' => [
			self::line($rNow - 7200, '[Warning] Could not increase number of max_open_files to more than 32000'),
			self::line($rNow - 60, '[Note] InnoDB: Buffer pool(s) load completed'),
		]]);

		$this->assertSame([0], $rResult['codes'], $rResult['said']);
		$this->assertSame([['NOTICE', 'InnoDB: Buffer pool(s) load completed', null, null, null, (string) ($rNow - 60)]], $rResult['syslog']);
	}

	public function testEveryLineIsStoredWhenSystemLogsAreKeptForEver(): void {
		$rNow = time();
		$this->assertCount(2, $this->cron(['syslog' => [
			self::line($rNow - 7200, '[Warning] Could not increase number of max_open_files to more than 32000'),
			self::line($rNow - 60, '[Note] InnoDB: Buffer pool(s) load completed'),
		]])['syslog']);
	}

	/** cron:cleanup prunes System Logs as it prunes the other logs: by their Keep Logs For setting, on MAIN. */
	public function testCleanupPrunesSystemLogsByTheirOwnSetting(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/CleanupCronJob.php');
		$this->assertStringContainsString("'mysql_syslog' => ['keep_syslog', 'date']", $rSource);
		$this->assertContains('keep_syslog', \XcVm\Tests\Support\InstallSchema::columns('settings'));
		$this->assertStringContainsString('name="keep_syslog"', (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/settings.php'));
	}
}
