<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\ReplicaApply;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Cluster\ReplicaStreamCache;
use XcVm\Core\Cluster\StreamRecords;
use XcVm\Core\Cluster\StreamRuntime;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Util\StreamUtils;
use XcVm\Domain\Stream\NodeStreams;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * A stream start reads its row, its sources and its options without the
 * HTML escaping of the row cleaner: a `<`, a `>` or an `&` in a source, a
 * header or an option reaches the command as it was saved, where get_row()
 * hands `&lt;` and `&gt;` and rewrites what looks like a numeric entity.
 * Every other value is read as before (the blanks around it trimmed, its
 * line ends as LF), so its command is the same; and a node that takes its
 * streams from its replica (StreamRecords) starts with the same text as
 * one that reads the database.
 *
 * The database is read with Database's own readers (a DatabaseHandler over
 * the test server), as a panel reads it.
 */
final class AuditDecisionRawStreamTest extends TestCase {
	private const SOURCE = 'http://src.example/live.ts?tag=<a>&n=1';

	private const HEADERS = "X-Tag: <a>&b\nX-Two: 2";

	/** The cleaner hands `&#38;` for its `&amp;#38;`. */
	private const AGENT = 'Agent <1> &amp;#38;';

	private const FILE = 's:1:/films/Tom & <Jerry>.mkv';

	private string $rDir;

	private TestDb $rSeed;

	/** Database's own readers over the test server; it keeps every statement it is asked (rAsked). */
	private DatabaseHandler $rDb;

	private int $rSid;

	/** The store's directory before this test (the suite's own, tests/bootstrap.php). */
	private string $rRuntimeDir;

	public static function setUpBeforeClass(): void {
		foreach (['SERVER_ID' => 5, 'STREAMS_PATH' => '/tmp/xcvm-test-streams/', 'DELAY_PATH' => '/tmp/xcvm-test-delay/'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
	}

	protected function setUp(): void {
		$this->rSid = (int) SERVER_ID;
		// No replica here: every reader asks the database.
		$this->rDir = sys_get_temp_dir() . '/xcvm-raw-stream-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'cache', 0777, true);
		ReplicaApply::useDir($this->rDir);
		ReplicaApply::useConfigDir($this->rDir);
		NodeFlows::usePath($this->rDir . 'flows.json');
		$this->rRuntimeDir = StreamRuntime::dir();
		StreamRuntime::useDir($this->rDir . 'runtime/');
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		StreamSource::useLoader(null);

		$this->rSeed = new TestDb();
		foreach (['streams', 'streams_servers', 'recordings', 'profiles', 'streams_types', 'streams_arguments', 'streams_options'] as $rTable) {
			$this->rSeed->exec(InstallSchema::table($rTable));
		}
		$this->rSeed->exec("INSERT INTO `streams_types` VALUES (1, 'Live Streams', 'live', 'live', 1), (2, 'Movies', 'movie', 'movie', 0), (3, 'Created Live', 'created_live', 'live', 1)");
		$this->rSeed->exec("INSERT INTO `profiles` VALUES (7, 'hd', '{\"3\":{\"cmd\":\"-b:v 4M\"}}')");
		// The install's own definitions of the three options.
		$this->rSeed->exec("INSERT INTO `streams_arguments` VALUES
			(1, 'fetch', 'User Agent', 'Set a Custom User Agent', 'http', 'user_agent', '-user_agent \\\"%s\\\"', 'text', 'Mozilla/5.0'),
			(17, 'fetch', 'Cookie', 'Set an HTTP Cookie', 'http', 'cookie', '-cookies \\'%s\\'', 'text', NULL),
			(19, 'fetch', 'Headers', 'Set Custom Headers', 'http', 'headers', '-headers \\'%s\\'', 'text', NULL)");
		// 10: a live stream whose source, headers and user agent hold `<`, `>` and
		// `&`, its archive and thumbnails recorded here. 11: an ordinary one, its
		// user agent stored with blanks around it and its headers with a CRLF.
		// 13: a created channel and 14: a movie running here, both from a file
		// with `<` and `>` in its name.
		$rInsert = 'INSERT INTO `streams` (`id`, `type`, `stream_display_name`, `stream_source`, `transcode_profile_id`, `enable_transcode`, `tv_archive_server_id`, `tv_archive_duration`, `vframes_server_id`, `target_container`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)';
		$this->rSeed->query($rInsert, 10, 1, 'Stored', json_encode([self::SOURCE], JSON_UNESCAPED_SLASHES), 0, 0, $this->rSid, 1, $this->rSid, null);
		$this->rSeed->query($rInsert, 11, 1, 'Ordinary', json_encode(['http://user:pass@src.example/b.ts?x=1&y=2']), 7, 1, 0, 0, 0, null);
		$this->rSeed->query($rInsert, 13, 3, 'Channel', json_encode([self::FILE], JSON_UNESCAPED_SLASHES), 7, 0, 0, 0, 0, null);
		$this->rSeed->query($rInsert, 14, 2, 'Film', json_encode([self::FILE], JSON_UNESCAPED_SLASHES), 0, 0, 0, 0, 0, 'mkv');
		$rInsert = 'INSERT INTO `streams_servers` (`server_stream_id`, `stream_id`, `server_id`, `parent_id`, `on_demand`, `pid`, `stream_status`, `current_source`, `bitrate`, `cchannel_rsources`) VALUES (?, ?, ?, NULL, 0, ?, 0, ?, ?, ?)';
		$this->rSeed->query($rInsert, 1, 10, $this->rSid, 4242, self::SOURCE, 3000, null);
		$this->rSeed->query($rInsert, 2, 11, $this->rSid, 4243, 'http://user:pass@src.example/b.ts?x=1&y=2', 2500, null);
		$this->rSeed->query($rInsert, 3, 13, $this->rSid, 4244, null, null, json_encode([self::FILE], JSON_UNESCAPED_SLASHES));
		$this->rSeed->query($rInsert, 4, 14, $this->rSid, 4245, null, null, null);
		$rInsert = 'INSERT INTO `streams_options` (`stream_id`, `argument_id`, `value`) VALUES (?, ?, ?)';
		$this->rSeed->query($rInsert, 10, 1, self::AGENT);
		$this->rSeed->query($rInsert, 10, 19, self::HEADERS);
		$this->rSeed->query($rInsert, 11, 1, "  curl/8 \n");
		$this->rSeed->query($rInsert, 11, 19, "X-One: 1\r\nX-Two: 2");

		$this->rDb = new class(TestDb::connect($this->rSeed->schema())) extends DatabaseHandler {
			/** @var list<list<mixed>> every statement asked, with its values */
			public array $rAsked = [];

			public function __construct(\PDO $rPdo) {
				$this->dbh = $rPdo;
			}

			public function query(string $query, mixed $buffered = false) {
				$this->rAsked[] = func_get_args();
				return parent::query(...func_get_args());
			}
		};
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		$this->rDb->close_mysql();
		StreamSource::useLoader(null);
		ReplicaApply::useDir(null);
		ReplicaApply::useConfigDir(null);
		NodeFlows::usePath(null);
		StreamRuntime::useDir($this->rRuntimeDir);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * What a start reads of a stream: its row, this node's row and its options.
	 *
	 * @return array{stream_info: array<string, mixed>, server_info: array<string, mixed>, stream_arguments: list<array<string, mixed>>}
	 */
	private function start(int $rStreamID): array {
		$rStream = StreamSource::streamRow($rStreamID, true);
		$rServer = StreamSource::serverRow($rStreamID);
		$this->assertNotNull($rStream);
		$this->assertNotNull($rServer);
		return ['stream_info' => $rStream, 'server_info' => $rServer, 'stream_arguments' => StreamSource::arguments($rStreamID)];
	}

	/**
	 * The ffmpeg line of a start, put together as startStream() does: the
	 * first source, the fetch options of its protocol, and buildLive().
	 *
	 * @param array{stream_info: array<string, mixed>, server_info: array<string, mixed>, stream_arguments: list<array<string, mixed>>} $rStream
	 */
	private function command(array $rStream): string {
		[$rSource] = json_decode((string) $rStream['stream_info']['stream_source'], true);
		$rStreamSource = StreamUtils::parseStreamURL($rSource, '');
		$rProtocol = strtolower(substr($rStreamSource, 0, (int) strpos($rStreamSource, '://')));
		$rBuild = new \ReflectionMethod(StreamProcess::class, 'buildLive');
		$rBuild->setAccessible(true);
		return $rBuild->invoke(null, [
			'stream' => $rStream,
			'settings' => ['ffmpeg_warnings' => 0, 'read_native_hls' => 0, 'dts_legacy_ffmpeg' => 0, 'ignore_keyframes' => 0, 'live_streaming_pass' => 'secret'],
			'servers' => [$this->rSid => ['rtmp_port' => 1935]],
			'streamID' => (int) $rStream['stream_info']['id'], 'streamSource' => $rStreamSource,
			'fetchOptions' => implode(' ', StreamUtils::getArguments($rStream['stream_arguments'], $rProtocol, 'fetch')),
			'ffprobe' => ['container' => 'mpegts', 'codecs' => ['video' => ['codec_name' => 'h264'], 'audio' => ['codec_name' => 'aac']]],
			'protocol' => $rProtocol, 'source' => $rSource,
			'segmentSettings' => ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4], 'externalPush' => [],
			'probesize' => (int) $rStream['stream_info']['probesize_ondemand'], 'analyseDuration' => 500000,
			'llod' => false, 'loopback' => false, 'segmentStart' => 0, 'delayActive' => false,
			'ffmpegCpu' => '/bin/ffmpeg', 'ffmpegGpu' => '/bin/ffmpeg-gpu',
		]);
	}

	/**
	 * A statement's rows through the row cleaner, as every reader took them before.
	 *
	 * @param list<mixed> $rAsked
	 * @return list<array<string, mixed>>
	 */
	private function cleaned(array $rAsked): array {
		$this->assertTrue($this->rDb->query(...$rAsked));
		return $this->rDb->get_rows();
	}

	/** A value's numbers as text, whichever way the driver or the cleaner typed them. */
	private static function text(mixed $rValue): mixed {
		if (is_array($rValue)) {
			return array_map([self::class, 'text'], $rValue);
		}
		return $rValue === null ? null : (string) $rValue;
	}

	public function testAStartReadsItsSourceItsHeadersAndItsOptionsAsStored(): void {
		$this->assertFalse(ReplicaStreamCache::owned(), 'the database is what answers');
		$rStream = $this->start(10);
		$this->assertSame([self::SOURCE], json_decode($rStream['stream_info']['stream_source'], true));
		$this->assertSame(self::SOURCE, $rStream['server_info']['current_source'], 'the source it runs, to find among its sources');
		$this->assertSame(['user_agent' => self::AGENT, 'headers' => self::HEADERS], array_column($rStream['stream_arguments'], 'value', 'argument_key'));
		$this->assertSame(['user_agent' => self::AGENT, 'headers' => self::HEADERS], array_map(static fn(array $rRow): string => $rRow['value'], StreamSource::arguments(10, true)), 'keyed by the option, as the proxy producer and the hand-off take them');

		$rCommand = $this->command($rStream);
		$this->assertStringContainsString(' -i ' . escapeshellarg(self::SOURCE) . ' ', $rCommand);
		$this->assertStringContainsString(" -headers '" . self::HEADERS . "' ", $rCommand);
		$this->assertStringContainsString(' -user_agent "' . self::AGENT . '" ', $rCommand);
		$this->assertStringNotContainsString('&lt;', $rCommand);
		$this->assertStringNotContainsString('&gt;', $rCommand);
	}

	public function testEveryReaderOfAStreamsDefinitionHandsItAsStored(): void {
		$rLive = json_encode([self::SOURCE], JSON_UNESCAPED_SLASHES);
		$rFile = json_encode([self::FILE], JSON_UNESCAPED_SLASHES);
		// The monitor, the proxy producer and the delay worker; the hand-off to the daemon; the loopback start.
		$this->assertSame([$rLive, self::SOURCE], [StreamSource::nodeRow(10)['stream_source'], StreamSource::nodeRow(10)['current_source']]);
		$this->assertSame(['stream_source' => $rLive], StreamSource::sourceRow(10));
		$this->assertSame($rLive, StreamSource::plainRow(10)['stream_source']);
		// The archive and thumbnail workers.
		$this->assertSame($rLive, StreamSource::workerRow(10, 'tv_archive')['stream_source']);
		$this->assertSame($rLive, StreamSource::workerRow(10, 'vframes')['stream_source']);
		// The created channel's builder: what is left to encode is its sources less those it has encoded.
		$this->assertSame($rFile, StreamSource::createdRow(13)['stream_source']);
		$this->assertSame($rFile, StreamSource::channelRow(13)['stream_source']);
		$this->assertSame($rFile, StreamSource::builtServerRow(13)['cchannel_rsources']);
		// The VOD relay.
		$this->assertSame($rFile, StreamSource::movieRow(14)['stream_source']);
		$this->assertSame($rFile, StreamSource::streamRow(14, false)['stream_source']);
	}

	/** The on-demand scanner probes the source the start opens: read as stored as well. */
	public function testTheOnDemandScanReadsTheSourceAsStored(): void {
		$this->rSeed->exec(InstallSchema::table('ondemand_check'));
		$this->rSeed->query('UPDATE `streams_servers` SET `pid` = NULL, `on_demand` = 1 WHERE `stream_id` = 10');

		$rDue = array_column(NodeStreams::onDemandDue(60, $this->rDb), null, 'id');

		$this->assertSame(json_encode([self::SOURCE], JSON_UNESCAPED_SLASHES), $rDue[10]['stream_source'] ?? null);
	}

	public function testAnOrdinaryStreamIsReadAndStartedAsBefore(): void {
		$rNow = $this->start(11);
		$rAsked = $this->rDb->rAsked;
		$this->assertCount(3, $rAsked, 'its row, this node\'s row, its options');
		// The same three statements through the row cleaner.
		$rBefore = ['stream_info' => $this->cleaned($rAsked[0])[0], 'server_info' => $this->cleaned($rAsked[1])[0], 'stream_arguments' => $this->cleaned($rAsked[2])];
		$this->assertSame(['curl/8', "X-One: 1\nX-Two: 2"], array_column($rBefore['stream_arguments'], 'value'), 'the blanks around a value were trimmed, its line ends LF');

		$this->assertSame(self::text($rBefore), self::text($rNow), 'the same columns and the same values');
		$this->assertSame($this->command($rBefore), $this->command($rNow), 'the same command, byte for byte');
		$this->assertStringContainsString(' -user_agent "curl/8" ', $this->command($rNow));
		$this->assertStringContainsString(" -headers 'X-One: 1\nX-Two: 2' ", $this->command($rNow));
		$this->assertSame(self::text(array_column($rBefore['stream_arguments'], null, 'argument_key')), self::text(StreamSource::arguments(11, true)));
	}

	public function testANodesReplicaCarriesTheTextTheDatabaseHolds(): void {
		$rData = StreamRecords::data($this->rSid, [10, 11, 13]);
		$rEntry = ReplicaStreamCache::entry(10, $rData[10], $this->rSid, 'etag', 1);
		$this->assertNotNull($rEntry);
		// What a node's start takes from its replica is what one takes from the database.
		$rStream = $this->start(10);
		$this->assertSame(json_encode([self::SOURCE], JSON_UNESCAPED_SLASHES), $rEntry['stream']['stream_source']);
		$this->assertSame($rStream['stream_info']['stream_source'], $rEntry['stream']['stream_source']);
		$this->assertSame(['user_agent' => self::AGENT, 'headers' => self::HEADERS], array_column($rEntry['arguments'], 'value', 'argument_key'));
		$rFromReplica = [
			'stream_info' => $rEntry['stream'] + $rEntry['type'] + ($rEntry['profile'] ?? array_fill_keys(array_keys(ReplicaSections::PROFILE_FIELDS), null)),
			'server_info' => $rEntry['server'], 'stream_arguments' => $rEntry['arguments'],
		];
		$this->assertSame($this->command($rStream), $this->command($rFromReplica), 'the same command on both');
		$this->assertSame(json_encode([self::FILE], JSON_UNESCAPED_SLASHES), $rData[13]['stream']['stream_source']);

		// An ordinary stream's record is the one MAIN signed before: its ETag does not move.
		$rStreams = $this->cleaned(['SELECT * FROM `streams` WHERE `id` = ?', 11]);
		$rOptions = $this->cleaned(['SELECT t1.`stream_id`, t1.`argument_id`, t1.`value`, t2.* FROM `streams_options` t1 INNER JOIN `streams_arguments` t2 ON t2.`id` = t1.`argument_id` WHERE t1.`stream_id` = ? ORDER BY t1.`argument_id`', 11]);
		$rProfiles = $this->cleaned(['SELECT * FROM `profiles` WHERE `profile_id` = ?', 7]);
		$this->assertSame(ReplicaSections::canonical(ReplicaSections::typed($rStreams[0], ReplicaSections::STREAM_FIELDS)), $rData[11]['stream']);
		$this->assertSame(ReplicaSections::canonical(array_map(static fn(array $rRow): array => ReplicaSections::typed($rRow, ReplicaSections::OPTION_FIELDS + ReplicaSections::ARGUMENT_FIELDS), $rOptions)), $rData[11]['options']);
		$this->assertSame(ReplicaSections::canonical(ReplicaSections::typed($rProfiles[0], ReplicaSections::PROFILE_FIELDS)), $rData[11]['profile']);
		$this->assertSame('curl/8', $rData[11]['options'][0]['value']);
	}
}
