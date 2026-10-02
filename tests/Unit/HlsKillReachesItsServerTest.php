<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Infrastructure\Redis\RedisManager;

if (!defined('SERVER_ID')) {
	define('SERVER_ID', 1);
}
if (!defined('CONS_TMP_PATH')) {
	define('CONS_TMP_PATH', sys_get_temp_dir() . '/xcvm-no-cons/');
}

/**
 * A segment of an HLS viewer is served while its marker (CONS_TMP_PATH/<uuid>)
 * exists on the server that serves it, and segment.php checks nothing else. An
 * admin's kill on MAIN of a viewer on a load balancer closed the record only:
 * the load balancer kept serving every segment of the playlist the player
 * held. The close now sends that server a delete_con (the cache job that
 * removes the marker, as the reaper sends for the viewers it ends).
 */
final class HlsKillReachesItsServerTest extends TestCase {
	private TestDb $rDb;

	/** @var array{0: mixed, 1: mixed} */
	private array $rGlobals = [null, null];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY, `uuid` TEXT, `container` TEXT, `server_id` INTEGER, `stream_id` INTEGER, `hls_end` INTEGER, `pid` INTEGER);'
			. ' CREATE TABLE `signals` (`signal_id` INTEGER PRIMARY KEY, `pid` INTEGER, `server_id` INTEGER, `rtmp` INTEGER, `time` INTEGER, `custom_data` TEXT, `cache` INTEGER);');
		DatabaseFactory::set($this->rDb);
		SignalDispatcher::useSink(null);
		$this->rGlobals = [$GLOBALS['rSettings'] ?? null, $GLOBALS['rServers'] ?? null];
		$GLOBALS['rSettings'] = ['redis_handler' => 0, 'save_closed_connection' => 0];
		$GLOBALS['rServers'] = [SERVER_ID => ['rtmp_mport_url' => 'http://127.0.0.1:9/']];
		$this->redis(time()); // MySQL mode: no Redis to connect to
	}

	/** RedisManager without a client, its last failure at $rAt (0: none). */
	private function redis(int $rAt): void {
		(new \ReflectionProperty(RedisManager::class, 'instance'))->setValue(null, null);
		(new \ReflectionProperty(RedisManager::class, 'rFailedAt'))->setValue(null, $rAt);
	}

	protected function tearDown(): void {
		$this->redis(0);
		[$GLOBALS['rSettings'], $GLOBALS['rServers']] = $this->rGlobals;
		DatabaseFactory::reset();
	}

	/** @return list<array<string, mixed>> */
	private function deleteCons(): array {
		$this->rDb->query("SELECT `server_id`, `cache`, `custom_data` FROM `signals` WHERE `custom_data` LIKE '%delete_con%'");
		return $this->rDb->get_rows();
	}

	private function viewer(int $rServerID, int $rEnded = 0): array {
		return ['activity_id' => 7, 'uuid' => str_repeat('a', 32), 'container' => 'hls', 'server_id' => $rServerID, 'stream_id' => 5, 'hls_end' => $rEnded, 'pid' => 0, 'user_id' => 3];
	}

	public function testKillingAnHlsViewerOnAnotherServerRemovesItsMarkerThere(): void {
		ConnectionTracker::closeConnection($this->viewer(2));
		$rSent = $this->deleteCons();
		$this->assertCount(1, $rSent, 'the load balancer that serves the viewer is told');
		$this->assertSame(2, (int) $rSent[0]['server_id']);
		$this->assertSame(1, (int) $rSent[0]['cache']);
		$this->assertSame(['type' => 'delete_con', 'uuid' => str_repeat('a', 32)], json_decode($rSent[0]['custom_data'], true));
	}

	public function testEndingOneWithoutRemovingItTellsItsServerToo(): void {
		ConnectionTracker::closeConnection($this->viewer(2), false, true);
		$this->assertCount(1, $this->deleteCons());
	}

	public function testNothingIsSentForThisServersOwnOrAnEndedViewer(): void {
		ConnectionTracker::closeConnection($this->viewer(SERVER_ID));
		ConnectionTracker::closeConnection($this->viewer(2, 1));
		$this->assertSame([], $this->deleteCons(), 'this server removes its own marker; an ended viewer has none left');
	}
}
