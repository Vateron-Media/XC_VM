<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ConnectionDigest;
use XcVm\Domain\Cluster\ConnectionSnapshot;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A CONNECTIONS node's registry against MAIN's store (cluster plan, Phase 6):
 * the digest both sides compute, MAIN asking for a snapshot only when a drift
 * outlives the events in flight, and a chunked snapshot applied as a whole.
 * MySQL mode (`lines_live`); ConnectionIngest's Redis path is covered by
 * ConnectionStoreTest.
 */
final class ConnectionSnapshotTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `user_id` int, `stream_id` int, `server_id` int, `proxy_id` int, `user_agent` text, `user_ip` text, `container` text, `pid` int, `date_start` int, `geoip_country_code` text, `isp` text, `external_device` text, `hls_last_read` int, `hls_end` int DEFAULT 0, `hmac_id` int, `hmac_identifier` text, `uuid` text)');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0]);
		$this->rDir = sys_get_temp_dir() . '/xcvm-snap-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		ConnectionDigest::useState($this->rDir . '/digest/');
		ConnectionSnapshot::useDir($this->rDir . '/snap/');
		ClusterClock::fix(1800000000000);
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		ConnectionDigest::useState(null);
		ConnectionSnapshot::useDir(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return array<string, mixed> */
	private function rec(string $rUUID, int $rUser, int $rEnd = 0): array {
		return ['uuid' => $rUUID, 'user_id' => $rUser, 'stream_id' => 100, 'user_ip' => '10.0.0.1', 'container' => 'hls', 'date_start' => 1, 'hls_last_read' => 1, 'hls_end' => $rEnd];
	}

	private function row(string $rUUID, int $rServer, int $rUser, int $rEnd = 0): void {
		$this->rDb->query('INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`, `hls_end`) VALUES (?, ?, ?, ?)', $rUUID, $rServer, $rUser, $rEnd);
	}

	public function testTheDigestMatchesTheAgents(): void {
		// The vector clusteragent's TestDigestMatchesThePanels pins too.
		$this->assertSame(['count' => 3, 'users' => 2, 'xor64' => '07b06e765aad73d6'], ConnectionDigest::of([
			['uuid' => 'a', 'user_id' => 7, 'hls_end' => 0],
			['uuid' => 'b', 'user_id' => '7'],
			['uuid' => 'c', 'user_id' => null, 'hmac_id' => 3, 'hmac_identifier' => 'dev1'],
			['uuid' => 'd', 'user_id' => 9, 'hls_end' => 1],
		]));
		$this->assertSame(['count' => 0, 'users' => 0, 'xor64' => '0000000000000000'], ConnectionDigest::of([]));
	}

	public function testMainAsksOnlyForADriftThatLasts(): void {
		$this->row('a', 5, 7);
		$this->row('b', 5, 8);
		$this->row('x', 6, 7); // another node's
		$this->row('e', 5, 9, 1); // ended
		$rSame = ConnectionDigest::of([$this->rec('a', 7), $this->rec('b', 8)]);
		$this->assertFalse(ConnectionDigest::check(5, $rSame));
		$rDrift = ConnectionDigest::of([$this->rec('a', 7)]);
		ClusterClock::fix(1800000001000);
		$this->assertFalse(ConnectionDigest::check(5, $rDrift), 'checked at most every 4 s');
		ClusterClock::fix(1800000004000);
		$this->assertFalse(ConnectionDigest::check(5, $rDrift), 'one miss: events may be in flight');
		ClusterClock::fix(1800000008000);
		$this->assertTrue(ConnectionDigest::check(5, $rDrift), 'two in a row: ask');
		ClusterClock::fix(1800000012000);
		ConnectionDigest::check(5, $rDrift);
		ClusterClock::fix(1800000016000);
		$this->assertFalse(ConnectionDigest::check(5, $rDrift), 'asked at most every 30 s');
		ClusterClock::fix(1800000040000);
		$this->assertTrue(ConnectionDigest::check(5, $rDrift));
		ClusterClock::fix(1800000044000);
		$this->assertFalse(ConnectionDigest::check(5, ['count' => '1']), 'a malformed digest is ignored');
	}

	public function testASnapshotIsAppliedWholeWithItsLastChunk(): void {
		$this->row('a', 5, 7);
		$this->row('stale', 5, 7);
		$this->row('x', 6, 7);
		$rSnap = 'aa11bb22cc33dd44';
		$rOut = ConnectionSnapshot::receive(5, ['snap_id' => $rSnap, 'seq' => 0, 'last' => false, 'records' => [$this->rec('a', 7), $this->rec('b', 8)]]);
		$this->assertSame(['ok' => true, 'done' => false], $rOut);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live`');
		$this->assertSame(3, (int) $this->rDb->get_row()['n'], 'nothing applied before the last chunk');

		$this->assertSame(['ok' => false, 'expected_seq' => 1], ConnectionSnapshot::receive(5, ['snap_id' => $rSnap, 'seq' => 2, 'last' => true, 'records' => []]));
		$rOut = ConnectionSnapshot::receive(5, ['snap_id' => $rSnap, 'seq' => 1, 'last' => true, 'records' => [$this->rec('h', 9, 1), $this->rec('x', 7), ['uuid' => 'bad uuid']]]);
		$this->assertSame(['ok' => true, 'done' => true, 'applied' => 3, 'removed' => 1, 'dropped' => 2], $rOut);

		$this->rDb->query('SELECT `uuid`, `server_id`, `hls_end` FROM `lines_live` ORDER BY `uuid`');
		$this->assertSame([['a', 5, 0], ['b', 5, 0], ['h', 5, 1], ['x', 6, 0]], array_map(static fn($rR) => [$rR['uuid'], (int) $rR['server_id'], (int) $rR['hls_end']], $this->rDb->get_rows()), 'stale removed; node 6\'s viewer untouched');
		$this->assertSame(ConnectionDigest::of([$this->rec('a', 7), $this->rec('b', 8)]), ConnectionDigest::of(ConnectionDigest::stored(5)), 'the digests agree again');
	}

	public function testAnEmptySnapshotClearsTheNodeAndABadChunkIsRefused(): void {
		$this->row('a', 5, 7);
		$this->assertSame(['ok' => true, 'done' => true, 'applied' => 0, 'removed' => 1, 'dropped' => 0], ConnectionSnapshot::receive(5, ['snap_id' => 'ff00ff00ff00ff00', 'seq' => 0, 'last' => true, 'records' => []]));
		$this->assertSame([], ConnectionDigest::stored(5));
		$this->assertTrue(ConnectionSnapshot::receive(5, ['snap_id' => 'NOT-HEX', 'seq' => 0, 'last' => true, 'records' => []])['bad'] ?? false);
		$this->assertTrue(ConnectionSnapshot::receive(5, ['snap_id' => 'ff00ff00ff00ff00', 'seq' => 0, 'last' => true, 'records' => array_fill(0, ConnectionSnapshot::MAX_RECORDS + 1, [])])['bad'] ?? false);
		$this->assertSame(['ok' => false, 'expected_seq' => 0], ConnectionSnapshot::receive(5, ['snap_id' => '1234123412341234', 'seq' => 1, 'last' => true, 'records' => []]), 'a chunk of a snapshot MAIN never started');
	}
}
