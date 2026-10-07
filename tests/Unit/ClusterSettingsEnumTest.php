<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Domain\Cluster\ClusterApi;
use XcVm\Domain\Cluster\ClusterPolicy;

/**
 * ClusterSettings::enum(): how every reader takes an enum setting, so a
 * default lives in ENUMS alone. An allowed value is itself, exactly as
 * normalize() stores it; anything else, unset included, is the default.
 * ClusterPolicy::ver() and ClusterApi::PROTO_RANGE are the other values the
 * cluster readers used to spell out each.
 */
final class ClusterSettingsEnumTest extends TestCase {
	public function testAnAllowedValueIsItself(): void {
		foreach (ClusterSettings::ENUMS as $rKey => [, $rAllowed]) {
			foreach ($rAllowed as $rValue) {
				$this->assertSame($rValue, ClusterSettings::enum($rKey, $rValue), $rKey);
			}
		}
	}

	public function testAnythingElseIsTheDefault(): void {
		foreach (ClusterSettings::ENUMS as $rKey => [$rDefault, $rAllowed]) {
			foreach ([null, '', 'nope', 0, 1, true, [], [$rAllowed[1]], strtoupper($rAllowed[1]), ' ' . $rAllowed[1]] as $rValue) {
				$this->assertSame($rDefault, ClusterSettings::enum($rKey, $rValue), $rKey . ' ' . var_export($rValue, true));
			}
		}
	}

	public function testWhatNormalizeStoresReadsBackTheSame(): void {
		$rEnv = ['https_ok' => true, 'api_mode_allowed' => true, 'credential_free_config' => true];
		foreach (ClusterSettings::ENUMS as $rKey => [, $rAllowed]) {
			foreach ([...$rAllowed, 'nope', ' ' . strtoupper($rAllowed[0]) . ' '] as $rValue) {
				// The cluster API on, as API mode for new nodes needs it.
				[$rOut] = ClusterSettings::normalize([$rKey => $rValue], [], ['cluster_api_enabled' => 1], $rEnv);
				$this->assertSame($rOut[$rKey], ClusterSettings::enum($rKey, $rOut[$rKey]), $rKey . ' ' . $rValue);
			}
		}
	}

	public function testTheDefaultsTheReadersUsed(): void {
		// What the call sites hard-coded before they read ENUMS through enum().
		$this->assertSame('legacy', ClusterSettings::ENUMS['lb_new_node_mode'][0]);
		$this->assertSame('auto', ClusterSettings::ENUMS['cluster_transport'][0]);
		$this->assertSame('graceful', ClusterSettings::ENUMS['lb_revocation_mode'][0]);
		$this->assertSame('local', ClusterSettings::ENUMS['lb_offline_admission'][0]);
	}

	public function testThePolicyNamesTheTransportAsRead(): void {
		$rMain = ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 80];
		$this->assertSame('auto', ClusterPolicy::current([], $rMain)['transport']);
		$this->assertSame('https_required', ClusterPolicy::current(['cluster_transport' => 'https_required'], $rMain)['transport']);
		$rUnknown = ClusterPolicy::current(['cluster_transport' => 'bogus'], $rMain);
		$this->assertSame('auto', $rUnknown['transport']);
		$this->assertSame(['http://10.0.0.1:80/cluster/v1/'], $rUnknown['main_urls'], 'plain HTTP, as before');
	}

	public function testThePolicyVersion(): void {
		$this->assertSame(1, ClusterPolicy::ver([]), 'unset: the column default');
		$this->assertSame(1, ClusterPolicy::ver(['cluster_policy_ver' => null]));
		$this->assertSame(7, ClusterPolicy::ver(['cluster_policy_ver' => '7']));
		$this->assertSame(0, ClusterPolicy::ver(['cluster_policy_ver' => 'x']), 'intval, as the readers took it');
		$this->assertSame(7, ClusterPolicy::current(['cluster_policy_ver' => 7], [])['policy_ver']);
	}

	public function testTheProtocolRange(): void {
		$this->assertSame(['min' => ClusterApi::PROTO_MIN, 'max' => ClusterApi::PROTO_MAX], ClusterApi::PROTO_RANGE);
	}
}
