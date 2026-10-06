<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Tests\Support\InstallSchema;

/**
 * The connection a live TS request finds in the table says whether it has
 * ended: live.php takes over the worker a row names only while the row is
 * open, because an ended row's worker serves another request by then.
 */
final class AuditStreamEntryLiveLookupTest extends TestCase {
	private const UUID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

	private TestDb $rDb;

	private mixed $rBefore;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('lines_live'));
		$rOwn = new ReflectionProperty(ConnectionTracker::class, 'db');
		$this->rBefore = $rOwn->getValue();
		$rOwn->setValue(null, $this->rDb);
	}

	protected function tearDown(): void {
		(new ReflectionProperty(ConnectionTracker::class, 'db'))->setValue(null, $this->rBefore);
	}

	/** @return array<string, mixed>|null the row a live TS request for the connection finds */
	private function found(int $rEnded): ?array {
		$this->rDb->query('INSERT INTO `lines_live` (`user_id`, `stream_id`, `server_id`, `user_agent`, `user_ip`, `container`, `pid`, `date_start`, `hls_last_read`, `hls_end`, `uuid`) VALUES (9, 5, 1, ?, ?, ?, 4321, ?, ?, ?, ?)', 'player/1.0', '10.0.0.1', 'ts', time(), time(), $rEnded, self::UUID);
		$rCtx = ['uuid' => self::UUID, 'is_hmac' => null, 'identifier' => null, 'user_id' => 9, 'server_id' => 1, 'stream_id' => 5, 'adaptive' => null];
		return ConnectionTracker::lookupLive(['redis_handler' => 0], $rCtx, 'ts', true, false, false);
	}

	public function testAnEndedConnectionIsFoundAsEnded(): void {
		$rRow = $this->found(1);
		$this->assertNotNull($rRow);
		$this->assertSame(4321, (int) $rRow['pid']);
		$this->assertTrue(ConnectionTracker::ended($rRow), 'the worker it names is left alone');
	}

	public function testAnOpenConnectionIsFoundAsOpen(): void {
		$rRow = $this->found(0);
		$this->assertNotNull($rRow);
		$this->assertFalse(ConnectionTracker::ended($rRow));
	}
}
