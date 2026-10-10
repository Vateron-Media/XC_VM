<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\ReplicaStreamCache;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * The node's own store of its streams' runtime state (cluster plan, section
 * 8: the node owns the `stream.state` fields "with a local copy"; section 9:
 * its recordings' "local recording.state override"): what StreamStateWriter
 * and ContentSink report is kept there too while the STREAMS flow is on, under
 * one lock with the event; it is seeded once from MAIN's rows when the agent
 * has delivered everything; a write with STREAMS off lapses it; it is
 * crash-safe, bounded and the node user's; a node in mode 2 keeps what the
 * agent did not take and sends it once the agent is back, a node in mode 1
 * writes MAIN's row then and the store lapses. MAIN and mode 0 write as
 * before and keep nothing.
 */
final class StreamRuntimeTest extends TestCase {
	private string $rDir;

	private int $rSid;

	/** The store's directory before this test (the suite's own, tests/bootstrap.php). */
	private string $rRuntimeDir;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rSid = (int) SERVER_ID;
		$this->rDir = sys_get_temp_dir() . '/xcvm-runtime-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster/spool', 0777, true);
		// As root, the store writes as the owner of the agent's directory.
		AgentUser::own($this->rDir);
		EventSpool::useDir($this->rDir . 'cluster/spool/');
		$this->rRuntimeDir = StreamRuntime::dir();
		StreamRuntime::useDir($this->rDir . 'cluster/runtime/');
		NodeRole::useMainBuild(false);
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT);
	}

	protected function tearDown(): void {
		ProcessRunner::useRunner(null);
		StreamStateWriter::useSink(null);
		StreamRuntime::useDir($this->rRuntimeDir);
		StreamRuntime::useLimits(null);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		EventSpool::useDir(null);
		NodeFlows::usePath(null);
		NodeRole::useMainBuild(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** The agent's flows.json, touched now (the agent alive) or $rAge seconds ago. */
	private function flows(?int $rFlows, int $rMode = 1, int $rAge = 0): void {
		$rFile = $this->rDir . 'cluster/flows.json';
		if ($rFlows === null) {
			@unlink($rFile);
		} else {
			file_put_contents($rFile, json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => 'active']));
			touch($rFile, time() - $rAge);
		}
		NodeFlows::usePath($rFile);
		clearstatcache();
	}

	/** A database that records what it is asked, and whether the store was seeded then, and answers $rAnswer. */
	private function recorder(bool $rAnswer = true): object {
		return new class ($rAnswer) {
			/** @var list<array{string, list<mixed>}> */
			public array $rQueries = [];

			/** @var list<bool> StreamRuntime::seeded() as each statement arrived */
			public array $rSeeded = [];

			public function __construct(private bool $rAnswer) {
			}

			public function query(string $rSql, mixed ...$rParams): bool {
				$this->rQueries[] = [$rSql, $rParams];
				$this->rSeeded[] = StreamRuntime::seeded();
				return $this->rAnswer;
			}
		};
	}

	/** The unsent marker's path. */
	private function marker(): string {
		return $this->rDir . 'cluster/runtime/unsent';
	}

	/** @return list<array<string, mixed>> the spooled P0 events, in file order */
	private function spooled(): array {
		$rFiles = glob($this->rDir . 'cluster/spool/p0/*.ndjson') ?: [];
		sort($rFiles);
		$rOut = [];
		foreach ($rFiles as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rOut[] = json_decode($rLine, true);
			}
		}
		return $rOut;
	}

	/** The store as a seed leaves it (as the node's user). */
	private function markSeeded(): void {
		@mkdir($this->rDir . 'cluster/runtime', 0700, true);
		file_put_contents($this->rDir . 'cluster/runtime/seeded', json_encode(['at' => time(), 'server_id' => $this->rSid, 'streams' => 0]));
		AgentUser::own($this->rDir . 'cluster/runtime');
	}

	// ── Written with the events ──────────────────────────────────────

	public function testTheWritersKeepWhatTheySendTheCurrentSourceRedacted(): void {
		$this->assertTrue(StreamStateWriter::update(10, $this->rSid, ['pid' => 4242, 'stream_status' => 0, 'current_source' => 'http://user:pass@src.example/a']));
		$this->assertTrue(StreamStateWriter::update(10, $this->rSid, ['monitor_pid' => 77]));
		$this->assertSame(['pid' => 4242, 'stream_status' => 0, 'current_source' => 'http://***@src.example/a', 'monitor_pid' => 77], StreamRuntime::get(10));
		$rEvents = $this->spooled();
		$this->assertCount(2, $rEvents, 'each write still spools its event');
		$this->assertSame('stream.state', $rEvents[0]['type']);
		$this->assertSame(['stream_id' => 10, 'server_id' => $this->rSid, 'fields' => ['pid' => 4242, 'stream_status' => 0, 'current_source' => 'http://***@src.example/a']], $rEvents[0]['d']);

		// By server_stream_id: a row this process read names its stream.
		StreamRuntime::remember(55, 10);
		$this->assertTrue(StreamStateWriter::updateRow(55, ['progress_info' => '{"fps":25}']));
		$this->assertSame('{"fps":25}', StreamRuntime::get(10)['progress_info']);

		// The workers' pids and a recording's status.
		$this->assertTrue(ContentSink::workerPid(10, 'tv_archive', 999));
		$this->assertTrue(ContentSink::recordingState(3, 1));
		$this->assertSame(999, StreamRuntime::get(10)['tv_archive_pid']);
		$this->assertSame(['tv_archive_pid' => 999, 'vframes_pid' => 0], StreamRuntime::streamFields(10));
		$this->assertSame(1, StreamRuntime::recordingStatus(3));
		$this->assertNull(StreamRuntime::recordingStatus(4));
		$this->assertSame([10], StreamRuntime::ids());
		$this->assertSame([3], StreamRuntime::recordingIDs());
	}

	/**
	 * An update by server_stream_id that no row this process read names: the
	 * stream caches' index (the last apply's `ssid`) finds its stream.
	 */
	public function testAnUpdateByServerStreamIdFindsItsStreamInTheReplicasIndex(): void {
		mkdir($this->rDir . 'cache', 0777, true);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		$this->assertTrue(ReplicaStreamCache::writeIndex([10 => ['etag' => 'e', 'ver' => 3, 'rec' => [], 'ssid' => 55]]));
		// The writers read it as the node's user, as cluster:apply leaves it.
		AgentUser::own($this->rDir . 'cache');
		// A new process: nothing remembered.
		StreamRuntime::useDir($this->rDir . 'cluster/runtime/');
		$this->assertTrue(StreamStateWriter::updateRow(55, ['pid' => 9]));
		$this->assertSame(['pid' => 9], StreamRuntime::get(10));
		$this->assertSame(['ssid' => 55, 'fields' => ['pid' => 9]], $this->spooled()[0]['d'], 'the event names the row as it did');
	}

	/**
	 * A recording finished here: its status kept (done), and its VOD's row as
	 * MAIN attaches it to this node (a producer, analysis due), which MAIN
	 * inserts itself: no event of the node's carries it.
	 */
	public function testARecordingDoneKeepsItsStatusAndItsVodsRow(): void {
		$this->assertTrue(ContentSink::recordingDone(3, $this->rSid, 18));
		$this->assertSame(2, StreamRuntime::recordingStatus(3));
		$this->assertSame(['pid' => 1, 'to_analyze' => 1], StreamRuntime::get(18));
		$rEvents = $this->spooled();
		$this->assertSame(['recording.state'], array_column($rEvents, 'type'), 'MAIN finishes it from the event');
		$this->assertSame(['id' => 3, 'status' => 2], $rEvents[0]['d']);
		$this->assertFileDoesNotExist($this->marker(), 'nothing left to send');
	}

	public function testAnotherServersRowIsNotKeptAndAnUnknownRowLapsesTheStore(): void {
		$this->markSeeded();
		$this->assertTrue(StreamStateWriter::update(10, $this->rSid + 1, ['pid' => 1]));
		$this->assertSame([], StreamRuntime::ids(), 'another server\'s row is not this node\'s');
		$this->assertTrue(StreamRuntime::seeded(), 'and nothing was missed');

		// A server_stream_id no row this process read, nor the replica, names.
		$this->assertTrue(StreamStateWriter::updateRow(66, ['pid' => 2]), 'the event still goes');
		$this->assertSame([], StreamRuntime::ids());
		$this->assertFalse(StreamRuntime::seeded(), 'missed: seeded again before a reader takes it');
		$this->assertCount(2, $this->spooled());
	}

	public function testServerFieldsFillEveryRuntimeColumnWithMainsDefault(): void {
		StreamStateWriter::update(10, $this->rSid, ['pid' => 1, 'cc_info' => '[]']);
		$rLive = StreamRuntime::serverFields(10, 1);
		$this->assertSame(ReplicaSections::STREAM_SERVER_LOCAL, array_keys($rLive));
		$this->assertSame(1, $rLive['pid']);
		$this->assertSame('[]', $rLive['cc_info']);
		$this->assertSame([0, 0, 0], [$rLive['stream_status'], $rLive['to_analyze'], $rLive['compatible']]);
		$this->assertNull($rLive['cchannel_rsources']);
		$this->assertNull($rLive['updated']);
		$rCreated = StreamRuntime::serverFields(20, 3);
		$this->assertSame(['[]', '[]'], [$rCreated['pids_create_channel'], $rCreated['cchannel_rsources']], 'a created channel\'s build, as MAIN inserts its row');
		$this->assertNull($rCreated['pid']);
		$this->assertSame(['tv_archive_pid' => 0, 'vframes_pid' => 0], StreamRuntime::streamFields(20));
	}

	// ── Crash-safe, bounded, the node user's ─────────────────────────

	public function testWritesAreAtomicAndATornFileIsNoEntry(): void {
		StreamStateWriter::update(10, $this->rSid, ['pid' => 1]);
		StreamStateWriter::update(10, $this->rSid, ['pid' => 2]);
		$this->assertSame([], glob($this->rDir . 'cluster/runtime/streams/.*.tmp') ?: [], 'no write left aside');
		// A write that died before its rename, and a file that does not read.
		file_put_contents($this->rDir . 'cluster/runtime/streams/.11.json.123.tmp', '{"id":11,"fields":{"pid":9},"unsent":[]}');
		file_put_contents($this->rDir . 'cluster/runtime/streams/12.json', '{"id":12,"fie');
		$this->assertSame([], StreamRuntime::get(11));
		$this->assertSame([], StreamRuntime::get(12));
		$this->assertSame(['pid' => 2], StreamRuntime::get(10));
		// A write over a file that does not read starts the entry afresh.
		StreamStateWriter::update(12, $this->rSid, ['pid' => 3]);
		$this->assertSame(['pid' => 3], StreamRuntime::get(12));
	}

	public function testTheStoreIsBounded(): void {
		StreamRuntime::useLimits(2, 1, 16);
		$this->markSeeded();
		StreamStateWriter::update(10, $this->rSid, ['pid' => 1]);
		StreamStateWriter::update(11, $this->rSid, ['pid' => 1]);
		$this->assertTrue(StreamRuntime::seeded());
		$this->assertTrue(StreamStateWriter::update(12, $this->rSid, ['pid' => 1]), 'the event still goes');
		$this->assertSame([10, 11], StreamRuntime::ids(), 'no entry past the bound');
		$this->assertFalse(StreamRuntime::seeded(), 'the store lapses: it no longer holds every stream');
		StreamStateWriter::update(10, $this->rSid, ['pid' => 2]);
		$this->assertSame(2, StreamRuntime::get(10)['pid'], 'an entry already there is still written');
		StreamStateWriter::update(10, $this->rSid, ['stream_info' => str_repeat('x', 17)]);
		$this->assertArrayNotHasKey('stream_info', StreamRuntime::get(10), 'a value past the bound is not kept');
		$this->assertTrue(StreamRuntime::recording(1, 1));
		$this->assertFalse(StreamRuntime::recording(2, 1));
		$this->assertTrue(StreamRuntime::recording(1, 2), 'a recording already there is still written');

		// Pruned to what the node holds, never an entry written in the last minutes.
		StreamRuntime::useLimits(null);
		StreamStateWriter::update(12, $this->rSid, ['pid' => 1]);
		foreach ([10, 11] as $rID) {
			touch($this->rDir . 'cluster/runtime/streams/' . $rID . '.json', time() - StreamRuntime::PRUNE_GRACE - 60);
		}
		touch($this->rDir . 'cluster/runtime/recordings/1.json', time() - StreamRuntime::PRUNE_GRACE - 60);
		$this->assertSame(['streams' => 1, 'recordings' => 1], StreamRuntime::prune([11], []));
		$this->assertSame([11, 12], StreamRuntime::ids(), '12 was written just now');
		$this->assertSame([], StreamRuntime::recordingIDs());
	}

	public function testTheStoreIsTheNodeUsersAlone(): void {
		StreamStateWriter::update(10, $this->rSid, ['pid' => 1]);
		ContentSink::recordingState(3, 1);
		$rStore = $this->rDir . 'cluster/runtime/';
		clearstatcache();
		$this->assertSame(0700, fileperms($rStore) & 0777);
		$this->assertSame(0600, fileperms($rStore . 'streams/10.json') & 0777);
		$this->assertSame(0600, fileperms($rStore . 'recordings/3.json') & 0777);
		foreach ([$rStore, $rStore . 'streams/10.json', $rStore . 'recordings/3.json'] as $rPath) {
			$this->assertSame(fileowner($this->rDir . 'cluster'), fileowner($rPath), $rPath . ': the agent directory\'s owner');
		}
	}

	// ── Seeded once, lapsed by a write it misses ─────────────────────

	private function main(): TestDb {
		$rDb = new TestDb();
		$rDb->exec(InstallSchema::table('streams_servers'));
		$rDb->exec(InstallSchema::table('streams'));
		$rOther = $this->rSid + 1;
		$rDb->exec("INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`, `monitor_pid`, `stream_status`, `current_source`, `stream_info`, `cc_info`) VALUES
			(1, 10, {$this->rSid}, 4242, 4243, 0, 'http://user:pass@src.example/a', '{\"codecs\":[]}', NULL),
			(2, 11, {$this->rSid}, NULL, NULL, 0, NULL, NULL, NULL),
			(3, 10, {$rOther}, 7, 8, 0, NULL, NULL, NULL),
			(4, 13, {$this->rSid}, NULL, NULL, 1, NULL, NULL, '[{\"finish\":10}]')");
		$rDb->exec("INSERT INTO `streams` (`id`, `type`, `tv_archive_server_id`, `tv_archive_pid`, `vframes_server_id`, `vframes_pid`) VALUES
			(10, 1, {$this->rSid}, 501, {$rOther}, 502), (12, 1, {$rOther}, 601, {$this->rSid}, 602), (11, 2, 0, 0, 0, 0)");
		return $rDb;
	}

	public function testTheSeedCopiesMainsRowsOnceTheAgentDeliveredEverything(): void {
		$rDb = $this->main();
		// From an earlier seed: a stream the node no longer has a row for.
		StreamStateWriter::update(99, $this->rSid, ['pid' => 1]);
		unlink($this->rDir . 'cluster/spool/p0/' . basename((glob($this->rDir . 'cluster/spool/p0/*.ndjson') ?: [''])[0]));
		// An event not yet delivered: MAIN's row may not hold it.
		StreamStateWriter::update(10, $this->rSid, ['monitor_pid' => 1]);
		$this->assertFalse(StreamRuntime::seed($rDb));
		$this->assertFalse(StreamRuntime::seeded());
		array_map('unlink', glob($this->rDir . 'cluster/spool/p0/*.ndjson') ?: []);
		// The agent stopped: its spool takes nothing, writes go to MAIN's row alone.
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 1, EventSpool::STALE_AFTER + 10);
		$this->assertFalse(StreamRuntime::seed($rDb));
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT);

		$this->assertTrue(StreamRuntime::seed($rDb));
		$this->assertTrue(StreamRuntime::seeded());
		$this->assertSame([10, 12, 13], StreamRuntime::ids(), 'rows with state, and this node\'s workers; 11 holds only defaults, 99 is gone');
		$rTen = StreamRuntime::get(10);
		$this->assertSame('http://***@src.example/a', $rTen['current_source'], 'sanitised as the event carries it');
		$this->assertEquals(4242, $rTen['pid']);
		$this->assertEquals(501, $rTen['tv_archive_pid'], 'its archive is recorded here');
		$this->assertArrayNotHasKey('vframes_pid', $rTen, 'its thumbnails are another server\'s');
		$this->assertEquals(602, StreamRuntime::get(12)['vframes_pid']);
		$this->assertEquals(1, StreamRuntime::get(13)['stream_status']);
		// Seeded once: a second seed changes nothing.
		$rDb->exec('UPDATE `streams_servers` SET `pid` = 1 WHERE `server_stream_id` = 1');
		$this->assertTrue(StreamRuntime::seed($rDb));
		$this->assertEquals(4242, StreamRuntime::get(10)['pid']);
		// Another server's store is not this one's.
		file_put_contents($this->rDir . 'cluster/runtime/seeded', json_encode(['at' => time(), 'server_id' => $this->rSid + 1, 'streams' => 0]));
		$this->assertFalse(StreamRuntime::seeded());
	}

	/**
	 * The seed flushes its entries to disk once, before it writes the marker
	 * that says they are there: `sync -f` on the store's directory, from an
	 * argv list (no shell), its errors to /dev/null as before.
	 */
	public function testTheSeedFlushesItsEntriesBeforeItsMarker(): void {
		$rDb = $this->main();
		$rStore = $this->rDir . 'cluster/runtime/';
		$rFlushes = [];
		ProcessRunner::useRunner(static function (array $rArgv, bool $rQuiet) use (&$rFlushes, $rStore): int {
			$rFlushes[] = [$rArgv, $rQuiet, count(glob($rStore . 'streams/*.json') ?: []), is_file($rStore . 'seeded')];
			return 1;
		});
		$this->assertTrue(StreamRuntime::seed($rDb), 'a flush that failed does not stop it, as before');
		$this->assertSame([[['sync', '-f', rtrim($rStore, '/')], true, 3, false]], $rFlushes, 'once, every entry written and no marker yet');
		$this->assertTrue(StreamRuntime::seeded());
	}

	/** A node whose rows on MAIN pass the bound is not seeded: its readers keep MAIN's database. */
	public function testTheSeedRefusesMoreStreamsThanTheBound(): void {
		$rDb = $this->main();
		StreamRuntime::useLimits(2);
		$this->assertFalse(StreamRuntime::seed($rDb), 'three streams with state, two at most');
		$this->assertFalse(StreamRuntime::seeded());
		$this->assertSame([], StreamRuntime::ids(), 'nothing copied');
		StreamRuntime::useLimits(3);
		$this->assertTrue(StreamRuntime::seed($rDb));
		$this->assertSame([10, 12, 13], StreamRuntime::ids());
	}

	/**
	 * MAIN's rows are read outside the store's lock, so a writer never waits
	 * on MAIN's database; a write kept, or one that went to MAIN's row alone
	 * (the lapse after it), while they were read means they may lack it: the
	 * seed copies nothing, and the next one does.
	 */
	public function testTheSeedCopiesNothingWhenAWriteLandedWhileItRead(): void {
		$rDb = new QueryLogDb($this->main());
		$rDb->rBefore = static function (): void {
			// A writer while the seed reads: it takes the store's lock, which the seed does not hold.
			StreamRuntime::keep(['stream_id' => 10], ['pid' => 7], static fn (): bool => true);
		};
		$this->assertFalse(StreamRuntime::seed($rDb));
		$this->assertFalse(StreamRuntime::seeded());
		$this->assertSame(['pid' => 7], StreamRuntime::get(10), 'the write kept, not overwritten by the rows read before it');

		$rDb->rBefore = static function (): void {
			// A write that went to MAIN's row alone, after its read: it lapses the store once it landed.
			StreamRuntime::lapse();
		};
		$this->assertFalse(StreamRuntime::seed($rDb));
		$this->assertFalse(StreamRuntime::seeded());

		$rDb->rBefore = null;
		$this->assertTrue(StreamRuntime::seed($rDb));
		$this->assertEquals(4242, StreamRuntime::get(10)['pid'], 'MAIN\'s row, which holds every write now');
	}

	/**
	 * What a node kept unsent in mode 2 is not in MAIN's rows: no seed while
	 * it is, and resend() runs whether or not the readers take the store.
	 */
	public function testNoSeedWhileSomethingIsUnsent(): void {
		$rDb = $this->main();
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 2, EventSpool::STALE_AFTER + 10);
		$this->assertFalse(StreamStateWriter::update(10, $this->rSid, ['monitor_pid' => 99]));
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 1);
		$this->assertFalse(StreamRuntime::seed($rDb), 'MAIN has not heard it');
		$this->assertSame(1, StreamStateWriter::resend());
		$this->assertFalse(StreamRuntime::seed($rDb), 'sent, not yet delivered');
		array_map('unlink', glob($this->rDir . 'cluster/spool/p0/*.ndjson') ?: []);
		$this->assertTrue(StreamRuntime::seed($rDb), 'delivered: MAIN\'s rows hold it');
	}

	/** A seed that failed is not tried again by the same process for a while (each try may connect to MAIN's database). */
	public function testAProcessWhoseSeedFailedTriesAgainOnlyLater(): void {
		$rDb = new QueryLogDb($this->main());
		DatabaseFactory::set($rDb);
		$this->flows(NodeFlows::STREAMS, 1);
		mkdir($this->rDir . 'cluster/spool/p0', 0777, true);
		file_put_contents($this->rDir . 'cluster/spool/p0/0000000000000000001-1-0000.ndjson', "{}\n");
		$this->assertFalse(StreamRuntime::ready(), 'an event pending');
		unlink($this->rDir . 'cluster/spool/p0/0000000000000000001-1-0000.ndjson');
		$this->assertFalse(StreamRuntime::ready(), 'not tried again yet');
		$this->assertSame([], $rDb->rQueries);
		StreamRuntime::useDir($this->rDir . 'cluster/runtime/');
		$this->assertTrue(StreamRuntime::ready(), 'another process seeds it');
		$this->assertTrue(StreamRuntime::seeded());
	}

	public function testReadyIsSeededOrASeed(): void {
		DatabaseFactory::set($this->main());
		$this->flows(0, 1);
		$this->assertFalse(StreamRuntime::ready(), 'nothing to seed without STREAMS');
		$this->flows(NodeFlows::STREAMS, 1);
		$this->assertTrue(StreamRuntime::ready(), 'mode 1 seeds from MAIN\'s rows');
		$this->assertTrue(StreamRuntime::seeded());
		DatabaseFactory::reset();
		$this->flows(NodeFlows::STREAMS, 2);
		$this->assertTrue(StreamRuntime::ready(), 'seeded: mode 2 takes it');
	}

	/**
	 * A node born in mode 2 (lb_new_node_mode api) has no MAIN rows to read:
	 * it seeds from what it kept itself, keeps those entries, asks nothing of
	 * MAIN's database, and its readers take the store.
	 */
	public function testModeTwoSeedsFromWhatTheNodeKept(): void {
		$rDb = new QueryLogDb($this->main());
		DatabaseFactory::set($rDb);
		mkdir($this->rDir . 'cluster/runtime/streams', 0700, true);
		file_put_contents($this->rDir . 'cluster/runtime/streams/10.json', json_encode(['id' => 10, 'ssid' => 7, 'fields' => ['pid' => 4242], 'unsent' => []]));
		AgentUser::own($this->rDir . 'cluster/runtime'); // as the node's writers leave it
		$this->flows(NodeFlows::STREAMS, 2);
		$this->assertTrue(StreamRuntime::ready());
		$this->assertTrue(StreamRuntime::seeded());
		$this->assertSame(['pid' => 4242], StreamRuntime::get(10), 'the node\'s own entry is kept');
		$this->assertSame([], $rDb->rQueries, 'nothing asked of MAIN\'s database');
	}

	public function testAWriteWithStreamsOffGoesToMainsRowAndLapsesTheStore(): void {
		StreamStateWriter::update(10, $this->rSid, ['pid' => 1]);
		ContentSink::recordingState(3, 1);
		$this->markSeeded();
		$this->flows(NodeFlows::CONTENT, 1);
		$rDb = $this->recorder();
		$this->assertTrue(StreamStateWriter::update(10, $this->rSid, ['pid' => 2], $rDb));
		$this->assertSame([['UPDATE `streams_servers` SET `pid` = ? WHERE `stream_id` = ? AND `server_id` = ?', [2, 10, $this->rSid]]], $rDb->rQueries);
		$this->assertSame([true], $rDb->rSeeded, 'the store lapses once the write landed, not before');
		$this->assertFalse(StreamRuntime::seeded());
		$this->assertSame(['pid' => 1], StreamRuntime::get(10), 'not kept');
		// A recording's status written to MAIN's row alone: the node's no longer the latest.
		$this->flows(null);
		ContentSink::recordingState(3, 3, $rDb);
		$this->assertNull(StreamRuntime::recordingStatus(3));
	}

	/**
	 * Mode 1 with STREAMS on, the agent stopped: the write goes to MAIN's
	 * row, as before, and the writer answers what MAIN's row answered. The
	 * store keeps it but no longer follows (MAIN's row alone is whole): it
	 * lapses once the write landed, and nothing is left to resend, which
	 * would replay what MAIN already has.
	 */
	public function testModeOneWritesMainsRowWhenTheAgentTakesNoEventAndTheStoreLapses(): void {
		$this->markSeeded();
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 1, EventSpool::STALE_AFTER + 10);
		$rDb = $this->recorder();
		$this->assertTrue(StreamStateWriter::update(10, $this->rSid, ['pid' => 4242], $rDb), 'what MAIN\'s row answered');
		$this->assertFalse(StreamRuntime::seeded());
		$this->markSeeded();
		$this->assertTrue(ContentSink::workerPid(10, 'vframes', 31, $rDb));
		$this->assertFalse(StreamRuntime::seeded());
		$this->assertFalse(StreamStateWriter::update(10, $this->rSid, ['pid' => 4243], $this->recorder(false)), 'MAIN\'s row refused it');
		$this->assertSame([
			['UPDATE `streams_servers` SET `pid` = ? WHERE `stream_id` = ? AND `server_id` = ?', [4242, 10, $this->rSid]],
			['UPDATE `streams` SET `vframes_pid` = ? WHERE `id` = ?', [31, 10]],
		], $rDb->rQueries);
		$this->assertSame([true, true], $rDb->rSeeded, 'each lapses the store once its write landed, not before');
		$this->assertSame(['pid' => 4243, 'vframes_pid' => 31], StreamRuntime::get(10));
		$this->assertFileDoesNotExist($this->marker(), 'nothing unsent: MAIN\'s row has it');
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 1);
		$this->assertSame(0, StreamStateWriter::resend());
		$this->assertSame([], $this->spooled());
	}

	public function testMainAndModeZeroWriteAsBeforeAndKeepNothing(): void {
		$this->flows(null);
		$rDb = $this->recorder();
		StreamStateWriter::update(12, 3, ['pid' => 4242, 'stream_status' => 0], $rDb);
		StreamStateWriter::updateRow(77, ['progress_info' => ''], $rDb);
		ContentSink::workerPid(12, 'vframes', 5, $rDb);
		ContentSink::recordingState(1, 1, $rDb);
		$this->assertSame([
			['UPDATE `streams_servers` SET `pid` = ?, `stream_status` = ? WHERE `stream_id` = ? AND `server_id` = ?', [4242, 0, 12, 3]],
			['UPDATE `streams_servers` SET `progress_info` = ? WHERE `server_stream_id` = ?', ['', 77]],
			['UPDATE `streams` SET `vframes_pid` = ? WHERE `id` = ?', [5, 12]],
			['UPDATE `recordings` SET `status` = ? WHERE `id` = ?;', [1, 1]],
		], $rDb->rQueries);
		$this->assertDirectoryDoesNotExist($this->rDir . 'cluster/runtime', 'no store');
		$this->assertSame([], $this->spooled());
	}

	// ── Mode 2 keeps what the agent did not take ─────────────────────

	public function testModeTwoKeepsWhatTheAgentDidNotTakeAndSendsItOnceItIsBack(): void {
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 2, EventSpool::STALE_AFTER + 10);
		$rDb = $this->recorder();
		$this->assertFalse(StreamStateWriter::update(10, $this->rSid, ['pid' => 4242, 'current_source' => 'http://u:p@src.example/x'], $rDb), 'not sent');
		$this->assertFalse(ContentSink::workerPid(10, 'vframes', 31, $rDb));
		$this->assertFalse(ContentSink::recordingState(3, 1, $rDb));
		$this->assertSame([], $rDb->rQueries, 'mode 2 has no database to fall back to');
		$this->assertSame(['pid' => 4242, 'current_source' => 'http://***@src.example/x', 'vframes_pid' => 31], StreamRuntime::get(10), 'the node keeps it');
		$this->assertSame(1, StreamRuntime::recordingStatus(3));
		$this->assertSame(0, StreamStateWriter::resend(), 'nothing while the agent takes no event');

		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 2);
		StreamStateWriter::update(11, $this->rSid, ['pid' => 7]);
		$this->assertSame(1, StreamStateWriter::resend());
		$rEvents = $this->spooled();
		$this->assertSame(['stream.state', 'stream.state', 'stream.worker'], array_column($rEvents, 'type'));
		$this->assertSame(['stream_id' => 10, 'server_id' => $this->rSid, 'fields' => ['pid' => 4242, 'current_source' => 'http://***@src.example/x']], $rEvents[1]['d']);
		$this->assertSame(['stream_id' => 10, 'worker' => 'vframes', 'pid' => 31], $rEvents[2]['d']);
		$this->assertSame(0, StreamStateWriter::resend(), 'sent once');
		$this->assertFileDoesNotExist($this->marker());
	}

	/** A node in mode 2 whose agent takes no event writes no movie analysis: it has no database. */
	public function testModeTwoWritesNoMovieAnalysisWithoutTheAgent(): void {
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 2, EventSpool::STALE_AFTER + 10);
		$rDb = $this->recorder();
		$this->assertFalse(ContentSink::movieProperties(11, ['duration_secs' => 60], $rDb));
		$this->assertSame([], $rDb->rQueries);
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 2);
		$this->assertTrue(ContentSink::movieProperties(11, ['duration_secs' => 60], $rDb));
		$this->assertSame(['stream_id' => 11, 'props' => ['duration_secs' => 60]], $this->spooled()[0]['d']);
	}

	/**
	 * The unsent marker is written before the entry and goes only after a
	 * walk that left nothing unsent, and only if no write marked it again
	 * since: an entry holding unsent columns is never left without it.
	 */
	public function testTheUnsentMarkerOutlivesAWalkThatLeftSomethingUnsent(): void {
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT, 2, EventSpool::STALE_AFTER + 10);
		StreamStateWriter::update(10, $this->rSid, ['pid' => 1]);
		StreamStateWriter::update(11, $this->rSid, ['pid' => 2]);
		$this->assertFileExists($this->marker());
		$this->assertSame(1, StreamRuntime::resend(static fn (int $rID): bool => $rID !== 11), '11 not taken');
		$this->assertFileExists($this->marker(), 'the next walk looks again');
		$rMarker = $this->marker();
		$this->assertSame(1, StreamRuntime::resend(static function () use ($rMarker): bool {
			// A write marks it again during the walk (under the lock the walk holds).
			file_put_contents($rMarker, json_encode(['at' => time(), 'token' => 'another']));
			return true;
		}));
		$this->assertFileExists($this->marker(), 'marked again since the walk began');
		$this->assertSame(0, StreamRuntime::resend(static fn (): bool => true), 'nothing unsent left');
		$this->assertFileDoesNotExist($this->marker());
	}
}
