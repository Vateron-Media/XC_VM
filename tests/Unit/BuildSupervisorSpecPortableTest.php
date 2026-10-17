<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource;

/**
 * Portable mode of StreamProcess::buildSupervisorSpec (4b-i): MAIN composes a
 * node-agnostic supervisor Spec for a serve-only LB, with node-local values as
 * placeholder tokens the target node resolves, and no on-node side effects
 * (no key/IV files, no ingest registration). The default path is unchanged.
 *
 * Runs without a DB or a fanout via StreamSource::useLoader (portable mode skips
 * registerIngest/writeStreamKeyIv, so the fanout is never touched).
 */
final class BuildSupervisorSpecPortableTest extends TestCase {

	public static function setUpBeforeClass(): void {
		foreach ([
			'SERVER_ID' => 1,
			'STREAMS_PATH' => '/tmp/xcvm-test-streams/',
			'LOGS_TMP_PATH' => '/tmp/xcvm-test-logs/',
			'CACHE_TMP_PATH' => '/tmp/xcvm-test-cache/',
			'DELAY_PATH' => '/tmp/xcvm-test-delay/',
			'FFMPEG_BIN_40' => '/bin/ffmpeg40',
			'FFPROBE_BIN_40' => '/bin/ffprobe40',
		] as $k => $v) {
			if (!defined($k)) {
				define($k, $v);
			}
		}
	}

	protected function setUp(): void {
		@mkdir(STREAMS_PATH, 0o777, true);
		StreamSource::useLoader(function (string $rWhat, int $rID, array $rOpts) {
			if ($rWhat === 'stream') {
				return $this->streamInfo();
			}
			if ($rWhat === 'server') {
				return ['parent_id' => 0, 'server_id' => 1, 'on_demand' => 0];
			}
			if ($rWhat === 'arguments') {
				return [];
			}
			return null;
		});
		$GLOBALS['rSettings'] = $this->settings();
		$GLOBALS['rServers'] = [1 => ['rtmp_port' => 1935, 'server_id' => 1]];
		$GLOBALS['rFFMPEG_CPU'] = '/bin/ffmpeg';
		$GLOBALS['rFFMPEG_GPU'] = '/bin/ffmpeg-gpu';
		$GLOBALS['rFFPROBE'] = '/bin/ffprobe';
		// log_path reads SettingsManager (not the global), so enable it here to
		// exercise the log-path tokenization.
		SettingsManager::set(['save_restart_logs' => 1]);
	}

	protected function tearDown(): void {
		StreamSource::useLoader(null);
		SettingsManager::set([]);
		foreach (['7_.key', '7_.iv', '7.errors', '7_.source_0', '7_.source_1'] as $rF) {
			@unlink(STREAMS_PATH . $rF);
		}
	}

	/** @return array<string,mixed> */
	private function streamInfo(array $rOver = []): array {
		return $rOver + [
			'id' => 7,
			'type_key' => 'live',
			'stream_source' => json_encode(['http://src.example/live.ts']),
			'direct_proxy' => 0,
			'delay_minutes' => 0,
			'custom_ffmpeg' => '',
			'stream_all' => 0,
			'custom_map' => '',
			'gen_timestamps' => 0,
			'read_native' => 0,
			'enable_transcode' => 0,
			'transcode_profile_id' => 0,
			'transcode_attributes' => '[]',
			'profile_options' => '[]',
			'rtmp_output' => 0,
			'probesize_ondemand' => 0,
			'llod' => 0,
			// Keyed by server_id — on MAIN (SERVER_ID 1) this is MAIN's bucket and
			// carries a push credential; portable mode must strip it.
			'external_push' => json_encode(['1' => ['rtmp://push.example/app?streamKey=PUSHSECRET']]),
		];
	}

	/** @return array<string,mixed> */
	private function settings(array $rOver = []): array {
		return $rOver + [
			'seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4,
			'encrypt_hls' => 0, 'fanout_source_backend' => 'ffmpeg',
			'live_streaming_pass' => 'secret', 'priority_backup' => 0,
			'ffmpeg_warnings' => 0, 'read_native_hls' => 0, 'dts_legacy_ffmpeg' => 0,
			'ignore_keyframes' => 0, 'save_restart_logs' => 0, 'stream_max_analyze' => 0,
			'probesize' => 1000000, 'probe_extra_wait' => 0,
		];
	}

	public function testPortableSpecCarriesTokensNotNodePaths(): void {
		$rSpec = StreamProcess::buildSupervisorSpec(7, ['features' => []]);
		$this->assertNotNull($rSpec, 'a plain CPU transcode stream builds a portable Spec');
		$rCmd = $rSpec['sources'][0]['cmd'];

		$this->assertStringContainsString('{ingest_sock}', $rCmd);
		$this->assertStringContainsString('{ffmpeg_cpu}', $rCmd);
		$this->assertStringContainsString('{streams}', $rCmd);
		$this->assertStringNotContainsString(STREAMS_PATH, $rCmd, 'no node STREAMS_PATH prefix leaks');
		$this->assertSame('{logs}stream_log.log', $rSpec['log_path'], 'a populated log_path is tokenized');

		$this->assertSame('{streams}7_.pid', $rSpec['pid_path']);
		$this->assertSame('{streams}7.errors', $rSpec['errors_path']);
		$this->assertSame('{streams}7_.m3u8', $rSpec['adopt_match']);
		$this->assertSame('{server_id}', $rSpec['server_id']);

		// No node-local absolute path leaks ANYWHERE in the Spec: every test path
		// constant shares this prefix, so one scan catches a missed constant too.
		$this->assertStringNotContainsString('/tmp/xcvm-test-', (string) json_encode($rSpec), 'no node path leaks into the portable Spec');
		// external_push (MAIN's bucket + its push credential) is stripped.
		$this->assertStringNotContainsString('PUSHSECRET', (string) json_encode($rSpec), 'external_push is stripped in portable mode');
	}

	public function testPortableLoopbackStreamIsNotComposed(): void {
		// A loopback child (parent_id > 0) is out of portable scope: its source is
		// a relay URL carrying live_streaming_pass, which must never be composed
		// into a replicated Spec. Portable mode returns null → directive "other".
		StreamSource::useLoader(function (string $rWhat, int $rID, array $rOpts) {
			if ($rWhat === 'stream') {
				return $this->streamInfo();
			}
			if ($rWhat === 'server') {
				return ['parent_id' => 3, 'server_id' => 1, 'on_demand' => 0];
			}
			return [];
		});
		$GLOBALS['rSettings'] = $this->settings(['live_streaming_pass' => 'TOP-SECRET-RELAY-PASS']);
		$this->assertNull(
			StreamProcess::buildSupervisorSpec(7, ['features' => []]),
			'a loopback child must not be composed in portable mode (no secret leak)'
		);
	}

	public function testPortableModeHasNoSideEffects(): void {
		$rSpec = StreamProcess::buildSupervisorSpec(7, ['features' => []]);
		// A non-null Spec with the {ingest_sock} token intact proves registerIngest
		// was NOT called: if it had been, it would have failed (no fanout in the test
		// → null Spec) or replaced the token with a real socket path.
		$this->assertNotNull($rSpec, 'portable mode composes without touching the fanout');
		$this->assertStringContainsString('{ingest_sock}', $rSpec['sources'][0]['cmd'], 'ingest socket stayed a token — registerIngest not called');
		$this->assertFileDoesNotExist(STREAMS_PATH . '7_.key', 'portable mode writes no key file');
		$this->assertFileDoesNotExist(STREAMS_PATH . '7_.iv', 'portable mode writes no IV file');
	}

	public function testPortableRemuxGatedOnTargetFeatures(): void {
		// backend=auto, but the target reports no `remux` feature → the native
		// remuxer is not composed (no {binary}); the command stays ffmpeg. MAIN
		// cannot know a remote node's live remux capability, so it defaults off.
		$GLOBALS['rSettings'] = $this->settings(['fanout_source_backend' => 'auto']);
		$rSpec = StreamProcess::buildSupervisorSpec(7, ['features' => []]);
		$this->assertStringNotContainsString('{binary}', (string) json_encode($rSpec), 'no native remuxer without the remux feature');
		$this->assertStringContainsString('{ffmpeg_cpu}', $rSpec['sources'][0]['cmd']);
		// backend=auto with no remux feature takes the refusal path, which on-node
		// would noteProducer to disk — portable mode must write nothing.
		$this->assertFileDoesNotExist(STREAMS_PATH . '7.errors', 'portable mode writes no producer note');
	}

	public function testPortableDtsLegacyNeitherLeaksBinaryNorSwaps(): void {
		// AC3 audio + dts_legacy_ffmpeg on would, on-node, swap {ffmpeg_cpu} for
		// FfmpegPaths::legacy() (a MAIN binary path) and decide -nofix_dts from the
		// placeholder. Portable forces the setting off so the token survives, and
		// the fail-closed guard rejects any residual MAIN binary.
		@mkdir(CACHE_TMP_PATH, 0o777, true);
		$rCache = CACHE_TMP_PATH . md5('http://src.example/live.ts');
		file_put_contents($rCache, igbinary_serialize(['container' => 'mpegts', 'codecs' => ['audio' => ['codec_name' => 'ac3']]]));
		$GLOBALS['rSettings'] = $this->settings(['dts_legacy_ffmpeg' => 1]);
		try {
			$rSpec = StreamProcess::buildSupervisorSpec(7, ['features' => []]);
			$this->assertNotNull($rSpec, 'no MAIN binary in the Spec (the guard did not trip)');
			$rCmd = $rSpec['sources'][0]['cmd'];
			$this->assertStringContainsString('{ffmpeg_cpu}', $rCmd, 'binary token survives the AC3/dts_legacy path');
			$this->assertStringNotContainsString(FFMPEG_BIN_40, $rCmd, 'no legacy MAIN binary swapped into the command');
		} finally {
			@unlink($rCache);
		}
	}

	public function testPortableSpecSignalsEncryption(): void {
		$GLOBALS['rSettings'] = $this->settings(['encrypt_hls' => 1]);
		$rSpec = StreamProcess::buildSupervisorSpec(7, ['features' => []]);
		$this->assertTrue($rSpec['encrypt'], 'encrypt flag tells the agent to generate+register a key/IV');
		$this->assertFileDoesNotExist(STREAMS_PATH . '7_.key', 'still no key file written on MAIN');
	}
}
