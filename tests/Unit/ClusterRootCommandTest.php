<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterExecCommand;
use XcVm\Cli\Commands\ClusterRootCommand;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\EventSpool;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\RootPin;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * Phase 4 root commands: root trusts only its own pin of the panel key, runs
 * a node.root command at most once, and writes its result without following
 * anything planted in the xc_vm-writable inbox.
 */
final class ClusterRootCommandTest extends TestCase {
	private const NODE = '0f8fad5b-d9cb-469f-a165-70867728950e';

	private string $rBase;

	private FakeClusterCrypto $rCrypto;

	protected function setUp(): void {
		$this->rBase = sys_get_temp_dir() . '/rootpin_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rBase . 'etc', 0755, true);
		mkdir($this->rBase . 'inbox', 0700, true);
		RootPin::useDirs($this->rBase . 'etc/', $this->rBase . 'inbox/');
		$this->rCrypto = new FakeClusterCrypto();
		$this->assertTrue(RootPin::write($this->rCrypto->info()['panel_sign_pub'], self::NODE));
	}

	protected function tearDown(): void {
		RootPin::useDirs(null, null);
		exec('rm -rf ' . escapeshellarg($this->rBase));
	}

	private function command(int $rSeq, array $rOver = [], string $rTag = 'cmd'): array {
		$rDoc = (string) json_encode($rOver + ['v' => 1, 'type' => 'node.root', 'exp' => 1800000600, 'iat' => 1800000000, 'cmd_id' => bin2hex(random_bytes(16)), 'seq' => $rSeq, 'node_uuid' => self::NODE, 'gen' => 1, 'dedupe_key' => null, 'action' => 'reload_nginx', 'args' => new \stdClass()]);
		return ['doc' => $rDoc, 'sig' => Enc::b64url($this->rCrypto->sign($rTag, $rDoc))];
	}

	/** A document of the old shape (no top-level action), which the extension would not sign: signed with the raw key. */
	private function rawCommand(int $rSeq, array $rOver): array {
		$rDoc = (string) json_encode($rOver + ['v' => 1, 'type' => 'node.root', 'exp' => 1800000600, 'iat' => 1800000000, 'cmd_id' => bin2hex(random_bytes(16)), 'seq' => $rSeq, 'node_uuid' => self::NODE, 'gen' => 1, 'dedupe_key' => null]);
		return ['doc' => $rDoc, 'sig' => Enc::b64url(\XcVm\Tests\Support\ClusterReference::panelSign($this->rCrypto->rSeed, 'cmd', $rDoc))];
	}

	private function inbox(int $rSeq, array $rCmd): void {
		file_put_contents($this->rBase . 'inbox/' . $rSeq . '.json', json_encode($rCmd));
	}

	/**
	 * The blocklist flush (plan, section 7: `cron:root_signals` blocklist is
	 * applied by `cluster:root`): a node that takes MAIN's root commands gets
	 * it as a signed node.root, which runs through executeAction like every
	 * other root action, and stops polling the signals table for it.
	 */
	public function testTheFlushComesAsARootCommandOnceTheNodeTakesThem(): void {
		$rFlows = $this->rBase . 'flows.json';
		NodeFlows::usePath($rFlows);
		try {
			foreach ([[null, false], [['mode' => 1, 'flows' => NodeFlows::TELEMETRY], false], [['mode' => 1, 'flows' => NodeFlows::COMMANDS, 'state' => 'active'], true], [['mode' => 2, 'flows' => 255, 'state' => 'quarantined'], true], [['mode' => 1, 'flows' => NodeFlows::COMMANDS, 'state' => 'revoked'], false]] as [$rDoc, $rExpected]) {
				@unlink($rFlows);
				if ($rDoc !== null) {
					file_put_contents($rFlows, json_encode($rDoc));
				}
				NodeFlows::usePath($rFlows);
				$this->assertSame($rExpected, RootSignalsCronJob::rootCommandsFromMain(), json_encode($rDoc));
			}
			// The same flows without a pin root trusts: MAIN keeps the signals table for them.
			file_put_contents($rFlows, json_encode(['mode' => 1, 'flows' => NodeFlows::COMMANDS, 'state' => 'active']));
			NodeFlows::usePath($rFlows);
			$this->assertTrue(RootSignalsCronJob::rootCommandsFromMain(), 'COMMANDS on, the pin in place');
			chmod($this->rBase . 'etc', 0775);
			$this->assertFalse(RootSignalsCronJob::rootCommandsFromMain(), 'COMMANDS on, no pin');
		} finally {
			NodeFlows::usePath(null);
		}
		$rSource = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$rReads = strpos($rSource, '$rReads = self::readsMainDatabase();');
		$rGate = strpos($rSource, 'if ($rReads && !self::rootCommandsFromMain()) {');
		$rRead = strpos($rSource, "SELECT `signal_id` FROM `signals` WHERE `server_id` = ? AND `custom_data` = '{");
		$this->assertNotFalse($rReads);
		$this->assertNotFalse($rGate);
		$this->assertLessThan($rGate, $rReads);
		$this->assertTrue($rRead > $rGate && $rRead - $rGate < 120, 'the legacy flush row is read only where MAIN still sends it');
	}

	/**
	 * What the flush does, run as cluster:root runs it: iptables flushed and
	 * saved, one FLUSH line in mysql_syslog, and nothing of the next case
	 * (reboot: its REBOOT line, close_mysql, `sudo reboot`). The database
	 * throws on any statement but the FLUSH line, so a fall-through stops
	 * before the reboot's shell_exec.
	 */
	public function testTheFlushActionFlushesIptablesAndLogsOnce(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 5);
		}
		$rJob = new class extends RootSignalsCronJob {
			/** @var list<string> */
			public array $rCalls = [];

			protected function flushIPs(): void {
				$this->rCalls[] = 'flushIPs';
			}

			protected function saveiptables(): void {
				$this->rCalls[] = 'saveiptables';
			}
		};
		$rDb = new class {
			/** @var list<string> */
			public array $rCalls = [];

			public function query(string $rQuery, mixed ...$rArgs): bool {
				$this->rCalls[] = $rQuery;
				if (!str_contains($rQuery, "VALUES(?, 'FLUSH',")) {
					throw new \RuntimeException('not the flush\'s line: ' . $rQuery);
				}
				return true;
			}

			public function close_mysql(): void {
				$this->rCalls[] = 'close_mysql';
			}
		};
		ob_start();
		try {
			$rJob->executeAction(['action' => 'flush'], [], $rDb);
		} finally {
			$rOut = (string) ob_get_clean();
		}
		$this->assertSame(['flushIPs', 'saveiptables'], $rJob->rCalls, 'flushed, then saved, once each');
		$this->assertCount(1, $rDb->rCalls, 'one statement, and no close_mysql');
		$this->assertStringStartsWith('INSERT INTO `mysql_syslog`', $rDb->rCalls[0]);
		$this->assertStringNotContainsString('Rebooting', $rOut);
	}

	public function testThePinIsReadOnlyFromASafeDirectory(): void {
		$rPin = RootPin::read();
		$this->assertSame([$this->rCrypto->info()['panel_sign_pub'], self::NODE], [$rPin['pub'], $rPin['node']]);
		$this->assertTrue(RootPin::matches($this->rCrypto->info()['panel_sign_pub'], self::NODE));
		$this->assertFalse(RootPin::matches(str_repeat("\1", 32), self::NODE));
		chmod($this->rBase . 'etc', 0775);
		$this->assertNull(RootPin::read(), 'a group-writable directory is not trusted');
	}

	public function testVerification(): void {
		$rPin = RootPin::read();
		$rOk = $this->command(3);
		$this->assertIsArray(RootPin::verify($rPin, $rOk['doc'], (string) Enc::b64urlDecode($rOk['sig']), 1800000000, 2));
		$rCases = [
			'bad signature' => [$this->command(3, [], 'den'), 2],
			'another node' => [$this->command(3, ['node_uuid' => '11111111-1111-4111-a111-111111111111']), 2],
			'not root' => [$this->command(3, ['type' => 'node.rpc']), 2],
			'stale' => [$this->command(3, ['exp' => 1799999000]), 2],
			'replay' => [$this->command(3), 3],
			'unknown action' => [$this->command(3, ['action' => 'rm_rf']), 2],
			// The action is the envelope's (the extension classes by it): one
			// among the arguments as well, or instead, is refused.
			'a second action among the arguments' => [$this->command(3, ['args' => ['action' => 'update']]), 2],
			'the action among the arguments only' => [$this->rawCommand(3, ['args' => ['action' => 'reload_nginx']]), 2],
		];
		foreach ($rCases as $rName => [$rCmd, $rHigh]) {
			$this->assertIsString(RootPin::verify($rPin, $rCmd['doc'], (string) Enc::b64urlDecode($rCmd['sig']), 1800000000, $rHigh), $rName);
		}
	}

	public function testACredentialActionsOutcomeAlsoGoesToMainAsAnEvent(): void {
		// cluster:exec acks "queued" when root is slower than it waits: the outcome
		// MAIN revokes on travels as node.root_result too (DbCredentials::acked).
		file_put_contents($this->rBase . 'flows.json', '{}'); // the agent alive, beside its spool
		EventSpool::useDir($this->rBase . 'spool/');
		try {
			$rCmd = $this->command(1, ['action' => NodeCredentials::STRIP]);
			$this->inbox(1, $rCmd);
			ClusterRootCommand::drain(static fn() => "Stripped.\n{\"config\":{\"db_credentials\":false}}\n", 1800000000);
			$rFiles = glob($this->rBase . 'spool/p0/*') ?: [];
			$this->assertCount(1, $rFiles);
			$rEvent = json_decode((string) file($rFiles[0], FILE_IGNORE_NEW_LINES)[0], true);
			$this->assertSame('node.root_result', $rEvent['type']);
			$this->assertSame([json_decode($rCmd['doc'], true)['cmd_id'], true, "Stripped.\n{\"config\":{\"db_credentials\":false}}"], [$rEvent['d']['cmd_id'], $rEvent['d']['ok'], $rEvent['d']['result']]);
			$this->inbox(2, $this->command(2));
			ClusterRootCommand::drain(static fn() => 'Reloading nginx...', 1800000000);
			$this->assertCount(1, glob($this->rBase . 'spool/p0/*') ?: [], 'another action reports nothing');
		} finally {
			EventSpool::useDir(null);
		}
	}

	public function testDrainRunsEachCommandOnce(): void {
		$rRan = [];
		$rRun = static function (array $rAction) use (&$rRan): string {
			$rRan[] = $rAction['action'];
			return "Reloading nginx...\n";
		};
		$this->inbox(1, $this->command(1));
		$this->inbox(2, $this->command(2, ['action' => 'update']));
		$rDone = ClusterRootCommand::drain($rRun, 1800000000);
		$this->assertSame(['reload_nginx', 'update'], $rRan);
		$this->assertSame(2, RootPin::highWater());
		$this->assertSame(['ok' => true, 'result' => 'Reloading nginx...'], json_decode((string) file_get_contents($this->rBase . 'inbox/1.done'), true));
		$this->assertCount(2, $rDone);
		$this->assertSame([], glob($this->rBase . 'inbox/*.json'), 'the inbox is emptied');

		// The same command again (a replay into the inbox): refused, not run.
		$this->inbox(2, $this->command(2));
		ClusterRootCommand::drain($rRun, 1800000000);
		$this->assertCount(2, $rRan);
		$this->assertStringContainsString('refused by root', (string) file_get_contents($this->rBase . 'inbox/2.done'));
	}

	public function testAPlantedSymlinkIsNotFollowed(): void {
		$rTarget = $this->rBase . 'precious';
		file_put_contents($rTarget, 'keep me');
		symlink($rTarget, $this->rBase . 'inbox/5.done');
		$this->inbox(5, $this->command(5));
		ClusterRootCommand::drain(static fn() => 'x', 1800000000);
		$this->assertSame('keep me', file_get_contents($rTarget));
		$this->assertFalse(is_link($this->rBase . 'inbox/5.done'));
	}

	public function testExecHandsRootCommandsOver(): void {
		ob_start();
		$rCode = ClusterExecCommand::handToRoot($this->command(7), 7, 0);
		$rOut = ob_get_clean();
		$this->assertSame(0, $rCode);
		$this->assertSame('{"queued":true}', $rOut, 'no root result yet: acked as queued');
		$this->assertFileExists($this->rBase . 'inbox/7.json');
		// Root picks it up and answers.
		ClusterRootCommand::drain(static fn() => 'done', 1800000000);
		$this->assertSame(7, RootPin::highWater());
	}
}
