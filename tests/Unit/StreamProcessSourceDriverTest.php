<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Module\SourceDriverRegistry;
use XcVm\Domain\Stream\StreamSource;
use XcVm\Domain\Stream\StreamProcess;

/**
 * A module source driver in StreamProcess: who runs a source
 * (sourceDriverFor), the launch line core builds from the driver's argv
 * (driverCommand), what the driver is given (driverContext), and the
 * supervisor spec entry / PHP-monitor checks built on them.
 */
final class StreamProcessSourceDriverTest extends TestCase {
	private TestSourceDriver $driver;

	public static function setUpBeforeClass(): void {
		foreach (['SERVER_ID' => 1, 'STREAMS_PATH' => '/tmp/xcvm-test-streams/'] as $k => $v) {
			if (!defined($k)) {
				define($k, $v);
			}
		}
		@mkdir(STREAMS_PATH, 0775, true);
	}

	protected function setUp(): void {
		SourceDriverRegistry::reset();
		$this->driver = new TestSourceDriver(['acmedash']);
		SourceDriverRegistry::register($this->driver, 90);
		@unlink(STREAMS_PATH . '42.errors');
	}

	protected function tearDown(): void {
		StreamSource::useLoader(null);
		SourceDriverRegistry::reset();
	}

	private static function call(string $rMethod, ...$rArgs) {
		$m = new ReflectionMethod(StreamProcess::class, $rMethod);
		$m->setAccessible(true);
		return $m->invoke(null, ...$rArgs);
	}

	private static function live(array $rOverrides = []): array {
		return array_merge(['type_key' => 'live', 'enable_transcode' => 0, 'delay_minutes' => 0], $rOverrides);
	}

	private function ctx(bool $rSupervised = true): array {
		return self::call('driverContext', 42, 'acmedash://p/c', '0', [], ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4], '/run/in/42.sock', $rSupervised);
	}

	private function errors(): string {
		return (string) @file_get_contents(STREAMS_PATH . '42.errors');
	}

	// ── sourceDriverFor ───────────────────────────────────────────

	public function testDriverRunsItsOwnSource(): void {
		$this->assertSame($this->driver, self::call('sourceDriverFor', self::live(), [], 'acmedash://p/c'));
		$this->assertNull(self::call('sourceDriverFor', self::live(), [], 'http://src/live.ts'), 'core runs it');
	}

	public function testLbDriverBuildsArgvOnTheNode(): void {
		SourceDriverRegistry::reset();
		SourceDriverRegistry::register($this->driver, 0, true);
		StreamSource::useLoader(static fn(string $rWhat): array => $rWhat === 'stream' ? ['stream_source' => json_encode(['acmedash://p/c'])] : []);
		$this->assertSame(['/opt/xcvm-test', '-i', 'acmedash://p/c', '-o', STREAMS_PATH . '42_.m3u8'], StreamProcess::lbDriverArgv(42, 0, '/run/lb/42.sock'));
		SourceDriverRegistry::reset();
		SourceDriverRegistry::register($this->driver);
		$this->expectException(UnexpectedValueException::class);
		StreamProcess::lbDriverArgv(42, 0, '/run/lb/42.sock');
	}

	public function testPortableDriverEntryDefersArgvBuildToLb(): void {
		SourceDriverRegistry::reset();
		SourceDriverRegistry::register($this->driver, 0, true);
		$rEntry = self::call('driverSpecEntries', 42, self::live(), [], 'acmedash://p/c', '0', ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4], '{ingest_sock}', true);
		$this->assertCount(1, $rEntry);
		$this->assertStringContainsString('source:run', $rEntry[0]['cmd']);
		$this->assertStringContainsString('source:probe', $rEntry[0]['probe_cmd']);
		$this->assertStringNotContainsString('/opt/xcvm-test', $rEntry[0]['cmd']);
		$this->assertStringNotContainsString('acmedash://p/c', $rEntry[0]['cmd'] . $rEntry[0]['probe_cmd']);
	}

	public function testSourceNobodyReadsIsRefused(): void {
		$this->assertSame('no source driver for otherdash:// on this node', self::call('sourceDriverFor', self::live(), [], 'otherdash://p/c'));
	}

	/** The driver replaces ffmpeg, so every ffmpeg-only setting must refuse it rather than vanish. */
	public function testFfmpegOnlySettingsRefuseTheDriver(): void {
		$this->assertSame('transcoding is enabled', self::call('sourceDriverFor', self::live(['enable_transcode' => 1]), [], 'acmedash://p/c'));
		$this->assertSame('RTMP (FLV) output is enabled', self::call('sourceDriverFor', self::live(['rtmp_output' => 1]), [], 'acmedash://p/c'));
		$this->assertSame('a source driver cannot feed a delayed stream', self::call('sourceDriverFor', self::live(['delay_minutes' => 5]), [], 'acmedash://p/c'));
		$this->assertSame('an input audio codec is forced', self::call('sourceDriverFor', self::live(), ['force_input_acodec' => ['value' => 'aac']], 'acmedash://p/c'));
	}

	// ── driverContext ─────────────────────────────────────────────

	public function testContextShape(): void {
		$c = $this->ctx();
		$this->assertSame(42, $c['stream_id']);
		$this->assertSame('acmedash://p/c', $c['url']);
		$this->assertSame(['dir' => STREAMS_PATH, 'playlist' => '42_.m3u8', 'segment_pattern' => '42_%d.ts', 'seg_time' => 6, 'list_size' => 8, 'delete_threshold' => 4], $c['hls']);
		$this->assertSame('/run/in/42.sock', $c['ingest']);
		$this->assertSame(STREAMS_PATH . '42_.progress', $c['progress_path']);
		$this->assertSame(STREAMS_PATH . '42.errors', $c['errors_path']);
	}

	/** The form pre-fills the default UA; passing it on would override the provider's own. */
	public function testFetchCarriesOnlyOperatorValues(): void {
		$c = self::call('driverContext', 42, 'acmedash://p/c', '0', [
			'user_agent' => ['value' => 'Mozilla/5.0', 'argument_default_value' => 'Mozilla/5.0'],
			'proxy' => ['value' => '10.0.0.1:3128', 'argument_default_value' => ''],
			'cookie' => ['value' => ''],
			'headers' => ['value' => "X-A: 1\r\n"],
		], ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4], null, true);
		$this->assertSame(['proxy' => '10.0.0.1:3128', 'headers' => "X-A: 1\r\n"], $c['fetch']);
	}

	// ── driverCommand ─────────────────────────────────────────────

	public function testSupervisedCommandIsEscapedArgvWithoutTail(): void {
		$cmd = self::call('driverCommand', $this->driver, $this->ctx());
		$this->assertSame("'/opt/xcvm-test' '-i' 'acmedash://p/c' '-o' '" . STREAMS_PATH . "42_.m3u8'", $cmd);
	}

	public function testMonitorCommandGetsThePidTail(): void {
		$cmd = self::call('driverCommand', $this->driver, $this->ctx(false));
		$this->assertStringEndsWith(" >/dev/null 2>>'" . STREAMS_PATH . "42.errors' & echo $! > '" . STREAMS_PATH . "42_.pid'", $cmd);
	}

	public function testShellMetacharactersStayInsideTheirArgument(): void {
		$this->driver->argv = ['/opt/xcvm-test', 'a; rm -rf /', '$(id)', STREAMS_PATH . '42_.m3u8'];
		$cmd = self::call('driverCommand', $this->driver, $this->ctx());
		$this->assertSame("'/opt/xcvm-test' 'a; rm -rf /' '\$(id)' '" . STREAMS_PATH . "42_.m3u8'", $cmd);
	}

	public function testArgvMustStartWithTheAbsoluteBinary(): void {
		$this->driver->argv = ['xcvm-test', STREAMS_PATH . '42_.m3u8'];
		$this->expectException(UnexpectedValueException::class);
		self::call('driverCommand', $this->driver, $this->ctx());
	}

	/** Stop, kill and adoption find the producer by its playlist. */
	public function testCommandMustNameThePlaylist(): void {
		$this->driver->argv = ['/opt/xcvm-test', '-o', '/elsewhere/out.m3u8'];
		$this->expectException(UnexpectedValueException::class);
		self::call('driverCommand', $this->driver, $this->ctx());
	}

	// ── spec entry / monitor checks ───────────────────────────────

	/** No ffmpeg fallback; the probe asks the driver through console.php source:probe. */
	public function testSpecEntryProbesThroughTheDriver(): void {
		$e = self::call('driverSpecEntries', 42, self::live(), [], 'acmedash://p/c', '0', ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4], '/run/in/42.sock');
		$this->assertCount(1, $e);
		$this->assertSame(['label', 'cmd', 'probe_cmd'], array_keys($e[0]));
		$this->assertSame('acmedash://p/c', $e[0]['label']);
		$this->assertSame(PHP_BIN . ' ' . MAIN_HOME . "console.php source:probe 42 '" . base64_encode('acmedash://p/c') . "'", $e[0]['probe_cmd']);
	}

	public function testDriverProbeStandsInForFfprobe(): void {
		$this->assertNull(StreamProcess::driverProbe(42, 'http://src/live.ts'), 'core source: run ffprobe');
		$this->assertSame(['streams' => [], 'format' => ['format_name' => 'mpegts']], StreamProcess::driverProbe(42, 'acmedash://p/c'));
		$this->driver->up = false;
		$this->assertSame([], StreamProcess::driverProbe(42, 'acmedash://p/c'));
	}

	public function testSourceProbeCommandExitsOnTheDriversWord(): void {
		$rCommand = new \XcVm\Cli\Commands\SourceProbeCommand();
		$this->assertSame('source:probe', $rCommand->getName());
		$this->assertSame(0, $rCommand->execute(['42', base64_encode('acmedash://p/c')]));
		$this->assertSame(1, $rCommand->execute(['42', base64_encode('http://src/live.ts')]), 'not a driver source');
		$this->assertSame(1, $rCommand->execute(['42', '%%%']), 'undecodable');
		$this->driver->up = false;
		$this->assertSame(1, $rCommand->execute(['42', base64_encode('acmedash://p/c')]));
	}

	public function testAdminCheckShowsTheDriversAnswer(): void {
		$m = new ReflectionMethod(\XcVm\Public\Controllers\Admin\Ajax\StreamToolsAjaxController::class, 'driverSourceTable');
		$m->setAccessible(true);
		$this->assertNull($m->invoke(null, 'http://src/live.ts'));
		$this->assertStringContainsString('Module source available', $m->invoke(null, 'acmedash://p/c'));
		$this->driver->up = false;
		$this->assertStringContainsString('Module source unavailable', $m->invoke(null, 'acmedash://p/c'));
	}

	public function testRefusedSourceIsSkippedAndLogged(): void {
		$e = self::call('driverSpecEntries', 42, self::live(['enable_transcode' => 1]), [], 'acmedash://p/c', '1', ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4], null);
		$this->assertSame([], $e);
		$this->assertStringContainsString('source #1 skipped: transcoding is enabled', $this->errors());
	}

	public function testCoreSourceGetsNoDriverEntry(): void {
		$this->assertNull(self::call('driverSpecEntries', 42, self::live(), [], 'http://src/live.ts', '0', ['seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4], null));
	}

	public function testBrokenArgvSkipsTheSource(): void {
		$this->driver->argv = ['/opt/xcvm-test'];
		$this->assertSame('', self::call('driverLaunch', 42, $this->driver, $this->ctx()));
		$this->assertStringContainsString('must name', $this->errors());
	}

	public function testAvailabilityIsTheDriversWord(): void {
		$this->assertTrue(StreamProcess::sourceAnswers(42, 'acmedash://p/c', []));
		$this->driver->up = false;
		$this->assertFalse(StreamProcess::sourceAnswers(42, 'acmedash://p/c', []));
		$this->assertStringContainsString('reports acmedash://p/c unavailable', $this->errors());
		$this->assertFalse(StreamProcess::sourceAnswers(42, 'otherdash://p/c', []), 'nobody reads it');
		$this->assertFalse(self::call('driverAvailable', 42, 'transcoding is enabled', 'acmedash://p/c'));
	}

	public function testMonitorPicksOnlyAReachableAcceptedDriver(): void {
		$this->assertSame($this->driver, self::call('monitorDriverPick', 42, self::live(), [], 'acmedash://p/c'));
		$this->assertNull(self::call('monitorDriverPick', 42, self::live(), [], 'http://src/live.ts'), 'core source: ffprobe decides');
		$this->assertNull(self::call('monitorDriverPick', 42, self::live(['enable_transcode' => 1]), [], 'acmedash://p/c'));
		$this->driver->up = false;
		$this->assertNull(self::call('monitorDriverPick', 42, self::live(), [], 'acmedash://p/c'));
	}

	public function testStartTimeoutIsTheLongestDriverWait(): void {
		$this->assertSame(90, self::call('driverStartTimeout', ['http://a/b.ts', 'acmedash://p/c']));
		$this->assertSame(0, self::call('driverStartTimeout', ['http://a/b.ts']));
	}
}
