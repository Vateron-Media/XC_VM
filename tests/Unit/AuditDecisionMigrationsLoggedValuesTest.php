<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\MigrationRunner;
use XcVm\Core\Logging\FileLogger;
use XcVm\Tests\Support\InstallSchema;

/** A Database over a connection the test opened (the panel's comes from the xcvm_core extension). */
final class AuditDecisionMigrationsLoggedValuesDb extends Database {
	public function __construct(\PDO $rPdo) {
		$this->dbh = $rPdo;
	}
}

/**
 * A failed query is written to the panel log with its placeholders, and the
 * server's message without the values it quotes: the log is shown in the
 * panel and submitted with its diagnostics. Migration 067 brings the rows
 * written before that rule to it:
 *
 * - A statement logged as it was sent, with the values bound to it, is
 *   blanked. The driver marks that form with the statement's length in square
 *   brackets ("[84] SELECT ..."), which a statement logged with its
 *   placeholders never begins with.
 * - A message reads as Database::query() logs it now.
 *
 * The rows stay, sent or not, with everything else they hold. A row written
 * under the rule and a row of another kind are not written at all. The step
 * is applied by the panel's own runner and can run again; a version rollback
 * lets it run once more, for what the older version logs meanwhile.
 */
final class AuditDecisionMigrationsLoggedValuesTest extends TestCase {
	private const MIGRATION = '067_blank_logged_query_values.sql';

	/** Part of every value bound here. */
	private const VALUE = 'S3CRET';

	/**
	 * Statements that fail, with the values bound to them: the server's message
	 * names a column or a table, quotes the statement where it stops reading
	 * it (on one line and across two), or quotes a value of the wrong type.
	 */
	private const FAILURES = [
		'an unknown column'              => ['INSERT INTO `lines` (`username`, `password`, `no_such_column`) VALUES (?, ?, ?);', ['S3CRET-name', "S3CRET-pass'word", '1']],
		'an unknown table'               => ['SELECT `id` FROM `no_such_table` WHERE `password` = ?;', ['S3CRET-password']],
		'a syntax error'                 => ['SELECT `id` FROM `lines` WHERE `id` IN () AND `password` = ?;', ["S3CRET-pass'word"]],
		'a syntax error over two lines'  => ["SELECT `id` FROM `lines` WHERE `id` IN ()\nAND `password` = ? AND `username` = ?;", ["S3CRET-pass'word", 'S3CRET-name']],
		'a value of the wrong type'      => ['INSERT INTO `lines` (`max_connections`, `password`) VALUES (?, ?);', ["S3CRET-pass'word", 'S3CRET-password']],
	];

	private TestDb $rDb;

	private string $rLog;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		// The panel's connection charset (Database::applySessionTimeouts).
		$this->rDb->pdo->exec('SET NAMES utf8mb4');
		$this->rDb->exec(InstallSchema::table('panel_logs'));
		$this->rDb->exec(InstallSchema::migration('005_add_panel_logs_sent_flag'));
		$this->rDb->exec('CREATE TABLE `lines` (`id` INT AUTO_INCREMENT PRIMARY KEY, `username` VARCHAR(64), `password` VARCHAR(64), `max_connections` INT NOT NULL DEFAULT 1)');
		// A strict server refuses a value of the wrong type, and quotes it.
		$this->rDb->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
		$this->rLog = sys_get_temp_dir() . '/xcvm-loggedvalues-' . bin2hex(random_bytes(4)) . '.log';
		FileLogger::setLogFile($this->rLog);
	}

	protected function tearDown(): void {
		FileLogger::setLogFile(null);
		@unlink($this->rLog);
	}

	/**
	 * What the panel logged of a failed statement before the rule: the
	 * statement as the driver sent it, and the server's message whole.
	 *
	 * @param list<string> $rValues
	 * @return array{log_message: string, log_extra: string}
	 */
	private function before(string $rQuery, array $rValues): array {
		$rStatement = $this->rDb->pdo->prepare($rQuery);
		try {
			$rStatement->execute($rValues);
		} catch (\PDOException $e) {
			ob_start();
			$rStatement->debugDumpParams();
			$rSent = explode('Sent SQL:', (string) ob_get_clean());
			$this->assertArrayHasKey(1, $rSent, 'the driver gives the statement as it was sent');
			return ['log_message' => $e->getMessage(), 'log_extra' => trim(explode("\n", $rSent[1])[0])];
		}
		$this->fail('The statement did not fail: ' . $rQuery);
	}

	/**
	 * What Database::query() logs of the same failure.
	 *
	 * @param list<string> $rValues
	 * @return array{log_message: string, log_extra: string}
	 */
	private function now(string $rQuery, array $rValues): array {
		$this->assertFalse((new AuditDecisionMigrationsLoggedValuesDb($this->rDb->pdo))->query($rQuery, ...$rValues));
		$rLines = file($this->rLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
		$rEntry = json_decode((string) base64_decode((string) end($rLines)), true);
		return ['log_message' => (string) $rEntry['message'], 'log_extra' => (string) $rEntry['extra']];
	}

	/**
	 * A row of the panel log, as cron:errors writes them.
	 *
	 * @param array<string, mixed> $rSet
	 */
	private function row(array $rSet): int {
		$rRow = $rSet + ['type' => 'pdo', 'log_message' => null, 'log_extra' => null, 'line' => 290, 'date' => 1790000000, 'sent' => 0, 'server_id' => 1, 'unique' => bin2hex(random_bytes(16)), 'file' => '/home/xc_vm/Core/Database/Database.php', 'env' => 'fpm-fcgi', 'version' => '1.2.3'];
		$this->rDb->query('INSERT INTO `panel_logs` (`' . implode('`, `', array_keys($rRow)) . '`) VALUES (' . implode(', ', array_fill(0, count($rRow), '?')) . ');', ...array_values($rRow));
		return (int) $this->rDb->last_insert_id();
	}

	/** @return array<int, array<string, mixed>> the rows by id */
	private function rows(): array {
		$this->rDb->query('SELECT * FROM `panel_logs` ORDER BY `id`;');
		return array_column($this->rDb->get_raw_rows(), null, 'id');
	}

	/** Pending steps, applied as `console.php status` and an update apply them: every other step is already applied. */
	private function migrate(): string {
		$this->rDb->query('CREATE TABLE IF NOT EXISTS `migrations` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `migration` VARCHAR(255) NOT NULL UNIQUE, `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP);');
		foreach ($this->otherSteps() as $rName) {
			$this->rDb->query('INSERT IGNORE INTO `migrations` (`migration`) VALUES (?);', $rName);
		}
		ob_start();
		MigrationRunner::run($this->rDb);
		return (string) ob_get_clean();
	}

	/** @return list<string> */
	private function otherSteps(): array {
		return array_values(array_diff(array_map('basename', glob(MAIN_HOME . 'migrations/database/up/*.sql') ?: []), [self::MIGRATION]));
	}

	public function testAStatementLoggedWithItsValuesIsBlankedAndItsRowStays(): void {
		$rOld = [];
		foreach (self::FAILURES as $rWhich => [$rQuery, $rValues]) {
			$rEntry = $this->before($rQuery, $rValues);
			$this->assertMatchesRegularExpression('/^\[\d+\] /', $rEntry['log_extra'], $rWhich . ': the statement as it was sent');
			$rOld[$rWhich . ', not submitted'] = $this->row($rEntry);
			$rOld[$rWhich . ', submitted'] = $this->row($rEntry + ['sent' => 1, 'server_id' => 2, 'env' => 'cli']);
		}
		$rBefore = $this->rows();
		$this->assertStringContainsString(self::VALUE, (string) json_encode(array_column($rBefore, 'log_extra')));

		$rOut = $this->migrate();

		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $rOut);
		$rAfter = $this->rows();
		$this->assertSame(array_keys($rBefore), array_keys($rAfter), 'every row stays');
		foreach ($rOld as $rWhich => $rID) {
			$this->assertSame('', $rAfter[$rID]['log_extra'], $rWhich);
			$this->assertSame(array_diff_key($rBefore[$rID], ['log_extra' => 0, 'log_message' => 0]), array_diff_key($rAfter[$rID], ['log_extra' => 0, 'log_message' => 0]), $rWhich . ': nothing else of the row is written');
		}
		$this->assertStringNotContainsString(self::VALUE, (string) json_encode($rAfter), 'no bound value is left in the log');
	}

	public function testAMessageReadsAsItIsLoggedNow(): void {
		$rOld = [];
		$rNow = [];
		foreach (self::FAILURES as $rWhich => [$rQuery, $rValues]) {
			$rOld[$rWhich] = $this->row($this->before($rQuery, $rValues));
			$rNow[$rWhich] = $this->now($rQuery, $rValues)['log_message'];
		}
		$rBefore = $this->rows();

		$this->migrate();

		$rAfter = $this->rows();
		foreach ($rOld as $rWhich => $rID) {
			$this->assertSame($rNow[$rWhich], $rAfter[$rID]['log_message'], $rWhich);
		}
		// What names the fault stays; what the server quoted of the statement or of a value goes.
		$this->assertSame($rBefore[$rOld['an unknown column']]['log_message'], $rAfter[$rOld['an unknown column']]['log_message']);
		$this->assertStringContainsString('no_such_table', $rAfter[$rOld['an unknown table']]['log_message']);
		$this->assertStringContainsString("near '?' at line 1", $rAfter[$rOld['a syntax error']]['log_message']);
		$this->assertStringContainsString("\n", $rBefore[$rOld['a syntax error over two lines']]['log_message'], 'the server quoted both lines');
		$this->assertStringEndsWith("near '?' at line 1", $rAfter[$rOld['a syntax error over two lines']]['log_message']);
		$this->assertStringContainsString("Incorrect integer value: '?' for column", $rAfter[$rOld['a value of the wrong type']]['log_message']);
	}

	public function testARowWrittenUnderTheRuleAndARowOfAnotherKindAreNotWritten(): void {
		$rKept = [];
		foreach (self::FAILURES as $rWhich => [$rQuery, $rValues]) {
			$rEntry = $this->now($rQuery, $rValues);
			$this->assertSame($rQuery, $rEntry['log_extra'], $rWhich . ': the statement with its placeholders');
			$rKept['under the rule, ' . $rWhich] = $this->row($rEntry);
		}
		// A statement the caller wrote whole is logged as it was written, before the rule and under it.
		$rKept['a statement without placeholders'] = $this->row(['log_message' => "SQLSTATE[42S22]: Column not found: 1054 Unknown column 'lang' in 'INSERT INTO'", 'log_extra' => "INSERT INTO `epg_data` (`epg_id`, `lang`) VALUES (4,'en')"]);
		$rKept['a statement that names a length in brackets further on'] = $this->row(['log_message' => 'SQLSTATE[HY000]: General error', 'log_extra' => 'SELECT `id` FROM `lines` WHERE `username` = ? /* [12] */']);
		$rKept['no statement'] = $this->row(['log_message' => 'SQLSTATE[HY000]: General error: 2006 MySQL server has gone away', 'log_extra' => null]);
		$rKept['no message'] = $this->row(['log_message' => null, 'log_extra' => 'SELECT 1']);
		// Other kinds of entry: an EPG import, a PHP error, a line for the system log.
		$rKept['an EPG entry'] = $this->row(['type' => 'epg', 'log_message' => "Unexpected token near '<tv>'", 'log_extra' => '[3] https://epg.example.net/guide.xml']);
		$rKept['a PHP error'] = $this->row(['type' => 'error', 'log_message' => "Unsupported operand value: 'x' + 'y'", 'log_extra' => "[12] near 'here'"]);
		$rKept['a system log line'] = $this->row(['type' => 'syslog', 'log_message' => "RESTART: near 'nginx'", 'log_extra' => '']);
		$rBefore = $this->rows();

		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $this->migrate());

		$rAfter = $this->rows();
		foreach ($rKept as $rWhich => $rID) {
			$this->assertSame($rBefore[$rID], $rAfter[$rID], $rWhich);
		}
	}

	public function testApplyingTheStepAgainChangesNothing(): void {
		foreach (self::FAILURES as [$rQuery, $rValues]) {
			$this->row($this->before($rQuery, $rValues));
			$this->row($this->now($rQuery, $rValues));
		}

		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $this->migrate());
		$this->assertStringContainsString('No pending migrations.', $this->migrate(), 'the step is recorded');

		// A step that failed half way is not recorded and runs again from the top.
		$rRows = $this->rows();
		$this->rDb->query('DELETE FROM `migrations` WHERE `migration` = ?;', self::MIGRATION);

		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $this->migrate());

		$this->assertSame($rRows, $this->rows());
	}

	/** The older version logs a statement with its values again: the next update blanks those too. */
	public function testAVersionRollbackLeavesTheLogAsItIsAndLetsTheStepRunAgain(): void {
		[$rQuery, $rValues] = self::FAILURES['a syntax error'];
		$rOld = $this->row($this->before($rQuery, $rValues));
		$this->migrate();
		$rRows = $this->rows();

		$rResult = MigrationRunner::rollback($this->rDb, $this->otherSteps());

		$this->assertSame([self::MIGRATION], $rResult['reversed']);
		$this->assertSame($rRows, $this->rows(), 'a blanked statement does not come back');

		$rLater = $this->row($this->before($rQuery, $rValues));
		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $this->migrate());
		$rAgain = $this->rows();
		$this->assertSame($rRows[$rOld], $rAgain[$rOld]);
		$this->assertSame('', $rAgain[$rLater]['log_extra']);
		$this->assertStringNotContainsString(self::VALUE, (string) json_encode($rAgain));
	}
}
