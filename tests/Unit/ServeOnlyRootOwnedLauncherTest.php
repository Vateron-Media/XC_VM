<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;
use XcVm\Core\Config\TreeOwnership;

/**
 * xc_vm.service runs `bash /home/xc_vm/service` as root, and the install's blanket
 * `chown -R xc_vm:xc_vm` makes that script (and its directory) xc_vm's: a compromised
 * xc_vm would own root at the next start. A serve-only node is PHP-free and can never
 * run rootOwn(), so the install root-owns TreeOwnership::ROOT_OWNED itself, over SSH.
 *
 * @covers \XcVm\Cli\Commands\LbInstallFlow
 * @covers \XcVm\Cli\Commands\ServerInstallCommand
 */
final class ServeOnlyRootOwnedLauncherTest extends TestCase {
	public function testCommandRootOwnsTheUnitTargetKeepingGroupXcVm(): void {
		$rCmd = LbInstallFlow::rootOwnPhpFreeCommand();
		$this->assertStringContainsString('root:xc_vm', $rCmd);
		$this->assertStringContainsString(MAIN_HOME . 'service', $rCmd);
		$this->assertStringContainsString("'" . rtrim(MAIN_HOME, '/') . "' ", $rCmd, 'the base directory too: its owner replaces what is in it');
		$rTreeLoop = substr($rCmd, (int) strpos($rCmd, 'chown -hR'));
		$this->assertStringNotContainsString("'" . rtrim(MAIN_HOME, '/') . "' ", $rTreeLoop, 'never the whole tree');
		// PHP-free node: console.php and bin/php are gone.
		$this->assertStringNotContainsString('console.php', $rCmd);
		$this->assertStringNotContainsString('bin/php', $rCmd);
		$this->assertSame(0, preg_match('/chown[^;]*\sxc_vm:xc_vm/', $rCmd));
		$this->assertArrayHasKey('service', TreeOwnership::ROOT_OWNED);
	}

	public function testInstallRunsItAfterTheBlanketChownAndPhpRemoval(): void {
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		// The blanket chown lives in finalizeHostAfterRuntime(), called before the completion block.
		$this->assertStringContainsString("'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME)", $rSrc);
		$rBlanket = strpos($rSrc, '$this->finalizeHostAfterRuntime(');
		$rRm = strpos($rSrc, "console.php ' . MAIN_HOME . 'bin/php");
		$rOwn = strpos($rSrc, 'LbInstallFlow::rootOwnPhpFreeCommand()');
		$rRestart = strpos($rSrc, 'systemctl restart xc_vm', (int) $rOwn);
		$this->assertNotFalse($rOwn);
		$this->assertGreaterThan($rBlanket, $rOwn);
		$this->assertGreaterThan($rRm, $rOwn);
		$this->assertNotFalse($rRestart);
	}
}
