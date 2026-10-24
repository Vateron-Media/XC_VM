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

	public function testFailureAbortsWithStatus4InsideTheFalseBranch(): void {
		$rStart = $this->pos('$rHelperOK = ');
		$rIf = $this->pos('if (!$rSudoersOK) {');
		$rEnd = strpos($this->src, "return 1;\n\t\t\t}\n\t\t}", $rIf);
		$this->assertNotFalse($rEnd);
		// Nothing but the two provisioning assignments sits between them and the guard.
		$this->assertSame(
			"\$rHelperOK = LbInstallFlow::provisionNodeRootHelper(\$rConn, \$rRunSSH, \$rSendFileSSH);\n\t\t\t"
			. "\$rSudoersOK = \$rHelperOK && LbInstallFlow::provisionNodeRootSudoers(\$rConn, \$rRunSSH);\n\t\t\t",
			substr($this->src, $rStart, $rIf - $rStart)
		);
		// The guard's whole body: mark the server failed, say so, stop. No other statement.
		$this->assertSame(
			"if (!\$rSudoersOK) {\n\t\t\t\t\$db->query('UPDATE `servers` SET `status` = 4 WHERE `id` = ?;', \$rServerID);\n"
			. "\t\t\t\techo \"The node.root helper / sudoers grant could not be installed on the node. Exiting\\n\";\n\t\t\t\t",
			substr($this->src, $rIf, $rEnd - $rIf)
		);
		// ...and the abort is the guard's only exit: the commit comes strictly after it.
		$this->assertGreaterThan($rEnd, $this->pos("SET `status` = 1, `http_broadcast_port`"));
	}
}
