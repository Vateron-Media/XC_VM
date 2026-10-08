<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\DaemonTrait;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeRole;

/**
 * A daemon decides once, at its start, whether it reads MAIN's database, and
 * keeps the connection it opened. After a move to mode 2 the signals, queue
 * and scanner daemons went on reading MAIN's MySQL and Redis over it until
 * something else restarted them; after a move back they read nothing. They
 * leave their loop when the node crosses mode 2 (DaemonTrait::modeChanged),
 * and their next generation decides again.
 */
final class DaemonModeChangeTest extends TestCase {
	private string $rFile;

	protected function setUp(): void {
		$this->rFile = sys_get_temp_dir() . '/xcvm-daemon-mode-' . bin2hex(random_bytes(4)) . '.json';
		NodeRole::useMainBuild(false);
	}

	protected function tearDown(): void {
		NodeRole::useMainBuild(null);
		NodeFlows::usePath(null);
		@unlink($this->rFile);
	}

	private function mode(int $rMode): void {
		file_put_contents($this->rFile, json_encode(['mode' => $rMode, 'flows' => 255, 'state' => 'active']));
		NodeFlows::usePath($this->rFile, true);
		clearstatcache();
	}

	public function testADaemonLeavesItsLoopWhenTheNodeCrossesModeTwo(): void {
		$rDaemon = new class {
			use DaemonTrait;

			public function start(): void {
				$this->initDaemonMD5();
			}

			public function changed(): bool {
				return $this->modeChanged();
			}
		};
		$this->mode(1);
		$this->assertFalse($rDaemon->changed(), 'not started: nothing to compare with');

		$rDaemon->start();
		$this->assertFalse(NodeRole::refusesConnects());
		$this->assertFalse($rDaemon->changed());
		$this->mode(0);
		$this->assertFalse($rDaemon->changed(), 'modes 0 and 1 both read MAIN\'s database');

		$this->mode(2);
		$this->assertTrue(NodeRole::refusesConnects());
		$this->assertTrue($rDaemon->changed(), 'started reading MAIN\'s database, and the node no longer may');

		$rDaemon->start(); // its next generation
		$this->assertFalse($rDaemon->changed());
		$this->mode(1);
		$this->assertTrue($rDaemon->changed(), 'and back: it would read nothing');
	}

	/**
	 * Each daemon that holds a connection asks every pass, not with its
	 * minute's settings refresh: signals, queue and fanout_sync through
	 * refreshOrBreak(), whose first statement it is; the scanner in its loop's
	 * condition (it sleeps a minute a pass); the on-demand daemon, which has
	 * no DaemonTrait and pings the connection it opened, by itself.
	 */
	public function testTheDaemonsThatKeepAConnectionCheckItEveryPass(): void {
		$rTrait = (string) file_get_contents(MAIN_HOME . 'Cli/DaemonTrait.php');
		$this->assertMatchesRegularExpression('/function refreshOrBreak\(\): bool \{\s*(?:\/\/[^\n]*\s*)*if \(\$this->modeChanged\(\)\) \{/', $rTrait, 'before the settings\' timer');
		$rCommand = static fn(string $rName): string => (string) file_get_contents(MAIN_HOME . 'Cli/Commands/' . $rName . 'Command.php');
		foreach (['Signals', 'Queue', 'FanoutSync'] as $rDaemon) {
			$this->assertStringContainsString('$this->refreshOrBreak()', $rCommand($rDaemon), $rDaemon);
		}
		$this->assertStringContainsString('while (!$this->modeChanged() && (', $rCommand('Scanner'));
		$this->assertMatchesRegularExpression('/while \(true\) \{\s*(?:\/\/[^\n]*\s*)*if \(\$rApi !== NodeRole::refusesConnects\(\)\) \{/', $rCommand('Watchdog'), 'the watchdog, which runs pass after pass in mode 2');
		$this->assertMatchesRegularExpression('/\$rRefuses = NodeRole::refusesConnects\(\);.*if \(\$rRefuses !== NodeRole::refusesConnects\(\) \|\|/s', $rCommand('Ondemand'));
	}

	/**
	 * fanout_sync is started by `service` alone, so a generation that dies at
	 * its start is gone for good: on a node in mode 2 it opens no Redis of
	 * MAIN's (the Redis handler on), which that node is refused.
	 */
	public function testFanoutSyncOpensNoRedisOnANodeThatRefuses(): void {
		$this->assertMatchesRegularExpression('/if \(!NodeRole::refusesConnects\(\)\) \{\s*\$this->initRedisIfEnabled\(\);/', (string) file_get_contents(MAIN_HOME . 'Cli/Commands/FanoutSyncCommand.php'));
	}
}
