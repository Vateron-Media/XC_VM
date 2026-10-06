<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ServerEnrolCommand;
use XcVm\Tests\Support\FakeClusterCrypto;
use XcVm\Tests\Support\FakeSshFleet;

/**
 * server:enrol asks the node whether it runs this release as root, like every
 * other step of the enrolment: config/ and, once the node has been updated,
 * bin/ are closed to every user but xc_vm, so the SSH user itself may not be
 * able to see the files.
 */
final class AuditModInstallEnrolReadinessTest extends TestCase {
	public function testTheReleaseIsLookedForAsRoot(): void {
		$rSsh = new FakeSshFleet();
		// A node without the release: enrol() stops at the check.
		$rSsh->rNodes['10.0.0.7'] = ['hostkey' => sha1('host-7'), 'password' => 'pw', 'ready' => false];
		$rServers = [7 => ['id' => 7, 'is_main' => 0, 'server_type' => 0, 'server_ip' => '10.0.0.7', 'ssh_hostkey_sha1' => sha1('host-7')]];

		ob_start();
		try {
			$rWhy = ServerEnrolCommand::enrol($rServers, 7, 22, ['username' => 'admin', 'password' => 'pw'], null, new FakeClusterCrypto(), $rSsh);
		} finally {
			ob_end_clean();
		}

		$this->assertStringStartsWith('The node does not run this panel release yet', (string) $rWhy);
		$rCommands = $rSsh->commands('10.0.0.7');
		$this->assertCount(1, $rCommands);
		preg_match_all('/(sudo )?test -f (\S+)/', $rCommands[0], $rTests);
		$this->assertSame(['/home/xc_vm/bin/xc_agent/run.sh', '/home/xc_vm/config/config.enc'], $rTests[2]);
		$this->assertSame(['sudo ', 'sudo '], $rTests[1], 'each file is looked for as root');
		$this->assertStringEndsWith('&& echo READY', $rCommands[0]);
	}
}
