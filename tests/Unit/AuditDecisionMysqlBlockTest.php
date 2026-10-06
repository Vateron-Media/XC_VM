<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\CronJobs\RootMysqlCronJob;
use XcVm\Core\Process\ProcessRunner;

/**
 * cron:root_mysql blocks no address. What MariaDB writes to the system log
 * about a refused login is no ground for a block, so the job does not pick
 * those lines out (no `AUTH` row, no address taken from one) and counts no
 * rows towards one: who may reach the database port is the allowlist's to say
 * (DbAllowlist), and a block is an admin's own.
 *
 * The rest of the job is as it was: MariaDB is restarted when it is down,
 * connections asleep longer than `mysql_sleep_kill` are closed, and MariaDB's
 * notes and warnings in the system log become System Logs rows.
 *
 * Every run here is a child PHP in which the job's commands (systemctl, the
 * tail of the log) and the server's process list are stand-ins: nothing of
 * the host is asked or restarted and no connection of the test server is
 * closed. The rows are real, in a schema of the child's own.
 */
final class AuditDecisionMysqlBlockTest extends TestCase {
	private const IS_ACTIVE = 'systemctl is-active mariadb 2>/dev/null';
	private const RESTART = 'systemctl restart mariadb 2>&1';
	private const TAIL = 'sudo tail -n 1000 /var/log/syslog | grep mysqld';

	private const REFUSED = "[Warning] Access denied for user 'root'@'203.0.113.7' (using password: YES)";

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-mysql-block-' . bin2hex(random_bytes(4)) . '/';
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
				\XcVm\Core\Config\SettingsManager::set(['mysql_sleep_kill' => $rCase['sleep_kill'], 'live_streaming_pass' => 'pass']);

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
		file_put_contents($this->rDir . 'case.json', json_encode($rCase + ['syslog' => [], 'rows' => [], 'sleep_kill' => 0, 'mariadb' => [], 'runs' => 1]));
		[$rCode, $rOut] = ProcessRunner::capture([PHP_BINARY, $this->rDir . 'cron.php', MAIN_HOME . 'vendor/autoload.php', dirname(__DIR__) . '/Support/TestDb.php', $this->rDir], 1 << 20);
		$rResult = json_decode($rOut, true);
		$this->assertIsArray($rResult, 'exit ' . $rCode . ': ' . $rOut);
		return $rResult;
	}

	public function testARefusedLoginInTheLogIsAWarningLikeAnyOtherAndNamesNoAddress(): void {
		$rNow = time();
		$rResult = $this->cron(['syslog' => [
			self::line($rNow - 50, self::REFUSED),
			self::line($rNow - 40, "[Warning] IP address '198.51.100.9' could not be resolved: Name or service not known"),
			self::line($rNow - 30, '[Note] InnoDB: Buffer pool(s) load completed'),
			// What the job has always left out.
			self::line($rNow - 20, "[Warning] Aborted connection 15 to db: 'xc_vm' user: 'user_x' host: '198.51.100.9' (Got timeout reading communication packets)"),
			gmdate('M j H:i:s', $rNow - 10) . ' panel kernel: Out of memory: Killed process 812 (mysqld)',
			gmdate('M j H:i:s', $rNow - 5) . ' panel CRON[900]: (root) CMD (console.php cron:root_signals)',
		]]);

		$this->assertSame([0], $rResult['codes'], $rResult['said']);
		$this->assertSame([
			['WARNING', "Access denied for user 'root'@'203.0.113.7' (using password: YES)", null, null, null, (string) ($rNow - 50)],
			['WARNING', "IP address '198.51.100.9' could not be resolved: Name or service not known", null, null, null, (string) ($rNow - 40)],
			['NOTICE', 'InnoDB: Buffer pool(s) load completed', null, null, null, (string) ($rNow - 30)],
		], $rResult['syslog']);
		$this->assertSame([self::IS_ACTIVE, self::TAIL], $rResult['ran']);
	}

	public function testRowsOfRefusedLoginsAnEarlierReleaseKeptBlockNoAddress(): void {
		$rNow = time();
		$rResult = $this->cron(['rows' => array_fill(0, 11, ['AUTH', "Access denied for user 'root'@'203.0.113.7' (using password: YES)", 'root', '203.0.113.7', $rNow - 60])]);

		$this->assertSame([0], $rResult['codes'], $rResult['said']);
		$this->assertSame([], $rResult['blocked'], 'no address is blocked for the rows on record');
		$this->assertSame([], $rResult['blocks'], 'and the flood guard is given none');
		$this->assertSame('', $rResult['said']);
	}

	public function testRefusedLoginsInTheLogBlockNoAddressOnALaterRun(): void {
		$rNow = time();
		$rLines = [];
		foreach (range(1, 12) as $i) {
			$rLines[] = self::line($rNow - 60 + $i, self::REFUSED);
		}
		// Two runs, as the crontab's two minutes: what the first one keeps, the second one reads.
		$rResult = $this->cron(['syslog' => $rLines, 'runs' => 2]);

		$this->assertSame([0, 0], $rResult['codes'], $rResult['said']);
		$this->assertSame([], $rResult['blocked'], 'no address is blocked for the lines of the log');
		$this->assertSame([], $rResult['blocks'], 'and the flood guard is given none');
		$this->assertSame('', $rResult['said']);
		$this->assertCount(12, $rResult['syslog']);
		$this->assertSame(['WARNING'], array_values(array_unique(array_column($rResult['syslog'], 0))));
		$this->assertSame([null], array_values(array_unique(array_column($rResult['syslog'], 3))), 'no row carries an address');
	}

	public function testTheCommandDoesNotSayItBlocks(): void {
		$this->assertSame('Cron: monitor MariaDB, parse syslog (root)', (new RootMysqlCronJob())->getDescription());
	}

	public function testConnectionsAsleepTooLongAreStillClosed(): void {
		$this->assertSame([
			["SELECT `id` FROM `INFORMATION_SCHEMA`.`PROCESSLIST` WHERE `COMMAND` = 'Sleep' AND `TIME` > ?;", [600]],
			['KILL ?;', [41]],
			['KILL ?;', [42]],
		], $this->cron(['sleep_kill' => 600])['asked']);

		// 0: the setting is off.
		$this->assertSame([], $this->cron(['sleep_kill' => 0])['asked']);
	}

	public function testMariaDbIsStillRestartedWhenItIsDown(): void {
		$rResult = $this->cron(['mariadb' => ['inactive', 'active']]);
		$this->assertSame([self::IS_ACTIVE, self::RESTART, self::IS_ACTIVE, self::TAIL], $rResult['ran']);
		$this->assertSame([0], $rResult['codes']);
		$this->assertStringContainsString('MariaDB successfully restarted', $rResult['said']);

		// It stays down: the run ends there.
		$rResult = $this->cron(['mariadb' => ['inactive', 'inactive']]);
		$this->assertSame([self::IS_ACTIVE, self::RESTART, self::IS_ACTIVE], $rResult['ran']);
		$this->assertSame([1], $rResult['codes']);
		$this->assertStringContainsString('FAILED to restart MariaDB', $rResult['said']);
	}
}
