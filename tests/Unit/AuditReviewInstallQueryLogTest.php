<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\Database;
use XcVm\Core\Logging\FileLogger;

/** A Database over a connection the test opened (the panel's comes from the xcvm_core extension). */
final class AuditReviewInstallQueryLogDb extends Database {
	public function __construct(\PDO $rPdo) {
		$this->dbh = $rPdo;
	}
}

/**
 * The values bound to a statement are not part of the panel log, which the
 * panel shows and submits with its diagnostics. The server's own message is
 * logged with it, and quotes a value in places ("Incorrect integer value:
 * 'x'", a syntax error's "near '...'"): those are logged without it. What
 * names the fault (a column, a table) stays, and error() gives the caller
 * the message whole.
 */
final class AuditReviewInstallQueryLogTest extends TestCase {
	private const VALUE = "the-line's-password";

	private TestDb $rTestDb;

	private string $rLog;

	protected function setUp(): void {
		$this->rTestDb = new TestDb();
		$this->rTestDb->exec('CREATE TABLE `lines` (`id` INT AUTO_INCREMENT PRIMARY KEY, `password` VARCHAR(64), `max_connections` INT NOT NULL DEFAULT 1)');
		$this->rLog = sys_get_temp_dir() . '/xcvm-querylog-' . bin2hex(random_bytes(4)) . '.log';
		FileLogger::setLogFile($this->rLog);
	}

	protected function tearDown(): void {
		FileLogger::setLogFile(null);
		@unlink($this->rLog);
	}

	/** @return list<string> the messages written to the panel log */
	private function logged(): array {
		$rMessages = [];
		foreach (file_exists($this->rLog) ? file($this->rLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : [] as $rLine) {
			$rMessages[] = (string) json_decode(base64_decode($rLine), true)['message'];
		}
		return $rMessages;
	}

	public function testTheServersMessageIsLoggedWithoutTheValuesItQuotes(): void {
		$rDb = new AuditReviewInstallQueryLogDb($this->rTestDb->pdo);
		// A syntax error quotes the statement as it was sent, values included.
		$this->assertFalse($rDb->query('SELECT `id` FROM `lines` WHERE `id` IN () AND `password` = ?;', self::VALUE));
		$this->assertStringContainsString("the-line\\'s-password", $rDb->error(), 'the caller still gets the message whole');
		// A strict server refuses a value of the wrong type, and quotes it.
		$this->rTestDb->pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES'");
		$this->assertFalse($rDb->query('INSERT INTO `lines` (`max_connections`, `password`) VALUES (?, ?);', self::VALUE, 'x'));
		$this->assertStringContainsString(self::VALUE, $rDb->error());

		$rMessages = $this->logged();
		$this->assertCount(2, $rMessages);
		$this->assertStringNotContainsString('password', implode("\n", $rMessages));
		$this->assertStringContainsString("near '?' at line 1", $rMessages[0]);
		$this->assertStringContainsString("Incorrect integer value: '?'", $rMessages[1]);
	}

	public function testWhatNamesTheFaultStaysInTheLog(): void {
		$rDb = new AuditReviewInstallQueryLogDb($this->rTestDb->pdo);
		$this->assertFalse($rDb->query('SELECT `no_such_column` FROM `lines` WHERE `password` = ?;', self::VALUE));
		$this->assertFalse($rDb->query('SELECT `id` FROM `no_such_table` WHERE `password` = ?;', self::VALUE));

		$rMessages = $this->logged();
		$this->assertCount(2, $rMessages);
		$this->assertStringContainsString("Unknown column 'no_such_column'", $rMessages[0]);
		$this->assertStringContainsString("no_such_table' doesn't exist", $rMessages[1]);
	}
}
