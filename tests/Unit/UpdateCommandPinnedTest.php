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

	/** cron:servers, hourly: a load balancer offline when MAIN updated is told later. */
	public function testOnlyLoadBalancersBehindMainAreToldToUpdate(): void {
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
		];
		// Behind (2) or unknown (8); not MAIN, a current one, a proxy, one
		// updating now, an offline one or a disabled one.
		$this->assertSame([2, 8], UpdateCommand::lbsBehind($rServers, $rNow));
	}

	public function testRejectsAnythingElse(): void {
		foreach ([null, '', '1.2', '1.2.3-beta.1', '1.2.3-dev', '1.2.3;id', 123] as $rBad) {
			$this->assertNull(UpdateCommand::pinned($rBad));
		}
	}
}
