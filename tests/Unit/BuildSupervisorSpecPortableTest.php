<?php

use PHPUnit\Framework\TestCase;
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
	}

	protected function tearDown(): void {
		StreamSource::useLoader(null);
		@unlink(STREAMS_PATH . '7_.key');
		@unlink(STREAMS_PATH . '7_.iv');
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
		$this->assertStringNotContainsString(LOGS_TMP_PATH, (string) $rSpec['log_path'] ?: '{logs}', 'log_path tokenized');

		$this->assertSame('{streams}7_.pid', $rSpec['pid_path']);
		$this->assertSame('{streams}7.errors', $rSpec['errors_path']);
		$this->assertSame('{streams}7_.m3u8', $rSpec['adopt_match']);
		$this->assertSame('{server_id}', $rSpec['server_id']);

		// No node-local absolute path leaks ANYWHERE in the Spec: every test path
		// constant shares this prefix, so one scan catches a missed constant too.
		$this->assertStringNotContainsString('/tmp/xcvm-test-', (string) json_encode($rSpec), 'no node path leaks into the portable Spec');
	}

	public function testPortableModeHasNoSideEffects(): void {
		StreamProcess::buildSupervisorSpec(7, ['features' => []]);
		$this->assertFileDoesNotExist(STREAMS_PATH . '7_.key', 'portable mode writes no key file');
		$this->assertFileDoesNotExist(STREAMS_PATH . '7_.iv', 'portable mode writes no IV file');
	}

	public function testPortableSpecSignalsEncryption(): void {
		$GLOBALS['rSettings'] = $this->settings(['encrypt_hls' => 1]);
		$rSpec = StreamProcess::buildSupervisorSpec(7, ['features' => []]);
		$this->assertTrue($rSpec['encrypt'], 'encrypt flag tells the agent to generate+register a key/IV');
		$this->assertFileDoesNotExist(STREAMS_PATH . '7_.key', 'still no key file written on MAIN');
	}
}
