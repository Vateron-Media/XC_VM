<?php

use XcVm\Cli\Commands\UpdateCommand;
use PHPUnit\Framework\TestCase;

/**
 * UpdateCommand::pinned() — the release MAIN names to a load balancer.
 *
 * A MAIN on the dev channel names its nightly (x.y.z-dev.N); dropping it sent
 * the LB to its own channel lookup instead of MAIN's build.
 */
class UpdateCommandPinnedTest extends TestCase {
	public function testAcceptsReleaseAndNightlyTags(): void {
		$this->assertSame('1.2.3', UpdateCommand::pinned(' 1.2.3 '));
		$this->assertSame('1.2.3-dev.7', UpdateCommand::pinned('1.2.3-dev.7'));
	}

	/** cron:servers: a load balancer stays listed until it is on MAIN's release, and is told when it is back. */
	public function testAListedLoadBalancerIsToldWhenItIsBackAndNotARolledBackOne(): void {
		if (!defined('XC_VM_VERSION')) {
			define('XC_VM_VERSION', '2.6.0');
		}
		$rNow = 1800000000;
		$rLb = ['is_main' => 0, 'server_type' => 0, 'enabled' => 1, 'status' => 1, 'last_check_ago' => $rNow - 30, 'xc_vm_version' => '0.0.1'];
		$rServers = [
			1 => ['id' => 1, 'is_main' => 1] + $rLb,
			2 => ['id' => 2] + $rLb,
			3 => ['id' => 3, 'xc_vm_version' => XC_VM_VERSION] + $rLb,
			4 => ['id' => 4, 'server_type' => 1] + $rLb,
			5 => ['id' => 5, 'status' => 5] + $rLb,
			6 => ['id' => 6, 'last_check_ago' => $rNow - 600] + $rLb,
			7 => ['id' => 7, 'enabled' => 0] + $rLb,
			8 => ['id' => 8, 'xc_vm_version' => null] + $rLb,
			10 => ['id' => 10] + $rLb,
		];
		// Listed and never told (0). Back and behind (2), or its release
		// unknown (8): told. Still updating (5), offline (6) or disabled (7):
		// waited for. MAIN, one on MAIN's release by now (3), a proxy (4) and
		// one that is gone (9): dropped.
		$rNever = array_fill_keys([1, 2, 3, 4, 5, 6, 7, 8, 9], 0);
		$this->assertSame(['tell' => [2, 8], 'wait' => [2 => 0, 5 => 0, 6 => 0, 7 => 0, 8 => 0]], UpdateCommand::lbsToTell($rServers, $rNever, $rNow));
		// Told an hour ago: its update is on its way, it only stays listed.
		// Told more than a day ago and still behind: that update expired while
		// it was away, so it is told again.
		$this->assertSame(['tell' => [], 'wait' => [2 => $rNow - 3600]], UpdateCommand::lbsToTell($rServers, [2 => $rNow - 3600], $rNow));
		$this->assertSame(['tell' => [2], 'wait' => [2 => $rNow - 90000]], UpdateCommand::lbsToTell($rServers, ['2' => $rNow - 90000], $rNow));
		// 10 is behind and online but not listed: it reached MAIN's release,
		// then an admin rolled it back, and it stays there.
		$this->assertSame(['tell' => [], 'wait' => []], UpdateCommand::lbsToTell($rServers, [], $rNow));
	}

	public function testRejectsAnythingElse(): void {
		foreach ([null, '', '1.2', '1.2.3-beta.1', '1.2.3-dev', '1.2.3;id', 123] as $rBad) {
			$this->assertNull(UpdateCommand::pinned($rBad));
		}
	}
}
