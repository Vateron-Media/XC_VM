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

	public function testSudoersIsArgumentRestrictedValidatedAndFailsSafe(): void {
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		// Scoped NOPASSWD for xc_vm — never the whole-root NOPASSWD: ALL.
		$this->assertStringContainsString('xc_vm ALL=(root) NOPASSWD: ', $rLb);
		$this->assertStringNotContainsString('NOPASSWD: ALL', $rLb);
		// Every rule is pinned to the EXACT command + arguments the agent issues —
		// not the bare binary — so the grant cannot become arbitrary root.
		foreach ([
			'/usr/sbin/ipset restore',
			'/usr/sbin/ipset flush xcvm_block4',
			'/usr/sbin/iptables -C INPUT -m set --match-set xcvm_block4 src -j DROP',
			'/usr/sbin/ip6tables -I INPUT -m set --match-set xcvm_block6 src -j DROP',
			'/usr/bin/systemctl stop xc_vm',
			'/usr/bin/systemctl start xc_vm',
			'/usr/sbin/reboot',
			'/usr/bin/umount -l ',
			'/usr/sbin/modprobe ip_conntrack',
			'/usr/sbin/sysctl -p',
			'/usr/bin/cpufreq-set -c * -g *',
			'/usr/bin/tee /etc/fstab',
			'/usr/bin/tee /etc/sysctl.conf',
			'/usr/bin/chmod [0-7][0-7][0-7] /etc/fstab',
			'content/streams', // mount/umount/chown pinned to the streams path
		] as $rNeedle) {
			$this->assertStringContainsString($rNeedle, $rLb, "sudoers pins: $rNeedle");
		}
		// The dangerous vectors are NOT granted as bare binaries (no arbitrary
		// root file write / service control): no allowlist entry is a bare tee,
		// chmod, chown or systemctl (each would be the binary followed immediately
		// by the array element's closing quote).
		$this->assertStringNotContainsString("/usr/bin/tee'", $rLb);
		$this->assertStringNotContainsString("/usr/bin/chmod'", $rLb);
		$this->assertStringNotContainsString("/usr/bin/chown'", $rLb);
		$this->assertStringNotContainsString("/usr/bin/systemctl'", $rLb);
		// Root-owned path, 0440, validated with visudo, removed on any syntax error.
		$this->assertStringContainsString('/etc/sudoers.d/xcvm-noderoot', $rLb);
		$this->assertStringContainsString('chmod 0440', $rLb);
		$this->assertStringContainsString('visudo -cf', $rLb);
		$this->assertStringContainsString('|| sudo rm -f', $rLb);
	}
}
