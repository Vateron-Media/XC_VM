<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ToolsCommand;
use XcVm\Cli\CronJobs\RootSignalsCronJob;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * The blocklist flush (the Flush button's root action on every server, and
 * `tools flush`) removes the blocks the panel added, one
 * `-A INPUT -s <address> -j DROP` rule each, and the flood guard's block
 * files. The host's other firewall rules, chains and policies are not the
 * panel's: an operator's default-drop policy with its ACCEPT rules, ufw's
 * chains and the panel's own DB allowlist chain stay as they are.
 */
final class AuditRootCronFirewallFlushTest extends TestCase {
	/** `iptables -S INPUT` of a host with rules of its own and three blocks of the panel's (one twice). */
	private const V4 = [
		'-P INPUT DROP',
		'-A INPUT -s 203.0.113.7/32 -j DROP',
		'-A INPUT -m conntrack --ctstate RELATED,ESTABLISHED -j ACCEPT',
		'-A INPUT -i lo -j ACCEPT',
		'-A INPUT -p tcp -m tcp --dport 22 -j ACCEPT',
		'-A INPUT -s 198.51.100.9/32 -j DROP',
		'-A INPUT -s 198.51.100.9/32 -j DROP',
		'-A INPUT -s 10.20.0.0/16 -j DROP',
		'-A INPUT -s 192.0.2.9/32 -p tcp -m tcp --dport 3306 -j DROP',
		'-A INPUT -s 192.0.2.10/32 -j REJECT --reject-with icmp-port-unreachable',
		'-A INPUT -j ufw-before-input',
		'-A INPUT -j XCVM_DB',
	];

	/** `ip6tables -S INPUT`: one block of the panel's, and two networks the operator drops (a /32 is a network here). */
	private const V6 = [
		'-P INPUT ACCEPT',
		'-A INPUT -s 2001:4860:4860::8888/128 -j DROP',
		'-A INPUT -s 2001:db8:1::/48 -j DROP',
		'-A INPUT -s 2001:db8::/32 -j DROP',
		'-A INPUT -p ipv6-icmp -j ACCEPT',
	];

	/** What removes those blocks, and nothing else of the firewall: one commit per address family. */
	private const REMOVED = [
		'iptables -S INPUT',
		'iptables-restore --noflush',
		'*filter',
		'-D INPUT -s 203.0.113.7 -j DROP',
		'-D INPUT -s 198.51.100.9 -j DROP',
		'-D INPUT -s 198.51.100.9 -j DROP',
		'COMMIT',
		'ip6tables -S INPUT',
		'ip6tables-restore --noflush',
		'*filter',
		'-D INPUT -s 2001:4860:4860::8888 -j DROP',
		'COMMIT',
	];

	/** The same blocks rule by rule, where the tool refuses the list. */
	private const ONE_BY_ONE = [
		'iptables -S INPUT',
		'iptables-restore --noflush',
		'iptables -D INPUT -s 203.0.113.7 -j DROP',
		'iptables -D INPUT -s 198.51.100.9 -j DROP',
		'iptables -D INPUT -s 198.51.100.9 -j DROP',
		'ip6tables -S INPUT',
		'ip6tables-restore --noflush',
		'ip6tables -D INPUT -s 2001:4860:4860::8888 -j DROP',
	];

	private string $rDir;

	private string $rPath;

	protected function setUp(): void {
		if (!defined('FLOOD_TMP_PATH')) {
			define('FLOOD_TMP_PATH', sys_get_temp_dir() . '/xcvm_flood_' . getmypid() . '/');
		}
		@mkdir(FLOOD_TMP_PATH, 0775, true);
		$this->rDir = sys_get_temp_dir() . '/xcvm_fw_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'bin', 0777, true);
		file_put_contents($this->rDir . 'v4', implode("\n", self::V4) . "\n");
		file_put_contents($this->rDir . 'v6', implode("\n", self::V6) . "\n");
		// A `sudo` stand-in, first on the PATH: it logs what it was asked (and
		// the list a restore is given, unless the file `refuse` makes it fail)
		// and lists this test's rules. Nothing reaches the host's firewall.
		file_put_contents($this->rDir . 'bin/sudo', "#!/bin/sh\necho \"\$*\" >> " . escapeshellarg($this->rDir . 'sudo.log') . "\ncase \"\$*\" in\n\t'iptables -S INPUT') cat " . escapeshellarg($this->rDir . 'v4') . ";;\n\t'ip6tables -S INPUT') cat " . escapeshellarg($this->rDir . 'v6') . ";;\n\t*-restore*) [ ! -e " . escapeshellarg($this->rDir . 'refuse') . " ] || exit 1; cat >> " . escapeshellarg($this->rDir . 'sudo.log') . ";;\nesac\nexit 0\n");
		chmod($this->rDir . 'bin/sudo', 0755);
		$this->rPath = (string) getenv('PATH');
	}

	protected function tearDown(): void {
		putenv('PATH=' . $this->rPath);
		DatabaseFactory::reset();
		foreach (['203.0.113.7', '198.51.100.9'] as $rIP) {
			@unlink(FLOOD_TMP_PATH . 'block_' . $rIP);
		}
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return list<string> what the stand-in was asked to run on the firewall, a restore's list after its command */
	private function firewallCommands(): array {
		$rLog = is_file($this->rDir . 'sudo.log') ? file($this->rDir . 'sudo.log', FILE_IGNORE_NEW_LINES) : [];
		return array_values(preg_grep('/^(ip6?tables(-restore)? |\*filter$|-D |COMMIT$)/', $rLog ?: []) ?: []);
	}

	public function testOnlyRulesOfThePanelsOwnFormAreItsBlocks(): void {
		$this->assertSame(['203.0.113.7', '198.51.100.9', '198.51.100.9'], RootSignalsCronJob::ownBlocks(self::V4));
		$this->assertSame(['2001:4860:4860::8888'], RootSignalsCronJob::ownBlocks(self::V6));
		$this->assertSame([], RootSignalsCronJob::ownBlocks(['-P INPUT DROP', '-A INPUT -j DROP', '-A FORWARD -s 203.0.113.7/32 -j DROP', '-A INPUT -s 203.0.113.7/32 -i eth1 -j DROP']));
		// The single-address prefix is the family's own: /32 of an IPv6 address is a network.
		$this->assertSame([], RootSignalsCronJob::ownBlocks(['-A INPUT -s 2a01:4f8::/32 -j DROP', '-A INPUT -s 2001:db8::1/64 -j DROP', '-A INPUT -s 10.0.0.0/8 -j DROP', '-A INPUT -s 0.0.0.0/0 -j DROP']));
		$this->assertSame(['192.0.2.1', '2001:db8::1'], RootSignalsCronJob::ownBlocks(['-A INPUT -s 192.0.2.1 -j DROP', '-A INPUT -s 2001:db8::1 -j DROP']));
	}

	public function testTheRootActionRemovesThePanelsBlocksAndNoOtherRule(): void {
		touch(FLOOD_TMP_PATH . 'block_203.0.113.7');
		putenv('PATH=' . $this->rDir . 'bin:' . $this->rPath);
		$rJob = new RootSignalsCronJob();
		$rFlush = new ReflectionMethod($rJob, 'flushIPs');
		$rFlush->setAccessible(true);
		$rFlush->invoke($rJob);

		$this->assertSame([], preg_grep('/ -F\b/', $this->firewallCommands()), 'no chain of the host is flushed');
		$this->assertSame(self::REMOVED, $this->firewallCommands());
		// The shell hands `rm` every block file of the directory: this test's is one of them.
		$this->assertNotEmpty(preg_grep('#^rm -f (.* )?' . preg_quote(FLOOD_TMP_PATH . 'block_203.0.113.7', '#') . '( |$)#', (array) file($this->rDir . 'sudo.log', FILE_IGNORE_NEW_LINES)), 'the flood guard\'s block files go too');
	}

	public function testWhereTheListIsRefusedTheBlocksAreRemovedOneByOne(): void {
		touch($this->rDir . 'refuse');
		putenv('PATH=' . $this->rDir . 'bin:' . $this->rPath);
		$rJob = new RootSignalsCronJob();
		$rJob->unblockAll();

		$this->assertSame([], preg_grep('/ -F\b/', $this->firewallCommands()), 'no chain of the host is flushed');
		$this->assertSame(self::ONE_BY_ONE, $this->firewallCommands());
	}

	public function testToolsFlushRemovesThePanelsBlocksAndNoOtherRule(): void {
		$rDb = new TestDb();
		$rDb->exec('CREATE TABLE `cluster_changes` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `section` varchar(32), `op` varchar(8), `kind` varchar(16), `value` varchar(255), `time` int)');
		$rDb->exec('CREATE TABLE `blocked_ips` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `ip` varchar(39) UNIQUE, `notes` text, `date` int)');
		$rDb->exec("INSERT INTO `blocked_ips` (`ip`) VALUES ('203.0.113.7'), ('198.51.100.9')");
		DatabaseFactory::set($rDb);
		putenv('PATH=' . $this->rDir . 'bin:' . $this->rPath);
		$rTools = new ToolsCommand();
		$rFlush = new ReflectionMethod($rTools, 'processFlush');
		$rFlush->setAccessible(true);
		ob_start();
		try {
			$this->assertSame(0, $rFlush->invoke($rTools));
		} finally {
			ob_end_clean();
		}

		$this->assertSame([], preg_grep('/ -F\b/', $this->firewallCommands()), 'no chain of the host is flushed');
		$this->assertSame(self::REMOVED, $this->firewallCommands());
		$rDb->query('SELECT COUNT(*) AS `count` FROM `blocked_ips`');
		$this->assertSame(0, (int) $rDb->get_row()['count']);
	}

	/**
	 * The real iptables/ip6tables in a fresh network namespace (root, `sudo`
	 * and `unshare`): opt in with XCVM_TEST_NETNS=1.
	 */
	public function testInANetworkNamespaceTheHostsOwnRulesSurviveTheFlush(): void {
		if (getenv('XCVM_TEST_NETNS') !== '1') {
			$this->markTestSkipped('XCVM_TEST_NETNS=1 runs this against the real iptables in a network namespace');
		}
		$rScript = $this->rDir . 'netns.php';
		file_put_contents($rScript, '<?php require ' . var_export(MAIN_HOME . 'vendor/autoload.php', true) . ';
define("FLOOD_TMP_PATH", ' . var_export($this->rDir, true) . ');
function fw(): array {
	$rOut = [];
	foreach (["iptables", "ip6tables"] as $rTool) {
		exec($rTool . " -S", $rOut);
	}
	return $rOut;
}
foreach (["iptables -N OPERATOR", "iptables -A OPERATOR -s 192.0.2.50/32 -j ACCEPT", "iptables -A INPUT -i lo -j ACCEPT", "iptables -A INPUT -p tcp --dport 22 -j ACCEPT", "iptables -A INPUT -s 10.20.0.0/16 -j DROP", "iptables -A INPUT -s 192.0.2.9 -p tcp --dport 3306 -j DROP", "iptables -A INPUT -j OPERATOR", "iptables -P INPUT DROP", "ip6tables -A INPUT -p tcp --dport 22 -j ACCEPT", "ip6tables -A INPUT -s 2001:db8::/32 -j DROP", "ip6tables -A INPUT -s 2001:db8:1::/48 -j DROP", "ip6tables -P INPUT DROP"] as $rRule) {
	exec($rRule);
}
$rBefore = fw();
foreach (["iptables -I INPUT -s 203.0.113.7 -j DROP", "iptables -I INPUT -s 198.51.100.9 -j DROP", "iptables -I INPUT -s 198.51.100.9 -j DROP", "ip6tables -I INPUT -s 2001:4860:4860::8888 -j DROP"] as $rBlock) {
	exec($rBlock);
}
$rBlocked = fw();
touch(FLOOD_TMP_PATH . "block_203.0.113.7");
$rJob = new XcVm\Cli\CronJobs\RootSignalsCronJob();
$rFlush = new ReflectionMethod($rJob, "flushIPs");
$rFlush->setAccessible(true);
$rFlush->invoke($rJob);
echo json_encode([$rBefore, $rBlocked, fw(), file_exists(FLOOD_TMP_PATH . "block_203.0.113.7")]);');
		$rJson = shell_exec('unshare -n ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($rScript) . ' 2>&1');
		// The script's last line: a tool's own messages come before it.
		$rOut = json_decode((string) strrchr("\n" . trim((string) $rJson), "\n"), true);
		$this->assertIsArray($rOut, (string) $rJson);
		$this->assertContains('-P INPUT DROP', $rOut[0]);
		$this->assertContains('-A INPUT -j OPERATOR', $rOut[0]);
		$this->assertCount(count($rOut[0]) + 4, $rOut[1], 'the four blocks are in place before the flush');
		$this->assertSame($rOut[0], $rOut[2], 'after the flush the firewall is as the operator left it');
		$this->assertFalse($rOut[3], 'the flood guard\'s block files go too');
	}
}
