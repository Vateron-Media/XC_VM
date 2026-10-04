<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\EventIngest;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\RecordingFinalizer;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\QueryLogDb;

/**
 * Phase 5, content: recordings, worker pids and movie analysis. A legacy node
 * writes them into MAIN's database; a node with CONTENT / STREAMS on spools
 * P0 events, which MAIN applies only where the node is the owner. A finished
 * recording becomes exactly one VOD (RecordingFinalizer).
 */
final class ClusterContentTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	/** The store's directory before this test (the suite's own, tests/bootstrap.php). */
	private string $rRuntimeDir;

	/** @var list<int> */
	private array $rChanged = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec('CREATE TABLE `streams` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `type` int, `stream_display_name` text, `stream_source` text, `target_container` text, `year` text, `movie_properties` text, `rating` int, `read_native` int, `movie_symlink` int, `remove_subtitles` int, `transcode_profile_id` int, `order` int, `added` int, `category_id` text, `tv_archive_server_id` int, `tv_archive_pid` int, `vframes_server_id` int, `vframes_pid` int)');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`server_stream_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `server_id` int, `parent_id` int, `pid` int, `to_analyze` int, `stream_status` int, `progress_info` text, `monitor_pid` int, `current_source` text, `stream_started` int, `stream_info` text, `audio_codec` text, `video_codec` text, `resolution` int, `bitrate` int, `compatible` int)');
		$this->rDb->exec('CREATE TABLE `recordings` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `stream_id` int, `created_id` int, `category_id` text, `bouquets` text, `title` text, `description` text, `stream_icon` text, `start` int, `end` int, `source_id` int, `archive` int, `status` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `bouquets` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `bouquet_movies` text)');
		$this->rDb->query('INSERT INTO `recordings` (`id`, `stream_id`, `category_id`, `bouquets`, `title`, `description`, `start`, `end`, `source_id`, `status`) VALUES (1, 100, \'[3]\', \'[9]\', \'Match\', \'Final\', 1800000000, 1800003600, 5, 1), (2, 100, \'[]\', \'[]\', \'Other\', \'\', 1800000000, 1800003600, 6, 1)');
		$this->rDb->query('INSERT INTO `bouquets` (`id`, `bouquet_movies`) VALUES (9, \'[1]\')');
		$this->rDb->query('INSERT INTO `streams` (`id`, `type`, `movie_properties`, `tv_archive_server_id`, `vframes_server_id`, `order`) VALUES (100, 1, NULL, 5, 6, 7), (200, 2, \'{"name":"Film","duration_secs":1}\', NULL, NULL, 8), (300, 2, \'{"name":"Theirs"}\', NULL, NULL, 9)');
		$this->rDb->query('INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `pid`) VALUES (20, 200, 5, 1), (30, 300, 6, 1), (11, 100, 5, 0)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix(1800000000000);
		NodeRegistry::startEnrolment(5, '11111111-1111-4111-a111-111111111111', random_bytes(32), random_bytes(32), 1);
		NodeRegistry::update(5, ['state' => 'active', 'mode' => 1, 'flows' => NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT]);
		EventIngest::onStreamChanged(function (int $rID): void {
			$this->rChanged[] = $rID;
		});

		$this->rDir = sys_get_temp_dir() . '/xcvm-content-' . bin2hex(random_bytes(4));
		mkdir($this->rDir);
		EventSpool::useDir($this->rDir . '/spool/');
		// With STREAMS on the node keeps recording statuses: in this test's directory.
		$this->rRuntimeDir = StreamRuntime::dir();
		StreamRuntime::useDir($this->rDir . '/runtime/');
	}

	protected function tearDown(): void {
		EventIngest::onStreamChanged(null);
		EventSpool::useDir(null);
		StreamRuntime::useDir($this->rRuntimeDir);
		NodeFlows::usePath(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(int $rFlows): void {
		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => $rFlows, 'state' => 'active']));
		NodeFlows::usePath($this->rDir . '/flows.json');
	}

	private function val(string $rSql): mixed {
		$this->rDb->query($rSql);
		$rRow = $this->rDb->get_rows()[0] ?? [];
		return $rRow === [] ? null : reset($rRow);
	}

	/** @return list<array<string, mixed>> */
	private function spooled(): array {
		$rOut = [];
		$rFiles = glob($this->rDir . '/spool/p0/*.ndjson') ?: [];
		sort($rFiles);
		foreach ($rFiles as $rFile) {
			foreach (array_filter(explode("\n", (string) file_get_contents($rFile))) as $rLine) {
				$rOut[] = json_decode($rLine, true);
			}
		}
		return $rOut;
	}

	private function ingest(array ...$rEvents): array {
		$rNode = NodeRegistry::byServer(5);
		return EventIngest::ingest($rNode, 'p0', (int) $rNode['useq_p0'] + 1, $rEvents);
	}

	// ── Node side ────────────────────────────────────────────────────────

	public function testALegacyNodeWritesMainsDatabase(): void {
		$this->flows(0);
		ContentSink::recordingState(1, 3);
		ContentSink::workerPid(100, 'tv_archive', 4321);
		ContentSink::movieProperties(200, ['name' => 'Film', 'duration_secs' => 60]);
		$this->assertSame([3, 4321], [(int) $this->val('SELECT `status` FROM `recordings` WHERE `id` = 1'), (int) $this->val('SELECT `tv_archive_pid` FROM `streams` WHERE `id` = 100')]);
		$this->assertSame(['name' => 'Film', 'duration_secs' => 60], json_decode((string) $this->val('SELECT `movie_properties` FROM `streams` WHERE `id` = 200'), true));
		$this->assertSame([], $this->spooled());
	}

	public function testANodeWithTheFlowsOnSpoolsEvents(): void {
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT);
		ContentSink::recordingState(1, 3);
		ContentSink::workerPid(100, 'vframes', 77);
		ContentSink::movieProperties(200, ['name' => 'Renamed on the node', 'duration_secs' => 60, 'bitrate' => 900]);
		ContentSink::recordingDone(1, 5);
		$this->assertSame(1, (int) $this->val('SELECT `status` FROM `recordings` WHERE `id` = 1'), 'MAIN\'s database is not written');
		$rEvents = $this->spooled();
		$this->assertSame(['recording.state', 'stream.worker', 'vod.analysis', 'recording.state'], array_column($rEvents, 'type'));
		$this->assertSame(['duration_secs' => 60, 'bitrate' => 900], $rEvents[2]['d']['props'], 'only what an analysis sets');
		$this->assertSame(2, $rEvents[3]['d']['status']);
		$this->expectException(\InvalidArgumentException::class);
		ContentSink::workerPid(100, 'delay', 1);
	}

	// ── MAIN side ────────────────────────────────────────────────────────

	public function testRecordingStateAppliesToTheNodesOwnRecordings(): void {
		$rOut = $this->ingest(['type' => 'recording.state', 'd' => ['id' => 1, 'status' => 3]], ['type' => 'recording.state', 'd' => ['id' => 2, 'status' => 3]], ['type' => 'recording.state', 'd' => ['id' => 1, 'status' => 7]]);
		$this->assertSame([1, 2], [$rOut['applied'], $rOut['dropped']]);
		$this->assertSame([3, 1], [(int) $this->val('SELECT `status` FROM `recordings` WHERE `id` = 1'), (int) $this->val('SELECT `status` FROM `recordings` WHERE `id` = 2')]);
	}

	public function testWorkerPidsOnlyWhereTheWorkerRunsOnTheNode(): void {
		$rOut = $this->ingest(['type' => 'stream.worker', 'd' => ['stream_id' => 100, 'worker' => 'tv_archive', 'pid' => 55]], ['type' => 'stream.worker', 'd' => ['stream_id' => 100, 'worker' => 'vframes', 'pid' => 66]], ['type' => 'stream.worker', 'd' => ['stream_id' => 100, 'worker' => 'id', 'pid' => 1]]);
		$this->assertSame([1, 2], [$rOut['applied'], $rOut['dropped']]);
		$this->assertSame([55, 0], [(int) $this->val('SELECT `tv_archive_pid` FROM `streams` WHERE `id` = 100'), (int) $this->val('SELECT `vframes_pid` FROM `streams` WHERE `id` = 100')]);
		$this->assertSame([100], $this->rChanged, 'the stream cache is refreshed on MAIN');
	}

	public function testVodAnalysisMergesIntoMainsCopyForAMovieTheNodeHolds(): void {
		$rOut = $this->ingest(['type' => 'vod.analysis', 'd' => ['stream_id' => 200, 'props' => ['duration_secs' => 60, 'name' => 'Hijack']]], ['type' => 'vod.analysis', 'd' => ['stream_id' => 300, 'props' => ['duration_secs' => 1]]]);
		$this->assertSame([1, 1], [$rOut['applied'], $rOut['dropped']]);
		$this->assertSame(['name' => 'Film', 'duration_secs' => 60], json_decode((string) $this->val('SELECT `movie_properties` FROM `streams` WHERE `id` = 200'), true));
		$this->assertSame(['name' => 'Theirs'], json_decode((string) $this->val('SELECT `movie_properties` FROM `streams` WHERE `id` = 300'), true));
	}

	public function testStreamStateRefreshesTheCacheUnlessOnlyProgressChanged(): void {
		$this->ingest(['type' => 'stream.state', 'd' => ['ssid' => 11, 'fields' => ['progress_info' => '{}']]], ['type' => 'stream.state', 'd' => ['ssid' => 11, 'fields' => ['pid' => 9]]], ['type' => 'stream.state', 'd' => ['ssid' => 30, 'fields' => ['pid' => 9]]]);
		$this->assertSame([100], $this->rChanged);
	}

	public function testARecordingBecomesOneVod(): void {
		$rID = RecordingFinalizer::create(1, 5, 's:5:/images/' . str_repeat('a', 32) . '.jpg');
		$this->assertGreaterThan(300, $rID);
		$this->assertSame($rID, RecordingFinalizer::create(1, 5, null), 'asked again: the same VOD');
		$this->assertNull(RecordingFinalizer::create(2, 5, null), 'another node\'s recording');
		$this->assertSame(1, (int) $this->val('SELECT COUNT(*) FROM `streams` WHERE `type` = 2 AND `stream_display_name` = \'Match\''));
		$this->assertSame([VOD_PATH . $rID . '.mp4'], json_decode((string) $this->val('SELECT `stream_source` FROM `streams` WHERE `id` = ' . $rID), true));
		$rProps = json_decode((string) $this->val('SELECT `movie_properties` FROM `streams` WHERE `id` = ' . $rID), true);
		$this->assertSame(['s:5:/images/' . str_repeat('a', 32) . '.jpg', 3600], [$rProps['movie_image'], $rProps['duration_secs']]);
		$this->assertSame('[1,' . $rID . ']', $this->val('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = 9'));
		$this->assertSame(0, (int) $this->val('SELECT COUNT(*) FROM `streams_servers` WHERE `stream_id` = ' . $rID), 'not attached before the file exists');

		// The node converted it and reports status 2.
		$this->assertSame(1, $this->ingest(['type' => 'recording.state', 'd' => ['id' => 1, 'status' => 2]])['applied']);
		$this->ingest(['type' => 'recording.state', 'd' => ['id' => 1, 'status' => 2]]);
		$this->assertSame(1, (int) $this->val('SELECT COUNT(*) FROM `streams_servers` WHERE `stream_id` = ' . $rID . ' AND `server_id` = 5 AND `pid` = 1 AND `to_analyze` = 1'));
		$this->assertSame(2, (int) $this->val('SELECT `status` FROM `recordings` WHERE `id` = 1'));
	}

	public function testTwoOverlappingCallsForARecordingMakeOneVod(): void {
		// The node asked again while MAIN was still running its first request
		// (the agent gave up on it): the second call runs to its end just
		// before the first records its VOD.
		$rLog = new QueryLogDb($this->rDb);
		$rSecond = null;
		$rLog->rBefore = static function (string $rQuery) use ($rLog, &$rSecond): void {
			if (str_starts_with($rQuery, 'UPDATE `recordings` SET `created_id`')) {
				$rLog->rBefore = null;
				$rSecond = RecordingFinalizer::create(1, 5, null);
			}
		};
		DatabaseFactory::set($rLog);
		$rFirst = RecordingFinalizer::create(1, 5, null);
		DatabaseFactory::set($this->rDb);
		$this->assertIsInt($rSecond);
		$this->assertSame($rSecond, $rFirst, 'both get the VOD recorded first');
		$this->assertSame($rFirst, (int) $this->val('SELECT `created_id` FROM `recordings` WHERE `id` = 1'));
		$this->assertSame(1, (int) $this->val('SELECT COUNT(*) FROM `streams` WHERE `type` = 2 AND `stream_display_name` = \'Match\''), 'the other row is gone');
		$this->assertSame('[1,' . $rFirst . ']', $this->val('SELECT `bouquet_movies` FROM `bouquets` WHERE `id` = 9'), 'added to its bouquets once');
		$this->assertSame($rFirst, RecordingFinalizer::create(1, 5, null), 'asked again: the same VOD');
	}

	public function testAnIconFromElsewhereIsNotTaken(): void {
		$rID = RecordingFinalizer::create(1, 5, 'http://evil.example/x.jpg');
		$this->assertNull(json_decode((string) $this->val('SELECT `movie_properties` FROM `streams` WHERE `id` = ' . $rID), true)['movie_image']);
	}

	public function testStreamMonitorDerivesTheRowAsTheReconcileDoes(): void {
		$this->rDb->query('UPDATE `streams_servers` SET `monitor_pid` = 900, `stream_status` = 2 WHERE `server_stream_id` = 11');
		$rState = ['supervised' => true, 'running' => true, 'confirmed' => true, 'pid' => 4321, 'daemon_pid' => 900, 'source' => 'http://***@origin/1.ts', 'uptime_ms' => 5000, 'meta' => ['video_codec' => 'h264', 'audio_codec' => 'aac', 'height' => 720, 'bitrate_kbps' => 3000]];
		$rOut = $this->ingest(
			['type' => 'stream.monitor', 'd' => ['stream_id' => 100, 'state' => $rState]],
			['type' => 'stream.monitor', 'd' => ['stream_id' => 300, 'state' => $rState]], // node 6's stream
			['type' => 'stream.monitor', 'd' => ['stream_id' => 100, 'state' => ['supervised' => false]]]
		);
		$this->assertSame([1, 2], [$rOut['applied'], $rOut['dropped']]);
		$this->rDb->query('SELECT `pid`, `stream_status`, `video_codec`, `resolution`, `bitrate`, `current_source`, `stream_started` FROM `streams_servers` WHERE `server_stream_id` = 11');
		$rRow = $this->rDb->get_row();
		$this->assertSame([4321, 0, 'h264', 720, 3000, 'http://***@origin/1.ts'], [(int) $rRow['pid'], (int) $rRow['stream_status'], $rRow['video_codec'], (int) $rRow['resolution'], (int) $rRow['bitrate'], $rRow['current_source']]);
		$this->assertSame(1800000000 - 5, (int) $rRow['stream_started']);
		$this->assertSame([100], $this->rChanged);

		// Nothing new: nothing written, the cache is left alone.
		$this->ingest(['type' => 'stream.monitor', 'd' => ['stream_id' => 100, 'state' => $rState]]);
		$this->assertSame([100], $this->rChanged);

		// A row the panel stopped is not revived.
		$this->rDb->query('UPDATE `streams_servers` SET `monitor_pid` = NULL, `pid` = NULL, `stream_status` = 0 WHERE `server_stream_id` = 11');
		$this->assertSame(1, $this->ingest(['type' => 'stream.monitor', 'd' => ['stream_id' => 100, 'state' => $rState]])['dropped']);
	}

	// skip-on-panel: PHPUnit feeds an isolated test to its child PHP on stdin, and the
	// panel's PHP (ionCube Loader + opcache.enable_cli) segfaults on a script read from stdin.
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	#[\PHPUnit\Framework\Attributes\RunInSeparateProcess]
	#[\PHPUnit\Framework\Attributes\PreserveGlobalState(false)]
	public function testTheNodesReconcileLeavesTheStateToTheAgentsFeed(): void {
		define('SERVER_ID', 5);
		$this->rDb->query('UPDATE `streams_servers` SET `monitor_pid` = 900, `stream_status` = 2 WHERE `server_stream_id` = 11');
		$rStates = ['streams' => ['100' => ['supervised' => true, 'running' => true, 'confirmed' => true, 'pid' => 4321, 'daemon_pid' => 900]]];

		file_put_contents($this->rDir . '/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::STREAMS, 'state' => 'active', 'features' => ['fanout_events']]));
		NodeFlows::usePath($this->rDir . '/flows.json');
		$this->assertSame([100], \XcVm\Domain\Stream\StreamProcess::reconcileSupervised($rStates));
		$this->assertSame([], $this->spooled(), 'the agent\'s feed carries it');

		$this->flows(NodeFlows::STREAMS); // an agent that does not follow the feed
		\XcVm\Domain\Stream\StreamProcess::reconcileSupervised($rStates);
		$this->assertSame(['stream.state'], array_column($this->spooled(), 'type'));
	}

	public function testContentEventsNeedTheContentFlow(): void {
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_STREAMS]);
		$rOut = $this->ingest(['type' => 'recording.state', 'd' => ['id' => 1, 'status' => 3]], ['type' => 'vod.analysis', 'd' => ['stream_id' => 200, 'props' => ['bitrate' => 1]]]);
		$this->assertSame([0, 2], [$rOut['applied'], $rOut['dropped']]);
	}
}
