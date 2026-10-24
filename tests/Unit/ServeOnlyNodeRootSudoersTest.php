<?php

use PHPUnit\Framework\TestCase;

/**
 * A serve-only (PHP-free) node's agent runs as xc_vm and escalates the node.root
 * and viewer-ban commands via `sudo -n` (XC_VM_Fanout privCmd/privWriteFile), so
 * the install must grant xc_vm a NOPASSWD sudo allowlist scoped to exactly those
 * binaries — written to a root-owned path, validated with visudo, and removed on
 * any syntax error so a bad write can never lock sudo out.
 *
 * @covers \XcVm\Cli\Commands\LbInstallFlow
 * @covers \XcVm\Cli\Commands\ServerInstallCommand
 */
final class ServeOnlyNodeRootSudoersTest extends TestCase {
	public function testServeOnlyInstallProvisionsTheSudoersBeforeTheRestart(): void {
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString('LbInstallFlow::provisionNodeRootSudoers($rConn, $rRunSSH)', $rCmd);

		// Ordering: written in the serve-only completion AFTER PHP is removed and
		// BEFORE the restart that (re)launches the agent as xc_vm — so the allowlist
		// is already in place when the agent first runs sudo -n.
		$rRm = strpos($rCmd, "console.php ' . MAIN_HOME . 'bin/php");
		$rSudoers = strpos($rCmd, 'provisionNodeRootSudoers');
		$this->assertNotFalse($rRm);
		$this->assertNotFalse($rSudoers);
		$this->assertGreaterThan($rRm, $rSudoers, 'the sudoers is written after PHP removal');
		$rRestartAfter = strpos($rCmd, 'systemctl restart xc_vm', $rSudoers);
		$this->assertNotFalse($rRestartAfter, 'a restart follows the sudoers provisioning');
	}

	public function testSudoersGrantsOnlyTheHelperValidatedAndInstalledAtomically(): void {
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringNotContainsString('NOPASSWD: ALL', $rLb);
		$this->assertSame(1, substr_count($rLb, 'NOPASSWD: "'), 'a single grant');
		foreach (['/usr/bin/tee', '/usr/bin/chmod', '/usr/bin/chown', '/usr/bin/systemctl', 'cpufreq-set', 'ipset restore'] as $rOld) {
			$this->assertStringNotContainsString($rOld, $rLb);
		}
		$this->assertStringContainsString('visudo -cf', $rLb);
		$this->assertStringContainsString('install -m0440 -o root -g root', $rLb);
	}
}
