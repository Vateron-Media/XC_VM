<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * An adaptive master's variants are one HLS viewer (issue #61): the viewer's
 * connection is keyed by the master's stream, follows the variant it plays, and
 * lets a variant the player switches to later start on demand, while a token
 * with no open connection behind it still does not.
 */
final class AdaptiveViewerTest extends TestCase {
	private const MASTER = 100;
	private const VARIANT = 101;
	private const USER = 9;
	private const IP = '10.0.0.1';
	private const UA = 'player/1.0';

	private TestDb $rDb;

	private mixed $rBefore;

	private mixed $rGlobalDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec(InstallSchema::table('lines_live'));
		$rOwn = new ReflectionProperty(ConnectionTracker::class, 'db');
		$this->rBefore = $rOwn->getValue();
		$rOwn->setValue(null, $this->rDb);
		// openStore() connects lazily through the global handle.
		$this->rGlobalDb = $GLOBALS['db'] ?? null;
		$GLOBALS['db'] = $this->rDb;
	}

	protected function tearDown(): void {
		(new ReflectionProperty(ConnectionTracker::class, 'db'))->setValue(null, $this->rBefore);
		$GLOBALS['db'] = $this->rGlobalDb;
		DatabaseFactory::reset();
	}

	private function connect(int $rMaster, int $rEnded): void {
		$rUuid = ConnectionTracker::hlsConnectionKey(null, null, self::USER, $rMaster, self::IP, self::UA);
		$this->rDb->query('INSERT INTO `lines_live` (`user_id`, `stream_id`, `server_id`, `user_agent`, `user_ip`, `container`, `date_start`, `hls_last_read`, `hls_end`, `uuid`) VALUES (?, ?, 1, ?, ?, ?, ?, ?, ?, ?)', self::USER, self::VARIANT, self::UA, self::IP, 'hls', time(), time(), $rEnded, $rUuid);
	}

	/** @return array<string, mixed> */
	private function variantToken(?array $rAdaptive): array {
		$rToken = ['stream_id' => 102, 'extension' => 'm3u8', 'user_info' => ['id' => self::USER]];
		if ($rAdaptive !== null) {
			$rToken['adaptive'] = $rAdaptive;
		}
		return $rToken;
	}

	public function testTheMastersViewerIsOpenOnAnotherVariant(): void {
		$this->connect(self::MASTER, 0);
		$this->assertTrue(ConnectionTracker::adaptiveViewerOpen(['redis_handler' => 0], $this->variantToken([1, self::MASTER]), self::IP, self::UA));
	}

	public function testAnEndedConnectionDoesNotCount(): void {
		$this->connect(self::MASTER, 1);
		$this->assertFalse(ConnectionTracker::adaptiveViewerOpen(['redis_handler' => 0], $this->variantToken([1, self::MASTER]), self::IP, self::UA));
	}

	public function testAnotherMastersConnectionDoesNotCount(): void {
		$this->connect(self::MASTER + 50, 0);
		$this->assertFalse(ConnectionTracker::adaptiveViewerOpen(['redis_handler' => 0], $this->variantToken([1, self::MASTER]), self::IP, self::UA));
	}

	public function testAnotherPlayerDoesNotCount(): void {
		$this->connect(self::MASTER, 0);
		$this->assertFalse(ConnectionTracker::adaptiveViewerOpen(['redis_handler' => 0], $this->variantToken([1, self::MASTER]), self::IP, 'other/2.0'));
	}

	public function testATokenOutsideAnAdaptiveMasterIsNeverExempt(): void {
		$this->connect(self::MASTER, 0);
		$this->assertFalse(ConnectionTracker::adaptiveViewerOpen(['redis_handler' => 0], $this->variantToken(null), self::IP, self::UA));
	}

	public function testTheConnectionMovesToTheVariantItPlays(): void {
		$this->connect(self::MASTER, 0);
		$rUuid = ConnectionTracker::hlsConnectionKey(null, null, self::USER, self::MASTER, self::IP, self::UA);
		$rConnection = ConnectionTracker::lookupLive(['redis_handler' => 0], ['uuid' => $rUuid, 'user_id' => self::USER, 'adaptive' => true], 'hls', false, true, true);
		$this->assertNotNull($rConnection);

		$this->assertTrue(ConnectionTracker::updateLive(['redis_handler' => 0], $rConnection, ['stream_id' => 102, 'server_id' => 1, 'proxy_id' => null, 'hls_last_read' => time()]));
		$this->rDb->query('SELECT `stream_id` FROM `lines_live` WHERE `uuid` = ?', $rUuid);
		$this->assertSame(102, (int) $this->rDb->get_row()['stream_id']);
	}
}
