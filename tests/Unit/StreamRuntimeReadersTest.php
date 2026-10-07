<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\ConnectAudit;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Cluster\Redactor;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\ReplicaStreamCache;
use XcVm\Core\Cluster\SettingsAudit;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Cluster\SignalSink;
use XcVm\Core\Cluster\StreamRecords;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\LazyDatabaseHandler;
use XcVm\Domain\Stream\ContentSink;
use XcVm\Domain\Stream\NodeStreams;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Domain\Stream\StreamStateWriter;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\AgentUser;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;
use XcVm\Tests\Support\ReplicaFixture;

/**
 * The readers that take a stream's definition and its runtime state
 * together, from one joined row of MAIN's database (cluster plan, section
 * 10: a node in mode 2 reads no MAIN database): the monitor, proxy producer
 * and delay worker (StreamSource::nodeRow), the archive and thumbnail
 * workers (workerRow), the loopback start (plainRow), the created channel's
 * builder (createdRow, builtServerRow, channelRow), the RTMP callback, and
 * the lists cron:streams, cron:vod, cron:cleanup and the on-demand daemon
 * select (NodeStreams). Once the replica owns the streams and the node's own
 * store is seeded (mode 1 or 2 with STREAMS on), each answers from the R2
 * stream caches and the store, in the shape it had, with a database that
 * refuses every connect, and never connects. Mode 0 asks MAIN's database
 * exactly what it did.
 */
final class StreamRuntimeReadersTest extends TestCase {
	private string $rDir;

	private ReplicaFixture $rFixture;

	private TestDb $rDb;

	private int $rSid;

	private int $rOther;

	/** The database the readers get once the replica answers: it refuses every connect, and counts them. */
	private LazyDatabaseHandler $rRefusing;

	/** The store's directory before this test (the suite's own, tests/bootstrap.php). */
	private string $rRuntimeDir;

	protected function setUp(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$this->rSid = (int) SERVER_ID;
		$this->rOther = $this->rSid + 1;
		$this->rDir = sys_get_temp_dir() . '/xcvm-runtime-readers-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cluster/spool', 0777, true);
		mkdir($this->rDir . 'cache', 0777, true);
		$this->rFixture = new ReplicaFixture($this->rDir . 'cluster/');
		AgentUser::own($this->rDir);
		ReplicaApply::useDir($this->rFixture->dir());
		ReplicaApply::useConfigDir($this->rDir);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		EventSpool::useDir($this->rDir . 'cluster/spool/');
		$this->rRuntimeDir = StreamRuntime::dir();
		StreamRuntime::useDir($this->rDir . 'cluster/runtime/');
		AgentClient::useSocket($this->rDir . 'no-agent.sock');
		NodeRole::useMainBuild(false);
		$this->flows(null);
		$this->rDb = $this->main();
		DatabaseFactory::set($this->rDb);
		$GLOBALS['db'] = $this->rDb;
		$this->rRefusing = new class extends LazyDatabaseHandler {
			public int $rConnects = 0;

			public function db_connect(bool $migrate = false, ?bool $graceful = null) {
				$this->rConnects++;
				throw new \RuntimeException('MAIN\'s database refuses every connect');
			}
		};
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		unset($GLOBALS['db']);
		ReplicaApply::useDir(null);
		ReplicaApply::useConfigDir(null);
		EventSpool::useDir(null);
		StreamRuntime::useDir($this->rRuntimeDir);
		AgentClient::useSocket(null);
		SignalDispatcher::useSink(null);
		SettingsManager::set([]);
		NodeRole::useMainBuild(null);
		NodeFlows::usePath(null);
		SettingsAudit::useDir(false);
		ConnectAudit::useDir(false);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function flows(?int $rFlows, int $rMode = 1): void {
		$rFile = $this->rDir . 'cluster/flows.json';
		if ($rFlows === null) {
			@unlink($rFile);
		} else {
			file_put_contents($rFile, json_encode(['mode' => $rMode, 'flows' => $rFlows, 'state' => 'active']));
		}
		NodeFlows::usePath($rFile);
		clearstatcache();
	}

	/**
	 * MAIN's database: a live stream relayed on by the other server, with
	 * its archive and thumbnails recorded here (10); a movie (11) and an
	 * episode (17) to analyse; a stopped stream (12); a created channel
	 * built from its sources (13); a stream on the other server alone (14);
	 * a direct-proxy stream (15); a stream relayed from the other server
	 * that failed (16). One row for each filter a reader applies: a live
	 * stream not running with an analysis due (20); a live stream whose
	 * archive is recorded here with no days kept (21); a movie with no
	 * producer (22); a created channel with one of its two sources built
	 * (23); a created channel relayed from the other server (24); a direct
	 * source that is not proxied, with a pid (25). Recordings on 10: one
	 * airing, one recording, one elsewhere. One open TS viewer of 10 here.
	 */
	private function main(): TestDb {
		$rDb = new TestDb();
		foreach (['streams', 'streams_servers', 'recordings', 'profiles', 'streams_types', 'streams_arguments', 'streams_options', 'lines_live'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		$s = $this->rSid;
		$o = $this->rOther;
		$rNow = time();
		$rDb->exec("INSERT INTO `streams_types` VALUES (1, 'Live Streams', 'live', 'live', 1), (2, 'Movies', 'movie', 'movie', 0), (3, 'Created Live', 'created_live', 'live', 1), (5, 'TV Series', 'series', 'series', 0)");
		$rDb->exec("INSERT INTO `profiles` VALUES (7, 'hd', '{\"3\":{\"cmd\":\"-b:v 4M\"}}')");
		$rDb->exec("INSERT INTO `streams_arguments` VALUES (1, 'fetch', 'User Agent', 'shown in the form', 'http', 'user_agent', '-user_agent \"%s\"', 'text', 'VLC')");
		$rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`, `notes`, `transcode_profile_id`, `enable_transcode`, `tv_archive_server_id`, `tv_archive_duration`, `tv_archive_pid`, `vframes_server_id`, `vframes_pid`, `direct_source`, `direct_proxy`, `delay_minutes`, `fps_restart`, `target_container`, `movie_properties`) VALUES
			(10, 1, 'News', '[\"http://user:pass@src.example/a\",\"http://src.example/b\"]', 'notes', 7, 1, {$s}, 24, 5010, {$s}, 5011, 0, 0, 0, 1, NULL, NULL),
			(11, 2, 'Film', '[\"http://src.example/film.mkv\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 'mkv', '{\"duration_secs\":60}'),
			(12, 1, 'Stopped', '[\"http://src.example/c\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL),
			(13, 3, 'Channel', '[\"s:{$s}:/media/a.mp4\",\"s:{$s}:/media/b.mp4\"]', NULL, 7, 1, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL),
			(14, 1, 'Elsewhere', '[\"http://src.example/e\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL),
			(15, 1, 'Proxied', '[\"http://src.example/f\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 1, 1, 0, 0, NULL, NULL),
			(16, 1, 'Relayed', '[\"http://src.example/g\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, 5, 0, NULL, NULL),
			(17, 5, 'Episode', '[\"http://src.example/ep.mp4\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 'mp4', NULL),
			(20, 1, 'Due', '[\"http://src.example/h\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL),
			(21, 1, 'No days', '[\"http://src.example/i\"]', NULL, 0, 0, {$s}, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL),
			(22, 2, 'Queued', '[\"http://src.example/q.mkv\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 'mkv', NULL),
			(23, 3, 'Half built', '[\"s:{$s}:/media/c.mp4\",\"s:{$s}:/media/d.mp4\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL),
			(24, 3, 'Relayed channel', '[\"s:{$o}:/media/e.mp4\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, 0, NULL, NULL),
			(25, 1, 'Direct', '[\"http://src.example/j\"]', NULL, 0, 0, 0, 0, 0, 0, 0, 1, 0, 0, 0, NULL, NULL)");
		$rDb->exec("INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `parent_id`, `on_demand`, `pid`, `monitor_pid`, `stream_status`, `stream_started`, `stream_info`, `progress_info`, `current_source`, `bitrate`, `to_analyze`, `compatible`, `cc_info`, `cchannel_rsources`, `pids_create_channel`, `delay_pid`, `audio_codec`, `video_codec`, `resolution`, `ondemand_check`) VALUES
			(1, 10, {$s}, NULL, 1, 4242, 4243, 0, 1700000000, '{\"codecs\":{}}', '{\"fps\":25}', 'http://user:pass@src.example/a', 3000, 0, 1, NULL, NULL, NULL, NULL, 'aac', 'h264', 1080, 12),
			(2, 10, {$o}, {$s}, 0, 70, 71, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
			(3, 11, {$s}, NULL, 0, 99, NULL, 0, 1700000100, NULL, NULL, NULL, NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
			(4, 12, {$s}, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
			(5, 13, {$s}, NULL, 0, NULL, NULL, 0, 1700000200, NULL, NULL, NULL, NULL, 0, 0, '[{\"finish\":100}]', '[\"s:{$s}:/media/a.mp4\",\"s:{$s}:/media/b.mp4\"]', '[]', NULL, NULL, NULL, NULL, NULL),
			(6, 14, {$o}, NULL, 0, 1, 1, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
			(7, 15, {$s}, NULL, 0, 55, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
			(8, 16, {$s}, {$o}, 0, NULL, NULL, 1, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, 81, NULL, NULL, NULL, NULL),
			(9, 17, {$s}, NULL, 0, 88, NULL, 1, NULL, NULL, NULL, NULL, NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
			(10, 20, {$s}, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 1, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
			(11, 21, {$s}, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
			(12, 22, {$s}, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL),
			(13, 23, {$s}, NULL, 0, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, '[\"s:{$s}:/media/c.mp4\"]', '[]', NULL, NULL, NULL, NULL, NULL),
			(14, 24, {$s}, {$o}, 0, NULL, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, '[]', '[]', NULL, NULL, NULL, NULL, NULL),
			(15, 25, {$s}, NULL, 0, 66, NULL, 0, NULL, NULL, NULL, NULL, NULL, 0, 0, NULL, NULL, NULL, NULL, NULL, NULL, NULL, NULL)");
		$rDb->exec("INSERT INTO `recordings` (`id`, `stream_id`, `created_id`, `source_id`, `title`, `start`, `end`, `archive`, `status`) VALUES
			(1, 10, 0, {$s}, 'Airing', " . ($rNow - 100) . ', ' . ($rNow + 3600) . ", 0, 0),
			(2, 10, 0, {$s}, 'Recording', " . ($rNow - 100) . ', ' . ($rNow + 3600) . ", 0, 1),
			(3, 10, 0, {$o}, 'Elsewhere', " . ($rNow - 100) . ', ' . ($rNow + 3600) . ', 0, 0)');
		$rDb->exec("INSERT INTO `lines_live` (`uuid`, `user_id`, `stream_id`, `server_id`, `container`, `hls_end`) VALUES ('v1', 1, 10, {$s}, 'ts', 0), ('v2', 1, 10, {$s}, 'hls', 1)");
		return $rDb;
	}

	/** What every converted reader answers now. */
	private function answers(): array {
		$rOut = ['node' => [], 'server' => []];
		foreach ([10, 12, 13, 14, 16] as $rID) {
			$rOut['node'][$rID] = StreamSource::nodeRow($rID);
			$rOut['server'][$rID] = StreamSource::serverRow($rID);
		}
		$rOut += [
			'archive' => [10 => StreamSource::workerRow(10, 'tv_archive'), 12 => StreamSource::workerRow(12, 'tv_archive'), 21 => StreamSource::workerRow(21, 'tv_archive')],
			'thumbs' => [10 => StreamSource::workerRow(10, 'vframes'), 16 => StreamSource::workerRow(16, 'vframes')],
			'plain' => [10 => StreamSource::plainRow(10), 15 => StreamSource::plainRow(15), 25 => StreamSource::plainRow(25)],
			'created' => [13 => StreamSource::createdRow(13), 12 => StreamSource::createdRow(12)],
			'built' => [13 => StreamSource::builtServerRow(13), 16 => StreamSource::builtServerRow(16), 24 => StreamSource::builtServerRow(24)],
			'channel' => [13 => StreamSource::channelRow(13), 10 => StreamSource::channelRow(10)],
			'stream' => [10 => StreamSource::streamRow(10, true), 11 => StreamSource::streamRow(11, false)],
			'movie' => [11 => StreamSource::movieRow(11), 17 => StreamSource::movieRow(17), 10 => StreamSource::movieRow(10), 22 => StreamSource::movieRow(22)],
			'recording' => [1 => StreamSource::recording(1)],
			'live_redis' => self::byStream(NodeStreams::liveChecks(true)),
			'live_mysql' => self::byStream(NodeStreams::liveChecks(false)),
			'proxied' => NodeStreams::proxied(),
			'on_demand' => array_map('intval', NodeStreams::onDemandIDs()),
			'created_channels' => self::byStream(NodeStreams::createdChannels()),
			'due' => NodeStreams::recordingsDue(),
			'analysis_count' => NodeStreams::analysisCount(),
			'analysis' => self::byStream(NodeStreams::analysis(0)),
			'file_streams' => self::sorted(NodeStreams::fileStreams()),
			'archives' => NodeStreams::archives(),
			'created_ids' => NodeStreams::createdIDs(),
			'vod' => NodeStreams::vodChecks(),
			'built_channels' => NodeStreams::builtChannels(),
			'active' => array_map('intval', NodeStreams::activeOnDemand()),
			'attached' => NodeStreams::attached([10, 16]),
		];
		return $rOut;
	}

	/** @param list<array<string, mixed>> $rRows */
	private static function byStream(array $rRows): array {
		$rOut = [];
		foreach ($rRows as $rRow) {
			$rOut[(int) ($rRow['stream_id'] ?? $rRow['id'])] = $rRow;
		}
		ksort($rOut);
		return $rOut;
	}

	private static function sorted(?array $rList): ?array {
		if ($rList !== null) {
			sort($rList);
		}
		return $rList;
	}

	/** The section as the agent stores it, applied, and the node's store seeded from MAIN's rows (mode 1). */
	private function replica(int $rFlows = NodeFlows::STREAMS | NodeFlows::CONNECTIONS | NodeFlows::CONTENT, int $rMode = 1): void {
		foreach (StreamRecords::data($this->rSid, StreamRecords::held($this->rSid, null)) as $rID => $rData) {
			$this->rFixture->stream($rID, $rData, 3);
		}
		$this->rFixture->streamsSince(7);
		$this->flows($rFlows, 1);
		ReplicaApply::run(false, 1800000000, $this->rSid, false);
		// The node's user's, as cluster:apply leaves them (a root writer reads the index as that user).
		AgentUser::own($this->rDir . 'cache');
		$this->assertTrue(ReplicaStreamCache::owned());
		$this->assertTrue(StreamRuntime::seed($this->rDb), 'seeded from MAIN\'s rows');
		$this->flows($rFlows, $rMode);
		// From here on MAIN's database refuses every connect.
		DatabaseFactory::set($this->rRefusing);
		$GLOBALS['db'] = $this->rRefusing;
	}

	/** Scalars as strings, as a driver reads them, at any depth. */
	private static function loose(mixed $rValue): mixed {
		if (is_array($rValue)) {
			return array_map([self::class, 'loose'], $rValue);
		}
		return $rValue === null ? null : (string) $rValue;
	}

	/**
	 * The same shape and the same values: every key MAIN's answer has, and
	 * its value, but for what no record carries (MAIN's catalogue metadata,
	 * the rows' `updated`, `aes_pid`), which is null, and the current source,
	 * sanitised as a node with STREAMS on reports it.
	 */
	private function assertSameAnswer(mixed $rSql, mixed $rReplica, string $rWhat): void {
		if (!is_array($rSql) || !is_array($rReplica)) {
			$this->assertSame(self::loose($rSql), self::loose($rReplica), $rWhat);
			return;
		}
		$this->assertEqualsCanonicalizing(array_keys($rSql), array_keys($rReplica), $rWhat . ': the same keys');
		$rLocal = array_merge(array_diff(ReplicaSections::STREAM_LOCAL, StreamRuntime::WORKER_FIELDS), ['aes_pid', 'argument_description']);
		foreach ($rSql as $rKey => $rValue) {
			if (is_array($rValue)) {
				$this->assertSameAnswer($rValue, $rReplica[$rKey], $rWhat . '.' . $rKey);
			} elseif (in_array($rKey, $rLocal, true)) {
				$this->assertNull($rReplica[$rKey], $rWhat . '.' . $rKey . ': no record carries it');
			} elseif ($rKey === 'current_source' && is_string($rValue)) {
				$this->assertSame(Redactor::redact($rValue), $rReplica[$rKey], $rWhat . '.current_source');
			} else {
				$this->assertSame(self::loose($rValue), self::loose($rReplica[$rKey]), $rWhat . '.' . $rKey);
			}
		}
	}

	public function testEachReaderAnswersFromTheReplicaAndTheStoreAsMainsDatabaseDid(): void {
		$rSql = $this->answers();
		// What the readers select, spelled out once.
		$this->assertSame([10, 16, 20], array_keys($rSql['live_redis']), '20: an analysis due');
		$this->assertSame(1, (int) $rSql['live_redis'][10]['attached']);
		$this->assertSame(1, (int) $rSql['live_mysql'][10]['online_clients']);
		$this->assertSame([['id' => '15']], self::loose($rSql['proxied']), 'not 25, a direct source not proxied');
		$this->assertSame([10], $rSql['on_demand']);
		$this->assertSame([13, 23], array_keys($rSql['created_channels']), 'not 24, relayed from a parent');
		$this->assertSame([11, 17], array_keys($rSql['analysis']), 'not 20, a live stream');
		$this->assertSame(3, $rSql['analysis_count']);
		$this->assertSame([10, 12, 13, 15, 16, 20, 21, 23, 24, 25], $rSql['file_streams']);
		$this->assertSame([10 => '24'], self::loose($rSql['archives']), 'not 21, no days kept');
		$this->assertSame([13], array_map('intval', array_column($rSql['built_channels'], 'id')), 'not 23, half built, nor 24');
		$this->assertSame([11, 17], array_map('intval', array_column($rSql['vod'], 'id')), 'not 22, no producer');
		$this->assertSame([['id' => '1']], self::loose($rSql['due']), 'airing, neither recording nor done');
		$this->assertNull($rSql['node'][14], 'another server\'s stream');
		$this->assertNull($rSql['archive'][12]);
		$this->assertNull($rSql['archive'][21], 'no days kept');
		$this->assertNull($rSql['plain'][15], 'a direct source');
		$this->assertNull($rSql['plain'][25]);
		$this->assertNull($rSql['built'][16], 'relayed from a parent');
		$this->assertNull($rSql['built'][24]);
		$this->assertNull($rSql['channel'][10], 'not a created channel');
		$this->assertNull($rSql['movie'][10], 'not a movie');
		$this->assertNull($rSql['movie'][22], 'no producer');
		$this->assertNotNull($rSql['movie'][17], 'an episode');

		$this->replica();
		$rReplica = $this->answers();
		foreach ($rSql as $rReader => $rAnswer) {
			$this->assertSameAnswer($rAnswer, $rReplica[$rReader], $rReader);
		}
		// An index written before it carried the lists' filter columns (or a
		// stream the index lacks): every stream is read and filtered by its entry.
		ReplicaStreamCache::writeIndex(array_map(static fn (array $rMeta): array => array_intersect_key($rMeta, array_flip(['etag', 'ver', 'rec', 'ssid'])), ReplicaStreamCache::index()));
		$rUnindexed = $this->answers();
		foreach ($rSql as $rReader => $rAnswer) {
			$this->assertSameAnswer($rAnswer, $rUnindexed[$rReader], $rReader . ' (unindexed)');
		}
		$this->assertSame(0, $this->rRefusing->rConnects, 'MAIN\'s database was never asked');
		// The runtime state the replica alone never carried: now the node's.
		$this->assertSame('http://***@src.example/a', $rReplica['node'][10]['current_source']);
		$this->assertSame('[{"finish":100}]', $rReplica['server'][13]['cc_info'], 'a created channel restarted at its position');
		$this->assertEquals(5010, $rReplica['archive'][10]['tv_archive_pid']);
	}

	/**
	 * The node says in its audit report whether it reads its streams on
	 * itself: what MAIN asks before it moves the node to mode 2 past the
	 * connect audit (ClusterAdmin::modeGate), where it could not read them
	 * from MAIN any more.
	 */
	public function testTheAuditReportSaysWhetherTheStreamsAreReadHere(): void {
		SettingsAudit::useDir($this->rDir . 'misses/', $this->rDir . 'cluster/');
		ConnectAudit::useDir($this->rDir . 'sql_audit/');
		$rSays = function (): mixed {
			$this->assertTrue(SettingsAudit::publish());
			$rDoc = json_decode((string) file_get_contents($this->rDir . 'cluster/audit.json'), true);
			return array_key_exists('streams_local', $rDoc) ? $rDoc['streams_local'] : 'not said';
		};

		$this->flows(NodeFlows::CONFIG);
		$this->assertSame('not said', $rSays(), 'without the STREAMS flow the node keeps no store');
		$this->flows(NodeFlows::STREAMS | NodeFlows::CONTENT);
		$this->assertFalse($rSays(), 'the flow is on, the replica and the store are not there yet');

		$this->replica();
		$this->assertTrue(StreamRuntime::seededFor($this->rSid));
		$this->assertFalse(StreamRuntime::seededFor($this->rOther), 'a store copied from another server is not this one\'s');
		$this->assertFalse($rSays(), 'its streams are local, but it still boots from MAIN\'s database');
		// An apply built its settings and servers too: it boots from the replica (ReplicaBoot::ready()).
		FileCache::setCache(ReplicaApply::OWNED_CACHE, (array) FileCache::getCache(ReplicaApply::OWNED_CACHE) + ['settings' => 'e', 'servers' => 'e/e']);
		$this->assertTrue($rSays());

		// A write the store did not keep: no longer whole, and the node says so at once, unasked.
		$rRead = fn(): mixed => json_decode((string) file_get_contents($this->rDir . 'cluster/audit.json'), true)['streams_local'];
		StreamRuntime::lapse();
		$this->assertFalse($rRead());
		// And that it is whole again, as soon as it is seeded: in mode 2 it would
		// otherwise not report for an hour.
		$this->assertTrue(StreamRuntime::seed($this->rDb));
		$this->assertTrue($rRead());
		// So when a section is no longer the replica's, and when it is again.
		ReplicaApply::disownStreams();
		$this->assertFalse($rRead());
		unlink($this->rDir . 'cluster/audit.json');
		ReplicaApply::run(false, 1800000000, $this->rSid, false);
		$this->assertTrue(ReplicaStreamCache::owned());
		// Said again, unasked (the file is back). This apply ran with CONFIG off, which
		// hands the settings and servers back to MAIN's database: the node boots from MAIN.
		$this->assertFalse($rRead());
		FileCache::setCache(ReplicaApply::OWNED_CACHE, (array) FileCache::getCache(ReplicaApply::OWNED_CACHE) + ['settings' => 'e', 'servers' => 'e/e']);
		$this->assertTrue($rSays());
	}

	public function testTheReadersFollowWhatTheNodeWritesSince(): void {
		$this->replica();
		// A process that read no row names its stream by server_stream_id through the stream caches' index.
		StreamRuntime::useDir($this->rDir . 'cluster/runtime/');
		StreamStateWriter::updateRow(4, ['pid' => 321, 'stream_status' => 0]);
		ContentSink::workerPid(10, 'tv_archive', 6000);
		ContentSink::recordingState(1, 1);
		$this->assertSame(321, StreamSource::nodeRow(12)['pid']);
		$this->assertSame([10, 12, 16, 20], array_keys(self::byStream(NodeStreams::liveChecks(true))), 'a stream started since');
		$this->assertSame(6000, StreamSource::workerRow(10, 'tv_archive')['tv_archive_pid']);
		$this->assertSame(1, StreamSource::recording(1)['status'], 'the node\'s own status wins over its record\'s');
		$this->assertSame([], NodeStreams::recordingsDue(), 'a recording the node started is not started again');
		$this->assertSame(0, $this->rRefusing->rConnects);
		$this->assertCount(3, glob($this->rDir . 'cluster/spool/p0/*.ndjson') ?: [], 'each still reported to MAIN');
	}

	public function testModeTwoReadsTheSameWithoutAConnect(): void {
		$rSql = $this->answers();
		$this->replica(NodeFlows::STREAMS | NodeFlows::CONNECTIONS | NodeFlows::CONTENT | NodeFlows::CONFIG, 2);
		$this->assertTrue(NodeRole::refusesConnects());
		$rReplica = $this->answers();
		foreach (['node', 'archive', 'plain', 'built', 'movie', 'live_redis', 'proxied', 'created_channels', 'analysis_count', 'analysis', 'file_streams', 'archives', 'vod', 'built_channels', 'active'] as $rReader) {
			$this->assertSameAnswer($rSql[$rReader], $rReplica[$rReader], $rReader);
		}
		$this->assertSame(0, $this->rRefusing->rConnects);
	}

	/**
	 * A recording finished here: MAIN attaches its VOD to this node itself
	 * (RecordingFinalizer::finish, from the node's `recording.state`), which
	 * no event of the node's carries, and the node's store keeps that row's
	 * state as the recorder reports it done. cron:vod then analyses the VOD
	 * and the VOD relay serves it, from the replica and the store.
	 */
	public function testARecordingFinishedHereIsAnalysedAndServed(): void {
		$this->replica();
		$s = $this->rSid;
		$rBefore = NodeStreams::analysisCount();
		$this->assertTrue(ContentSink::recordingDone(2, $s, 18));
		// MAIN: the VOD, attached to this node as finish() inserts it; its record stored by the agent and applied.
		$this->rDb->exec("INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`, `direct_source`, `direct_proxy`, `target_container`) VALUES (18, 2, 'Recorded', '[]', 0, 0, 'mp4')");
		$this->rDb->exec("INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `parent_id`, `pid`, `to_analyze`) VALUES (30, 18, {$s}, NULL, 1, 1)");
		DatabaseFactory::set($this->rDb);
		foreach (StreamRecords::data($s, [18]) as $rID => $rData) {
			$this->rFixture->stream($rID, $rData, 4);
		}
		DatabaseFactory::set($this->rRefusing);
		ReplicaApply::run(false, 1800000100, $s, false);
		$this->assertTrue(StreamSource::local());
		$this->assertSame($rBefore + 1, NodeStreams::analysisCount());
		$this->assertContains(18, array_map('intval', array_column(NodeStreams::analysis(0), 'stream_id')));
		$this->assertSame(18, StreamSource::movieRow(18)['id'] ?? null, 'served with its producer');
		$this->assertSame(0, $this->rRefusing->rConnects);
	}

	/**
	 * StreamProcess's own lookups once the store answers: a stream's pids
	 * without a pid file, and its PHP monitor, from the store; and no cache
	 * signal for MAIN with STREAMS on (MAIN refreshes from the events).
	 */
	public function testStreamProcessTakesThePidsFromTheStore(): void {
		if (!defined('STREAMS_PATH')) {
			define('STREAMS_PATH', $this->rDir . 'streams/');
		}
		$this->replica();
		$rID = 987654;
		$this->assertFileDoesNotExist(STREAMS_PATH . $rID . '_.pid');
		$rMonitor = proc_open([PHP_BINARY, '-r', 'cli_set_process_title("XC_VM[' . $rID . ']"); sleep(30);'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		$this->assertIsResource($rMonitor);
		try {
			$rPid = proc_get_status($rMonitor)['pid'];
			for ($i = 0; $i < 100 && trim((string) @file_get_contents('/proc/' . $rPid . '/cmdline')) !== 'XC_VM[' . $rID . ']'; $i++) {
				usleep(20000);
			}
			StreamStateWriter::update($rID, $this->rSid, ['pid' => 4321, 'monitor_pid' => $rPid]);
			$rLookup = new \ReflectionMethod(StreamProcess::class, 'pidFromFileOrColumn');
			$this->assertSame(4321, $rLookup->invoke(null, $rID, 'pid', '_.pid'));
			$this->assertSame($rPid, $rLookup->invoke(null, $rID, 'monitor_pid', '_.monitor'));
			(new \ReflectionMethod(StreamProcess::class, 'killPhpMonitor'))->invoke(null, $rID);
			for ($i = 0; $i < 100 && proc_get_status($rMonitor)['running']; $i++) {
				usleep(20000);
			}
			$this->assertFalse(proc_get_status($rMonitor)['running'], 'its monitor, named by the store, stopped');
		} finally {
			proc_terminate($rMonitor, 9);
			proc_close($rMonitor);
		}
		$rSignals = new class implements SignalSink {
			/** @var list<array<string, mixed>> */
			public array $rRows = [];

			public function insert(array $rRows): bool {
				array_push($this->rRows, ...$rRows);
				return true;
			}

			public function pending(int $rServerID, string $rCustomData): bool {
				return false;
			}
		};
		SignalDispatcher::useSink($rSignals);
		SettingsManager::set(['enable_cache' => 1]);
		StreamProcess::updateStreams([10, 12]);
		$this->assertSame([], $rSignals->rRows, 'no cache signal: MAIN refreshes from the node\'s events');
		$this->assertSame(0, $this->rRefusing->rConnects);
	}

	public function testTheSupervisorReconcileReadsAndKeepsTheStore(): void {
		$this->replica();
		file_put_contents($this->rDir . 'cluster/flows.json', json_encode(['mode' => 1, 'flows' => NodeFlows::STREAMS | NodeFlows::CONNECTIONS, 'state' => 'active', 'features' => ['fanout_events']]));
		NodeFlows::usePath($this->rDir . 'cluster/flows.json');
		$rStates = ['streams' => [
			'10' => ['supervised' => true, 'running' => true, 'confirmed' => true, 'pid' => 5555, 'daemon_pid' => 900, 'meta' => ['height' => 720]],
			'12' => ['supervised' => true, 'running' => true, 'pid' => 1],
		]];
		$this->assertSame([10], StreamProcess::reconcileSupervised($rStates), '12 is stopped here: released');
		$this->assertSame(5555, StreamSource::nodeRow(10)['pid'], 'kept in the store');
		$this->assertSame(720, StreamSource::nodeRow(10)['resolution']);
		$this->assertSame([], glob($this->rDir . 'cluster/spool/p0/*.ndjson') ?: [], 'the agent\'s feed tells MAIN');
		$this->assertSame(0, $this->rRefusing->rConnects);
	}

	public function testWithoutASeededStoreTheReadersKeepMainsDatabase(): void {
		foreach (StreamRecords::data($this->rSid, StreamRecords::held($this->rSid, null)) as $rID => $rData) {
			$this->rFixture->stream($rID, $rData, 3);
		}
		$this->rFixture->streamsSince(7);
		$this->flows(NodeFlows::STREAMS, 1);
		ReplicaApply::run(false, 1800000000, $this->rSid, false);
		// An event the agent has not delivered: MAIN's row may lack it, so no seed.
		mkdir($this->rDir . 'cluster/spool/p0', 0777, true);
		file_put_contents($this->rDir . 'cluster/spool/p0/0000000000000000001-1-0000.ndjson', "{}\n");
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		$this->assertFalse(StreamSource::local());
		$this->assertSame(4242, (int) StreamSource::nodeRow(10)['pid']);
		$this->assertCount(1, array_filter($rLog->rQueries, static fn (string $rQ): bool => str_contains($rQ, 'INNER JOIN `streams_servers`')), 'MAIN\'s row');
		// A node in mode 2 cannot seed: its readers keep asking MAIN's database, which refuses them.
		unlink($this->rDir . 'cluster/spool/p0/0000000000000000001-1-0000.ndjson');
		$this->flows(NodeFlows::STREAMS, 2);
		$this->assertFalse(StreamSource::local());
		$this->assertFalse(StreamRuntime::seeded());
	}

	/**
	 * A stream's viewers with CONNECTIONS on: the agent's registry, every
	 * stream in one call; an agent from before it (405) is asked whether it
	 * holds an open viewer of each stream (the callers only ask whether
	 * there is any). A stream the agent did not answer for counts one, so it
	 * is never stopped for want of an answer. MAIN's database is not asked.
	 */
	public function testViewersComeFromTheAgentsRegistryWithConnectionsOn(): void {
		$this->replica();
		$rScript = $this->rDir . 'agent.php';
		file_put_contents($rScript, <<<'PHP'
			<?php
			[, $rSock, $rLog, $rReplies] = $argv;
			@unlink($rSock);
			$rServer = stream_socket_server('unix://' . $rSock, $rErrNo, $rErr);
			foreach (json_decode($rReplies, true) as [$rCode, $rBody]) {
				$rConn = @stream_socket_accept($rServer, 10);
				if ($rConn === false) {
					break;
				}
				$rRaw = '';
				while (!str_contains($rRaw, "\r\n\r\n") && ($rChunk = fread($rConn, 8192)) !== false && $rChunk !== '') {
					$rRaw .= $rChunk;
				}
				[$rHead, $rIn] = array_pad(explode("\r\n\r\n", $rRaw, 2), 2, '');
				$rLen = preg_match('/^Content-Length: (\d+)/mi', $rHead, $rM) ? (int) $rM[1] : 0;
				while (strlen($rIn) < $rLen && ($rChunk = fread($rConn, 8192)) !== false && $rChunk !== '') {
					$rIn .= $rChunk;
				}
				file_put_contents($rLog, strtok($rHead, "\r") . ' ' . $rIn . "\n", FILE_APPEND);
				fwrite($rConn, 'HTTP/1.1 ' . $rCode . " X\r\nContent-Type: application/json\r\nContent-Length: " . strlen($rBody) . "\r\nConnection: close\r\n\r\n" . $rBody);
				fclose($rConn);
			}
			PHP);
		$rViewers = function (array $rReplies): array {
			@unlink($this->rDir . 'requests.log');
			$rNull = ['file', '/dev/null', 'w'];
			$rAgent = proc_open([PHP_BINARY, $this->rDir . 'agent.php', $this->rDir . 'agent.sock', $this->rDir . 'requests.log', json_encode($rReplies)], [0 => ['file', '/dev/null', 'r'], 1 => $rNull, 2 => $rNull], $rPipes);
			for ($i = 0; $i < 100 && !file_exists($this->rDir . 'agent.sock'); $i++) {
				usleep(20000);
			}
			AgentClient::useSocket($this->rDir . 'agent.sock');
			try {
				return [NodeStreams::viewers([10, 16]), file($this->rDir . 'requests.log', FILE_IGNORE_NEW_LINES)];
			} finally {
				proc_terminate($rAgent);
				proc_close($rAgent);
				@unlink($this->rDir . 'agent.sock');
			}
		};

		$this->assertSame([[10 => 3, 16 => 0], ['POST /v1/conn/counts HTTP/1.0 {"stream_ids":[10,16]}']], $rViewers([[200, '{"counts":{"10":3,"16":0}}']]));
		$this->assertSame([[10 => 1, 16 => 0], [
			'POST /v1/conn/counts HTTP/1.0 {"stream_ids":[10,16]}',
			'POST /v1/conn/find HTTP/1.0 {"match":{"stream_id":10,"hls_end":0}}',
			'POST /v1/conn/find HTTP/1.0 {"match":{"stream_id":16,"hls_end":0}}',
		]], $rViewers([[405, '{}'], [200, '{"uuid":"v1","stream_id":10,"hls_end":0}'], [404, '{}']]), 'an agent from before counts');
		// One that stops answering is not asked again stream by stream, each a whole timeout.
		$this->assertSame([[10 => 1, 16 => 1], [
			'POST /v1/conn/counts HTTP/1.0 {"stream_ids":[10,16]}',
			'POST /v1/conn/find HTTP/1.0 {"match":{"stream_id":10,"hls_end":0}}',
		]], $rViewers([[405, '{}'], ['---', '{}'], [404, '{}']]), 'an unanswered find ends the pass');
		// No agent to answer: counted as watched.
		AgentClient::useSocket($this->rDir . 'gone.sock');
		$this->assertSame([10 => 1], NodeStreams::viewers([10]));
		$this->assertSame(0, $this->rRefusing->rConnects);
	}

	/** Mode 0 (and MAIN) asks MAIN's database exactly what the readers asked before. */
	public function testModeZeroAsksWhatItAlwaysDid(): void {
		$rLog = new QueryLogDb($this->rDb);
		DatabaseFactory::set($rLog);
		StreamSource::nodeRow(10);
		StreamSource::workerRow(10, 'tv_archive');
		StreamSource::workerRow(10, 'vframes');
		StreamSource::plainRow(10);
		StreamSource::createdRow(13);
		StreamSource::builtServerRow(13);
		StreamSource::channelRow(13);
		StreamSource::movieRow(11);
		NodeStreams::liveChecks(true);
		NodeStreams::liveChecks(false);
		NodeStreams::proxied();
		NodeStreams::onDemandIDs();
		NodeStreams::createdChannels();
		NodeStreams::recordingsDue();
		NodeStreams::analysisCount();
		NodeStreams::analysis(1000);
		NodeStreams::fileStreams();
		NodeStreams::archives();
		NodeStreams::createdIDs();
		NodeStreams::vodChecks();
		NodeStreams::builtChannels();
		$this->assertSame([
			'SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.server_id = ? WHERE t1.id = ?',
			'SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t1.id = t2.stream_id AND t2.server_id = t1.tv_archive_server_id WHERE t1.`id` = ? AND t1.`tv_archive_server_id` = ? AND t1.`tv_archive_duration` > 0',
			'SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t1.id = t2.stream_id AND t2.server_id = t1.vframes_server_id WHERE t1.`id` = ? AND t1.`vframes_server_id` = ?',
			'SELECT * FROM `streams` WHERE direct_source = 0 AND id = ?',
			'SELECT * FROM `streams` t1 LEFT JOIN `profiles` t3 ON t1.transcode_profile_id = t3.profile_id WHERE t1.`id` = ?',
			'SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ? AND `parent_id` IS NULL',
			'SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t1.type = 3 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?',
			"SELECT t1.* FROM `streams` t1 INNER JOIN `streams_servers` t2 ON t2.stream_id = t1.id AND t2.pid IS NOT NULL AND t2.server_id = ? INNER JOIN `streams_types` t3 ON t3.type_id = t1.type AND t3.type_key IN ('movie', 'series') WHERE t1.`id` = ?",
			'SELECT t2.stream_display_name, t2.delay_minutes, t1.stream_started, t1.stream_info, t2.fps_restart, t1.stream_status, t1.progress_info, t1.stream_id, t1.monitor_pid, t1.on_demand, t1.server_stream_id, t1.pid, servers_attached.attached, t2.vframes_server_id, t2.vframes_pid, t2.tv_archive_server_id, t2.tv_archive_pid FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type LEFT JOIN (SELECT `stream_id`, COUNT(*) AS `attached` FROM `streams_servers` WHERE `parent_id` = ? AND `pid` IS NOT NULL AND `pid` > 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0 GROUP BY `stream_id`) AS `servers_attached` ON `servers_attached`.`stream_id` = t1.`stream_id` WHERE (t1.pid IS NOT NULL OR t1.stream_status <> 0 OR t1.to_analyze = 1) AND t1.server_id = ? AND t3.live = 1',
			"SELECT t2.stream_display_name, t2.delay_minutes, t1.stream_started, t1.stream_info, t2.fps_restart, t1.stream_status, t1.progress_info, t1.stream_id, t1.monitor_pid, t1.on_demand, t1.server_stream_id, t1.pid, clients.online_clients, clients_hls.online_clients_hls, servers_attached.attached, t2.vframes_server_id, t2.vframes_pid, t2.tv_archive_server_id, t2.tv_archive_pid FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type LEFT JOIN (SELECT stream_id, COUNT(*) as online_clients FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY stream_id) AS clients ON clients.stream_id = t1.stream_id LEFT JOIN (SELECT `stream_id`, COUNT(*) AS `attached` FROM `streams_servers` WHERE `parent_id` = ? AND `pid` IS NOT NULL AND `pid` > 0 AND `monitor_pid` IS NOT NULL AND `monitor_pid` > 0 GROUP BY `stream_id`) AS `servers_attached` ON `servers_attached`.`stream_id` = t1.`stream_id` LEFT JOIN (SELECT stream_id, COUNT(*) as online_clients_hls FROM `lines_live` WHERE `server_id` = ? AND `container` = 'hls' AND `hls_end` = 0 GROUP BY stream_id) AS clients_hls ON clients_hls.stream_id = t1.stream_id WHERE (t1.pid IS NOT NULL OR t1.stream_status <> 0 OR t1.to_analyze = 1) AND t1.server_id = ? AND t3.live = 1",
			'SELECT `streams`.`id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`direct_source` = 1 AND `streams`.`direct_proxy` = 1 AND `streams_servers`.`server_id` = ? AND `streams_servers`.`pid` > 0;',
			'SELECT `stream_id` FROM `streams_servers` WHERE `on_demand` = 1 AND `server_id` = ?;',
			'SELECT * FROM `streams` t1 INNER JOIN `streams_servers` t3 ON t3.stream_id = t1.id LEFT JOIN `profiles` t2 ON t2.profile_id = t1.transcode_profile_id WHERE t1.type = 3 AND t3.server_id = ? AND t3.parent_id IS NULL;',
			'SELECT `id` FROM `recordings` WHERE `status` NOT IN (1,2) AND `source_id` = ? AND ((`start` <= UNIX_TIMESTAMP() AND `end` > UNIX_TIMESTAMP()) OR (`archive` = 1));',
			'SELECT COUNT(*) AS `count` FROM `streams_servers` WHERE `to_analyze` = 1 AND `server_id` = ?',
			'SELECT t1.*,t2.* FROM `streams_servers` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id AND t2.direct_source = 0 INNER JOIN `streams_types` t3 ON t3.type_id = t2.type AND t3.live = 0 WHERE t1.to_analyze = 1 AND t1.server_id = ? LIMIT 1000, 1000',
			'SELECT `id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` IN (1,3,4) AND `streams_servers`.`server_id` = ?;',
			'SELECT `id`, `tv_archive_duration` FROM `streams` WHERE `type` = 1 AND `tv_archive_server_id` = ? AND `tv_archive_duration` > 0;',
			'SELECT `id` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`type` = 3 AND `streams_servers`.`server_id` = ?;',
			'SELECT `server_stream_id`, `id`, `target_container`, `movie_properties`, `stream_status` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `server_id` = ? AND `type` IN (2,5) AND `streams`.`direct_source` = 0 AND `streams_servers`.`pid` > 0;',
			"SELECT `id`, `stream_display_name`, `server_stream_id` FROM `streams` t1 INNER JOIN `streams_servers` t3 ON t3.stream_id = t1.id LEFT JOIN `profiles` t2 ON t2.profile_id = t1.transcode_profile_id WHERE t1.type = 3 AND t3.server_id = ? AND JSON_CONTAINS(t3.cchannel_rsources, t1.stream_source) AND JSON_CONTAINS(t1.stream_source, t3.cchannel_rsources) AND t3.pids_create_channel = '[]';",
		], $rLog->rQueries);
		$this->assertDirectoryDoesNotExist($this->rDir . 'cluster/runtime', 'no store');
	}

	/**
	 * The readers that took a joined row of MAIN's database now take it
	 * through the seam (the SQL moved into it, the query mode 0 runs); the
	 * streaming endpoints keep theirs for mode 0 and ask the replica first.
	 */
	public function testTheReadersNoLongerQueryTheJoinedRowThemselves(): void {
		$rRoot = MAIN_HOME;
		foreach ([
			'Cli/Commands/MonitorCommand.php', 'Cli/Commands/ProxyCommand.php', 'Cli/Commands/DelayCommand.php', 'Cli/Commands/ArchiveCommand.php',
			'Cli/Commands/ThumbnailCommand.php', 'Cli/Commands/CreatedCommand.php', 'Cli/CronJobs/StreamsCronJob.php', 'Cli/CronJobs/VodCronJob.php',
			'Cli/CronJobs/CleanupCronJob.php', 'Public/admin/vod.php',
		] as $rFile) {
			$this->assertStringNotContainsString('`streams_servers`', (string) file_get_contents($rRoot . $rFile), $rFile);
		}
		foreach (['Public/stream/rtmp.php', 'Public/admin/live.php', 'Public/admin/timeshift.php'] as $rFile) {
			$rSource = (string) file_get_contents($rRoot . $rFile);
			$this->assertMatchesRegularExpression('/if \(\$rLocal\) \{\n\t*\$rChannelInfo = StreamSource::joined\(|if \(StreamSource::local\(\)\) \{\n\t*\$rChannelInfo = StreamSource::joined\(/', $rSource, $rFile . ': the replica first');
			$this->assertSame(1, substr_count($rSource, 'INNER JOIN `streams_servers`'), $rFile . ': MAIN\'s row only where the replica does not answer');
		}
		$rProcess = (string) file_get_contents($rRoot . 'Domain/Stream/StreamProcess.php');
		foreach (['createChannelItem', 'startLoopback'] as $rFunction) {
			preg_match('/function ' . $rFunction . '\(.*?\n\t}\n/s', $rProcess, $rBody);
			$this->assertStringNotContainsString('->query(', $rBody[0] ?? 'missing', $rFunction);
		}
	}
}
