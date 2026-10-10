<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ConnectionAdmission;
use XcVm\Domain\Cluster\ConnectionLimits;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Streaming\Auth\StreamAuth;

/**
 * max_connections for a CONNECTIONS node's viewers (cluster plan, Phase 6):
 * the node sends conn.limit instead of reading MAIN's store per request, and
 * MAIN runs the same rule from its 1 s loop, for the node's own viewer only,
 * with a line's limit read from `lines`.
 */
final class ConnectionLimitsTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	/** @var list<array<int, mixed>> */
	private array $rEnforced = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `lines` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `max_connections` int, `pair_id` int, `is_restreamer` int)');
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `uuid` text, `server_id` int, `user_id` int, `hmac_id` int, `hmac_identifier` text, `hls_end` int DEFAULT 0)');
		$this->rDb->query('INSERT INTO `lines` VALUES (7, 1, 9, 0), (8, 0, NULL, 0)');
		$this->rDb->query("INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`, `hmac_id`, `hmac_identifier`) VALUES ('mine', 5, 7, NULL, NULL), ('theirs', 6, 7, NULL, NULL), ('free', 5, 8, NULL, NULL), ('hm', 5, NULL, 3, 'dev')");
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0]);
		$this->rDir = sys_get_temp_dir() . '/xcvm-limits-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		ConnectionLimits::useQueue($this->rDir . '/q/', function (...$rArgs): void {
			$this->rEnforced[] = $rArgs;
		});
	}

	protected function tearDown(): void {
		unset($_SERVER['REMOTE_ADDR']);
		ConnectionLimits::useQueue(null);
		EventSpool::useDir(null);
		NodeFlows::usePath(null);
		SettingsManager::set([]);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	public function testMainEnforcesTheLinesLimitForTheNodesOwnViewer(): void {
		$this->assertTrue(ConnectionLimits::queue(5, ['uuid' => 'mine', 'ip' => '10.0.0.1', 'user_agent' => 'VLC', 'user_id' => 7]));
		$this->assertSame(1, ConnectionLimits::drain());
		$this->assertCount(1, $this->rEnforced);
		[$rUserInfo, $rHMAC, , $rIP, $rUA, $rUUID] = $this->rEnforced[0];
		$this->assertSame([7, 1, 9, null, '10.0.0.1', 'VLC', 'mine'], [$rUserInfo['id'], $rUserInfo['max_connections'], $rUserInfo['pair_id'], $rHMAC, $rIP, $rUA, $rUUID], 'the limit and pair come from lines');
		$this->assertSame([], glob($this->rDir . '/q/*') ?: [], 'the queue is drained');
	}

	public function testANodeCannotAskAboutAnotherNodesViewerOrAnotherLine(): void {
		ConnectionLimits::queue(5, ['uuid' => 'theirs', 'user_id' => 7]); // node 6's viewer
		ConnectionLimits::queue(5, ['uuid' => 'mine', 'user_id' => 8]);   // not that line's
		ConnectionLimits::queue(5, ['uuid' => 'gone', 'user_id' => 7]);
		$this->assertSame(0, ConnectionLimits::drain());
		$this->assertSame([], $this->rEnforced);
		$this->assertFalse(ConnectionLimits::queue(5, ['uuid' => 'x y', 'user_id' => 7]));
		$this->assertFalse(ConnectionLimits::queue(5, ['uuid' => 'mine']), 'no owner');
	}

	public function testANodesEventCannotPassItselfOffAsAnAdmissionCut(): void {
		// Only MAIN queues an admission cut, which skips the owner check. A
		// node's conn.limit carrying its markers is rebuilt from its own keys.
		$rCut = [];
		ConnectionAdmission::useEnforcer(static function (...$rArgs) use (&$rCut): void {
			$rCut[] = $rArgs;
		});
		try {
			$this->assertTrue(ConnectionLimits::queue(5, ['uuid' => 'theirs', 'user_id' => 7, 'others' => 9, 'admission' => true])); // node 6's viewer
			$rFiles = glob($this->rDir . '/q/*.json') ?: [];
			$this->assertCount(1, $rFiles);
			$rQueued = json_decode((string) file_get_contents($rFiles[0]), true);
			$this->assertArrayNotHasKey('admission', $rQueued);
			$this->assertArrayNotHasKey('others', $rQueued);
			$this->assertSame(0, ConnectionLimits::drain());
			$this->assertSame([], $this->rEnforced);
			$this->assertSame([], $rCut, 'no line cut on a node\'s word');
		} finally {
			ConnectionAdmission::useEnforcer(null);
		}
	}

	/** @return list<string> the queue's files */
	private function queued(): array {
		return array_map('basename', glob($this->rDir . '/q/*.json') ?: []);
	}

	/**
	 * A node asks on every request of a line with a limit, a playlist refresh
	 * included: a viewer has one file, which its next request replaces, so the
	 * queue holds no more files than there are viewers (it held one a request,
	 * and filled tmp when requests outran the loop).
	 */
	public function testAViewersNextRequestReplacesItsQueuedCheck(): void {
		for ($i = 0; $i < 50; $i++) {
			$this->assertTrue(ConnectionLimits::queue(5, ['uuid' => 'mine', 'user_id' => 7, 'ip' => '198.51.100.' . $i]));
		}
		$this->assertTrue(ConnectionLimits::queueAdmission(5, 'mine', 7, '198.51.100.9', 'vlc', time() + 15));
		$this->assertSame(['a-5-mine.json', 'l-5-mine.json'], $this->queued(), 'one check and one admission');

		ConnectionAdmission::useEnforcer(static function (): void {
		});
		try {
			ConnectionLimits::drain();
		} finally {
			ConnectionAdmission::useEnforcer(null);
		}
		$this->assertCount(1, $this->rEnforced);
		$this->assertSame('198.51.100.49', $this->rEnforced[0][3], 'the last request\'s');
		$this->assertSame([], $this->queued());
	}

	/** With more checks than a pass runs, the passes take them in turn: none waits behind the names that sort first. */
	public function testPassesTakeTheChecksInTurn(): void {
		$this->rDb->query("INSERT INTO `lines_live` (`uuid`, `server_id`, `user_id`) VALUES ('aa', 5, 7), ('bb', 5, 7), ('cc', 5, 7)");
		$rAsk = static fn(string $rUUID): bool => ConnectionLimits::queue(5, ['uuid' => $rUUID, 'user_id' => 7]);
		$rSeen = fn(): array => array_map(static fn(array $rArgs): string => $rArgs[5], $this->rEnforced);
		$rAsk('aa');
		$rAsk('bb');
		$rAsk('cc');

		$this->assertSame(1, ConnectionLimits::drain(1));
		$rAsk('aa'); // asks again at once, as a playlist refresh does
		$this->assertSame(1, ConnectionLimits::drain(1));
		$rAsk('aa');
		$this->assertSame(1, ConnectionLimits::drain(1));
		$this->assertSame(['aa', 'bb', 'cc'], $rSeen(), 'bb and cc were not passed over for aa');
		$this->assertSame(1, ConnectionLimits::drain(1));
		$this->assertSame(['aa', 'bb', 'cc', 'aa'], $rSeen());
	}

	/**
	 * A check that waited five minutes is dropped unrun, as is a backlog of the
	 * files a release up to 2.6.4 wrote (one a request); and nothing is queued
	 * while no pass has run for a minute.
	 */
	public function testTheQueueIsBounded(): void {
		$rDir = $this->rDir . '/q/';
		ConnectionLimits::queue(5, ['uuid' => 'mine', 'user_id' => 7]);
		touch($rDir . 'l-5-mine.json', time() - 301);
		file_put_contents($rDir . '0000000123456789012-4242-00ff.json', '{"server_id":5,"uuid":"mine","user_id":7}');
		$this->assertSame(0, ConnectionLimits::drain());
		$this->assertSame([], $this->rEnforced, 'neither is run');
		$this->assertSame([], $this->queued(), 'both are gone');

		touch($rDir . '.drained', time() - 61);
		$this->assertFalse(ConnectionLimits::queue(5, ['uuid' => 'mine', 'user_id' => 7]), 'nothing drains the queue');
		$this->assertFalse(ConnectionLimits::queueAdmission(5, 'mine', 7, '', '', time() + 15));
		$this->assertSame([], $this->queued());

		ConnectionLimits::drain();
		$this->assertTrue(ConnectionLimits::queue(5, ['uuid' => 'mine', 'user_id' => 7]), 'a pass ran: queued again');
	}

	/**
	 * An admission's cut leaves room for its viewer. Run after the viewer's
	 * reservation ran out without the viewer opening, it would close a viewer
	 * of the line for a place nobody takes: it is dropped.
	 */
	public function testAnAdmissionWhoseViewerNeverOpenedCutsNoOne(): void {
		$this->rDb->exec(InstallSchema::table('cluster_reservations'));
		$rCut = [];
		ConnectionAdmission::useEnforcer(static function (...$rArgs) use (&$rCut): void {
			$rCut[] = $rArgs;
		}, static fn(): int => 1800000000);
		try {
			ConnectionLimits::queueAdmission(5, 'never', 7, '198.51.100.9', 'vlc', 1800000000 - 1);
			$this->assertSame(0, ConnectionLimits::drain());
			$this->assertSame([], $rCut, 'expired and not open: no cut');

			// In time, it is cut as before; so is one whose viewer has opened, however late.
			ConnectionLimits::queueAdmission(5, 'never', 7, '198.51.100.9', 'vlc', 1800000000 + 10);
			ConnectionLimits::queueAdmission(5, 'mine', 7, '198.51.100.9', 'vlc', 1800000000 - 1);
			ConnectionLimits::drain();
			$rFor = array_values(array_unique(array_map(static fn(array $rArgs): string => (string) end($rArgs), $rCut)));
			$this->assertSame(['mine', 'never'], $rFor);
		} finally {
			ConnectionAdmission::useEnforcer(null);
		}
	}

	public function testUnlimitedLinesAndHmacIdentities(): void {
		ConnectionLimits::queue(5, ['uuid' => 'free', 'user_id' => 8]);
		ConnectionLimits::queue(5, ['uuid' => 'hm', 'hmac_id' => 3, 'hmac_identifier' => 'dev', 'max_connections' => 2]);
		$this->assertSame(2, ConnectionLimits::drain());
		$this->assertCount(1, $this->rEnforced, 'an unlimited line has nothing to enforce');
		[$rUserInfo, $rHMAC, $rIdentifier] = $this->rEnforced[0];
		$this->assertSame([2, 3, 'dev'], [$rUserInfo['max_connections'], $rHMAC, $rIdentifier], 'an HMAC limit is the signed one the node passes on');
	}

	public function testACONNECTIONSNodeAsksMainInsteadOfReadingItsStore(): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::COMMANDS | NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
		EventSpool::useDir($this->rDir . '/spool/');
		$_SERVER['REMOTE_ADDR'] = '10.0.0.1';
		StreamAuth::validateConnections(['id' => 7, 'max_connections' => 1, 'pair_id' => null], null, '', '10.0.0.1', 'VLC', 'mine');
		StreamAuth::validateConnections(['id' => null, 'max_connections' => 2], 3, 'dev', '10.0.0.1', 'VLC', 'hm');
		$rFiles = glob($this->rDir . '/spool/p0/*.ndjson') ?: [];
		sort($rFiles);
		$rEvents = array_map(static fn($rFile) => json_decode(trim((string) file_get_contents($rFile)), true), $rFiles);
		$this->assertSame(['conn.limit', 'conn.limit'], array_column($rEvents, 'type'));
		$this->assertSame(['uuid' => 'mine', 'ip' => '10.0.0.1', 'user_agent' => 'VLC', 'user_id' => 7], $rEvents[0]['d']);
		$this->assertSame(['hmac_id' => 3, 'hmac_identifier' => 'dev', 'max_connections' => 2], array_intersect_key($rEvents[1]['d'], ['hmac_id' => 1, 'hmac_identifier' => 1, 'max_connections' => 1]));
	}
}
