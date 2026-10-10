<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\DatabaseHandler;

/** A PDO stand-in that records what the handler asked of it. */
class TransactionRecorder {
	public array $calls = [];

	private bool $rOpen = false;

	public function beginTransaction() {
		$this->calls[] = 'begin';
		$this->rOpen = true;
		return true;
	}

	public function inTransaction(): bool {
		return $this->rOpen;
	}

	public function commit() {
		$this->calls[] = 'commit';
		$this->rOpen = false;
		return true;
	}

	public function rollBack() {
		$this->calls[] = 'rollback';
		$this->rOpen = false;
		return true;
	}
}

/** The production handler over a real connection (TestDb overrides the transaction methods). */
class RealPdoDb extends DatabaseHandler {
	public function __construct(\PDO $rPDO) {
		$this->dbh = $rPDO;
	}
}

class TransactionalDb extends DatabaseHandler {
	public function __construct(TransactionRecorder $rPDO) {
		$this->dbh = $rPDO;
	}
}

/**
 * transactional() must roll back whatever the callback throws. It caught only
 * \Exception, so a PHP Error (a TypeError, an undefined method) skipped the
 * rollback: the transaction stayed open on the connection and the handler
 * still believed itself inside one, which also switched off reconnects.
 */
class DatabaseTransactionalTest extends TestCase {
	public function testAnErrorInTheCallbackRollsBack(): void {
		$rPDO = new TransactionRecorder();
		$rDB = new TransactionalDb($rPDO);

		try {
			$rDB->transactional(function () {
				throw new \TypeError('bad argument');
			});
			$this->assertTrue(false, 'the Error was swallowed');
		} catch (\TypeError $e) {
			$this->assertSame('bad argument', $e->getMessage());
		}

		$this->assertSame(['begin', 'rollback'], $rPDO->calls);
		$this->assertFalse($rDB->isInTransaction());
	}

	public function testAnExceptionStillRollsBackAndSuccessCommits(): void {
		$rPDO = new TransactionRecorder();
		$rDB = new TransactionalDb($rPDO);
		try {
			$rDB->transactional(function () {
				throw new \RuntimeException('no');
			});
		} catch (\RuntimeException $e) {
		}
		$this->assertSame(7, $rDB->transactional(fn() => 7));
		$this->assertSame(['begin', 'rollback', 'begin', 'commit'], $rPDO->calls);
	}

	/**
	 * A DDL statement commits the transaction on its own (a module migration
	 * runs in transactional()). PDO then refuses the commit, and the rollback
	 * that followed: the callback's work was done, yet transactional() threw
	 * "There is no active transaction" and the handler stayed in a transaction.
	 */
	public function testAStatementThatCommitsOnItsOwnLeavesTheHandlerUsable(): void {
		$rTest = new \TestDb();
		$rDB = new RealPdoDb($rTest->pdo);

		$this->assertSame('done', $rDB->transactional(function () use ($rTest) {
			$rTest->pdo->exec('CREATE TABLE `ddl_in_tx` (`id` int)');
			return 'done';
		}));
		$this->assertFalse($rDB->isInTransaction());

		// The error the callback threw is the one the caller sees.
		try {
			$rDB->transactional(function () use ($rTest) {
				$rTest->pdo->exec('ALTER TABLE `ddl_in_tx` ADD COLUMN `more` int');
				throw new \RuntimeException('the real cause');
			});
			$this->fail('the callback\'s error was swallowed');
		} catch (\RuntimeException $e) {
			$this->assertSame('the real cause', $e->getMessage());
		}
		$this->assertFalse($rDB->isInTransaction());

		// And the next transaction is one: its rollback undoes its row.
		try {
			$rDB->transactional(function () use ($rTest) {
				$rTest->pdo->exec('INSERT INTO `ddl_in_tx` (`id`) VALUES (1)');
				throw new \RuntimeException('undo');
			});
		} catch (\RuntimeException) {
		}
		$this->assertSame(0, (int) $rTest->pdo->query('SELECT COUNT(*) FROM `ddl_in_tx`')->fetchColumn());
	}
}
