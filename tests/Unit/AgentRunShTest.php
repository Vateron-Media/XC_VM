<?php

use PHPUnit\Framework\TestCase;

/**
 * bin/xc_agent/run.sh, run for real in a throwaway home (XCVM_AGENT_HOME): a
 * binary MAIN just installed that keeps failing at start is replaced by the
 * one it replaced, and only while it is on trial. One on trial for reaching
 * MAIN (`reach`) that runs but never does within its time is replaced too,
 * and one that does ends its trial.
 */
final class AgentRunShTest extends TestCase {
	private string $rHome;

	protected function setUp(): void {
		$this->rHome = sys_get_temp_dir() . '/xcvm-runsh-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rHome . 'bin/xc_agent', 0777, true);
		mkdir($this->rHome . 'config/cluster', 0777, true);
		file_put_contents($this->rHome . 'config/cluster/agent.json', '{}');
		// The new binary fails at start; the previous one ends the supervisor (MAIN stopped the node).
		$this->binary('xc_agent', "echo new >> " . escapeshellarg($this->rHome . 'runs') . "\nexit 1");
		$this->binary('xc_agent.prev', "echo prev >> " . escapeshellarg($this->rHome . 'runs') . "\nexit 3");
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rHome));
	}

	private function binary(string $rName, string $rBody): void {
		file_put_contents($this->rHome . 'bin/xc_agent/' . $rName, "#!/bin/sh\n" . $rBody . "\n");
		chmod($this->rHome . 'bin/xc_agent/' . $rName, 0755);
	}

	/** @return list<string> which binary ran, in order */
	private function supervise(int $rReachSec = 300): array {
		$rCmd = 'XCVM_AGENT_HOME=' . escapeshellarg(rtrim($this->rHome, '/')) . ' XCVM_AGENT_REACH_SEC=' . $rReachSec . ' timeout 60 bash ' . escapeshellarg(MAIN_HOME . 'bin/xc_agent/run.sh') . ' 2>/dev/null';
		exec($rCmd);
		return file($this->rHome . 'runs', FILE_IGNORE_NEW_LINES) ?: [];
	}

	public function testANewAgentThatKeepsFailingAtStartIsRolledBack(): void {
		file_put_contents($this->rHome . 'bin/xc_agent/xc_agent.trial', time() . " 0\n");
		$this->assertSame(['new', 'new', 'new', 'prev'], $this->supervise());
		$this->assertStringContainsString('prev', (string) file_get_contents($this->rHome . 'bin/xc_agent/xc_agent'), 'the previous binary is back');
		$this->assertFileDoesNotExist($this->rHome . 'bin/xc_agent/xc_agent.trial');
		$this->assertFileDoesNotExist($this->rHome . 'bin/xc_agent/xc_agent.prev');
		$this->assertStringContainsString('the previous one is back', (string) file_get_contents($this->rHome . 'bin/xc_agent/xc_agent.log'));
	}

	public function testANewAgentThatNeverReachesMainIsRolledBack(): void {
		file_put_contents($this->rHome . 'bin/xc_agent/xc_agent.trial', time() . " 0 reach\n");
		// It starts and runs, but never reaches MAIN (writes no reached file).
		$this->binary('xc_agent', "echo new >> " . escapeshellarg($this->rHome . 'runs') . "\nexec sleep 30");
		$this->assertSame(['new', 'prev'], $this->supervise(2));
		$this->assertStringContainsString('prev', (string) file_get_contents($this->rHome . 'bin/xc_agent/xc_agent'), 'the previous binary is back');
		$this->assertFileDoesNotExist($this->rHome . 'bin/xc_agent/xc_agent.trial');
		$this->assertStringContainsString('did not reach MAIN within 2s', (string) file_get_contents($this->rHome . 'bin/xc_agent/xc_agent.log'));
	}

	public function testANewAgentThatReachesMainEndsItsTrial(): void {
		file_put_contents($this->rHome . 'bin/xc_agent/xc_agent.trial', time() . " 0 reach\n");
		// The agent it replaced wrote the file before this run: that is not this one reaching MAIN.
		touch($this->rHome . 'config/cluster/reached', time() - 5);
		$rReached = escapeshellarg($this->rHome . 'config/cluster/reached');
		$this->binary('xc_agent', "echo new >> " . escapeshellarg($this->rHome . 'runs') . "\nsleep 1.2\ntouch " . $rReached . "\nsleep 3\nexit 3");
		$this->assertSame(['new'], $this->supervise(4));
		$this->assertFileDoesNotExist($this->rHome . 'bin/xc_agent/xc_agent.trial', 'the trial is over');
		$this->assertFileExists($this->rHome . 'bin/xc_agent/xc_agent.prev', 'no rollback');
		$this->assertStringContainsString('reached MAIN: its trial is over', (string) file_get_contents($this->rHome . 'bin/xc_agent/xc_agent.log'));
	}

	public function testAfterItsTrialAFailingAgentIsLeftAlone(): void {
		file_put_contents($this->rHome . 'bin/xc_agent/xc_agent.trial', (time() - 3600) . " 2\n");
		// The new binary fails once, then stops (so the supervisor ends).
		$this->binary('xc_agent', "if [ -f " . escapeshellarg($this->rHome . 'runs') . " ]; then echo new >> " . escapeshellarg($this->rHome . 'runs') . "; exit 3; fi\necho new >> " . escapeshellarg($this->rHome . 'runs') . "\nexit 1");
		$this->assertSame(['new', 'new'], $this->supervise());
		$this->assertFileExists($this->rHome . 'bin/xc_agent/xc_agent.prev', 'no rollback');
		$this->assertFileDoesNotExist($this->rHome . 'bin/xc_agent/xc_agent.trial', 'the trial is over');
	}
}
