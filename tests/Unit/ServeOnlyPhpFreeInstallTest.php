<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * P6 Part A: a serve-only node installs with no PHP and no http-nginx. The
 * archive still extracts console.php (one archive), so the install removes it
 * once the extract is verified — which flips the Go agent's PHP-free gates
 * (self-heal, ffmpeg, certbot, node.root). The PHP interpreter and http-nginx
 * the distribution bundle places are stripped too; nginx_rtmp stays. A mode-1
 * install is byte-identical to today.
 *
 * @covers \XcVm\Cli\Commands\LbInstallFlow
 */
final class ServeOnlyPhpFreeInstallTest extends TestCase {
	/** A fake SSH runner capturing every command and answering the install's checks. */
	private function fakeSSH(array &$rRun): callable {
		return function ($rConn, string $rCmd) use (&$rRun): array {
			$rRun[] = $rCmd;
			if (str_contains($rCmd, 'mktemp')) {
				return ['output' => '/tmp/xcvm.ABCDEFGHIJ'];
			}
			if (str_contains($rCmd, 'md5')) {
				return ['output' => 'thehash'];
			}
			if (str_contains($rCmd, 'console.php && echo OK')) {
				return ['output' => 'OK'];
			}
			return ['output' => ''];
		};
	}

	/** $db is only touched on a failure path; the success path never calls it. */
	private function db(): object {
		return new class {
			public function query(...$rArgs) {}
		};
	}

	private function consoleRemovals(array $rRun): array {
		return array_values(array_filter($rRun, static fn(string $rC): bool => str_contains($rC, 'rm -rf ') && str_contains($rC, 'console.php')));
	}

	public function testServeOnlyInstallArchiveDropsConsolePhp(): void {
		$rRun = [];
		ob_start();
		$rOk = LbInstallFlow::installArchive(null, $this->fakeSSH($rRun), 'http://main/XC_VM.tar.gz', 'thehash', 1, $this->db(), true);
		ob_end_clean();
		$this->assertTrue($rOk, 'the serve-only archive install succeeds');
		// Pre-extract cleanup AND the post-sanity serve-only removal: console.php is gone.
		$this->assertCount(2, $this->consoleRemovals($rRun), 'serve-only removes console.php after the extract is verified (the gate-flip)');
	}

	public function testModeOneInstallArchiveKeepsConsolePhp(): void {
		$rRun = [];
		ob_start();
		LbInstallFlow::installArchive(null, $this->fakeSSH($rRun), 'http://main/XC_VM.tar.gz', 'thehash', 1, $this->db(), false);
		ob_end_clean();
		// Only the pre-extract cleanup — a mode-1 node keeps console.php, as today.
		$this->assertCount(1, $this->consoleRemovals($rRun), 'mode-1: no post-sanity console.php removal (byte-identical to today)');
	}

	public function testServeOnlyStripsPhpAndHttpNginxKeepsRtmp(): void {
		$rLb = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php');
		$this->assertStringContainsString('installDistributionBinaries($rConn, $rRunSSH, $rDistID, $rVersion, $rServeOnly)', $rLb, 'runPostExtractSteps threads serve-only to the binaries install');
		$this->assertStringContainsString("'sudo rm -rf ' . MAIN_HOME . 'bin/php ' . MAIN_HOME . 'bin/nginx'", $rLb, 'serve-only removes bin/php + the http-nginx build');
		$this->assertStringNotContainsString("MAIN_HOME . 'bin/nginx_rtmp'", $this->serveOnlyRemovalBlock($rLb), 'nginx_rtmp is NOT removed');
		$rCmd = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
		$this->assertStringContainsString('$rServerID, $db, $rServeOnly)', $rCmd, 'ServerInstallCommand passes serve-only to installArchive');
		$this->assertStringContainsString('$rSysCtl, $rServerID, $rServeOnly)', $rCmd, 'and to runPostExtractSteps');
	}

	/** The one `if ($rServeOnly)` removal block in installDistributionBinaries. */
	private function serveOnlyRemovalBlock(string $rLb): string {
		$rPos = strpos($rLb, "'sudo rm -rf ' . MAIN_HOME . 'bin/php");
		return $rPos === false ? '' : substr($rLb, $rPos, 120);
	}
}
