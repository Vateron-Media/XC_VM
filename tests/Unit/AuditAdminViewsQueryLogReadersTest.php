<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\User\UserCredits;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * QueryLogDb records what a code path asks the database and answers with the
 * wrapped database's answers, whichever reader the code path uses: read
 * through it, a value is what it is without it.
 */
final class AuditAdminViewsQueryLogReadersTest extends TestCase {
	private TestDb $rDb;

	private QueryLogDb $rLog;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rLog = new QueryLogDb($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	public function testTheFirstColumnIsTheWrappedDatabases(): void {
		$this->rLog->query('SELECT 7 UNION SELECT 8');
		$this->assertEquals(7, $this->rLog->get_col());

		$this->rLog->query('SELECT 7 UNION SELECT 8');
		$this->assertEquals([7, 8], $this->rLog->get_column());

		$this->assertSame(['SELECT 7 UNION SELECT 8', 'SELECT 7 UNION SELECT 8'], $this->rLog->rQueries);
	}

	/** UserCredits::balance() reads one column: the balance a log line is written with. */
	public function testABalanceIsReadThroughIt(): void {
		$this->rDb->exec(InstallSchema::table('users'));
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `credits`) VALUES (5, 'reseller', 12.5)");
		DatabaseFactory::set($this->rLog);

		$this->assertSame(12.5, UserCredits::balance(5));
	}
}
