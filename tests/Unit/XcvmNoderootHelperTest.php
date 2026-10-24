<?php

use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the root-owned serve-only helper (src/bin/install/xcvm-noderoot).
 * The script is exec'd against a sandbox root (XCVM_NODEROOT_ROOT) with hostile
 * input: every invalid call must exit non-zero and change nothing, and only the
 * two hardcoded ipsets may ever be named.
 */
final class XcvmNoderootHelperTest extends TestCase {
	private string $root;

	protected function setUp(): void {
		$this->root = sys_get_temp_dir() . '/noderoot-' . bin2hex(random_bytes(6));
		foreach (['etc', 'home/xc_vm/tmp/cluster', 'home/xc_vm/content/streams'] as $d) {
			mkdir($this->root . '/' . $d, 0777, true);
		}
		file_put_contents($this->root . '/etc/sysctl.conf', "net.core.somaxconn = 1\n");
		file_put_contents($this->root . '/etc/fstab', "UUID=x / ext4 defaults 0 1\n#tmpfs /home/xc_vm/content/streams tmpfs defaults,size=50% 0 0\n");
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->root));
	}

	/** @return array{0:int,1:string} */
	private function helper(array $args, array $env = []): array {
		$cmd = array_merge(['/bin/sh', MAIN_HOME . 'bin/install/xcvm-noderoot'], $args);
		$p = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['XCVM_NODEROOT_ROOT' => $this->root, 'PATH' => '/usr/bin:/bin'] + $env);
		$out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
		return [proc_close($p), $out];
	}

	private function stage(string $name, string $body): void {
		file_put_contents($this->root . '/home/xc_vm/tmp/cluster/' . $name, $body);
	}

	private function calls(): string {
		return is_file($this->root . '/calls.log') ? (string) file_get_contents($this->root . '/calls.log') : '';
	}

	public function testSysctlAcceptsNetKeysAndWritesThem(): void {
		$this->stage('sysctl.pending', "# c\nnet.ipv4.tcp_syncookies = 1\nnet.core.somaxconn=4096\n");
		[$rc] = $this->helper(['sysctl']);
		$this->assertSame(0, $rc);
		$this->assertSame("net.ipv4.tcp_syncookies = 1\nnet.core.somaxconn = 4096\n", file_get_contents($this->root . '/etc/sysctl.conf'));
		$this->assertStringContainsString('sysctl -p', $this->calls());
	}

	/** @dataProvider badSysctl */
	public function testSysctlRejectsWholeBodyAndChangesNothing(string $body): void {
		$this->stage('sysctl.pending', $body);
		[$rc] = $this->helper(['sysctl']);
		$this->assertNotSame(0, $rc);
		$this->assertSame("net.core.somaxconn = 1\n", file_get_contents($this->root . '/etc/sysctl.conf'));
		$this->assertSame('', $this->calls());
	}

	public static function badSysctl(): array {
		return [
			'core_pattern' => ["kernel.core_pattern=|/x\n"],
			'mixed' => ["net.ipv4.ip_forward = 1\nkernel.core_pattern = |/x\n"],
			'modprobe' => ["kernel.modprobe = /evil\n"],
			'dotdot' => ["net..x = 1\n"],
			'slash' => ["net/../kernel/core_pattern = 1\n"],
			'pipe value' => ["net.core.x = 1|/evil\n"],
			'prefix trick' => ["network.x = 1\n"],
			'hotplug' => ["kernel.hotplug = /x\n"],
			'other kernel' => ["kernel.shmmni = 1\n"],
			'kernel.shmmax prefix' => ["kernel.shmmax.evil = 1\n"],
			'usermodehelper' => ["kernel.usermodehelper.bset = 1\n"],
			'poweroff' => ["kernel.poweroff_cmd = /x\n"],
			'mixed net fs vm + core_pattern' => ["net.core.somaxconn = 1\nfs.file-max = 5\nvm.max_map_count = 6\nkernel.core_pattern = |/x\n"],
			'suid_dumpable' => ["fs.suid_dumpable = 2\n"],
			'protected_symlinks' => ["fs.protected_symlinks = 0\n"],
			'mmap_min_addr' => ["vm.mmap_min_addr = 0\n"],
			'userfaultfd' => ["vm.unprivileged_userfaultfd = 1\n"],
			'drop_caches' => ["vm.drop_caches = 3\n"],
			'mixed with stray vm' => ["net.core.somaxconn = 1\nvm.mmap_min_addr = 0\n"],
			'bare fs' => ["fs = 1\n"],
			'continuation' => ["net.core.x = 1\\\nkernel.core_pattern = x\n"],
		];
	}

	public function testSysctlAcceptsMainsRealBody(): void {
		$body = implode("\n", ['# XC_VM', '', 'net.core.somaxconn = 655350', 'net.ipv4.route.flush=1', 'fs.file-max = 6815744', 'fs.aio-max-nr = 6815744', 'fs.nr_open = 6815744', 'net.ipv4.ip_local_port_range = 1024 65000', 'net.ipv4.tcp_rmem = 10000000 10000000 10000000', 'net.netfilter.nf_conntrack_max=1215196608', 'vm.max_map_count = 655300', 'net.ipv6.conf.all.disable_ipv6 = 1', 'kernel.shmmax=134217728', 'kernel.shmall=134217728', 'vm.overcommit_memory = 1', 'net.ipv4.tcp_tw_reuse=1']);
		$this->stage('sysctl.pending', $body);
		[$rc] = $this->helper(['sysctl']);
		$this->assertSame(0, $rc);
		$w = (string) file_get_contents($this->root . '/etc/sysctl.conf');
		foreach (['fs.file-max = 6815744', 'vm.max_map_count = 655300', 'kernel.shmmax = 134217728', 'kernel.shmall = 134217728', 'vm.overcommit_memory = 1', 'net.core.somaxconn = 655350'] as $l) {
			$this->assertStringContainsString($l . "\n", $w);
		}
		$this->assertStringNotContainsString('core_pattern', $w);
	}

	public function testBanSyncBuildsRestoreNamingOnlyTheTwoSets(): void {
		$this->stage('bans.pending', "1.2.3.4\n8.8.0.0/16\n2001:4860::8888\n2001:4860::8888\n\n");
		[$rc] = $this->helper(['ban-sync']);
		$this->assertSame(0, $rc);
		$r = (string) file_get_contents($this->root . '/ipset.restore');
		$this->assertStringContainsString("add xcvm_block4_new 1.2.3.4\n", $r);
		$this->assertStringContainsString("add xcvm_block4_new 8.8.0.0/16\n", $r);
		$this->assertSame(1, substr_count($r, "add xcvm_block6_new 2001:4860::8888\n"));
		foreach (explode("\n", trim($r)) as $line) {
			preg_match_all('/xcvm_block\S*/', $line, $m);
			foreach ($m[0] as $name) {
				$this->assertMatchesRegularExpression('/^xcvm_block[46](_new)?$/', $name);
			}
			$this->assertMatchesRegularExpression('/^(create|flush|add|swap|destroy) /', $line);
		}
		$this->assertStringContainsString('-C INPUT -m set --match-set xcvm_block4 src -j DROP', $this->calls());
		$this->assertStringContainsString('-C INPUT -m set --match-set xcvm_block6 src -j DROP', $this->calls());
	}

	/** @dataProvider badBans */
	public function testBanSyncRejectsWholeFileAndTouchesNothing(string $body): void {
		$this->stage('bans.pending', "1.2.3.4\n" . $body);
		[$rc] = $this->helper(['ban-sync']);
		$this->assertNotSame(0, $rc);
		$this->assertFileDoesNotExist($this->root . '/ipset.restore');
		$this->assertSame('', $this->calls());
	}

	public static function badBans(): array {
		return [
			'default route' => ["0.0.0.0/0\n"],
			'v6 default' => ["::/0\n"],
			'wide cidr' => ["8.0.0.0/8\n"],
			'non-ip' => ["example.com\n"],
			'ipset directive' => ["flush othergset\n"],
			'injected add' => ["1.1.1.1 othergset\n"],
			'octet' => ["256.1.1.1\n"],
			'leading zero' => ["01.1.1.1\n"],
			'v6 cidr' => ["2001:4860::/32\n"],
			'v6 junk' => ["2001:::1\n"],
			'comment' => ["# x\n"],
		];
	}

	public function testBanFlushNamesOnlyTheTwoSets(): void {
		[$rc] = $this->helper(['ban-flush']);
		$this->assertSame(0, $rc);
		$this->assertSame("ipset flush xcvm_block4\nipset flush xcvm_block6\n", $this->calls());
	}

	public function testRamdiskOnUncommentsTheLineAndMounts(): void {
		[$rc] = $this->helper(['ramdisk', 'on']);
		$this->assertSame(0, $rc);
		$this->assertStringContainsString("\ntmpfs /home/xc_vm/content/streams tmpfs defaults,size=50% 0 0\n", file_get_contents($this->root . '/etc/fstab'));
		$this->assertSame("mount /home/xc_vm/content/streams\nchown -hR xc_vm:xc_vm /home/xc_vm/content/streams\n", $this->calls());
		$this->assertSame(0, $this->helper(['ramdisk', 'off'])[0]);
		$this->assertStringContainsString("\n#tmpfs /home/xc_vm/content/streams tmpfs defaults,size=50% 0 0\n", file_get_contents($this->root . '/etc/fstab'));
		$this->assertStringContainsString('umount -l /home/xc_vm/content/streams', $this->calls());
	}

	public function testRamdiskFailsWithoutExactlyOneLine(): void {
		file_put_contents($this->root . '/etc/fstab', "UUID=x / ext4 defaults 0 1\n");
		[$rc] = $this->helper(['ramdisk', 'on']);
		$this->assertNotSame(0, $rc);
		$this->assertSame("UUID=x / ext4 defaults 0 1\n", file_get_contents($this->root . '/etc/fstab'));
		$this->assertSame('', $this->calls());
	}

	public function testGovernorOnlyValidKnownNames(): void {
		file_put_contents($this->root . '/cpufreq-info.out', "performance powersave\n");
		$this->assertSame(0, $this->helper(['governor', 'performance'])[0]);
		$this->assertStringContainsString('cpufreq-set -c 0 -g performance', $this->calls());
		$before = $this->calls();
		$this->assertNotSame(0, $this->helper(['governor', 'x;reboot'])[0]);
		$this->assertNotSame(0, $this->helper(['governor', 'Performance'])[0]);
		$this->assertSame(0, $this->helper(['governor', 'ondemand'])[0], 'unknown governor is a no-op');
		$this->assertSame($before, $this->calls());
	}

	/** @dataProvider badCalls */
	public function testMalformedCallsRejectedWithNoEffect(array $args): void {
		[$rc] = $this->helper($args);
		$this->assertNotSame(0, $rc);
		$this->assertSame('', $this->calls());
		$this->assertSame("net.core.somaxconn = 1\n", file_get_contents($this->root . '/etc/sysctl.conf'));
	}

	public static function badCalls(): array {
		return [
			'no verb' => [[]],
			'unknown verb' => [['rm']],
			'ramdisk ..' => [['ramdisk', '../..']],
			'ramdisk evil' => [['ramdisk', 'evil']],
			'service bogus' => [['service', 'bogus']],
			'service extra' => [['service', 'start', 'reboot']],
			'reboot extra' => [['reboot', 'now']],
			'ban-flush extra' => [['ban-flush', 'x']],
			'ban-sync extra' => [['ban-sync', 'x']],
			'sysctl extra' => [['sysctl', '/etc/passwd']],
			'ramdisk extra' => [['ramdisk', 'on', 'off']],
		];
	}

	public function testHostileTmpdirIsIgnored(): void {
		// Nonexistent: a helper that honors TMPDIR fails its mktemp; one that pins /tmp does not.
		$evil = $this->root . '/evil-tmp-missing';
		$this->stage('sysctl.pending', "net.core.somaxconn = 9\n");
		$this->stage('bans.pending', "1.2.3.4\n");
		foreach ([['sysctl'], ['ban-sync']] as $a) {
			$this->assertSame(0, $this->helper($a, ['TMPDIR' => $evil])[0]);
		}
		$this->assertDirectoryDoesNotExist($evil);
	}

	public function testServiceAndRebootRunTheirFixedCommands(): void {
		$this->assertSame(0, $this->helper(['service', 'restart'])[0]);
		$this->assertSame(0, $this->helper(['reboot'])[0]);
		$this->assertSame("systemctl restart xc_vm\nreboot\n", $this->calls());
	}
}
