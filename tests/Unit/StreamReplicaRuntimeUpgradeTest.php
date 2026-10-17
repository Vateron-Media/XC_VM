<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\StreamReplica;
use XcVm\Domain\Stream\StreamSource;

/**
 * StreamReplica::runtimeUpgrade — the serve-only composer closure (Phase B1):
 * for a serve-only target it composes the portable Spec and upgrades an "other"
 * directive to "transcode", leaving a proxy or a non-supervisable stream
 * alone. Runs DB-free via StreamSource::useLoader (same portable-mode snapshot
 * as BuildSupervisorSpecPortableTest), so it does not exercise the DB-backed
 * runtimeLoader — only the compose+upgrade seam.
 *
 * @covers \XcVm\Domain\Cluster\StreamReplica
 */
final class StreamReplicaRuntimeUpgradeTest extends TestCase {

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
			return [];
		});
		$GLOBALS['rSettings'] = $this->settings();
		$GLOBALS['rServers'] = [1 => ['rtmp_port' => 1935, 'server_id' => 1]];
		$GLOBALS['rFFMPEG_CPU'] = '/bin/ffmpeg';
		$GLOBALS['rFFMPEG_GPU'] = '/bin/ffmpeg-gpu';
		$GLOBALS['rFFPROBE'] = '/bin/ffprobe';
		SettingsManager::set([]);
	}

	protected function tearDown(): void {
		StreamSource::useLoader(null);
		SettingsManager::set([]);
		foreach (['7_.key', '7_.iv', '7.errors'] as $rF) {
			@unlink(STREAMS_PATH . $rF);
		}
	}

	/** @return array<string,mixed> */
	private function streamInfo(): array {
		return [
			'id' => 7, 'type_key' => 'live', 'stream_source' => json_encode(['http://src.example/live.ts']),
			'direct_proxy' => 0, 'delay_minutes' => 0, 'custom_ffmpeg' => '', 'stream_all' => 0, 'custom_map' => '',
			'gen_timestamps' => 0, 'read_native' => 0, 'enable_transcode' => 0, 'transcode_profile_id' => 0,
			'transcode_attributes' => '[]', 'profile_options' => '[]', 'rtmp_output' => 0, 'probesize_ondemand' => 0,
			'llod' => 0, 'external_push' => '[]',
		];
	}

	/** @return array<string,mixed> */
	private function settings(array $rOver = []): array {
		return $rOver + [
			'seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4, 'encrypt_hls' => 0,
			'fanout_source_backend' => 'ffmpeg', 'live_streaming_pass' => 'secret', 'priority_backup' => 0,
			'ffmpeg_warnings' => 0, 'read_native_hls' => 0, 'dts_legacy_ffmpeg' => 0, 'ignore_keyframes' => 0,
			'save_restart_logs' => 0, 'stream_max_analyze' => 0, 'probesize' => 1000000, 'probe_extra_wait' => 0,
		];
	}

	public function testServeOnlyComposerUpgradesOtherToTranscode(): void {
		$rUpgrade = StreamReplica::runtimeUpgrade(1, []);
		$rOut = $rUpgrade(7, ['kind' => 'other', 'source' => null]);

		$this->assertSame('transcode', $rOut['kind']);
		$this->assertStringContainsString('{ffmpeg_cpu}', $rOut['spec']['sources'][0]['cmd']);
		$this->assertStringNotContainsString('/tmp/xcvm-test-', (string) json_encode($rOut), 'no node path leaks into the directive');
	}

	public function testComposerLeavesProxyUntouched(): void {
		// A proxy is served by the puller; the composer must not displace it.
		$rProxy = ['kind' => 'proxy', 'source' => ['urls' => ['http://x']]];
		$this->assertSame($rProxy, StreamReplica::runtimeUpgrade(1, [])(7, $rProxy));
	}

	public function testComposerKeepsLoopbackChildAsOther(): void {
		// A loopback child (parent_id > 0): its source carries live_streaming_pass,
		// so buildSupervisorSpec returns null in portable mode and the directive
		// stays "other" — no relay secret may reach the record.
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

		$rOut = StreamReplica::runtimeUpgrade(1, [])(7, ['kind' => 'other', 'source' => null]);

		$this->assertSame('other', $rOut['kind']);
		$this->assertArrayNotHasKey('spec', $rOut);
		$this->assertStringNotContainsString('TOP-SECRET-RELAY-PASS', (string) json_encode($rOut), 'no relay secret in the directive');
	}
}
