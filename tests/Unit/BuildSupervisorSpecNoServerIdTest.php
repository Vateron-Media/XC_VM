<?php

use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\StreamProcess;
use XcVm\Domain\Stream\StreamSource;

/**
 * Portable buildSupervisorSpec must compose in the cluster API process, whose
 * bootstrap (Public/cluster/index.php → ConstantsInitializer) does NOT define
 * the SERVER_ID constant (only LegacyInitializer does). Portable mode composes
 * for a TARGET node, so it must never read MAIN's SERVER_ID.
 *
 * This runs in a separate process WITHOUT SERVER_ID (the path constants are
 * defined, SERVER_ID deliberately is not) — reproducing the real entry point.
 * Other test classes define SERVER_ID process-wide, so isolation is required.
 *
 * @covers \XcVm\Domain\Stream\StreamProcess
 */
final class BuildSupervisorSpecNoServerIdTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState(false)]
	public function testPortableSpecComposesWithoutServerIdConstant(): void {
		foreach ([
			'STREAMS_PATH' => '/tmp/xcvm-nosid-streams/', 'LOGS_TMP_PATH' => '/tmp/xcvm-nosid-logs/',
			'CACHE_TMP_PATH' => '/tmp/xcvm-nosid-cache/', 'DELAY_PATH' => '/tmp/xcvm-nosid-delay/',
			'FFMPEG_BIN_40' => '/bin/ffmpeg40', 'FFPROBE_BIN_40' => '/bin/ffprobe40',
		] as $k => $v) {
			if (!defined($k)) {
				define($k, $v);
			}
		}
		$this->assertFalse(defined('SERVER_ID'), 'this test reproduces the cluster process: SERVER_ID is undefined');
		@mkdir(STREAMS_PATH, 0o777, true);
		StreamSource::useLoader(function (string $rWhat, int $rID, array $rOpts) {
			if ($rWhat === 'stream') {
				return [
					'id' => 7, 'type_key' => 'live', 'stream_source' => json_encode(['http://src.example/live.ts']),
					'direct_proxy' => 0, 'delay_minutes' => 0, 'custom_ffmpeg' => '', 'stream_all' => 0, 'custom_map' => '',
					'gen_timestamps' => 0, 'read_native' => 0, 'enable_transcode' => 0, 'transcode_profile_id' => 0,
					'transcode_attributes' => '[]', 'profile_options' => '[]', 'rtmp_output' => 0, 'probesize_ondemand' => 0,
					'llod' => 0, 'external_push' => '[]',
				];
			}
			if ($rWhat === 'server') {
				return ['parent_id' => 0, 'server_id' => 5, 'on_demand' => 0];
			}
			return [];
		});
		$GLOBALS['rSettings'] = [
			'seg_time' => 6, 'seg_list_size' => 8, 'seg_delete_threshold' => 4, 'encrypt_hls' => 0,
			'fanout_source_backend' => 'ffmpeg', 'live_streaming_pass' => 'secret', 'priority_backup' => 0,
			'ffmpeg_warnings' => 0, 'read_native_hls' => 0, 'dts_legacy_ffmpeg' => 0, 'ignore_keyframes' => 0,
			'save_restart_logs' => 0, 'stream_max_analyze' => 0, 'probesize' => 1000000, 'probe_extra_wait' => 0,
		];
		$GLOBALS['rFFMPEG_CPU'] = '/bin/ffmpeg';
		$GLOBALS['rFFMPEG_GPU'] = '/bin/ffmpeg-gpu';
		$GLOBALS['rFFPROBE'] = '/bin/ffprobe';
		SettingsManager::set([]);

		// Target server 5: composes a transcode Spec with a {server_id} token, and
		// must NOT throw "Undefined constant SERVER_ID" on the way.
		$rSpec = StreamProcess::buildSupervisorSpec(7, ['features' => [], 'server_id' => 5]);

		$this->assertNotNull($rSpec, 'portable Spec composes in a process with no SERVER_ID');
		$this->assertSame('{server_id}', $rSpec['server_id']);
		$this->assertStringContainsString('{ffmpeg_cpu}', $rSpec['sources'][0]['cmd']);
		StreamSource::useLoader(null);
	}
}
