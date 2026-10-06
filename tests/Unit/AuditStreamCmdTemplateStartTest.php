<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * The other command templates beside StreamProcess::buildLive(): a movie's
 * encode (startMovie), a created channel's item (createChannelItem), the
 * probe of a live start (startStream) and the on-demand scanner's
 * (ScannerCommand). Each is fixed text with a {TOKEN} wherever a value goes,
 * filled in one pass: a user agent, a cookie, a profile option, a logo or a
 * subtitle path that happens to name a token reaches the program as typed.
 *
 * Each start runs in a child PHP against the install schema's own tables,
 * with programs in ffmpeg's and ffprobe's place that record what they
 * received. What they receive for ordinary values is kept here as it has
 * always been (EXPECTED), with the movie's command line as recorded beside
 * its files.
 */
final class AuditStreamCmdTemplateStartTest extends TestCase {
	private const AGENT = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36';

	private const HEADERS = "Referer: https://example.com/\r\nOrigin: https://example.com";

	private const GPU_OPTIONS = '-hwaccel cuvid -hwaccel_device 0 -resize 1280x720 {INPUT_CODEC} -gpu 0 -drop_second_field 1';

	/** The stream each start works on. */
	private const ID = ['movie' => 7, 'channel' => 8, 'live' => 9, 'scan' => 10];

	private string $rHome;

	private TestDb $rDb;

	/** @var array<string, int> The seeded options' ids, by argument_key. */
	private array $rArgumentIDs = [];

	/** The first source of the rows in place: the file a channel item is made from. */
	private string $rSource = '';

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-template-start-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rHome, 0700, true);
		// Each records its arguments, one per NUL, and ends its run with a record separator.
		foreach (['ffmpeg' => '', 'ffprobe' => 'cat ' . escapeshellarg($this->rHome . 'ffprobe.json') . "\n"] as $rName => $rAnswer) {
			$rLog = escapeshellarg($this->rHome . $rName . '.argv');
			file_put_contents($this->rHome . $rName, "#!/bin/sh\n{ for a in \"\$@\"; do printf '%s\\000' \"\$a\"; done; printf '\\036'; } >> " . $rLog . "\n" . $rAnswer);
			chmod($this->rHome . $rName, 0755);
		}
		file_put_contents($this->rHome . 'ffprobe.json', '{}');

		$this->rDb = new TestDb();
		foreach (['streams', 'streams_types', 'streams_servers', 'streams_options', 'streams_arguments', 'profiles', 'ondemand_check'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		foreach (['streams_types', 'streams_arguments'] as $rTable) {
			preg_match('/^INSERT INTO `' . $rTable . '` .*?;$/ms', (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql'), $rInsert);
			$this->rDb->exec($rInsert[0]);
		}
		$this->rDb->query('SELECT `id`, `argument_key` FROM `streams_arguments`;');
		$this->rArgumentIDs = array_map('intval', array_column($this->rDb->get_rows(), 'id', 'argument_key'));

		file_put_contents($this->rHome . 'start.php', <<<'PHP'
			<?php
			use XcVm\Cli\Commands\ScannerCommand;
			use XcVm\Core\Cluster\NodeFlows;
			use XcVm\Core\Cluster\NodeLease;
			use XcVm\Core\Cluster\NodeRole;
			use XcVm\Core\Config\SettingsManager;
			use XcVm\Core\Database\DatabaseHandler;
			use XcVm\Core\Logging\FileLogger;
			use XcVm\Domain\Stream\StreamProcess;
			use XcVm\Infrastructure\Database\DatabaseFactory;
			use XcVm\Streaming\Codec\FfmpegPaths;

			$rHome = getenv('XCVM_TEST_HOME');
			define('SERVER_ID', 1);
			foreach (['STREAMS_PATH' => 'streams/', 'STREAMS_TMP_PATH' => 'streams_tmp/', 'CREATED_PATH' => 'created/', 'VOD_PATH' => 'vod/', 'DELAY_PATH' => 'delay/', 'CACHE_TMP_PATH' => 'cache/', 'SIGNALS_TMP_PATH' => 'signals/', 'LOGS_TMP_PATH' => 'logs/', 'BIN_PATH' => 'bin/'] as $rName => $rDir) {
				define($rName, $rHome . $rDir);
				mkdir($rHome . $rDir);
			}
			define('FFMPEG_BIN_40', $rHome . 'ffmpeg');
			define('FFPROBE_BIN_40', $rHome . 'ffprobe');
			// No daemon: nothing of this node's is asked.
			define('FANOUT_CTL_SOCK', $rHome . 'no-daemon.sock');
			require getenv('XCVM_TEST_BOOTSTRAP');
			FileLogger::setLogFile($rHome . 'error.log');
			NodeFlows::usePath($rHome . 'flows.json');
			NodeLease::usePath($rHome . 'lease_state.json', $rHome . 'fence.json');
			NodeRole::useServers(static fn(): array => []);
			$rSettings = [
				'ffmpeg_warnings' => 0, 'stream_max_analyze' => 2000000, 'probesize' => 5000000, 'probe_extra_wait' => 3,
				'seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4, 'priority_backup' => 0, 'send_xc_vm_header' => 0,
				'request_prebuffer' => 0, 'api_probe' => 0, 'enable_cache' => 0, 'redis_handler' => 0, 'on_demand_scan_time' => 3600,
				'on_demand_max_probe' => 0, 'live_streaming_pass' => 'pass',
			];
			SettingsManager::set($rSettings);
			$rServers = [];
			$rFFMPEG_CPU = $rFFMPEG_GPU = $rHome . 'ffmpeg';
			$rFFPROBE = $rHome . 'ffprobe';
			FfmpegPaths::resolve('4.0');
			$db = new class(TestDb::connect(getenv('XCVM_TEST_SCHEMA'))) extends DatabaseHandler {
				public function __construct(\PDO $rPdo) {
					$this->dbh = $rPdo;
				}
			};
			DatabaseFactory::set($db);
			$rID = intval(getenv('XCVM_TEST_ID'));
			switch (getenv('XCVM_TEST_START')) {
				case 'movie':
					StreamProcess::startMovie($rID);
					break;
				case 'channel':
					StreamProcess::createChannelItem($rID, (string) getenv('XCVM_TEST_SOURCE'));
					break;
				case 'live':
					StreamProcess::startStream($rID);
					break;
				case 'scan':
					$rScanner = new ScannerCommand();
					(new ReflectionMethod($rScanner, 'scanOnDemandStreams'))->invoke($rScanner);
					break;
			}
			echo "\nSTART DONE\n";
			PHP);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	private function insert(string $rTable, array $rRow): void {
		$this->rDb->query('INSERT INTO `' . $rTable . '` (`' . implode('`, `', array_keys($rRow)) . '`) VALUES (' . implode(', ', array_fill(0, count($rRow), '?')) . ')', ...array_values($rRow));
	}

	/**
	 * The rows of one start, in place of any before them: the stream
	 * (`columns`, its `sources`), this node's row, the stream's own options
	 * (`arguments`, key => value; `own`, its transcode attributes) and its
	 * transcode profile (`profile`, the options the profile page stores).
	 */
	private function rows(string $rWhat, array $rSpec): void {
		foreach (['streams', 'streams_servers', 'streams_options', 'profiles', 'ondemand_check'] as $rTable) {
			$this->rDb->exec('DELETE FROM `' . $rTable . '`');
		}
		@unlink($this->rHome . 'ffmpeg.argv');
		@unlink($this->rHome . 'ffprobe.argv');
		$rID = self::ID[$rWhat];
		$this->rSource = $rSpec['sources'][0];
		$rColumns = ['id' => $rID, 'type' => ['movie' => 2, 'channel' => 3, 'live' => 1, 'scan' => 1][$rWhat], 'stream_display_name' => 'Stream', 'stream_source' => json_encode($rSpec['sources']), 'direct_source' => 0, 'read_native' => 0, 'gen_timestamps' => 0, 'target_container' => 'mp4'];
		if (isset($rSpec['profile'])) {
			$this->insert('profiles', ['profile_id' => 1, 'profile_name' => 'Profile', 'profile_options' => json_encode($rSpec['profile'])]);
			$rColumns += ['enable_transcode' => 1, 'transcode_profile_id' => 1];
		} elseif (isset($rSpec['own'])) {
			$rColumns += ['enable_transcode' => 1, 'transcode_profile_id' => -1, 'transcode_attributes' => json_encode($rSpec['own'])];
		}
		$this->insert('streams', ($rSpec['columns'] ?? []) + $rColumns);
		$this->insert('streams_servers', ['stream_id' => $rID, 'server_id' => 1, 'on_demand' => $rWhat === 'scan' ? 1 : 0]);
		foreach ($rSpec['arguments'] ?? [] as $rKey => $rValue) {
			$this->insert('streams_options', ['stream_id' => $rID, 'argument_id' => $this->rArgumentIDs[$rKey], 'value' => $rValue]);
		}
		// What ffprobe answers: a source with H.264 video where a profile asks for its decoder, else nothing.
		file_put_contents($this->rHome . 'ffprobe.json', isset($rSpec['profile']['gpu']) ? '{"streams":[{"codec_type":"video","codec_name":"h264"}],"format":{"format_name":"mpegts","filename":"-"}}' : '{}');
	}

	/** @return list<list<string>> What a program received, run by run. */
	private function runs(string $rProgram): array {
		$rFile = $this->rHome . $rProgram . '.argv';
		$rRuns = [];
		foreach (file_exists($rFile) ? explode("\x1e", (string) file_get_contents($rFile), -1) : [] as $rRun) {
			$rRuns[] = explode("\0", $rRun, -1);
		}
		return $rRuns;
	}

	/**
	 * Run one start in a child PHP.
	 *
	 * @return array{command: string|null, ffmpeg: list<list<string>>, ffprobe: list<list<string>>} the movie's recorded command line, and what each program received
	 */
	private function start(string $rWhat): array {
		$rEnv = TestDb::env() + [
			'XCVM_TEST_HOME' => $this->rHome, 'XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'XCVM_TEST_SCHEMA' => $this->rDb->schema(),
			'XCVM_TEST_START' => $rWhat, 'XCVM_TEST_ID' => (string) self::ID[$rWhat], 'XCVM_TEST_SOURCE' => $this->rSource, 'PATH' => (string) getenv('PATH'),
		];
		exec('rm -rf ' . implode(' ', array_map(fn(string $rDir): string => escapeshellarg($this->rHome . $rDir), ['streams', 'streams_tmp', 'created', 'vod', 'delay', 'cache', 'signals', 'logs', 'bin'])));
		$rProc = proc_open([PHP_BINARY, $this->rHome . 'start.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rHome, $rEnv);
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]) . (string) stream_get_contents($rPipes[2]);
		fclose($rPipes[1]);
		fclose($rPipes[2]);
		proc_close($rProc);
		$this->assertStringContainsString('START DONE', $rOut);
		// A movie's and a channel item's encoder is started in the background.
		$rEncoder = in_array($rWhat, ['movie', 'channel'], true);
		for ($i = 0; $rEncoder && $i < 150 && $this->runs('ffmpeg') === []; $i++) {
			usleep(20000);
		}
		$rCommand = $this->rHome . 'vod/' . self::ID[$rWhat] . '_.ffmpeg';
		return ['command' => file_exists($rCommand) ? (string) file_get_contents($rCommand) : null, 'ffmpeg' => $this->runs('ffmpeg'), 'ffprobe' => $this->runs('ffprobe')];
	}

	// ── ordinary values: each program receives what it always has ──────────

	public static function ordinaryStarts(): array {
		$rEncode = ['-vcodec' => 'libx264', '-preset' => 'veryfast', '-acodec' => 'aac', 3 => ['cmd' => '-b:v 2500k', 'val' => 2500], 9 => ['cmd' => '-vf scale=1280:720', 'val' => '1280:720'], 10 => ['cmd' => '-aspect 16:9', 'val' => '16:9']];
		$rGpu = [
			'software_decoding' => 0, 'gpu' => ['val' => '1_0', 'cmd' => self::GPU_OPTIONS, 'device' => 0, 'resize' => '1280x720'], '-vcodec' => 'h264_nvenc', '-acodec' => 'aac',
			16 => ['cmd' => '', 'val' => '/home/xc_vm/logos/my logo.png', 'pos' => '20:30'], 9 => ['cmd' => '', 'val' => '1280:720'], 17 => ['cmd' => '', 'val' => 1],
		];
		$rFetch = ['user_agent' => self::AGENT, 'proxy' => '1.2.3.4:8080', 'cookie' => 'session=abc123; token=x.y-z;', 'headers' => self::HEADERS];
		$rSubtitles = json_encode(['files' => ['/home/xc_vm/subs/My Film.en.srt', '/home/xc_vm/subs/My Film.pt.srt'], 'names' => ['English', 'Portuguese'], 'charset' => ['UTF-8', 'ISO-8859-1'], 'location' => 1]);
		return [
			'a movie copied from a URL' => ['movie', ['sources' => ['http://vod.example/films/My Film (2020).mkv?token=abc&x=1'], 'arguments' => $rFetch, 'columns' => ['read_native' => 1, 'movie_subtitles' => $rSubtitles]]],
			'a movie with a CPU profile' => ['movie', ['sources' => ['http://vod.example/films/film.mkv'], 'profile' => $rEncode, 'columns' => ['remove_subtitles' => 1, 'target_container' => 'mkv']]],
			'a movie with a GPU profile and a logo' => ['movie', ['sources' => ['http://vod.example/films/film.mkv'], 'profile' => $rGpu, 'arguments' => ['user_agent' => 'VLC/3.0.20 LibVLC/3.0.20']]],
			'a movie with its own options' => ['movie', ['sources' => ['http://vod.example/films/film.mkv'], 'own' => ['-vcodec' => 'libx264', '-acodec' => 'aac'], 'arguments' => ['bitrate' => '2500', 'scaling' => '1280:-1'], 'columns' => ['custom_map' => '-map 0:v:0 -map 0:a:1']]],
			'a channel item without a profile' => ['channel', ['sources' => ['/home/xc_vm/content/vod/My Film (2020).mp4']]],
			'a channel item with a CPU profile' => ['channel', ['sources' => ['/home/xc_vm/content/vod/film.mp4'], 'profile' => $rEncode]],
			'a channel item with a GPU profile and a logo' => ['channel', ['sources' => ['/home/xc_vm/content/vod/film.mp4'], 'profile' => $rGpu]],
			'a live start probing two sources' => ['live', ['sources' => ['http://src.example:8080/first/stream.m3u8?token=abc&x=1', 'http://backup.example/second.ts'], 'arguments' => $rFetch]],
			'an on-demand scan of two sources' => ['scan', ['sources' => ['http://src.example:8080/first/stream.m3u8?token=abc&x=1', 'http://backup.example/second.ts'], 'arguments' => $rFetch]],
		];
	}

	/** One start's outcome with this run's own directory named, so it reads the same wherever that is. */
	private function ordinary(string $rWhat, array $rSpec): array {
		$this->rows($rWhat, $rSpec);
		$rResult = $this->start($rWhat);
		array_walk_recursive($rResult, function (&$rText): void {
			$rText = is_string($rText) ? str_replace($this->rHome, '<home>/', $rText) : $rText;
		});
		return $rResult;
	}

	#[DataProvider('ordinaryStarts')]
	public function testAnOrdinaryStartIsUnchanged(string $rWhat, array $rSpec): void {
		$this->assertArrayHasKey($this->dataName(), self::EXPECTED);
		$this->assertSame(self::EXPECTED[$this->dataName()], $this->ordinary($rWhat, $rSpec));
	}

	public function testEveryExpectedStartHasItsCase(): void {
		$this->assertSame(array_keys(self::ordinaryStarts()), array_keys(self::EXPECTED));
	}

	// ── a value that names a token is still a value ────────────────────────

	/** The start with one value in one of the places a value takes, on a source a shell would act on were it read outside its own quotes. */
	private function startWith(string $rWhat, string $rPlace, string $rValue): array {
		$rRan = 'touch${IFS}' . $this->rHome . 'ran-';
		$rSource = $rWhat === 'channel' ? '/home/xc_vm/content/$(' . $rRan . '1)/;' . $rRan . '2;/film.mp4' : 'http://src.example/film.mkv?a=$(' . $rRan . '1)&b=;' . $rRan . '2;';
		$rSpec = match ($rPlace) {
			'user_agent', 'headers' => ['arguments' => [$rPlace => $rValue]],
			'cookie' => ['arguments' => ['cookie' => 'a=' . $rValue . ';']],
			'scaling' => ['arguments' => ['scaling' => $rValue], 'own' => ['-vcodec' => 'libx264']],
			'profile option' => ['profile' => ['-vcodec' => 'libx264', 10 => ['cmd' => '-aspect "' . $rValue . '"', 'val' => $rValue]]],
			'profile logo' => ['profile' => ['-vcodec' => 'libx264', 16 => ['cmd' => '', 'val' => $rValue, 'pos' => '10:10']]],
			'subtitle' => ['columns' => ['movie_subtitles' => json_encode(['files' => [$rValue], 'names' => ['English'], 'charset' => ['UTF-8'], 'location' => 1])]],
			'source' => ['sources' => ['http://vod.example/' . $rValue . '/film.mkv'], 'columns' => ['read_native' => 1, 'movie_subtitles' => json_encode(['files' => ['/home/xc_vm/subs/film.srt'], 'names' => ['English'], 'charset' => ['UTF-8'], 'location' => 1])]],
		} + ['sources' => [$rSource]];
		$this->rows($rWhat, $rSpec);
		$rResult = $this->start($rWhat);
		return $rWhat === 'movie' || $rWhat === 'channel' ? $rResult['ffmpeg'] : $rResult['ffprobe'];
	}

	public static function valuesNamingAToken(): array {
		return [
			'a movie, a user agent naming the source' => ['movie', 'user_agent', '{STREAM_SOURCE}'],
			'a movie, a cookie naming the source' => ['movie', 'cookie', '{STREAM_SOURCE}'],
			// The row reader hands a value's line breaks on as \n.
			'a movie, headers naming tokens' => ['movie', 'headers', "X-One: {STREAM_SOURCE}\nX-Two: {READ_NATIVE}"],
			'a movie, a scaling naming the source' => ['movie', 'scaling', '{STREAM_SOURCE}'],
			'a movie, a profile option naming the source' => ['movie', 'profile option', '{STREAM_SOURCE}'],
			'a movie, a profile logo naming the source' => ['movie', 'profile logo', '/home/xc_vm/logos/{STREAM_SOURCE}.png'],
			'a movie, a subtitle file naming the source' => ['movie', 'subtitle', '/home/xc_vm/subs/{STREAM_SOURCE}.srt'],
			'a movie, a source naming tokens' => ['movie', 'source', '{READ_NATIVE}{TRANSCODE}{SUBTITLES}'],
			'a channel item, a profile option naming the source' => ['channel', 'profile option', '{STREAM_SOURCE}'],
			'a channel item, a profile logo naming the source' => ['channel', 'profile logo', '/home/xc_vm/logos/{STREAM_SOURCE}.png'],
			'a live probe, a user agent naming the source' => ['live', 'user_agent', '{STREAM_SOURCE}'],
			'a live probe, a user agent with a word in braces' => ['live', 'user_agent', 'Player {CONCAT} 1.0'],
			'a live probe, a cookie naming the source' => ['live', 'cookie', '{STREAM_SOURCE}'],
			'a scan, a user agent naming the source' => ['scan', 'user_agent', '{STREAM_SOURCE}'],
			'a scan, a cookie naming the source' => ['scan', 'cookie', '{STREAM_SOURCE}'],
		];
	}

	#[DataProvider('valuesNamingAToken')]
	public function testAValueNamingATokenReachesTheProgramAsTyped(string $rWhat, string $rPlace, string $rValue): void {
		// What the program receives for a plain word in that place, with the word exchanged for the value.
		$rExpected = $this->startWith($rWhat, $rPlace, 'VALUE');
		array_walk_recursive($rExpected, static function (string &$rArgument) use ($rValue): void {
			$rArgument = str_replace('VALUE', $rValue, $rArgument);
		});
		$this->assertCount(1, $rExpected, 'the program ran once');
		$this->assertContains('-i', $rExpected[0]);
		$this->assertSame([], glob($this->rHome . 'ran-*'), 'nothing but the program ran');
		$this->assertSame($rExpected, $this->startWith($rWhat, $rPlace, $rValue));
		$this->assertSame([], glob($this->rHome . 'ran-*'), 'nothing but the program ran');
	}

	private const EXPECTED = [
		'a movie copied from a URL' => [
			'command' => "<home>/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err  -user_agent \"Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36\" -http_proxy \"http://1.2.3.4:8080\" -cookies 'session=abc123; token=x.y-z;path=/;domain=;' -headers 'Referer: https://example.com/\nOrigin: https://example.com' -fflags +genpts -async 1 -re -i 'http://vod.example/films/My%20Film%20(2020).mkv?token=abc&x=1'  -sub_charenc 'UTF-8' -i '/home/xc_vm/subs/My Film.en.srt' -sub_charenc 'ISO-8859-1' -i '/home/xc_vm/subs/My Film.pt.srt' -vcodec copy -scodec mov_text -acodec copy -movflags +faststart -dn -map 0 -copy_unknown  -ignore_unknown -map 1 -metadata:s:s:0 title=English -metadata:s:s:0 language=English -map 2 -metadata:s:s:1 title=Portuguese -metadata:s:s:1 language=Portuguese  <home>/vod/7.mp4 >/dev/null 2><home>/vod/7.errors & echo \$! > <home>/vod/7_.pid",
			'ffmpeg' => [['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', '-http_proxy', 'http://1.2.3.4:8080', '-cookies', 'session=abc123; token=x.y-z;path=/;domain=;', '-headers', "Referer: https://example.com/\nOrigin: https://example.com", '-fflags', '+genpts', '-async', '1', '-re', '-i', 'http://vod.example/films/My%20Film%20(2020).mkv?token=abc&x=1', '-sub_charenc', 'UTF-8', '-i', '/home/xc_vm/subs/My Film.en.srt', '-sub_charenc', 'ISO-8859-1', '-i', '/home/xc_vm/subs/My Film.pt.srt', '-vcodec', 'copy', '-scodec', 'mov_text', '-acodec', 'copy', '-movflags', '+faststart', '-dn', '-map', '0', '-copy_unknown', '-ignore_unknown', '-map', '1', '-metadata:s:s:0', 'title=English', '-metadata:s:s:0', 'language=English', '-map', '2', '-metadata:s:s:1', 'title=Portuguese', '-metadata:s:s:1', 'language=Portuguese', '<home>/vod/7.mp4']],
			'ffprobe' => [],
		],
		'a movie with a CPU profile' => [
			'command' => '<home>/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err   -fflags +genpts -async 1  -i \'http://vod.example/films/film.mkv\'  -aspect 16:9 -scodec srt -vf scale=1280:720 -b:v 2500k -acodec aac -preset veryfast -vcodec libx264 -movflags +faststart -dn -map 0:a -map 0:v -ignore_unknown  <home>/vod/7.mkv >/dev/null 2><home>/vod/7.errors & echo $! > <home>/vod/7_.pid',
			'ffmpeg' => [['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-fflags', '+genpts', '-async', '1', '-i', 'http://vod.example/films/film.mkv', '-aspect', '16:9', '-scodec', 'srt', '-vf', 'scale=1280:720', '-b:v', '2500k', '-acodec', 'aac', '-preset', 'veryfast', '-vcodec', 'libx264', '-movflags', '+faststart', '-dn', '-map', '0:a', '-map', '0:v', '-ignore_unknown', '<home>/vod/7.mkv']],
			'ffprobe' => [],
		],
		'a movie with a GPU profile and a logo' => [
			'command' => '<home>/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err -hwaccel cuvid -hwaccel_device 0 -resize 1280x720 -c:v h264_cuvid -gpu 0 -drop_second_field 1 -user_agent "VLC/3.0.20 LibVLC/3.0.20" -fflags +genpts -async 1  -i \'http://vod.example/films/film.mkv\' -i \'/home/xc_vm/logos/my logo.png\' -filter_complex "[0:v]yadif,scale=1280:720[bg]; [1:v]scale=250:-1[logo]; [bg][logo]overlay=20:30" -acodec aac -scodec mov_text -vcodec h264_nvenc -movflags +faststart -dn -map 0 -copy_unknown  -ignore_unknown  <home>/vod/7.mp4 >/dev/null 2><home>/vod/7.errors & echo $! > <home>/vod/7_.pid',
			'ffmpeg' => [['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-hwaccel', 'cuvid', '-hwaccel_device', '0', '-resize', '1280x720', '-c:v', 'h264_cuvid', '-gpu', '0', '-drop_second_field', '1', '-user_agent', 'VLC/3.0.20 LibVLC/3.0.20', '-fflags', '+genpts', '-async', '1', '-i', 'http://vod.example/films/film.mkv', '-i', '/home/xc_vm/logos/my logo.png', '-filter_complex', '[0:v]yadif,scale=1280:720[bg]; [1:v]scale=250:-1[logo]; [bg][logo]overlay=20:30', '-acodec', 'aac', '-scodec', 'mov_text', '-vcodec', 'h264_nvenc', '-movflags', '+faststart', '-dn', '-map', '0', '-copy_unknown', '-ignore_unknown', '<home>/vod/7.mp4']],
			'ffprobe' => [['-probesize', '5000000', '-analyzeduration', '2000000', '-i', 'http://vod.example/films/film.mkv', '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format']],
		],
		'a movie with its own options' => [
			'command' => '<home>/ffmpeg -y -nostdin -hide_banner -loglevel error -err_detect ignore_err   -fflags +genpts -async 1  -i \'http://vod.example/films/film.mkv\'  -filter_complex "scale=1280:-1" -scodec mov_text -vcodec libx264 -acodec aac -b:v 2500k -movflags +faststart -dn -map 0:v:0 -map 0:a:1 -copy_unknown  -ignore_unknown  <home>/vod/7.mp4 >/dev/null 2><home>/vod/7.errors & echo $! > <home>/vod/7_.pid',
			'ffmpeg' => [['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-fflags', '+genpts', '-async', '1', '-i', 'http://vod.example/films/film.mkv', '-filter_complex', 'scale=1280:-1', '-scodec', 'mov_text', '-vcodec', 'libx264', '-acodec', 'aac', '-b:v', '2500k', '-movflags', '+faststart', '-dn', '-map', '0:v:0', '-map', '0:a:1', '-copy_unknown', '-ignore_unknown', '<home>/vod/7.mp4']],
			'ffprobe' => [],
		],
		'a channel item without a profile' => [
			'command' => null,
			'ffmpeg' => [['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-progress', '<home>/created/8_8b4c2e94c6e8c6ed1ad203d14d56934a.progress', '-fflags', '+genpts', '-async', '1', '-i', '/home/xc_vm/content/vod/My Film (2020).mp4', '-vcodec', 'copy', '-acodec', 'copy', '-strict', '-2', '-mpegts_flags', '+initial_discontinuity', '-f', 'mpegts', '<home>/created/8_8b4c2e94c6e8c6ed1ad203d14d56934a.ts']],
			'ffprobe' => [],
		],
		'a channel item with a CPU profile' => [
			'command' => null,
			'ffmpeg' => [['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-progress', '<home>/created/8_c6de19ee894f910d8064e7548b3f043e.progress', '-fflags', '+genpts', '-async', '1', '-i', '/home/xc_vm/content/vod/film.mp4', '-aspect', '16:9', '-vf', 'scale=1280:720', '-b:v', '2500k', '-acodec', 'aac', '-preset', 'veryfast', '-vcodec', 'libx264', '-strict', '-2', '-mpegts_flags', '+initial_discontinuity', '-f', 'mpegts', '<home>/created/8_c6de19ee894f910d8064e7548b3f043e.ts']],
			'ffprobe' => [],
		],
		'a channel item with a GPU profile and a logo' => [
			'command' => null,
			'ffmpeg' => [['-y', '-nostdin', '-hide_banner', '-loglevel', 'error', '-err_detect', 'ignore_err', '-progress', '<home>/created/8_c6de19ee894f910d8064e7548b3f043e.progress', '-hwaccel', 'cuvid', '-hwaccel_device', '0', '-resize', '1280x720', '-c:v', 'h264_cuvid', '-gpu', '0', '-drop_second_field', '1', '-fflags', '+genpts', '-async', '1', '-i', '/home/xc_vm/content/vod/film.mp4', '-i', '/home/xc_vm/logos/my logo.png', '-filter_complex', '[0:v]yadif,scale=1280:720[bg]; [1:v]scale=250:-1[logo]; [bg][logo]overlay=20:30', '-gpu', '0', '-acodec', 'aac', '-vcodec', 'h264_nvenc', '-strict', '-2', '-mpegts_flags', '+initial_discontinuity', '-f', 'mpegts', '<home>/created/8_c6de19ee894f910d8064e7548b3f043e.ts']],
			'ffprobe' => [['-probesize', '5000000', '-analyzeduration', '2000000', '-i', '/home/xc_vm/content/vod/film.mp4', '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format']],
		],
		'a live start probing two sources' => [
			'command' => null,
			'ffmpeg' => [],
			'ffprobe' => [['-user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', '-http_proxy', 'http://1.2.3.4:8080', '-cookies', 'session=abc123; token=x.y-z;path=/;domain=;', '-headers', "Referer: https://example.com/\nOrigin: https://example.com\r\nX-XC_VM-Prebuffer:1", '-probesize', '5000000', '-analyzeduration', '2000000', '-i', 'http://src.example:8080/first/stream.m3u8?token=abc&x=1', '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format'], ['-user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', '-http_proxy', 'http://1.2.3.4:8080', '-cookies', 'session=abc123; token=x.y-z;path=/;domain=;', '-headers', "Referer: https://example.com/\nOrigin: https://example.com\r\nX-XC_VM-Prebuffer:1", '-probesize', '5000000', '-analyzeduration', '2000000', '-i', 'http://backup.example/second.ts', '-v', 'quiet', '-print_format', 'json', '-show_streams', '-show_format']],
		],
		'an on-demand scan of two sources' => [
			'command' => null,
			'ffmpeg' => [],
			'ffprobe' => [['-user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', '-http_proxy', 'http://1.2.3.4:8080', '-cookies', 'session=abc123; token=x.y-z;path=/;domain=;', '-headers', "Referer: https://example.com/\nOrigin: https://example.com", '-probesize', '128000', '-analyzeduration', '10000000', '-i', 'http://src.example:8080/first/stream.m3u8?token=abc&x=1', '-loglevel', 'error', '-print_format', 'json', '-show_streams', '-show_format'], ['-user_agent', 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36', '-http_proxy', 'http://1.2.3.4:8080', '-cookies', 'session=abc123; token=x.y-z;path=/;domain=;', '-headers', "Referer: https://example.com/\nOrigin: https://example.com", '-probesize', '128000', '-analyzeduration', '10000000', '-i', 'http://backup.example/second.ts', '-loglevel', 'error', '-print_format', 'json', '-show_streams', '-show_format']],
		],
	];
}
