<?php

namespace XcVm\Tests\Unit;

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\DatabaseHandler;

/** A PDO stand-in that records what the handler asked of it. */
class TransactionRecorder {
	public array $calls = [];

	public function beginTransaction() {
		$this->calls[] = 'begin';
		return true;
	}

	public function commit() {
		$this->calls[] = 'commit';
		return true;
	}

	public function rollBack() {
		$this->calls[] = 'rollback';
		return true;
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
}
