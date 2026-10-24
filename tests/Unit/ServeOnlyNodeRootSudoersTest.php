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

		// Ordering is pinned in ServeOnlyProvisionOrderTest; here only that a restart
		// (which relaunches the agent as xc_vm) still follows the grant.
		$rSudoers = strpos($rCmd, 'LbInstallFlow::provisionNodeRootSudoers(');
		$this->assertNotFalse(strpos($rCmd, 'systemctl restart xc_vm', (int) $rSudoers), 'a restart follows the sudoers provisioning');
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

	public function testDowngradeRevokesTheNodeRootGrantInsideTheMarkerGuard(): void {
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$rPos = strpos($rCmd, "'if sudo test -f ' . CONFIG_PATH . 'cluster/serve_only; then ");
		$this->assertNotFalse($rPos);
		$rLine = substr($rCmd, $rPos, strpos($rCmd, "\n", $rPos) - $rPos);
		$this->assertSame(
			"'if sudo test -f ' . CONFIG_PATH . 'cluster/serve_only; then sudo rm -f ' . LbInstallFlow::NODE_ROOT_SUDOERS . ' ' . LbInstallFlow::NODE_ROOT_HELPER . '; sudo -u xc_vm rm -f ' . CONFIG_PATH . 'cluster/serve_only && sudo systemctl restart xc_vm; fi');",
			trim($rLine),
			'exact downgrade command: revoke (plain sudo, root-owned) then marker removal then restart, all inside the marker guard'
		);
		$this->assertStringContainsString('sudo -u xc_vm rm -f ', $rLine, 'marker removal kept');
		$this->assertLessThan(strpos($rLine, 'systemctl restart'), strpos($rLine, 'LbInstallFlow::NODE_ROOT_SUDOERS'), 'grant is gone before the restart');
		$this->assertLessThan(strpos($rLine, '; fi'), strpos($rLine, 'LbInstallFlow::NODE_ROOT_HELPER'), 'inside the guard');
	}
}
