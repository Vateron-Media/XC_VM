<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * The serve-only node's xc_vm may reach root through ONE grant: the root-owned
 * /usr/local/sbin/xcvm-noderoot helper. The helper is sent from MAIN's trusted tree
 * (never copied from the xc_vm-writable node copy) and the sudoers is validated in a
 * temp file before it is installed, so a bad write can never brick sudo.
 *
 * @covers \XcVm\Cli\Commands\LbInstallFlow
 */
final class ServeOnlyNodeRootHelperGrantTest extends TestCase {
	private const GRANT = "Defaults:xc_vm !requiretty\nxc_vm ALL=(root) NOPASSWD: /usr/local/sbin/xcvm-noderoot\n";

	/** @return array{0: callable, 1: \ArrayObject} */
	private function fakeRun(string $rFailOn = ''): array {
		$rLog = new \ArrayObject();
		$rRun = function ($rConn, string $rCmd) use ($rLog, $rFailOn): array {
			$rLog[] = $rCmd;
			$rOk = $rFailOn === '' || strpos($rCmd, $rFailOn) === false;
			return ['output' => $rOk ? "__NODEROOT_SUDOERS_OK__ __NODEROOT_HELPER_OK__\n" : '', 'exit' => $rOk ? 0 : 1];
		};
		return [$rRun, $rLog];
	}

	public function testSudoersIsExactlyTheHelperOnlyGrant(): void {
		[$rRun, $rLog] = $this->fakeRun();
		$this->assertTrue(LbInstallFlow::provisionNodeRootSudoers('c', $rRun));
		$rAll = implode("\n", (array) $rLog);
		$this->assertStringContainsString(escapeshellarg(self::GRANT), $rAll);
		foreach (['ipset', 'tee /etc/', 'systemctl', 'cpufreq-set', 'mount ', 'sysctl', 'iptables', 'reboot', 'chmod [', 'env_keep', 'XCVM_NODEROOT_ROOT', 'TMPDIR'] as $rOld) {
			$this->assertStringNotContainsString($rOld, $rAll, "old grant or env_keep remains: $rOld");
		}
		$this->assertSame(1, substr_count($rAll, 'NOPASSWD:'));
	}

	public function testValidatedInTempBeforeAnythingTouchesTheFinalPath(): void {
		[$rRun, $rLog] = $this->fakeRun();
		LbInstallFlow::provisionNodeRootSudoers('c', $rRun);
		$rAll = implode("\n", (array) $rLog);
		$rFinal = '/etc/sudoers.d/xcvm-noderoot';
		$rVisudo = strpos($rAll, 'visudo -cf');
		$rFirstFinal = strpos($rAll, $rFinal);
		$this->assertNotFalse($rVisudo);
		$this->assertGreaterThan($rVisudo, $rFirstFinal, 'the final path is first named after the visudo check');
		$this->assertStringNotContainsString('tee ' . $rFinal, $rAll);
		$this->assertStringNotContainsString('> ' . $rFinal, $rAll);
		$this->assertStringContainsString('install -m0440 -o root -g root', $rAll);
		$this->assertStringContainsString('&& echo __NODEROOT_SUDOERS_OK__', $rAll);
		$this->assertStringContainsString('rm -f', $rAll);
		// the install only follows a passing visudo
		$this->assertMatchesRegularExpression('/visudo -cf [^&;]*&&\s*sudo install/', $rAll);
	}

	public function testSudoersFailsWhenValidationOrInstallDoesNot(): void {
		[$rRun] = $this->fakeRun('visudo');
		$this->assertFalse(LbInstallFlow::provisionNodeRootSudoers('c', $rRun));
	}

	public function testHelperIsSentFromMainsTreeAndRootOwned(): void {
		[$rRun, $rLog] = $this->fakeRun();
		$rSent = [];
		$rSend = function ($rConn, string $rPath, string $rOut, bool $rWarn = false) use (&$rSent): bool {
			$rSent[] = [$rPath, $rOut];
			return true;
		};
		$this->assertTrue(LbInstallFlow::provisionNodeRootHelper('c', $rRun, $rSend));
		$this->assertCount(1, $rSent);
		$this->assertSame(MAIN_HOME . 'bin/install/xcvm-noderoot', $rSent[0][0], 'MAINs own copy is the source');
		$this->assertSame('/usr/local/sbin/xcvm-noderoot', $rSent[0][1]);
		$rAll = implode("\n", (array) $rLog);
		$this->assertStringContainsString('sudo chown root:root /usr/local/sbin/xcvm-noderoot', $rAll);
		$this->assertStringContainsString('sudo chmod 0755 /usr/local/sbin/xcvm-noderoot', $rAll);
		$this->assertStringNotContainsString('cp ', $rAll);
		$this->assertStringNotContainsString('/home/xc_vm/bin/install/xcvm-noderoot', $rAll);
	}

	public function testHelperFailsOnSendOrOwnershipFailure(): void {
		[$rRun] = $this->fakeRun();
		$this->assertFalse(LbInstallFlow::provisionNodeRootHelper('c', $rRun, fn() => false));
		[$rRun2] = $this->fakeRun('chmod 0755');
		$this->assertFalse(LbInstallFlow::provisionNodeRootHelper('c', $rRun2, fn() => true));
		[$rRun3] = $this->fakeRun('chown root:root');
		$this->assertFalse(LbInstallFlow::provisionNodeRootHelper('c', $rRun3, fn() => true));
	}

	public function testInstallProvisionsTheHelperBeforeTheGrant(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$rHelper = strpos($rSrc, 'LbInstallFlow::provisionNodeRootHelper($rConn, $rRunSSH, $rSendFileSSH)');
		$rGrant = strpos($rSrc, 'LbInstallFlow::provisionNodeRootSudoers($rConn, $rRunSSH)');
		$this->assertNotFalse($rHelper);
		$this->assertNotFalse($rGrant);
		$this->assertLessThan($rGrant, $rHelper);
	}
}
