<?php

use XcVm\Streaming\Fanout\FanoutConfig;
use PHPUnit\Framework\TestCase;

/**
 * FanoutConfig writes the xc_fanout daemon's config.json from panel settings.
 * BIN_PATH is defined by tests/bootstrap.php; the daemon tree is created under it.
 */
final class FanoutConfigTest extends TestCase {
	private string $dir;
	private string $path;

	protected function setUp(): void {
		$this->dir = rtrim(BIN_PATH, '/') . '/xc_fanout';
		$this->path = $this->dir . '/config.json';
		if (!is_dir($this->dir)) {
			mkdir($this->dir, 0775, true);
		}
		@unlink($this->path);
	}

	protected function tearDown(): void {
		@unlink($this->path);
		@rmdir($this->dir);
	}

	/** @return array<string,mixed> */
	private function read(): array {
		$this->assertFileExists($this->path);
		$rDecoded = json_decode((string) file_get_contents($this->path), true);
		$this->assertIsArray($rDecoded);
		return $rDecoded;
	}

	private function baseSettings(): array {
		return array(
			'seg_time'                     => 6,
			'client_prebuffer'             => 30,
			'restreamer_prebuffer'         => 0,
			'fanout_hls_window'            => 6,
			'fanout_grace_sec'             => 10,
			'fanout_write_timeout_sec'     => 15,
			'fanout_chunk_bytes'           => 12032,
			'fanout_max_gop_bytes'         => 10528000,
			'fanout_source_insecure'       => 1,
			'fanout_default_prebuffer_sec' => 0,
			'fanout_idle_buffer_grace_sec' => 30,
			'fanout_idle_buffer_ratio'     => 0.5,
			'fanout_source_backend'        => 'auto',
			'fanout_supervise'             => 1,
		);
	}

	public function testMapsSettingsToDaemonKeys(): void {
		$this->assertTrue(FanoutConfig::sync($this->baseSettings()));
		$c = $this->read();

		$this->assertSame(6, $c['hls_target_sec']);      // ← seg_time
		$this->assertSame(6, $c['hls_window']);
		$this->assertSame(10, $c['grace_sec']);
		$this->assertSame(15, $c['write_timeout_sec']);
		$this->assertSame(12032, $c['chunk_bytes']);
		$this->assertSame(10528000, $c['max_gop_bytes']);
		$this->assertTrue($c['source_insecure']);
		$this->assertSame(0, $c['default_prebuffer_sec']);
		$this->assertSame(30, $c['idle_buffer_grace_sec']);
		$this->assertSame(0.5, $c['idle_buffer_ratio']);
	}

	public function testPrebufferMaxSecIsDerivedFromWhatTheRingMustHold(): void {
		// client 30 + one 6 s segment of headroom = 36; HLS 6 x 6 = 36.
		$this->assertTrue(FanoutConfig::sync($this->baseSettings()));
		$this->assertSame(36, $this->read()['prebuffer_max_sec']);

		// A larger HLS window pushes the ring up (12*6 = 72).
		$s = $this->baseSettings();
		$s['fanout_hls_window'] = 12;
		FanoutConfig::sync($s);
		$this->assertSame(72, $this->read()['prebuffer_max_sec']);

		// So does any prebuffer a viewer can ask for, with its headroom.
		foreach (array('client_prebuffer' => 50, 'restreamer_prebuffer' => 50, 'fanout_default_prebuffer_sec' => 50) as $rKey => $rValue) {
			$s = $this->baseSettings();
			$s[$rKey] = $rValue;
			FanoutConfig::sync($s);
			$this->assertSame(56, $this->read()['prebuffer_max_sec'], $rKey);
		}

		// Capped at the daemon's clamp.
		$s = $this->baseSettings();
		$s['client_prebuffer'] = 300;
		FanoutConfig::sync($s);
		$this->assertSame(120, $this->read()['prebuffer_max_sec']);
	}

	/**
	 * The ring is the daemon's memory. A fixed 40 s floor kept every channel at
	 * 40 s however far the operator lowered the prebuffer and the HLS window, so
	 * no panel setting could shrink it.
	 */
	public function testLoweringThePrebufferAndHlsWindowShrinksTheRing(): void {
		$s = $this->baseSettings();
		$s['client_prebuffer'] = 10;
		$s['fanout_hls_window'] = 3;
		FanoutConfig::sync($s);
		$this->assertSame(18, $this->read()['prebuffer_max_sec']); // 3 x 6 = 18 > 10 + 6

		$s['client_prebuffer'] = 0;
		$s['fanout_hls_window'] = 1;
		FanoutConfig::sync($s);
		$this->assertSame(12, $this->read()['prebuffer_max_sec']); // never under two segments
	}

	public function testClampsOutOfRangeValues(): void {
		$s = $this->baseSettings();
		$s['fanout_hls_window']        = 999;   // >20
		$s['fanout_grace_sec']         = 0;     // <1
		$s['fanout_write_timeout_sec'] = 9999;  // >600
		$s['fanout_chunk_bytes']       = 1;     // <188
		$s['fanout_idle_buffer_ratio'] = 5.0;   // >1
		$s['seg_time']                 = 99;    // >30
		FanoutConfig::sync($s);
		$c = $this->read();

		$this->assertSame(20, $c['hls_window']);
		$this->assertSame(1, $c['grace_sec']);
		$this->assertSame(600, $c['write_timeout_sec']);
		$this->assertSame(188, $c['chunk_bytes']);
		// A whole-number ratio JSON-encodes as "1", decoding to int — value-compare.
		$this->assertEquals(1, $c['idle_buffer_ratio']);
		$this->assertSame(30, $c['hls_target_sec']);
	}

	public function testSourceInsecureFalse(): void {
		$s = $this->baseSettings();
		$s['fanout_source_insecure'] = 0;
		FanoutConfig::sync($s);
		$this->assertFalse($this->read()['source_insecure']);
	}

	public function testIsIdempotent(): void {
		$this->assertTrue(FanoutConfig::sync($this->baseSettings()));
		// Nothing changed → no rewrite.
		$this->assertFalse(FanoutConfig::sync($this->baseSettings()));
	}

	public function testPreservesUnknownDaemonKeys(): void {
		// A key the panel does not own must survive the read-modify-write.
		file_put_contents($this->path, json_encode(array('some_future_daemon_key' => 'keep-me')));
		FanoutConfig::sync($this->baseSettings());
		$c = $this->read();
		$this->assertSame('keep-me', $c['some_future_daemon_key']);
		$this->assertSame(6, $c['hls_window']);
	}

	public function testMapsSourceBackend(): void {
		$this->assertTrue(FanoutConfig::sync($this->baseSettings()));
		$this->assertSame('auto', $this->read()['source_backend']);
	}

	public function testSourceBackendAcceptsTheDaemonsValues(): void {
		foreach (array('auto', 'ffmpeg', 'native') as $rBackend) {
			$s = $this->baseSettings();
			$s['fanout_source_backend'] = $rBackend;
			FanoutConfig::sync($s);
			$this->assertSame($rBackend, $this->read()['source_backend']);
		}
	}

	/**
	 * A typo must never pin a channel to a backend that does not exist: it falls
	 * back to auto, the same thing the daemon does with an unknown value.
	 */
	public function testSourceBackendRejectsUnknownValues(): void {
		foreach (array('FFMPEG', 'ffmpg', '', 'nativ') as $rBogus) {
			$s = $this->baseSettings();
			$s['fanout_source_backend'] = $rBogus;
			FanoutConfig::sync($s);
			$this->assertSame('auto', $this->read()['source_backend'], "bogus value: {$rBogus}");
		}
	}

	/** The daemon accepts streams for supervision only while this says so. */
	public function testMapsSupervise(): void {
		$this->assertTrue(FanoutConfig::sync($this->baseSettings()));
		$this->assertTrue($this->read()['supervise']);

		$s = $this->baseSettings();
		$s['fanout_supervise'] = 0;
		FanoutConfig::sync($s);
		$this->assertFalse($this->read()['supervise']);

		$s = $this->baseSettings();
		unset($s['fanout_supervise']); // a panel from before migration 018
		FanoutConfig::sync($s);
		$this->assertTrue($this->read()['supervise'], 'the column default');
	}

}
