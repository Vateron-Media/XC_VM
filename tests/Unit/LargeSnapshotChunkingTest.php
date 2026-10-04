<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ConnectionDigest;
use XcVm\Domain\Cluster\ConnectionSnapshot;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * A 20 000-connection `conn_snapshot` (plan, Phase 6 acceptance: "a
 * 20k-connection snapshot applies atomically"): twenty chunks of 1000, staged
 * aside, and MAIN's store for the node changes only when the last one
 * arrives, all at once (in MySQL mode, one transaction). A bad chunk, a
 * chunk out of order, a staged chunk that no longer reads or a connection
 * lost mid-apply leaves the store exactly as it was.
 *
 * MySQL mode, against `lines_live` as install/database.sql creates it (with
 * its uuid and server_id keys, so 20k upserts stay fast). Each
 * test runs in its own process: MAIN holds the whole registry while it
 * applies it, and that peak stays out of the suite's.
 */
// skip-on-panel: PHPUnit feeds an isolated test to its child PHP on stdin, and the
// panel's PHP (ionCube Loader + opcache.enable_cli) segfaults on a script read from stdin.
#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
#[\PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses]
#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
final class LargeSnapshotChunkingTest extends TestCase {
	private const SID = 5;

	private const TOTAL = 20000;

	private TestDb $rDb;

	private string $rDir;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$rSql = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		preg_match('/CREATE TABLE IF NOT EXISTS `lines_live` \(.*?\) ENGINE=[^;]*;/s', $rSql, $rM);
		$rDdl = (string) $rM[0];
		$this->rDb->exec($rDdl);
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0]);
		$this->rDir = sys_get_temp_dir() . '/xcvm-bigsnap-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		ConnectionDigest::useState($this->rDir . '/digest/');
		ConnectionSnapshot::useDir($this->rDir . '/snap/');
		ClusterClock::fix(1800000000000);

		// What MAIN holds before: 1000 of the node's viewers with older state,
		// 500 it no longer has, one it ended, and another node's viewers.
		$rInsert = $this->rDb->pdo->prepare('INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`, `stream_id`, `container`, `hls_last_read`, `hls_end`) VALUES (?, ?, ?, ?, ?, ?, ?)');
		$this->rDb->pdo->beginTransaction();
		for ($i = 0; $i < 1000; $i++) {
			$rInsert->execute([$this->uuid($i), self::SID, $this->user($i), 100, 'hls', 1, 0]);
		}
		for ($i = 0; $i < 500; $i++) {
			$rInsert->execute([sprintf('gone%06d', $i), self::SID, 9, 100, 'ts', 1, 0]);
		}
		$rInsert->execute(['ended000001', self::SID, 9, 100, 'hls', 1, 1]);
		for ($i = 0; $i < 3; $i++) {
			$rInsert->execute([sprintf('other%06d', $i), 6, 7, 100, 'ts', 1, 0]);
		}
		$this->rDb->pdo->commit();
	}

	protected function tearDown(): void {
		ClusterClock::fix(null);
		ConnectionDigest::useState(null);
		ConnectionSnapshot::useDir(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function uuid(int $i): string {
		return sprintf('v%010d', $i);
	}

	private function user(int $i): int {
		return 1000 + intdiv($i, 4); // four viewers a line
	}

	/**
	 * One chunk of the node's registry, as the agent sends it: 1000 records,
	 * numbered from 0. Built on demand, so the test holds one chunk at a time
	 * besides what MAIN itself holds.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function chunk(int $rSeq): array {
		$rRecords = [];
		for ($i = $rSeq * ConnectionSnapshot::MAX_RECORDS; $i < min(self::TOTAL, ($rSeq + 1) * ConnectionSnapshot::MAX_RECORDS); $i++) {
			$rRecords[] = [
				'uuid' => $this->uuid($i), 'user_id' => $this->user($i), 'stream_id' => 100 + $i % 50, 'user_ip' => '10.1.' . intdiv($i, 250) % 256 . '.' . $i % 250,
				'user_agent' => 'VLC/3.0.20 LibVLC/3.0.20', 'container' => $i % 3 === 0 ? 'ts' : 'hls', 'pid' => 0, 'date_start' => 1799990000 + $i,
				'geoip_country_code' => 'PT', 'isp' => 'Example ISP', 'hls_last_read' => 1800000000, 'hls_end' => 0,
			];
		}
		return $rRecords;
	}

	/** The digest of the whole registry, as the agent computes it. */
	private function registryDigest(): array {
		$rOwners = [];
		for ($i = 0; $i < self::TOTAL; $i++) {
			$rOwners[] = ['uuid' => $this->uuid($i), 'user_id' => $this->user($i)];
		}
		return ConnectionDigest::of($rOwners);
	}

	/** @param list<array<string, mixed>> $rRecords */
	private function send(string $rSnap, int $rSeq, bool $rLast, array $rRecords): array {
		return ConnectionSnapshot::receive(self::SID, ['snap_id' => $rSnap, 'seq' => $rSeq, 'last' => $rLast, 'records' => $rRecords]);
	}

	/**
	 * MAIN's database, whose connection is lost at the $rFailAt-th write to
	 * `lines_live`: that write and everything after it fail, the commit too
	 * (as DatabaseHandler answers once the server is gone), until restore().
	 * With $rOnce, only that one write fails.
	 */
	private function failingDb(int $rFailAt, bool $rOnce = false): DatabaseHandler {
		$rDb = new class ($this->rDb, $rFailAt, $rOnce) extends DatabaseHandler {
			public int $rWrites = 0;

			public bool $rDown = false;

			public function __construct(private TestDb $rInner, private int $rFailAt, private bool $rOnce) {
			}

			public function restore(): void {
				$this->rDown = false;
				$this->rFailAt = PHP_INT_MAX;
			}

			public function query($query, ...$args): bool {
				if (preg_match('/^(UPDATE|INSERT INTO) `lines_live`/', (string) $query) && ++$this->rWrites === $this->rFailAt) {
					if ($this->rOnce) {
						return false;
					}
					$this->rDown = true;
				}
				return !$this->rDown && $this->rInner->query($query, ...$args);
			}

			public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
				return $this->rDown ? [] : $this->rInner->get_rows($use_id, $column_as_id, $unique_row, $sub_row_id);
			}

			public function get_row() {
				return $this->rDown ? [] : $this->rInner->get_row();
			}

			public function num_rows(): int {
				return $this->rDown ? 0 : $this->rInner->num_rows();
			}

			public function beginTransaction() {
				return $this->rInner->pdo->beginTransaction();
			}

			public function commit() {
				return !$this->rDown && $this->rInner->pdo->commit();
			}

			public function rollback() {
				return $this->rInner->pdo->rollBack();
			}
		};
		DatabaseFactory::set($rDb);
		return $rDb;
	}

	/** Every row MAIN's store holds, as [uuid, server, user, hls_last_read, hls_end]. */
	private function store(): string {
		$this->rDb->query('SELECT `uuid`, `server_id`, `user_id`, `hls_last_read`, `hls_end` FROM `lines_live` ORDER BY `uuid`');
		return hash('sha256', (string) json_encode(array_map(static fn($rR) => array_values($rR), $this->rDb->get_rows())));
	}

	private function rowsOf(int $rServerID): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `server_id` = ?', $rServerID);
		return (int) $this->rDb->get_row()['n'];
	}

	public function testTwentyThousandConnectionsApplyWithTheLastChunkOnly(): void {
		$rChunks = intdiv(self::TOTAL, ConnectionSnapshot::MAX_RECORDS);
		$this->assertSame(20, $rChunks);
		$this->assertLessThan(ConnectionSnapshot::MAX_CHUNKS, $rChunks);
		$rBefore = $this->store();
		$rSnap = 'a1b2c3d4e5f60718';
		for ($rSeq = 0; $rSeq < $rChunks - 1; $rSeq++) {
			$rChunk = $this->chunk($rSeq);
			// The agent BOXes each chunk into one request, well under nginx's 8 MB cap.
			$this->assertLessThan(ClusterApi::MAX_BODY / 8, strlen((string) json_encode(['snap_id' => $rSnap, 'seq' => $rSeq, 'last' => false, 'records' => $rChunk])));
			$this->assertSame(['ok' => true, 'done' => false], $this->send($rSnap, $rSeq, false, $rChunk));
			$this->assertSame($rBefore, $this->store(), 'nothing applied before the last chunk (chunk ' . $rSeq . ')');
		}

		$rOut = $this->send($rSnap, 19, true, $this->chunk(19));
		$this->assertSame(['ok' => true, 'done' => true, 'applied' => self::TOTAL, 'removed' => 500, 'dropped' => 0], $rOut);
		$this->assertSame(self::TOTAL + 1, $this->rowsOf(self::SID), 'the whole registry, plus the viewer the node had ended');
		$this->assertSame(3, $this->rowsOf(6), 'another node\'s viewers are untouched');
		$this->rDb->query("SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `uuid` LIKE 'gone%'");
		$this->assertSame(0, (int) $this->rDb->get_row()['n']);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `server_id` = ? AND `hls_last_read` = 1800000000', self::SID);
		$this->assertSame(self::TOTAL, (int) $this->rDb->get_row()['n'], 'viewers MAIN already held are updated in place, not duplicated');
		$this->assertSame($this->registryDigest(), ConnectionDigest::of(ConnectionDigest::stored(self::SID)), 'the digests agree');
		$this->assertSame([], glob($this->rDir . '/snap/' . self::SID . '/*.json') ?: [], 'nothing stays staged');
	}

	public function testABadChunkLeavesTheStoreUnchanged(): void {
		$rBefore = $this->store();
		$rSnap = 'b1b2c3d4e5f60718';
		for ($rSeq = 0; $rSeq < 10; $rSeq++) {
			$this->send($rSnap, $rSeq, false, $this->chunk($rSeq));
		}
		$rTen = $this->chunk(10);

		// Chunks MAIN refuses outright (400): too big, not a list, past the cap.
		$this->assertTrue($this->send($rSnap, 10, false, array_merge($rTen, [$this->chunk(11)[0]]))['bad'] ?? false, 'over 1000 records');
		$this->assertTrue($this->send($rSnap, 10, true, ['a' => $rTen[0]])['bad'] ?? false, 'records not a list');
		$this->assertTrue($this->send($rSnap, ConnectionSnapshot::MAX_CHUNKS, true, [])['bad'] ?? false, 'past the chunk cap');
		$this->assertTrue(ConnectionSnapshot::receive(self::SID, ['snap_id' => $rSnap, 'seq' => 10, 'last' => 'yes', 'records' => $rTen])['bad'] ?? false, 'last not a boolean');
		$this->assertSame($rBefore, $this->store());

		// A chunk out of order (409 SNAP_GAP), even the last one: the agent drops the snapshot.
		$this->assertSame(['ok' => false, 'expected_seq' => 10], $this->send($rSnap, 11, true, $this->chunk(11)));
		$this->assertSame(['ok' => false, 'expected_seq' => 0], $this->send('c1b2c3d4e5f60718', 3, true, $this->chunk(3)), 'a chunk of another snapshot');
		$this->assertSame($rBefore, $this->store());

		// A staged chunk that no longer reads: the last chunk applies nothing,
		// the staging is cleared, and the node starts over from chunk 0.
		for ($rSeq = 10; $rSeq < 19; $rSeq++) {
			$this->assertTrue($this->send($rSnap, $rSeq, false, $this->chunk($rSeq))['ok']);
		}
		file_put_contents($this->rDir . '/snap/' . self::SID . '/4.json', substr((string) file_get_contents($this->rDir . '/snap/' . self::SID . '/4.json'), 0, 4096));
		$this->assertSame(['ok' => false, 'expected_seq' => 0], $this->send($rSnap, 19, true, $this->chunk(19)));
		$this->assertSame($rBefore, $this->store(), 'the store is exactly as before the snapshot');
		$this->assertSame([], glob($this->rDir . '/snap/' . self::SID . '/*.json') ?: []);

		// Sent again whole, it applies.
		$rSnap = 'd1b2c3d4e5f60718';
		for ($rSeq = 0; $rSeq < 20; $rSeq++) {
			$rOut = $this->send($rSnap, $rSeq, $rSeq === 19, $this->chunk($rSeq));
		}
		$this->assertSame(['ok' => true, 'done' => true, 'applied' => self::TOTAL, 'removed' => 500, 'dropped' => 0], $rOut);
		$this->assertSame($this->registryDigest(), ConnectionDigest::of(ConnectionDigest::stored(self::SID)));
	}

	public function testAConnectionLostMidApplyLeavesTheStoreAsItWasAndTheLastChunkAppliesWhenSentAgain(): void {
		$rBefore = $this->store();
		$rSnap = 'e1b2c3d4e5f60718';
		for ($rSeq = 0; $rSeq < 19; $rSeq++) {
			$this->send($rSnap, $rSeq, false, $this->chunk($rSeq));
		}
		$rDb = $this->failingDb(12000);
		$rOut = null;
		try {
			$rOut = $this->send($rSnap, 19, true, $this->chunk(19));
		} catch (\RuntimeException) {
			// ClusterApi answers 503 DB.
		}
		$this->assertNull($rOut, 'not reported applied: ' . json_encode($rOut));
		$this->assertGreaterThanOrEqual(12000, $rDb->rWrites, 'the connection was lost part-way through the apply');
		$rDb->restore();
		$this->assertSame($rBefore, $this->store(), 'rolled back: the store is exactly as before the snapshot');
		$this->assertCount(20, glob($this->rDir . '/snap/' . self::SID . '/[0-9]*.json') ?: [], 'the chunks stay staged');

		// The node sends the last chunk again, and the whole snapshot applies.
		$this->assertSame(['ok' => true, 'done' => true, 'applied' => self::TOTAL, 'removed' => 500, 'dropped' => 0], $this->send($rSnap, 19, true, $this->chunk(19)));
		$this->assertSame($this->registryDigest(), ConnectionDigest::of(ConnectionDigest::stored(self::SID)));
		$this->assertSame([], glob($this->rDir . '/snap/' . self::SID . '/*.json') ?: []);
	}

	public function testAWriteMainCannotMakeNeverRemovesTheConnectionItHolds(): void {
		// The update of a viewer MAIN already holds fails, once.
		$this->failingDb(1, true);
		$rOut = $this->send('f1b2c3d4e5f60718', 0, true, [$this->chunk(0)[0], ['uuid' => 'fresh000001', 'user_id' => 1, 'stream_id' => 100, 'container' => 'ts', 'hls_end' => 0]]);
		$this->assertSame(['ok' => true, 'done' => true, 'applied' => 1, 'removed' => 1499, 'dropped' => 1], $rOut, 'every other viewer the node no longer has is removed');
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `lines_live` WHERE `uuid` IN (?, ?)', $this->uuid(0), 'fresh000001');
		$this->assertSame(2, (int) $this->rDb->get_row()['n'], 'the node still has it: it stays, and the digest check finds the difference');
	}
}
