<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * LbInstallFlow::installExtension — a load balancer cannot run without
 * xcvm_core, and neither the binaries bundle nor the archive carries it. An
 * install whose installer script could not be sent, or failed, used to carry
 * on: it failed later at "Failed to read install_id", or left the node on
 * whatever older extension an old bundle still had.
 */
final class LbInstallExtensionTest extends TestCase {
	/** @var list<string> */
	private array $rRan = [];

	public static function setUpBeforeClass(): void {
		foreach (['GIT_OWNER' => 'Vateron-Media', 'GIT_REPO_BIN' => 'XC_VM_Binaries'] as $rName => $rValue) {
			if (!defined($rName)) {
				define($rName, $rValue);
			}
		}
	}

	/** Run the step against a node whose installer prints $rScript and whose PHP answers $rPhp. */
	private function install(bool $rSent, string $rScript = "xcvm_core installed: x.so\nexit=0", string $rPhp = 'CORE_OK'): bool {
		$this->rRan = [];
		$rRunSSH = function ($rConn, string $rCommand) use ($rScript, $rPhp): array {
			$this->rRan[] = $rCommand;
			return ['output' => str_contains($rCommand, 'install_xcvm_core.sh') ? $rScript . "\n" : $rPhp, 'error' => ''];
		};
		ob_start();
		try {
			return LbInstallFlow::installExtension(null, $rRunSSH, static fn($rConn, string $rPath, string $rOutput, bool $rWarn = false): bool => $rSent);
		} finally {
			ob_end_clean();
		}
	}

	public function testTheInstallGoesOnOnlyWithTheExtensionLoaded(): void {
		$this->assertTrue($this->install(true));
		$this->assertCount(2, $this->rRan, 'the installer, then PHP asked for the class');
		$this->assertStringContainsString('echo "exit=$?"', $this->rRan[0], "the installer's own exit status is read");
		$this->assertStringContainsString('class_exists("XC_VM", false)', $this->rRan[1]);
	}

	public function testAScriptThatCouldNotBeSentStopsTheInstall(): void {
		$this->assertFalse($this->install(false));
		$this->assertSame([], $this->rRan, 'nothing is run on the node');
	}

	public function testAFailedInstallerStopsTheInstallEvenWithAnOlderExtensionLoaded(): void {
		$this->assertFalse($this->install(true, "curl: (6) Could not resolve host: raw.githubusercontent.com\nexit=6", 'CORE_OK'));
	}

	public function testAnExtensionPhpDoesNotLoadStopsTheInstall(): void {
		$this->assertFalse($this->install(true, "xcvm_core installed: x.so\nexit=0", 'CORE_MISSING'));
		$this->assertFalse($this->install(true, "xcvm_core installed: x.so\nexit=0", ''), 'PHP that does not even start');
	}
}
