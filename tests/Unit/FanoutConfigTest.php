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

	public function testPrebufferMaxSecIsDerivedAndFloored(): void {
		// client 30, restreamer 0, hls_window*seg = 6*6 = 36 → floor 40.
		$this->assertTrue(FanoutConfig::sync($this->baseSettings()));
		$this->assertSame(40, $this->read()['prebuffer_max_sec']);

		// A larger HLS window pushes the ring up (12*6 = 72).
		$s = $this->baseSettings();
		$s['fanout_hls_window'] = 12;
		FanoutConfig::sync($s);
		$this->assertSame(72, $this->read()['prebuffer_max_sec']);
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
}
