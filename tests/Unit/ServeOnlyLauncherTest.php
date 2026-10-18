<?php

use PHPUnit\Framework\TestCase;

/**
 * Behavioral tests for the serve-only launchers: run the real run.sh supervisors
 * against a stub daemon in a throwaway home (XCVM_FANOUT_HOME / XCVM_AGENT_HOME)
 * and assert the ARGUMENTS the daemon is actually invoked with — so a change that
 * stops expanding "${FRONT[@]}" / "${SEED[@]}", or drops a flag, fails here (the
 * source-assertion tests alone would not catch that).
 */
final class ServeOnlyLauncherTest extends TestCase {

	private string $rHome;

	protected function setUp(): void {
		if (trim((string) shell_exec('command -v timeout 2>/dev/null')) === '') {
			$this->markTestSkipped('coreutils timeout not available');
		}
		$this->rHome = sys_get_temp_dir() . '/xcvm_launch_' . bin2hex(random_bytes(6));
	}

	protected function tearDown(): void {
		if (isset($this->rHome) && is_dir($this->rHome)) {
			shell_exec('rm -rf ' . escapeshellarg($this->rHome));
		}
	}

	/** A stub binary: prints $rHelp on `-h` (so the capability gate passes), else records argv. */
	private function writeStub(string $rPath, string $rHelp, string $rCapture, string $rHelpMatch = '-h'): void {
		$rScript = "#!/bin/bash\n"
			. "for a in \"\$@\"; do if [ \"\$a\" = \"" . $rHelpMatch . "\" ]; then echo " . escapeshellarg($rHelp) . "; exit 0; fi; done\n"
			. "printf '%s\\n' \"\$*\" > " . escapeshellarg($rCapture) . "\n"
			. "exit 0\n";
		file_put_contents($rPath, $rScript);
		chmod($rPath, 0755);
	}

	public function testFanoutRunShLaunchesFrontWithTheConfiguredAddressAndRecordsCapability(): void {
		$rDir = $this->rHome . '/bin/xc_fanout';
		@mkdir($rDir, 0755, true);
		@mkdir($this->rHome . '/config/cluster', 0755, true);
		file_put_contents($this->rHome . '/config/cluster/serve_only', '7');
		file_put_contents($this->rHome . '/config/cluster/front_addr', ':8443');
		$rCapture = $rDir . '/captured';
		// -h lists -front so the gate opens; a real spawn (argv starts with -sock) is recorded.
		$this->writeStub($rDir . '/xc_fanout', '-front -front-cert -front-key -front-nginx', $rCapture);

		shell_exec('XCVM_FANOUT_HOME=' . escapeshellarg($this->rHome)
			. ' timeout 3 bash ' . escapeshellarg(MAIN_HOME . 'bin/xc_fanout/run.sh') . ' >/dev/null 2>&1');

		$this->assertFileExists($rCapture, 'the fanout daemon was actually spawned');
		$rArgs = (string) file_get_contents($rCapture);
		$this->assertStringContainsString('-front :8443', $rArgs, 'the front binds the configured https_broadcast_port from front_addr, not :443');
		$this->assertStringContainsString('-front-nginx http://127.0.0.1:8080', $rArgs, 'the front proxies non-serve requests to the nginx backend');
		$this->assertFileExists($this->rHome . '/config/cluster/front_capable', 'the supervisor recorded -front capability for the root cron');
	}

	public function testFanoutRunShDoesNotLaunchFrontWithoutTheMarker(): void {
		$rDir = $this->rHome . '/bin/xc_fanout';
		@mkdir($rDir, 0755, true);
		@mkdir($this->rHome . '/config/cluster', 0755, true);
		// No serve_only marker.
		$rCapture = $rDir . '/captured';
		$this->writeStub($rDir . '/xc_fanout', '-front -front-cert -front-key -front-nginx', $rCapture);

		shell_exec('XCVM_FANOUT_HOME=' . escapeshellarg($this->rHome)
			. ' timeout 3 bash ' . escapeshellarg(MAIN_HOME . 'bin/xc_fanout/run.sh') . ' >/dev/null 2>&1');

		$rArgs = is_file($rCapture) ? (string) file_get_contents($rCapture) : '';
		$this->assertStringNotContainsString('-front ', $rArgs, 'a node without the marker never launches the front');
		$this->assertFileDoesNotExist($this->rHome . '/config/cluster/front_capable', 'no capability file without the marker');
	}

	public function testAgentRunShSeedsFanoutWithResolverPathsWhenMarkerPresent(): void {
		$rDir = $this->rHome . '/bin/xc_agent';
		@mkdir($rDir, 0755, true);
		@mkdir($this->rHome . '/config/cluster', 0755, true);
		file_put_contents($this->rHome . '/config/cluster/serve_only', '7');
		file_put_contents($this->rHome . '/config/cluster/agent.json', '{}'); // STATE present → node branch
		$rCapture = $rDir . '/captured';
		// `run -h` lists -seed-fanout so the gate opens; a real `run -state ...` is recorded.
		$this->writeStub($rDir . '/xc_agent', '-seed-fanout -server-id -fanout-binary -ffmpeg -ffmpeg-gpu -ffprobe -streams -logs', $rCapture);

		shell_exec('XCVM_AGENT_HOME=' . escapeshellarg($this->rHome)
			. ' timeout 3 bash ' . escapeshellarg(MAIN_HOME . 'bin/xc_agent/run.sh') . ' >/dev/null 2>&1');

		$this->assertFileExists($rCapture, 'the agent daemon was actually spawned');
		$rArgs = (string) file_get_contents($rCapture);
		$this->assertStringContainsString('-seed-fanout', $rArgs, 'the agent run is invoked with -seed-fanout');
		$this->assertStringContainsString('-server-id 7', $rArgs, 'the server id from the marker is passed');
		$this->assertStringContainsString('content/streams/', $rArgs, '{streams} resolves to STREAMS_PATH (content/streams/)');
		$this->assertStringContainsString('tmp/logs/', $rArgs, '{logs} resolves to LOGS_TMP_PATH (tmp/logs/)');
	}
}
