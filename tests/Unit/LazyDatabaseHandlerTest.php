<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\DatabaseUnavailableException;
use XcVm\Core\Database\LazyDatabaseHandler;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Streaming endpoints get a LazyDatabaseHandler: no MySQL connection to MAIN
 * until the first query, so a request answered from cache or Redis opens none.
 * The real connect goes through the xcvm_core extension; here it is replaced
 * by a connection to the test server that counts how often it is opened.
 */
final class LazyDatabaseHandlerTest extends TestCase {

	private function handler(): LazyDatabaseHandler {
		return new class extends LazyDatabaseHandler {
			public int $rOpens = 0;

			public function db_connect(bool $migrate = false, ?bool $graceful = null) {
				$this->rOpens++;
				$this->dbh = \TestDb::connect();
				$this->connected = true;
				return true;
			}
		};
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		unset($GLOBALS['db']);
	}

	public function testNothingOpensUntilTheFirstQuery(): void {
		$rDb = $this->handler();
		$this->assertFalse($rDb->isOpen());
		$this->assertTrue($rDb->ping(), 'an unopened handle is usable, not dead');
		$this->assertSame(0, $rDb->rOpens);

		$this->assertTrue($rDb->query('SELECT 1 AS `one`'));
		$this->assertSame(1, $rDb->rOpens);
		$this->assertInstanceOf(\PDO::class, $rDb->dbh);
		$rDb->query('SELECT ? AS `two`', 2);
		$this->assertSame(1, $rDb->rOpens, 'opened once');
		$this->assertTrue($rDb->isOpen());
	}

	public function testEscapeAndTransactionsOpenToo(): void {
		$rDb = $this->handler();
		$this->assertSame("'x'", $rDb->escape('x'));
		$this->assertSame(1, $rDb->rOpens);

		$rTx = $this->handler();
		$this->assertTrue($rTx->beginTransaction());
		$this->assertSame(1, $rTx->rOpens);
		$rTx->rollback();
	}

	/** Graceful (MAIN's cluster entry point): MySQL down throws, once tried, and every later use throws without trying again. */
	public function testAGracefulHandleThrowsWhenTheDatabaseIsDown(): void {
		$rDb = new class (true) extends LazyDatabaseHandler {
			public int $rOpens = 0;

			public function db_connect(bool $migrate = false, ?bool $graceful = null) {
				$this->rOpens++;
				return $graceful === true ? false : throw new \LogicException('an exit() on a graceful handle');
			}
		};
		foreach (['query', 'escape', 'beginTransaction'] as $rUse) {
			try {
				$rUse === 'query' ? $rDb->query('SELECT 1') : $rDb->{$rUse}('x');
				$this->fail($rUse . ' went on without a database');
			} catch (DatabaseUnavailableException) {
			}
		}
		$this->assertSame(1, $rDb->rOpens, 'tried once: no reconnect loop per query');
	}

	public function testConnectLazyKeepsAnUnopenedHandle(): void {
		$GLOBALS['db'] = $this->handler();
		DatabaseFactory::connectLazy();
		$this->assertSame($GLOBALS['db'], DatabaseFactory::get());
		$this->assertSame(0, $GLOBALS['db']->rOpens, 'reused, not replaced, not opened');
	}

	public function testStreamingEndpointsUseTheLazyHandler(): void {
		$rRoot = MAIN_HOME;
		foreach (['Public/stream/live.php', 'Public/stream/vod.php', 'Public/stream/timeshift.php', 'Streaming/Lifecycle/ShutdownHandler.php'] as $rFile) {
			$rSource = (string) file_get_contents($rRoot . $rFile);
			$this->assertStringContainsString('DatabaseFactory::connectLazy();', $rSource, $rFile);
			$this->assertStringNotContainsString('DatabaseFactory::connect();', $rSource, $rFile);
		}
	}
}
