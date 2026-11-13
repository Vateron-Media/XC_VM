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
		$this->assertFalse(ConnectionDigest::check(5, $rDrift), 'two quick checks can still precede an in-flight event');
		ClusterClock::fix(1800000016000);
		$this->assertTrue(ConnectionDigest::check(5, $rDrift), 'persistent drift after the settling window asks');
		ClusterClock::fix(1800000020000);
		$this->assertFalse(ConnectionDigest::check(5, $rDrift), 'asked at most every 30 s');
		ClusterClock::fix(1800000048000);
		$this->assertTrue(ConnectionDigest::check(5, $rDrift));
		ClusterClock::fix(1800000052000);
		$this->assertFalse(ConnectionDigest::check(5, ['count' => '1']), 'a malformed digest is ignored');
	}

	public function testPendingStateEventsDoNotTriggerFullSnapshotsButSettledDriftStillDoes(): void {
		$this->row('a', 5, 7);
		$rDigest = ConnectionDigest::of([$this->rec('a', 7), $this->rec('b', 8)]);
		for ($i = 0; $i < 4; $i++) {
			ClusterClock::fix(1800000000000 + $i * 4000);
			$this->assertFalse(ConnectionDigest::check(5, $rDigest, ['files' => 1]), 'P0 already carries the missing change');
		}
		$this->assertSame(0, ConnectionDigest::state(5)['miss']);
		$this->assertTrue(ConnectionDigest::state(5)['pending_p0']);
		ClusterClock::fix(1800000016000);
		$this->assertFalse(ConnectionDigest::check(5, $rDigest, ['files' => 0]));
		ClusterClock::fix(1800000020000);
		$this->assertTrue(ConnectionDigest::check(5, $rDigest, ['files' => 0]), 'persistent drift after the outbox drains still asks for recovery');
	}

	public function testDifferentInFlightViewersDoNotAccumulateAsOnePersistentGap(): void {
		for ($i = 0; $i < 12; $i++) {
			ClusterClock::fix(1800000000000 + $i * 4000);
			$rDigest = ConnectionDigest::of([$this->rec('changing-' . $i, 7)]);
			$this->assertFalse(ConnectionDigest::check(5, $rDigest, ['files' => 1]));
		}
	}

	public function testBusyP0CannotHidePersistentDriftForever(): void {
		$rDigest = ConnectionDigest::of([$this->rec('missing', 7)]);
		ClusterClock::fix(1800000000000);
		$this->assertFalse(ConnectionDigest::check(5, $rDigest, ['files' => 1]));
		ClusterClock::fix(1800000032000);
		$this->assertFalse(ConnectionDigest::check(5, $rDigest, ['files' => 1]));
		ClusterClock::fix(1800000036000);
		$this->assertTrue(ConnectionDigest::check(5, $rDigest, ['files' => 1]));
	}

	public function testAQuietOutboxStillAllowsAnInFlightEventToSettle(): void {
		$digest = ConnectionDigest::of([$this->rec('a', 7)]);
		foreach ([0, 4000, 8000] as $elapsed) {
			ClusterClock::fix(1800000000000 + $elapsed);
			$this->assertFalse(ConnectionDigest::check(5, $digest, ['files' => 0]));
		}
		$this->row('a', 5, 7);
		ClusterClock::fix(1800000012000);
		$this->assertFalse(ConnectionDigest::check(5, $digest, ['files' => 0]));
		$this->assertSame(0, ConnectionDigest::state(5)['asked']);
	}

	public function testRepeatedUnrepairableSnapshotsBackOffButADifferentGapRecoversNormally(): void {
		$this->row('foreign', 6, 7);
		$records = [$this->rec('foreign', 7)];
		$digest = ConnectionDigest::of($records);
		$this->assertFalse(ConnectionDigest::check(5, $digest));
		ClusterClock::fix(1800000012000);
		$this->assertTrue(ConnectionDigest::check(5, $digest));
		$out = ConnectionSnapshot::receive(5, ['snap_id' => 'aa11bb22cc33dd44', 'seq' => 0, 'last' => true, 'records' => $records]);
		$this->assertSame(1, $out['dropped']);
		ClusterClock::fix(1800000044000);
		$this->assertFalse(ConnectionDigest::check(5, $digest));
		$this->assertSame(60000, ConnectionDigest::state(5)['retry_ms']);
		ClusterClock::fix(1800000072000);
		$this->assertTrue(ConnectionDigest::check(5, $digest));
		ConnectionDigest::snapshotApplied(5, $records);
		ClusterClock::fix(1800000104000);
		$this->assertFalse(ConnectionDigest::check(5, $digest));
		$this->assertSame(120000, ConnectionDigest::state(5)['retry_ms']);
		$changed = ConnectionDigest::of([$this->rec('different', 8)]);
		ClusterClock::fix(1800000108000);
		$this->assertFalse(ConnectionDigest::check(5, $changed));
		ClusterClock::fix(1800000120000);
		$this->assertTrue(ConnectionDigest::check(5, $changed), 'a new gap uses normal recovery, not the old backoff');
		for ($i = 0; $i < 5; $i++) {
			ConnectionDigest::snapshotApplied(5, $records);
		}
		ClusterClock::fix(1800000124000);
		ConnectionDigest::check(5, $digest);
		$this->assertSame(300000, ConnectionDigest::state(5)['retry_ms'], 'retries stay bounded');
		ClusterClock::fix(1800000416000);
		$this->assertFalse(ConnectionDigest::check(5, $digest));
		ClusterClock::fix(1800000420000);
		$this->assertTrue(ConnectionDigest::check(5, $digest), 'even an unchanged gap is retried within five minutes');
		$this->row('foreign', 5, 7);
		ClusterClock::fix(1800000424000);
		$this->assertFalse(ConnectionDigest::check(5, $digest));
		$this->assertSame(30000, ConnectionDigest::state(5)['retry_ms']);
		$this->assertArrayNotHasKey('gap', json_decode(file_get_contents($this->rDir . '/digest/5.snapshot.json'), true));
	}

	public function testSnapshotFeedbackUsesTheSameScalarFieldsAsIngest(): void {
		$records = [['uuid' => 'hmac-viewer', 'hmac_id' => 9, 'hmac_identifier' => ['invalid'], 'hls_end' => 0]];
		$this->rDb->query('INSERT INTO lines_live (uuid, server_id, hmac_id, hmac_identifier, hls_end) VALUES (?, 5, 9, NULL, 0);', 'hmac-viewer');
		ConnectionDigest::snapshotApplied(5, $records);
		$this->assertFileDoesNotExist($this->rDir . '/digest/5.snapshot.json', 'discarded nested fields must not create divergence or emit warnings');
	}

	public function testCompletionFeedbackIsIdempotentForSnapshotRetries(): void {
		$this->row('foreign', 6, 7);
		$payload = ['snap_id' => 'aa11bb22cc33dd44', 'seq' => 0, 'last' => true, 'records' => [$this->rec('foreign', 7)]];
		ConnectionSnapshot::receive(5, $payload);
		ConnectionSnapshot::receive(5, $payload);
		$file = $this->rDir . '/digest/5.snapshot.json';
		$this->assertSame(60000, json_decode(file_get_contents($file), true)['retry_ms']);
		$payload['snap_id'] = 'aa11bb22cc33dd55';
		ConnectionSnapshot::receive(5, $payload);
		$this->assertSame(120000, json_decode(file_get_contents($file), true)['retry_ms']);
	}

	public function testCommittedDigestRecordsFeedbackWithoutReadingTheStoreAgain(): void {
		$records = [$this->rec('a', 7)];
		try {
			DatabaseFactory::reset();
			ConnectionDigest::snapshotApplied(5, $records, ConnectionDigest::of([]), 'aa11bb22cc33dd44');
		} finally {
			DatabaseFactory::set($this->rDb);
		}
		$this->assertSame(60000, json_decode(file_get_contents($this->rDir . '/digest/5.snapshot.json'), true)['retry_ms']);
		$applied = null;
		ConnectionSnapshot::apply(5, $records, $applied);
		$this->assertSame(ConnectionDigest::of(ConnectionDigest::stored(5)), $applied);
	}

	public function testBrokenFeedbackStorageNeverIssuesAnUnthrottledSnapshot(): void {
		$digest = ConnectionDigest::of([$this->rec('a', 7)]);
		$this->assertFalse(ConnectionDigest::check(5, $digest));
		file_put_contents($this->rDir . '/digest/5.snapshot.json', '{broken');
		foreach ([12000, 60000, 360000] as $elapsed) {
			ClusterClock::fix(1800000000000 + $elapsed);
			$this->assertFalse(ConnectionDigest::check(5, $digest));
		}
		$this->assertSame(0, ConnectionDigest::state(5)['asked']);
	}

	public function testConcurrentCompletionsSerializeTheirReadModifyWrite(): void {
		$dir = $this->rDir . '/digest/';
		mkdir($dir);
		$lock = fopen($dir . '5.lock', 'ce');
		flock($lock, LOCK_EX);
		$children = [];
		try {
			foreach (['aa11bb22cc33dd44', 'aa11bb22cc33dd55'] as $id) {
				$code = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
					. '\\XcVm\\Domain\\Cluster\\ConnectionDigest::useState(' . var_export($dir, true) . ');'
					. 'file_put_contents(' . var_export($dir . $id . '.ready', true) . ', "ready");'
					. '\\XcVm\\Domain\\Cluster\\ConnectionDigest::snapshotApplied(5, [["uuid"=>"a","user_id"=>7]], ["count"=>0,"users"=>0,"xor64"=>"0000000000000000"], ' . var_export($id, true) . ');';
				$process = proc_open([PHP_BINARY, '-r', $code], [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes);
				$this->assertIsResource($process);
				$children[] = $process;
			}
			$until = microtime(true) + 5;
			while (count(glob($dir . '*.ready')) < 2 && microtime(true) < $until) {
				usleep(1000);
			}
			$this->assertCount(2, glob($dir . '*.ready'));
			$this->assertFileDoesNotExist($dir . '5.snapshot.json', 'Both completions wait for the heartbeat/feedback lock.');
		} finally {
			flock($lock, LOCK_UN);
			fclose($lock);
			foreach ($children as $process) {
				$this->assertSame(0, proc_close($process));
			}
		}
		$this->assertSame(120000, json_decode(file_get_contents($dir . '5.snapshot.json'), true)['retry_ms']);
	}

	public function testPartialSnapshotDoesNotStartBackoff(): void {
		$this->row('foreign', 6, 7);
		$records = [$this->rec('foreign', 7)];
		ConnectionSnapshot::receive(5, ['snap_id' => 'aa11bb22cc33dd44', 'seq' => 0, 'last' => false, 'records' => $records]);
		$this->assertFileDoesNotExist($this->rDir . '/digest/5.snapshot.json');
		ConnectionSnapshot::receive(5, ['snap_id' => 'aa11bb22cc33dd44', 'seq' => 1, 'last' => true, 'records' => []]);
		$this->assertFileExists($this->rDir . '/digest/5.snapshot.json');
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
