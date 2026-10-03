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

	/** Where the script was sent on the node. */
	private string $rSentTo = '';

	/** Run the step against a node whose installer prints $rScript, whose PHP answers $rPhp and whose mktemp gives $rDir. */
	private function install(bool $rSent, string $rScript = "xcvm_core installed: x.so\nexit=0", string $rPhp = 'CORE_OK', string $rDir = '/tmp/xcvm.aB3dE6gH9k'): bool {
		$this->rRan = [];
		$this->rSentTo = '';
		$rRunSSH = function ($rConn, string $rCommand) use ($rScript, $rPhp, $rDir): array {
			$this->rRan[] = $rCommand;
			return ['output' => match (true) {
				str_starts_with($rCommand, 'mktemp') => $rDir . "\n",
				str_starts_with($rCommand, 'sudo bash') => $rScript . "\n",
				default => $rPhp,
			}, 'error' => ''];
		};
		ob_start();
		try {
			return LbInstallFlow::installExtension(null, $rRunSSH, function ($rConn, string $rPath, string $rOutput, bool $rWarn = false) use ($rSent): bool {
				$this->rSentTo = $rOutput;
				return $rSent;
			});
		} finally {
			ob_end_clean();
		}
	}

	public function testTheInstallGoesOnOnlyWithTheExtensionLoaded(): void {
		$this->assertTrue($this->install(true));
		$this->assertCount(3, $this->rRan, 'a private directory, the installer, then PHP asked for the class');
		$this->assertStringContainsString('echo "exit=$?"', $this->rRan[1], "the installer's own exit status is read");
		$this->assertStringContainsString('class_exists("XC_VM", false)', $this->rRan[2]);
	}

	public function testTheScriptRootRunsIsWhereNoOtherUserCanReachIt(): void {
		// At a fixed path in /tmp another user of the node could own the file
		// first, and change it between the transfer and the sudo.
		$this->assertTrue($this->install(true));
		$this->assertSame('mktemp -d /tmp/xcvm.XXXXXXXXXX', $this->rRan[0]);
		$this->assertSame('/tmp/xcvm.aB3dE6gH9k/install_xcvm_core.sh', $this->rSentTo);
		$this->assertStringStartsWith('sudo bash /tmp/xcvm.aB3dE6gH9k/install_xcvm_core.sh ', $this->rRan[1]);
		$this->assertStringEndsWith('rm -rf /tmp/xcvm.aB3dE6gH9k', $this->rRan[1], 'and is removed with its directory');

		// Anything but the directory mktemp names is refused: it goes into shell commands.
		foreach (['', '/tmp/install_xcvm_core.sh', '/tmp/xcvm.aB3dE6gH9k; id', "mktemp: failed to create directory"] as $rBad) {
			$this->assertFalse($this->install(true, "xcvm_core installed: x.so\nexit=0", 'CORE_OK', $rBad), var_export($rBad, true));
			$this->assertSame('', $this->rSentTo, 'nothing is sent');
			$this->assertCount(1, $this->rRan, 'and nothing is run');
		}
	}

	public function testTheArchiveRootUnpacksIsFetchedWhereNoOtherUserCanReachIt(): void {
		$rDb = new class {
			/** @var list<string> */
			public array $rQueries = [];

			public function query(string $rSql, mixed ...$rArgs): bool {
				$this->rQueries[] = $rSql;
				return true;
			}
		};
		$rInstall = function (string $rDir) use ($rDb): bool {
			$this->rRan = [];
			$rNode = function ($rConn, string $rCommand) use ($rDir): array {
				$this->rRan[] = $rCommand;
				return ['output' => match (true) {
					str_starts_with($rCommand, 'mktemp') => $rDir . "\n",
					str_contains($rCommand, 'md5sum') => "0123abc\n",
					str_starts_with($rCommand, 'test -f') => "OK\n",
					default => '',
				}, 'error' => ''];
			};
			ob_start();
			try {
				return LbInstallFlow::installArchive(null, $rNode, 'https://example.invalid/loadbalancer.tar.gz', '0123abc', 2, $rDb);
			} finally {
				ob_end_clean();
			}
		};

		$this->assertTrue($rInstall('/tmp/xcvm.aB3dE6gH9k'));
		$this->assertSame('mktemp -d /tmp/xcvm.XXXXXXXXXX', $this->rRan[0]);
		$this->assertStringContainsString(' -O /tmp/xcvm.aB3dE6gH9k/XC_VM.tar.gz ', $this->rRan[1]);
		$this->assertContains('sudo tar -zxvf /tmp/xcvm.aB3dE6gH9k/XC_VM.tar.gz -C "' . MAIN_HOME . '"', $this->rRan);
		$this->assertContains('sudo rm -rf /tmp/xcvm.aB3dE6gH9k', $this->rRan, 'and is removed with its directory');
		$this->assertSame([], $rDb->rQueries, 'the server is not marked failed');

		$this->assertFalse($rInstall('mktemp: failed to create directory'));
		$this->assertCount(1, $this->rRan, 'nothing is downloaded or unpacked');
		$this->assertCount(1, $rDb->rQueries, 'and the server is marked failed (status 4)');
	}

	public function testAScriptThatCouldNotBeSentStopsTheInstall(): void {
		$this->assertFalse($this->install(false));
		$this->assertSame(['mktemp -d /tmp/xcvm.XXXXXXXXXX', 'rm -rf /tmp/xcvm.aB3dE6gH9k'], $this->rRan, 'only the directory is made and removed');
	}

	public function testAFailedInstallerStopsTheInstallEvenWithAnOlderExtensionLoaded(): void {
		$this->assertFalse($this->install(true, "curl: (6) Could not resolve host: raw.githubusercontent.com\nexit=6", 'CORE_OK'));
	}

	public function testAnExtensionPhpDoesNotLoadStopsTheInstall(): void {
		$this->assertFalse($this->install(true, "xcvm_core installed: x.so\nexit=0", 'CORE_MISSING'));
		$this->assertFalse($this->install(true, "xcvm_core installed: x.so\nexit=0", ''), 'PHP that does not even start');
	}
}
