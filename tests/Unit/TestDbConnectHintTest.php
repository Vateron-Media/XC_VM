<?php

use PHPUnit\Framework\TestCase;

/**
 * Without a test database, the first test that needs one says how to get one,
 * instead of a bare driver message.
 */
final class TestDbConnectHintTest extends TestCase {
	private string|false $rSaved;

	protected function setUp(): void {
		$this->rSaved = getenv('XCVM_TEST_DB_DSN');
		putenv('XCVM_TEST_DB_DSN=mysql:host=127.0.0.1;port=1');
	}

	protected function tearDown(): void {
		putenv($this->rSaved === false ? 'XCVM_TEST_DB_DSN' : 'XCVM_TEST_DB_DSN=' . $this->rSaved);
	}

	public function testAFailedConnectionNamesTheRemedies(): void {
		try {
			TestDb::connect();
			$this->fail('connected to port 1');
		} catch (PDOException $e) {
			$this->assertStringContainsString('make test-db', $e->getMessage());
			$this->assertStringContainsString('XCVM_TEST_DB_DSN', $e->getMessage());
			$this->assertSame(2002, $e->getCode(), 'the driver code stays');
			$this->assertInstanceOf(PDOException::class, $e->getPrevious());
		}
	}
}
