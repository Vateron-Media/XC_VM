<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\License\LicenseGate;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Streaming\Fanout\FanoutClient;
use XcVm\Streaming\Fanout\FanoutMode;

/**
 * The fanout master switch: settings.fanout_enabled = 0 restores the delivery
 * that existed before fanout, on MAIN and on every LB, whatever the daemon's
 * sockets say (a daemon being stopped leaves them behind). Live TS goes to the
 * ProxyCommand relay or the chase-read of the on-disk segments, HLS to the
 * on-disk playlist, and a stream is watched by the PHP monitor, not handed to
 * the daemon's supervisor. With fanout on, a daemon that is merely down is
 * not-on-air, never a silent fallback.
 */
final class FanoutOffLegacyPathTest extends TestCase {
	private array $rSaved;

	private string $rDir;

	/** @var string[] socket stand-ins this test created */
	private array $rCreated = [];

	protected function setUp(): void {
		$this->rSaved = SettingsManager::getAll();
		$this->rDir = sys_get_temp_dir() . '/xcvm-fanout-off-' . getmypid() . '-' . uniqid();
		mkdir($this->rDir, 0777, true);
		if (!defined('FANOUT_CTL_SOCK')) {
			define('FANOUT_CTL_SOCK', $this->rDir . '/control.sock');
		}
		if (!defined('FANOUT_HTTP_SOCK')) {
			define('FANOUT_HTTP_SOCK', $this->rDir . '/http.sock');
		}
		// A stopped daemon's sockets can stay behind: the switch must win over them.
		foreach ([FANOUT_CTL_SOCK, FANOUT_HTTP_SOCK] as $rSock) {
			if (!file_exists($rSock)) {
				@mkdir(dirname($rSock), 0777, true);
				touch($rSock);
				$this->rCreated[] = $rSock;
			}
		}
		// Supervision asked for: fanout off must still leave the stream to PHP.
		SettingsManager::set(['fanout_enabled' => 0, 'fanout_supervise' => 1, 'encrypt_hls' => 0]);
	}

	protected function tearDown(): void {
		SettingsManager::set($this->rSaved);
		foreach ($this->rCreated as $rSock) {
			@unlink($rSock);
		}
		@rmdir($this->rDir);
	}

	public function testTheSwitchMakesEveryViewerLegacyAndTheDaemonUnasked(): void {
		$this->assertTrue(FanoutMode::legacyDelivery());
		$this->assertFalse(LicenseGate::fanoutUsable(), 'with its socket present');
		$this->assertFalse(FanoutClient::register(9902, ['urls' => ['http://example.invalid/s.ts']]));
		$this->assertFalse(FanoutClient::isStreamFed(9902));
		$this->assertNull(FanoutClient::hlsPlaylist(9902));
		$this->assertNull(FanoutClient::registerIngest(9902), 'the encoder writes the on-disk HLS only (no tee)');
	}

	/** live.php's TS arm: the relay / chase-read, never the daemon and never not-on-air. */
	public function testLiveTsTakesThePreFanoutPath(): void {
		$rLegacy = FanoutMode::legacyDelivery();
		// Proxy stream: live.php registers with the daemon only through fanoutUsable().
		$this->assertSame(FanoutMode::VIA_LEGACY, FanoutMode::tsDelivery($rLegacy, LicenseGate::fanoutUsable()));
		// Non-proxy stream.
		$this->assertSame(FanoutMode::VIA_LEGACY, FanoutMode::tsDelivery($rLegacy, FanoutClient::isStreamFed(9902)));
		// Whatever a daemon would claim.
		$this->assertSame(FanoutMode::VIA_LEGACY, FanoutMode::tsDelivery(true, true));

		// Fanout on: the daemon, or not-on-air when it does not serve the stream.
		$this->assertSame(FanoutMode::VIA_DAEMON, FanoutMode::tsDelivery(false, true));
		$this->assertSame(FanoutMode::OFF_AIR, FanoutMode::tsDelivery(false, false));
	}

	/** live.php's HLS arm: the on-disk playlist (HLSGenerator::generateHLS). */
	public function testLiveHlsTakesTheOnDiskPlaylist(): void {
		$this->assertSame(FanoutMode::VIA_LEGACY, FanoutMode::hlsDelivery(FanoutMode::legacyDelivery(), FanoutClient::hlsPlaylist(9902) !== null));
		$this->assertSame(FanoutMode::VIA_LEGACY, FanoutMode::hlsDelivery(true, true));

		$this->assertSame(FanoutMode::VIA_DAEMON, FanoutMode::hlsDelivery(false, true));
		$this->assertSame(FanoutMode::OFF_AIR, FanoutMode::hlsDelivery(false, false));
	}

	/**
	 * What a stopped stream gets: a proxy stream its ProxyCommand whether or
	 * not it is on demand (MonitorCommand does not run direct sources), any
	 * other on-demand stream its watchdog.
	 */
	public function testAStoppedStreamStartsItsPreFanoutProducer(): void {
		$this->assertSame(FanoutMode::START_PROXY, FanoutMode::startFor(true, true, true));
		$this->assertSame(FanoutMode::START_PROXY, FanoutMode::startFor(true, true, false));
		$this->assertSame(FanoutMode::START_MONITOR, FanoutMode::startFor(true, false, true));
		$this->assertSame(FanoutMode::OFF_AIR, FanoutMode::startFor(true, false, false), 'an always-on stream is the cron\'s to restart');

		// Fanout on: a proxy stream the daemon did not take is not-on-air.
		$this->assertSame(FanoutMode::OFF_AIR, FanoutMode::startFor(false, true, true));
		$this->assertSame(FanoutMode::START_MONITOR, FanoutMode::startFor(false, false, true));
	}

	/** The stream monitor: the PHP watchdog, not the daemon's supervisor. */
	public function testTheStreamMonitorIsThePhpWatchdog(): void {
		$this->assertFalse(StreamProcess::supervisionEnabled(), 'supervision asked for, fanout off');
		$this->assertFalse(StreamProcess::superviseStream(9902, false), 'startMonitor() then starts `console.php monitor`');
		$this->assertFalse(StreamProcess::superviseStream(9902, true));
		$this->assertNull(FanoutClient::monitorStates(), 'cron:streams sees no supervisor: every live stream is PHP-watched');
		$this->assertNull(StreamProcess::reconcileSupervised(null));
		$this->assertNull(FanoutClient::isSupervised(9902), 'MonitorCommand does not stand down');
		$this->assertFalse(StreamProcess::isWatched(9902, null), 'a stream whose monitor was the daemon is unwatched');
		$this->assertFalse(FanoutClient::daemonStreamMissing(9902), 'no re-feed restart loop');
	}

	/**
	 * A running ProxyCommand (`XC_VMProxy[<id>]`) is found by its own title:
	 * the PHP monitor's check never matches it, so a viewer arriving while it
	 * opens its source would start a second one, which kills the first.
	 */
	public function testARunningProxyProducerIsRecognised(): void {
		$rProc = proc_open([PHP_BINARY, '-r', 'cli_set_process_title("XC_VMProxy[9902]"); sleep(5);'], [], $rPipes);
		$this->assertIsResource($rProc);
		try {
			$rPID = (int) proc_get_status($rProc)['pid'];
			$rDeadline = microtime(true) + 3;
			while (trim((string) @file_get_contents('/proc/' . $rPID . '/cmdline'), "\0 ") !== 'XC_VMProxy[9902]' && microtime(true) < $rDeadline) {
				usleep(20000);
			}
			if (trim((string) @file_get_contents('/proc/' . $rPID . '/cmdline'), "\0 ") !== 'XC_VMProxy[9902]') {
				$this->markTestSkipped('process titles cannot be set here');
			}
			$this->assertTrue(ProcessManager::isNamedProcessRunning($rPID, 'XC_VMProxy', 9902));
			$this->assertFalse(ProcessManager::isNamedProcessRunning($rPID, 'XC_VMProxy', 9903));
			$this->assertFalse(ProcessManager::isMonitorAlive($rPID, 9902), 'why live.php must not use the monitor check');
		} finally {
			proc_terminate($rProc, 9);
			proc_close($rProc);
		}
	}

	/** live.php decides through the helpers above, and starts a proxy before any monitor. */
	public function testLivePhpRoutesThroughTheSwitch(): void {
		$rSource = (string) file_get_contents(MAIN_HOME . 'Public/stream/live.php');
		$this->assertStringContainsString('FanoutMode::startFor($rLegacy,', $rSource);
		$this->assertStringContainsString('FanoutMode::tsDelivery($rLegacy,', $rSource);
		$this->assertStringContainsString('FanoutMode::hlsDelivery($rLegacy,', $rSource);
		$this->assertStringContainsString('"XC_VMProxy"', $rSource);
		$this->assertStringNotContainsString('there is NO settings flag', $rSource);
		$this->assertStringContainsString('settings.fanout_enabled = 0', $rSource);
		$this->assertLessThan(
			strpos($rSource, 'FanoutMode::START_MONITOR'),
			strpos($rSource, 'StreamProcess::startProxy('),
			'the proxy producer is chosen before the on-demand monitor'
		);
	}
}
