<?php

use PHPUnit\Framework\TestCase;

/**
 * A proxy's control-channel key is root's, mode 0600: only its callback cron,
 * which runs as root, reads it. The install ends by handing the panel's tree
 * to xc_vm, so the key is given back to root after that step.
 *
 * The install runs over SSH, so the order of its steps is read, not run.
 */
final class AuditModInstallProxyKeyOwnerTest extends TestCase {
	public function testTheKeyIsRootsAgainOnceTheTreeHasBeenHandedToXcVm(): void {
		$rInstall = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$rRootOwn = "'sudo chown root:root ' . MAIN_HOME . 'config/proxy.key && sudo chmod 0600 ' . MAIN_HOME . 'config/proxy.key'";

		// The step that hands the whole tree to xc_vm, the key with it.
		$this->assertStringContainsString("'sudo chown -R xc_vm:xc_vm ' . MAIN_HOME);", $this->between($rInstall, 'private function finalizeHostAfterRuntime(', "\n\t}\n"));

		// What a proxy install does after it, up to the proxy's startup.
		$rAfter = $this->between($rInstall, '$this->finalizeHostAfterRuntime(', 'ProxyInstallFlow::runStartup(');
		$this->assertStringContainsString($rRootOwn, $rAfter);
		$this->assertStringNotContainsString('chown -R', $rAfter);

		// The same ownership and mode the key is installed with.
		$this->assertStringContainsString($rRootOwn, (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ProxyInstallFlow.php'));
	}

	/** The text of $rSource from $rFrom up to the next $rTo. */
	private function between(string $rSource, string $rFrom, string $rTo): string {
		$rStart = strpos($rSource, $rFrom);
		$this->assertNotFalse($rStart, $rFrom);
		$rEnd = strpos($rSource, $rTo, $rStart);
		$this->assertNotFalse($rEnd, $rTo);
		return substr($rSource, $rStart, $rEnd - $rStart);
	}
}
