<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterSettings;

/**
 * ClusterSettings::normalize(): numbers clamp, unknown enums fall back, and the
 * checks that must refuse rather than clamp do refuse.
 */
final class ClusterSettingsTest extends TestCase {
	private const MAIN = ['http_broadcast_port' => 25461, 'https_broadcast_port' => 25463, 'rtmp_port' => 8880, 'http_ports_add' => '8080,8081', 'https_ports_add' => ''];

	private function norm(array $rNew, array $rCurrent = [], array $rEnv = []): array {
		return ClusterSettings::normalize($rNew, self::MAIN, $rCurrent, $rEnv);
	}

	public function testThereAreTwentyThreeSettings(): void {
		$this->assertCount(23, ClusterSettings::keys());
	}

	public function testNumbersClampAndEnumsFallBack(): void {
		[$rOut, $rErrors] = $this->norm([
			'lb_token_rotation_min' => 2, 'lb_partition_tolerance_h' => 99, 'lb_telemetry_interval_sec' => 10,
			'cluster_ingest_concurrency' => 'x', 'lb_revocation_mode' => 'HARD', 'lb_offline_admission' => 'maybe',
		]);
		$this->assertSame([], $rErrors);
		$this->assertSame(['lb_token_rotation_min' => 5, 'lb_partition_tolerance_h' => 24, 'lb_telemetry_interval_sec' => 3, 'cluster_ingest_concurrency' => 6, 'lb_revocation_mode' => 'hard', 'lb_offline_admission' => 'local'], $rOut);
	}

	public function testOnlySubmittedKeysAreTouched(): void {
		$this->assertSame([[], []], $this->norm(['something_else' => 1]));
	}

	public function testGrace(): void {
		$this->assertSame(15, ClusterSettings::graceMin(60));
		$this->assertSame(5, ClusterSettings::graceMin(5));
		$this->assertSame(60, ClusterSettings::graceMin(1440));
		$this->assertSame(5, ClusterSettings::graceMin(1), 'L clamps first');
	}

	public function testPortCollisions(): void {
		foreach ([25461, 25463, 8880, 8080, 8081, 31210, 31290, 3306, 6379] as $rPort) {
			$this->assertSame([[], [['cluster_api_port', 'cluster_error_port_taken']]], $this->norm(['cluster_api_port' => $rPort]), (string) $rPort);
		}
		$this->assertSame([[], [['cluster_api_port', 'cluster_error_port_range']]], $this->norm(['cluster_api_port' => 443000]));
		$this->assertSame([[], [['cluster_api_port', 'cluster_error_port_range']]], $this->norm(['cluster_api_port' => 1023]));
		$this->assertSame([['cluster_api_port' => 0], []], $this->norm(['cluster_api_port' => '0']));
		$this->assertSame([['cluster_api_port' => 25500], []], $this->norm(['cluster_api_port' => '25500']));
	}

	public function testMainHost(): void {
		$this->assertSame([['cluster_main_host' => 'panel.example.com'], []], $this->norm(['cluster_main_host' => ' Panel.Example.com ']));
		$this->assertSame([['cluster_main_host' => ''], []], $this->norm(['cluster_main_host' => '']));
		foreach (['10.0.0.1', 'not a host', 'localhost', '-bad.example.com'] as $rBad) {
			$this->assertSame([[], [['cluster_main_host', 'cluster_error_host']]], $this->norm(['cluster_main_host' => $rBad]), $rBad);
		}
	}

	public function testScanRoots(): void {
		$this->assertSame([['lb_scan_roots' => '["/mnt/media","/srv"]'], []], $this->norm(['lb_scan_roots' => "/mnt/media/\n\n/srv\n/srv"]));
		$this->assertSame([['lb_scan_roots' => '["/home/xc_vm/content","/mnt","/media"]'], []], $this->norm(['lb_scan_roots' => '']), 'empty = defaults');
		foreach (['relative/dir', '/mnt/../etc', '/mnt//x', '/'] as $rBad) {
			$this->assertSame([[], [['lb_scan_roots', 'cluster_error_scan_roots']]], $this->norm(['lb_scan_roots' => $rBad]), $rBad);
		}
	}

	public function testHttpsRequiredNeedsWorkingHttps(): void {
		$this->assertSame([[], [['cluster_transport', 'cluster_error_https_probe']]], $this->norm(['cluster_transport' => 'https_required'], [], ['https_ok' => false]));
		$this->assertSame([[], [['cluster_transport', 'cluster_error_https_nodes']]], $this->norm(['cluster_transport' => 'https_required'], [], ['https_ok' => true, 'nodes_https_ok' => false]));
		$this->assertSame([['cluster_transport' => 'https_required'], []], $this->norm(['cluster_transport' => 'https_required'], [], ['https_ok' => true]));
		$this->assertSame([['cluster_transport' => 'https_required'], []], $this->norm(['cluster_transport' => 'https_required'], ['cluster_transport' => 'https_required']), 'already on: a resave does not re-probe');
	}

	public function testEnablingNeedsTheExtension(): void {
		$this->assertSame([[], [['cluster_api_enabled', 'cluster_error_extension']]], $this->norm(['cluster_api_enabled' => 1]));
		$this->assertSame([['cluster_api_enabled' => 1], []], $this->norm(['cluster_api_enabled' => 1], [], ['extension_ok' => true]));
		$this->assertSame([['cluster_api_enabled' => 0], []], $this->norm(['cluster_api_enabled' => 0]), 'switching off always works');
	}

	public function testApiModeWaitsForCutover(): void {
		$this->assertSame([[], [['lb_new_node_mode', 'cluster_error_api_mode_api_off']]], $this->norm(['lb_new_node_mode' => 'api']), 'the cluster API off');
		$this->assertSame([[], [['lb_new_node_mode', 'cluster_error_api_mode_extension']]], $this->norm(['lb_new_node_mode' => 'api'], ['cluster_api_enabled' => 1]));
		$this->assertSame([['lb_new_node_mode' => 'legacy'], []], $this->norm(['lb_new_node_mode' => 'legacy']));
	}

	public function testHttpsProbeWithoutHttpsOrDomain(): void {
		$this->assertSame('https_disabled', ClusterSettings::httpsSelfProbe(['enable_https' => 0])['reason']);
		$this->assertSame('no_domain', ClusterSettings::httpsSelfProbe(['enable_https' => 1, 'domain_name' => '10.0.0.1'])['reason']);
	}

	public function testEveryKeyHasATranslationInEveryLanguage(): void {
		$rDir = MAIN_HOME . 'Core/Localization/lang/';
		foreach (glob($rDir . '*.ini') as $rFile) {
			$rStrings = parse_ini_file($rFile, false, INI_SCANNER_RAW);
			foreach (array_merge(ClusterSettings::keys(), ['cluster', 'cluster_error_port_taken', 'cluster_error_https_probe', 'cluster_error_extension']) as $rKey) {
				$this->assertArrayHasKey($rKey, $rStrings, basename($rFile) . ': ' . $rKey);
			}
		}
	}
}
