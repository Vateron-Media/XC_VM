<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\IpsetCommand;

/**
 * `console.php ipset`, which an update starts: ipset where it is missing,
 * ipset-persistent only beside an iptables-persistent the server has, the
 * package lists refreshed once, and nothing at all where ipset is there or
 * apt is not. Stand-ins first on the PATH log what they were asked.
 */
final class IpsetCommandTest extends TestCase {
	private string $rDir;

	private string $rPath;

	/** Whether update.log was there before: the command logs to it (UpdateLogger), and a test's line is not kept. */
	private bool $rHadLog;

	protected function setUp(): void {
		$this->rHadLog = is_file(MAIN_HOME . 'update.log');
		$this->rDir = sys_get_temp_dir() . '/xcvm_ipset_' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'bin', 0777, true);
		$this->rPath = (string) getenv('PATH');
	}

	protected function tearDown(): void {
		putenv('PATH=' . $this->rPath);
		if (!$this->rHadLog) {
			@unlink(MAIN_HOME . 'update.log');
		}
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** A stand-in that logs its arguments; $rBody runs after (its exit status is the stand-in's). */
	private function tool(string $rName, string $rBody = 'exit 0'): void {
		file_put_contents($this->rDir . 'bin/' . $rName, "#!/bin/sh\necho \"" . $rName . " \$*\" >> " . escapeshellarg($this->rDir . 'log') . "\n" . $rBody . "\n");
		chmod($this->rDir . 'bin/' . $rName, 0755);
	}

	/** @return list<string> what apt-get was asked */
	private function aptCalls(): array {
		// No sbin on this PATH: this machine's own ipset is not found.
		putenv('PATH=' . $this->rDir . 'bin:/usr/bin:/bin');
		ob_start();
		$this->assertSame(0, (new IpsetCommand())->execute([]));
		ob_end_clean();
		$rLog = is_file($this->rDir . 'log') ? file($this->rDir . 'log', FILE_IGNORE_NEW_LINES) : [];
		return array_values(preg_grep('/^apt-get /', $rLog ?: []) ?: []);
	}

	public function testBesideIptablesPersistentBothAreInstalled(): void {
		$this->tool('apt-get');
		$this->tool('dpkg-query', "printf 'install ok installed'");
		$this->assertSame(['apt-get -o DPkg::Lock::Timeout=120 install -y -q ipset ipset-persistent'], $this->aptCalls());
	}

	public function testWithoutItIpsetAlone(): void {
		$this->tool('apt-get');
		$this->tool('dpkg-query', 'exit 1');
		$this->assertSame(['apt-get -o DPkg::Lock::Timeout=120 install -y -q ipset'], $this->aptCalls());
	}

	public function testIpsetAloneWhereThePersistentPackageWillNotInstallThenTheListsAreRefreshed(): void {
		// apt refuses anything naming ipset-persistent, and ipset until the lists are refreshed.
		$this->tool('apt-get', 'case "$*" in *ipset-persistent*) exit 100;; *update*) touch ' . escapeshellarg($this->rDir . 'updated') . '; exit 0;; *) [ -e ' . escapeshellarg($this->rDir . 'updated') . ' ];; esac');
		$this->tool('dpkg-query', "printf 'install ok installed'");
		$this->assertSame([
			'apt-get -o DPkg::Lock::Timeout=120 install -y -q ipset ipset-persistent',
			'apt-get -o DPkg::Lock::Timeout=120 install -y -q ipset',
			'apt-get -o DPkg::Lock::Timeout=120 update -q',
			'apt-get -o DPkg::Lock::Timeout=120 install -y -q ipset ipset-persistent',
			'apt-get -o DPkg::Lock::Timeout=120 install -y -q ipset',
		], $this->aptCalls());
	}

	public function testNothingWhereIpsetIsThereOrAptIsNot(): void {
		$this->tool('ipset');
		$this->tool('apt-get');
		$this->assertSame([], $this->aptCalls(), 'ipset there');

		unlink($this->rDir . 'bin/ipset');
		unlink($this->rDir . 'bin/apt-get');
		$this->assertSame([], $this->aptCalls(), 'no apt');
	}
}
