<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterDbAllowlistCommand;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Domain\Cluster\DbAllowlist;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The opt-in 3306/6379 allowlist: who is allowed, the rules it writes, that a
 * pass only rewrites what differs, and that turning it off removes it all.
 * An in-memory firewall stands in for iptables; the opt-in
 * testInANetworkNamespace runs the real tools in a throwaway namespace.
 */
final class DbAllowlistTest extends TestCase {
	private TestDb $rDb;

	/** @var array<string, array{chain: ?list<string>, jumps: int}> */
	private array $rFw = [];

	/** @var list<string> */
	private array $rCalls = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `servers` (`id` int, `server_type` int, `is_main` int, `server_ip` varchar(255), `private_ip` varchar(255), `proxy_signed` int DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `cluster_nodes` (`server_id` int, `mode` int)');
		$this->rDb->exec("CREATE TABLE `settings` (`cluster_db_allowlist` int DEFAULT 0, `cluster_db_allowlist_extra` varchar(1024) DEFAULT '')");
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec("INSERT INTO `settings` VALUES (0, '')");
		$this->rDb->exec("INSERT INTO `servers` (`id`, `server_type`, `is_main`, `server_ip`, `private_ip`) VALUES (1, 0, 1, '203.0.113.1', '10.0.0.1'), (2, 0, 0, '203.0.113.2', ''), (3, 0, 0, '203.0.113.3', '10.0.0.3'), (4, 1, 0, '2001:db8::4', NULL), (5, 0, 0, 'lb5.example', '')");
		$this->rDb->exec('INSERT INTO `cluster_nodes` VALUES (2, 1), (3, 2)');
		DatabaseFactory::set($this->rDb);
		$this->rFw = ['/sbin/iptables' => ['chain' => null, 'jumps' => 0], '/sbin/ip6tables' => ['chain' => null, 'jumps' => 0]];
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
	}

	public function testExtraEntriesAreNormalisedOrRefused(): void {
		$this->assertSame([['10.0.0.0/8', '192.0.2.7/32', '2001:db8::/32'], false], DbAllowlist::parseExtra("10.0.0.7/8\n192.0.2.7, 2001:DB8:0::1/32\n\n10.0.0.0/8"));
		foreach (['10.0.0.1/33', 'not-an-ip', '10.0.0.1/x', '::1/129', '10.0.0.1/-1'] as $rBad) {
			$this->assertSame([[], true], DbAllowlist::parseExtra($rBad), $rBad);
		}
		$this->assertSame([[], false], DbAllowlist::parseExtra(''));
		$this->assertSame([['cluster_db_allowlist' => 1, 'cluster_db_allowlist_extra' => "10.0.0.0/8\n192.0.2.7/32"], []], ClusterSettings::normalize(['cluster_db_allowlist' => '1', 'cluster_db_allowlist_extra' => "10.1.2.3/8\r\n192.0.2.7"], [], []));
		$this->assertSame([[], [['cluster_db_allowlist_extra', 'cluster_error_db_allowlist']]], ClusterSettings::normalize(['cluster_db_allowlist_extra' => 'example.com'], [], []));
		$this->assertContains('cluster_db_allowlist_extra', ClusterSettings::keys());
	}

	public function testEveryServerButMode2NodesAndTheExtraListAreAllowed(): void {
		$this->rDb->exec("UPDATE `settings` SET `cluster_db_allowlist_extra` = '198.51.100.0/24'");
		$rWanted = $this->allowlist()->wanted();
		// Server 3 runs in mode 2 and is left out; 5 is resolved by name; 4 is a v6 proxy.
		$this->assertSame([
			4 => ['10.0.0.1/32', '198.51.100.0/24', '198.51.100.5/32', '203.0.113.1/32', '203.0.113.2/32'],
			6 => ['2001:db8::4/128'],
		], $rWanted);
		$this->assertTrue(DbAllowlist::covers($rWanted[4], '198.51.100.77'));
		// A proxy that signs its channel (ProxyKey, D8) no longer reaches either port.
		$this->rDb->exec('UPDATE `servers` SET `proxy_signed` = 1 WHERE `id` = 4');
		$this->assertNotContains('2001:db8::4/128', $this->allowlist()->wanted()[6] ?? []);
		$this->assertFalse(DbAllowlist::covers($rWanted[4], '203.0.113.3'));
	}

	public function testNoMainRowMeansNothingIsTouched(): void {
		$this->rDb->exec('UPDATE `servers` SET `is_main` = 0');
		$this->rDb->exec('UPDATE `settings` SET `cluster_db_allowlist` = 1');
		$this->assertNull($this->allowlist()->sync());
		$this->assertSame([], $this->rCalls);
	}

	public function testApplyOnlyRewritesWhatDiffersAndOffRemovesIt(): void {
		$this->rDb->exec('UPDATE `settings` SET `cluster_db_allowlist` = 1');
		$this->assertSame([4 => 'applied', 6 => 'applied'], $this->allowlist()->sync());
		$this->assertSame(DbAllowlist::chainLines(['10.0.0.1/32', '198.51.100.5/32', '203.0.113.1/32', '203.0.113.2/32']), $this->rFw['/sbin/iptables']['chain']);
		$this->assertSame(1, $this->rFw['/sbin/iptables']['jumps']);
		$this->assertSame(['-N XCVM_DB', '-A XCVM_DB -i lo -j ACCEPT', '-A XCVM_DB -s 2001:db8::4/128 -j ACCEPT', '-A XCVM_DB -j DROP'], $this->rFw['/sbin/ip6tables']['chain']);

		// Steady: read-only checks, no restore, no audit.
		$this->rCalls = [];
		$this->assertSame([4 => 'ok', 6 => 'ok'], $this->allowlist()->sync());
		$this->assertSame([], preg_grep('/-restore| -I /', $this->rCalls));

		// A flush (iptables -F) empties the chain and drops the jump: back within a pass.
		$this->rFw['/sbin/iptables'] = ['chain' => ['-N XCVM_DB'], 'jumps' => 0];
		$this->assertSame([4 => 'applied', 6 => 'ok'], $this->allowlist()->sync());
		$this->assertSame(1, $this->rFw['/sbin/iptables']['jumps']);

		// A node moving to mode 2 leaves the list.
		$this->rDb->exec('UPDATE `cluster_nodes` SET `mode` = 2 WHERE `server_id` = 2');
		$this->assertSame([4 => 'applied', 6 => 'ok'], $this->allowlist()->sync());
		$this->assertNotContains('-A XCVM_DB -s 203.0.113.2/32 -j ACCEPT', $this->rFw['/sbin/iptables']['chain']);

		$this->rDb->exec('UPDATE `settings` SET `cluster_db_allowlist` = 0');
		$this->assertSame([4 => 'removed', 6 => 'removed'], $this->allowlist()->sync());
		$this->assertSame(['chain' => null, 'jumps' => 0], $this->rFw['/sbin/iptables']);
		$this->assertSame([4 => 'absent', 6 => 'absent'], $this->allowlist()->sync());

		$this->rDb->query('SELECT `event`, `detail` FROM `cluster_audit` ORDER BY `id`');
		$rAudit = $this->rDb->get_rows();
		$this->assertCount(4, $rAudit);
		$this->assertSame('cluster.db_allowlist', $rAudit[0]['event']);
		$this->assertStringContainsString('"on":false', $rAudit[3]['detail']);
	}

	public function testAFailedRestoreIsReportedAndNotAudited(): void {
		$this->rDb->exec('UPDATE `settings` SET `cluster_db_allowlist` = 1');
		$rResult = $this->allowlist(failRestore: true)->sync();
		$this->assertStringStartsWith('error: iptables-restore exited 1', $rResult[4]);
		$this->assertSame(0, $this->rFw['/sbin/iptables']['jumps'], 'no jump into a chain that was not written');
		$this->assertSame('skipped', $this->allowlist(noV6: true)->reconcile(null)[6]);
	}

	public function testStatusListsTheConnectionsTheAllowlistWouldCut(): void {
		$rSs = "0 0 10.0.0.1:3306 203.0.113.2:51234\n0 0 10.0.0.1:3306 192.0.2.99:40000\n0 0 [::ffff:10.0.0.1]:6379 [::ffff:192.0.2.99]:40001\n0 0 127.0.0.1:6379 127.0.0.1:5000\n0 0 [2001:db8::1]:3306 [2001:db8::4]:1234\n";
		$rWanted = $this->allowlist(ss: $rSs)->wanted();
		$this->assertSame(['192.0.2.99:40000', '[::ffff:192.0.2.99]:40001'], $this->allowlist(ss: $rSs)->refusedPeers($rWanted));

		ob_start();
		$rCode = (new ClusterDbAllowlistCommand($this->allowlist(ss: $rSs), false))->execute(['status']);
		$rOut = (string) ob_get_clean();
		$this->assertSame(0, $rCode);
		$this->assertStringContainsString('Setting: off', $rOut);
		$this->assertStringContainsString('192.0.2.99:40000', $rOut);
		$this->assertStringContainsString('IPv4 chain: absent', $rOut);
	}

	public function testApplyAndUndoFlipTheSetting(): void {
		ob_start();
		$this->assertSame(0, (new ClusterDbAllowlistCommand($this->allowlist(), false))->execute(['apply']));
		$this->rDb->query('SELECT `cluster_db_allowlist` FROM `settings`');
		$this->assertSame(1, (int) $this->rDb->get_row()['cluster_db_allowlist']);
		$this->assertNotNull($this->rFw['/sbin/iptables']['chain']);
		$this->assertSame(0, (new ClusterDbAllowlistCommand($this->allowlist(), false))->execute(['undo']));
		$this->assertSame(1, (new ClusterDbAllowlistCommand($this->allowlist(), false))->execute(['drop-all']));
		$rOut = (string) ob_get_clean();
		$this->rDb->query('SELECT `cluster_db_allowlist` FROM `settings`');
		$this->assertSame(0, (int) $this->rDb->get_row()['cluster_db_allowlist']);
		$this->assertNull($this->rFw['/sbin/iptables']['chain']);
		$this->assertStringContainsString('Allowlist off: IPv4 removed', $rOut);
	}

	/**
	 * The real iptables/ip6tables in a fresh network namespace (root and
	 * `unshare`): opt in with XCVM_TEST_NETNS=1.
	 */
	public function testInANetworkNamespace(): void {
		if (getenv('XCVM_TEST_NETNS') !== '1') {
			$this->markTestSkipped('XCVM_TEST_NETNS=1 runs this against the real iptables in a network namespace');
		}
		$rScript = tempnam(sys_get_temp_dir(), 'netns') . '.php';
		file_put_contents($rScript, '<?php require ' . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ';
use XcVm\Domain\Cluster\DbAllowlist;
$a = new DbAllowlist();
$w = [4 => ["10.0.0.0/8", "203.0.113.2/32"], 6 => ["2001:db8::4/128"]];
$out = [$a->reconcile($w), $a->reconcile($w), $a->live()];
DbAllowlist::run(["iptables", "-F", "XCVM_DB"], null);
$out[] = $a->reconcile($w);
$out[] = DbAllowlist::run(["iptables", "-S", "INPUT"], null)[1];
$out[] = $a->reconcile(null);
$out[] = $a->live();
echo json_encode($out);');
		$rJson = shell_exec('unshare -n ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($rScript) . ' 2>&1');
		@unlink($rScript);
		$rOut = json_decode((string) $rJson, true);
		$this->assertIsArray($rOut, (string) $rJson);
		$this->assertSame(['4' => 'applied', '6' => 'applied'], $rOut[0]);
		$this->assertSame(['4' => 'ok', '6' => 'ok'], $rOut[1]);
		$this->assertSame(DbAllowlist::chainLines(['10.0.0.0/8', '203.0.113.2/32']), $rOut[2]['4']);
		$this->assertSame(DbAllowlist::chainLines(['2001:db8::4/128']), $rOut[2]['6']);
		$this->assertSame(['4' => 'applied', '6' => 'ok'], $rOut[3]);
		$this->assertSame(1, substr_count($rOut[4], '-j XCVM_DB'));
		$this->assertSame(['4' => 'removed', '6' => 'removed'], $rOut[5]);
		$this->assertSame(['4' => null, '6' => null], $rOut[6]);
	}

	private function allowlist(bool $failRestore = false, bool $noV6 = false, string $ss = ''): DbAllowlist {
		$rExec = function (array $rArgv, ?string $rStdin) use ($failRestore, $ss): array {
			$this->rCalls[] = implode(' ', $rArgv);
			if ($rArgv[0] === 'ss') {
				return [0, $ss];
			}
			if (str_ends_with($rArgv[0], '-restore')) {
				if ($failRestore) {
					return [1, ''];
				}
				$rTool = substr($rArgv[0], 0, -8);
				$rLines = array_values(array_filter(explode("\n", (string) $rStdin), static fn ($l) => str_starts_with($l, '-A ')));
				$this->rFw[$rTool]['chain'] = array_merge(['-N XCVM_DB'], $rLines);
				return [0, ''];
			}
			$rTool = $rArgv[0];
			$rJump = array_slice($rArgv, 2) === array_merge(['INPUT'], DbAllowlist::jumpRule()) || array_slice($rArgv, 2) === array_merge(['INPUT', '1'], DbAllowlist::jumpRule());
			switch ($rArgv[1]) {
				case '-S':
					$rChain = $this->rFw[$rTool]['chain'];
					return $rChain === null ? [1, 'No chain/target/match by that name.'] : [0, implode("\n", $rChain) . "\n"];
				case '-C':
					return [$rJump && $this->rFw[$rTool]['jumps'] > 0 ? 0 : 1, ''];
				case '-I':
					if ($this->rFw[$rTool]['chain'] === null) {
						return [2, ''];
					}
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
		$rResolve = static fn (string $rHost): array => $rHost === 'lb5.example' ? ['198.51.100.5'] : [];
		return new DbAllowlist($rExec, $rResolve, [4 => '/sbin/iptables', 6 => $noV6 ? null : '/sbin/ip6tables']);
	}
}
