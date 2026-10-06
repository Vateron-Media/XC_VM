<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\Database;
use XcVm\Core\Logging\FileLogger;

/** A Database over a connection the test opened (the panel's comes from the xcvm_core extension). */
final class AuditCoreDataQueryLogDb extends Database {
	public function __construct(\PDO $rPdo) {
		$this->dbh = $rPdo;
	}
}

/**
 * A statement that fails is written to the panel log as it was written in
 * the code, with its placeholders: the values bound to it (passwords, keys,
 * tokens) are not part of the log, which the panel shows and submits with its
 * diagnostics. Whichever step failed, query() answers false.
 */
final class AuditCoreDataQueryLogTest extends TestCase {
	private TestDb $rTestDb;

	private string $rLog;

	protected function setUp(): void {
		$this->rTestDb = new TestDb();
		$this->rTestDb->exec('CREATE TABLE `lines` (`id` INT AUTO_INCREMENT PRIMARY KEY, `username` VARCHAR(64), `password` VARCHAR(64))');
		$this->rLog = sys_get_temp_dir() . '/xcvm-querylog-' . bin2hex(random_bytes(4)) . '.log';
		FileLogger::setLogFile($this->rLog);
	}

	protected function tearDown(): void {
		FileLogger::setLogFile(null);
		@unlink($this->rLog);
	}

	/** @return list<array<string,mixed>> the entries written to the panel log */
	private function logged(): array {
		$rEntries = [];
		foreach (file_exists($this->rLog) ? file($this->rLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $rLine) {
			$rEntries[] = json_decode(base64_decode($rLine), true);
		}
		return $rEntries;
	}

	public function testAFailedStatementIsLoggedWithoutTheValuesBoundToIt(): void {
		$rDb = new AuditCoreDataQueryLogDb($this->rTestDb->pdo);
		$rQuery = 'INSERT INTO `lines` (`username`, `password`, `no_such_column`) VALUES (?, ?, ?);';

		$this->assertFalse($rDb->query($rQuery, 'a-line', 'the-line-password', 1));
		$this->assertStringContainsString('no_such_column', $rDb->error());

		$rEntries = $this->logged();
		$this->assertCount(1, $rEntries);
		$this->assertSame('pdo', $rEntries[0]['type']);
		$this->assertSame($rQuery, $rEntries[0]['extra']);
		$this->assertStringNotContainsString('the-line-password', json_encode($rEntries));
	}

	public function testAStatementTheServerRefusesToPrepareIsAFailedQueryToo(): void {
		// The server prepares the statement itself, so the refusal comes before there is a statement to execute.
		$rPdo = TestDb::connect($this->rTestDb->schema());
		$rPdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);
		$rDb = new AuditCoreDataQueryLogDb($rPdo);
		$rQuery = 'SELECT `id` FROM `no_such_table` WHERE `password` = ?;';

		$this->assertFalse($rDb->query($rQuery, 'the-line-password'));
		$this->assertStringContainsString('no_such_table', $rDb->error());

		$rEntries = $this->logged();
		$this->assertCount(1, $rEntries);
		$this->assertSame($rQuery, $rEntries[0]['extra']);
	}
}
