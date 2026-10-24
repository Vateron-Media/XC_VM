<?php

use PHPUnit\Framework\TestCase;

/**
 * The node.root helper + sudoers grant are installed BEFORE serve_only is
 * exposed (DB commit + marker), and a failure aborts the install (status 4).
 *
 * @covers \XcVm\Cli\Commands\ServerInstallCommand
 */
final class ServeOnlyProvisionOrderTest extends TestCase {
	private string $src;

	protected function setUp(): void {
		$this->src = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/ServerInstallCommand.php');
	}

	private function pos(string $rNeedle): int {
		$rPos = strpos($this->src, $rNeedle);
		$this->assertNotFalse($rPos, $rNeedle);
		return $rPos;
	}

	public function testProvisioningPrecedesServeOnlyCommitAndMarker(): void {
		$rHelper = $this->pos('LbInstallFlow::provisionNodeRootHelper(');
		$rSudoers = $this->pos('LbInstallFlow::provisionNodeRootSudoers(');
		$this->assertGreaterThan($this->pos('$this->finalizeHostAfterRuntime('), $rHelper);
		$this->assertLessThan($rSudoers, $rHelper);
		$rCommit = $this->pos("SET `status` = 1, `http_broadcast_port`");
		$this->assertLessThan($rCommit, $rSudoers, 'grant before the serve_only DB commit');
		$this->assertLessThan($this->pos("cluster/serve_only > /dev/null"), $rSudoers, 'grant before the marker');
		$this->assertSame(1, substr_count($this->src, 'LbInstallFlow::provisionNodeRootHelper('));
	}

	public function testRootOwnStaysAfterPhpRemovalAndRestartIsLast(): void {
		$rRm = $this->pos("'bin/php');");
		$rOwn = $this->pos('LbInstallFlow::rootOwnPhpFreeCommand()');
		$this->assertGreaterThan($rRm, $rOwn);
		$this->assertGreaterThan($rOwn, $this->pos("'sudo systemctl restart xc_vm');"));
	}

	public function testFailureAbortsWithStatus4BeforeCommit(): void {
		$rBlock = substr($this->src, $this->pos('$rHelperOK = '), 700);
		$this->assertStringContainsString('$rHelperOK && LbInstallFlow::provisionNodeRootSudoers(', $rBlock);
		$this->assertMatchesRegularExpression('/if \(!\$rSudoersOK\) \{\s+\$db->query\(\'UPDATE `servers` SET `status` = 4 WHERE `id` = \?;\', \$rServerID\);.*?return 1;/s', $rBlock);
	}
}
