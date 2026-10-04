<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterLockdownCommand;
use XcVm\Domain\Cluster\ClusterLockdown;
use XcVm\Domain\Cluster\DbAllowlist;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * cluster:lockdown (plan, section 10, step 5): MariaDB and Redis bound to
 * loopback, the XCVM_DB chain narrowed to MAIN and the extra list, refused
 * while a node below mode 2 or a proxy could still need either port unless
 * --force, all of it undone by --undo, and never run by anything but an
 * operator. A fake runner stands in for iptables and systemctl.
 */
final class ClusterLockdownTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	/** @var array<string, array{chain: ?list<string>, jumps: int}> */
	private array $rFw = [];

	/** @var list<string> */
	private array $rCalls = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `servers` (`id` int, `server_type` int, `is_main` int, `server_ip` varchar(255), `private_ip` varchar(255), `proxy_signed` int DEFAULT 0)');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` int, `mode` int, `state` varchar(16) DEFAULT 'active')");
		$this->rDb->exec("CREATE TABLE `settings` (`cluster_db_allowlist` int DEFAULT 0, `cluster_db_allowlist_extra` varchar(1024) DEFAULT '')");
		$this->rDb->exec('CREATE TABLE `cluster_meta` (`name` varchar(64) PRIMARY KEY, `value` text, `updated_at` int)');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec("INSERT INTO `settings` VALUES (0, '')");
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_type`, `is_main`, `server_ip`, `private_ip`) VALUES (1, 0, 1, '203.0.113.1', '10.0.0.1'), (2, 0, 0, '203.0.113.2', ''), (3, 0, 0, '203.0.113.3', '')");
		$this->rDb->exec("INSERT INTO `cluster_nodes` (`server_id`, `mode`) VALUES (2, 1), (3, 2)");
		DatabaseFactory::set($this->rDb);
		$this->rDir = sys_get_temp_dir() . '/xcvm-lock-' . bin2hex(random_bytes(4));
		mkdir($this->rDir . '/mysql', 0755, true);
		file_put_contents($this->rDir . '/redis.conf', "bind *\nprotected-mode yes\nport 6379\n");
		$this->rFw = ['/sbin/iptables' => ['chain' => null, 'jumps' => 0], '/sbin/ip6tables' => ['chain' => null, 'jumps' => 0]];
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	private function lockdown(): ClusterLockdown {
		$rExec = function (array $rArgv, ?string $rStdin): array {
			$this->rCalls[] = implode(' ', $rArgv);
			if ($rArgv[0] === 'systemctl') {
				return [0, ''];
			}
			if (str_ends_with($rArgv[0], '-restore')) {
				$rTool = substr($rArgv[0], 0, -8);
				$this->rFw[$rTool]['chain'] = array_merge(['-N XCVM_DB'], array_values(array_filter(explode("\n", (string) $rStdin), static fn ($l) => str_starts_with($l, '-A '))));
				return [0, ''];
			}
			$rTool = $rArgv[0];
			switch ($rArgv[1]) {
				case '-S':
					return $this->rFw[$rTool]['chain'] === null ? [1, ''] : [0, implode("\n", $this->rFw[$rTool]['chain']) . "\n"];
				case '-C':
					return [$this->rFw[$rTool]['jumps'] > 0 ? 0 : 1, ''];
				case '-I':
					$this->rFw[$rTool]['jumps']++;
					return [0, ''];
				case '-D':
					if ($this->rFw[$rTool]['jumps'] === 0) {
						return [1, ''];
					}
					$this->rFw[$rTool]['jumps']--;
					return [0, ''];
				case '-F':
					return [0, ''];
				case '-X':
					$this->rFw[$rTool]['chain'] = null;
					return [0, ''];
			}
			return [127, ''];
		};
		$rAllowlist = new DbAllowlist($rExec, static fn (string $rHost): array => [], [4 => '/sbin/iptables', 6 => '/sbin/ip6tables']);
		return new ClusterLockdown($rAllowlist, $rExec, $this->rDir . '/mysql/', $this->rDir . '/redis.conf');
	}

	public function testItRefusesWhileANodeBelowModeTwoOrAProxyRemains(): void {
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_type`, `is_main`, `server_ip`, `private_ip`) VALUES (4, 1, 0, '203.0.113.4', '')");
		$this->assertSame(['nodes' => [2], 'proxies' => [4]], ClusterLockdown::blockers());
		ob_start();
		$rCode = (new ClusterLockdownCommand($this->lockdown(), false))->execute([]);
		$rText = (string) ob_get_clean();
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('load balancer(s) 2 are below mode 2', $rText);
		$this->assertStringContainsString('proxy server(s) 4', $rText);
		$this->assertFileDoesNotExist($this->rDir . '/mysql/' . ClusterLockdown::MYSQL_DROPIN, 'nothing changed');
		$this->assertSame([], $this->rCalls, 'the firewall was not touched');
		$this->assertNull(ClusterLockdown::state());

		// A proxy that signs its channel (ProxyKey, D8) uses MAIN's API only.
		$this->rDb->exec('UPDATE `servers` SET `proxy_signed` = 1 WHERE `id` = 4');
		$this->assertSame(['nodes' => [2], 'proxies' => []], ClusterLockdown::blockers());
	}

	public function testLockdownBindsToLoopbackAndNarrowsTheChainThenUndoRestoresIt(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 2');
		$this->assertSame(['nodes' => [], 'proxies' => []], ClusterLockdown::blockers());
		ob_start();
		$rCode = (new ClusterLockdownCommand($this->lockdown(), false))->execute(['--restart']);
		$rText = (string) ob_get_clean();
		$this->assertSame(0, $rCode, $rText);

		$this->assertStringContainsString("bind-address = 127.0.0.1\n", (string) file_get_contents($this->rDir . '/mysql/' . ClusterLockdown::MYSQL_DROPIN));
		$this->assertStringStartsWith(ClusterLockdown::REDIS_BIND . "\n", (string) file_get_contents($this->rDir . '/redis.conf'));
		// The setting is off, yet the chain is on: MAIN and loopback only.
		$this->assertSame(DbAllowlist::chainLines(['10.0.0.1/32', '203.0.113.1/32']), $this->rFw['/sbin/iptables']['chain']);
		$this->assertSame(1, $this->rFw['/sbin/iptables']['jumps']);
		$this->assertContains('systemctl restart mariadb', $this->rCalls);
		$this->assertTrue(DbAllowlist::lockedDown());

		$rUndo = $this->lockdown()->undo(false);
		$this->assertContains('MariaDB: drop-in removed', $rUndo);
		$this->assertContains('Redis: bind *', $rUndo);
		$this->assertFileDoesNotExist($this->rDir . '/mysql/' . ClusterLockdown::MYSQL_DROPIN);
		$this->assertStringStartsWith("bind *\n", (string) file_get_contents($this->rDir . '/redis.conf'), 'the bind it replaced');
		$this->assertNull($this->rFw['/sbin/iptables']['chain'], 'with the allowlist setting off, undo removes the chain');
		$this->assertFalse(DbAllowlist::lockedDown());
	}

	public function testTheCronKeepsTheLockdownChainWhateverTheSetting(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 2');
		$this->lockdown()->apply(false);
		$this->rFw['/sbin/iptables'] = ['chain' => null, 'jumps' => 0]; // a flush
		$rAllowlist = (new \ReflectionProperty(ClusterLockdown::class, 'rAllowlist'))->getValue($this->lockdown());
		$this->assertSame([4 => 'applied', 6 => 'ok'], $rAllowlist->sync('root'));
		$this->assertSame(DbAllowlist::chainLines(['10.0.0.1/32', '203.0.113.1/32']), $this->rFw['/sbin/iptables']['chain']);
	}

	public function testWithAnExtraListTheBindsStayAndTheFirewallAdmitsIt(): void {
		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 2');
		$this->rDb->exec("UPDATE `settings` SET `cluster_db_allowlist_extra` = '198.51.100.0/24'");
		$rLines = $this->lockdown()->apply(false);
		$this->assertFileDoesNotExist($this->rDir . '/mysql/' . ClusterLockdown::MYSQL_DROPIN);
		$this->assertStringStartsWith("bind *\n", (string) file_get_contents($this->rDir . '/redis.conf'));
		$this->assertStringContainsString('keep their binds', $rLines[0]);
		$this->assertSame(DbAllowlist::chainLines(['10.0.0.1/32', '198.51.100.0/24', '203.0.113.1/32']), $this->rFw['/sbin/iptables']['chain']);
	}

	public function testForceLocksDownAndRecordsWhomItCutOff(): void {
		$rLines = $this->lockdown()->apply(true);
		$this->assertNotEmpty($rLines);
		$this->assertSame(['nodes' => [2], 'proxies' => []], ClusterLockdown::state()['forced']);
		$this->assertNotContains('-A XCVM_DB -s 203.0.113.2/32 -j ACCEPT', $this->rFw['/sbin/iptables']['chain'], 'the mode-1 node is cut off');
	}

	/** Manual only: no cron, daemon or request path runs it. */
	#[\PHPUnit\Framework\Attributes\Group('skip-on-panel')]
	public function testNothingButTheOperatorRunsIt(): void {
		$rSrc = MAIN_HOME;
		$rHits = [];
		foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($rSrc, \FilesystemIterator::SKIP_DOTS)) as $rFile) {
			if (!str_ends_with($rFile->getPathname(), '.php') || str_contains($rFile->getPathname(), '/vendor/')) {
				continue;
			}
			$rBody = (string) file_get_contents($rFile->getPathname());
			if (str_contains($rBody, 'ClusterLockdown') || str_contains($rBody, 'cluster:lockdown')) {
				$rHits[] = substr($rFile->getPathname(), strlen($rSrc));
			}
		}
		sort($rHits);
		$this->assertSame(['Cli/Commands/ClusterLockdownCommand.php', 'Domain/Cluster/ClusterLockdown.php', 'Domain/Cluster/DbAllowlist.php', 'Domain/Cluster/LockdownRefused.php'], $rHits);
		$this->assertStringContainsString('Cli/Commands/ClusterLockdownCommand.php', (string) file_get_contents(dirname(__DIR__, 2) . '/Makefile'));
	}
}
