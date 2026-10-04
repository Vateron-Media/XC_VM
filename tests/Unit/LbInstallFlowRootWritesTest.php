<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\LbInstallFlow;

/**
 * server:install drives a node over SSH as a sudo-capable user, not root.
 *
 * - `sudo echo … >> /etc/fstab` runs only the echo under sudo; the redirect is
 *   the SSH user's own, so the fstab (and systemd, sysctl, nginx port) writes
 *   failed on every non-root install. Such writes go through `sudo tee`.
 * - config_pack() was handed `port => ConfigReader::get('port')`, but
 *   ConfigReader holds only the `server` section, so every LB got DB port 0.
 */
final class LbInstallFlowRootWritesTest extends TestCase {

	public function testSudoWriteRunsTheWriteItselfAsRoot(): void {
		$this->assertSame(
			"echo 'tmpfs /x tmpfs size=90% 0 0' | sudo tee -a '/etc/fstab' > /dev/null",
			LbInstallFlow::sudoWrite('tmpfs /x tmpfs size=90% 0 0', '/etc/fstab', true)
		);
		$this->assertSame(
			"echo 'listen 80;' | sudo tee '/home/xc_vm/bin/nginx/conf/ports/http.conf' > /dev/null",
			LbInstallFlow::sudoWrite('listen 80;', '/home/xc_vm/bin/nginx/conf/ports/http.conf')
		);
	}

	public function testSudoWriteWritesTheTextVerbatim(): void {
		$rFile = sys_get_temp_dir() . '/xcvm_sudo_write_' . uniqid();
		$rText = "\n" . 'on_play http://127.0.0.1:80/stream/rtmp; $HOME "q" \'s\'';
		try {
			foreach ([false, true] as $rAppend) {
				$rLine = str_replace('sudo tee', 'tee', LbInstallFlow::sudoWrite($rText, $rFile, $rAppend));
				exec($rLine, $rOut, $rCode);
				$this->assertSame(0, $rCode);
			}
			$this->assertSame($rText . "\n" . $rText . "\n", file_get_contents($rFile));
		} finally {
			@unlink($rFile);
		}
	}

	public function testNoSshFlowRedirectsASudoEcho(): void {
		$rRoot = MAIN_HOME . 'Cli/Commands/';
		foreach (['LbInstallFlow.php', 'ProxyInstallFlow.php', 'ServerInstallCommand.php'] as $rFile) {
			$rSource = (string) file_get_contents($rRoot . $rFile);
			$this->assertDoesNotMatchRegularExpression('/sudo echo[^\n]*>/', $rSource, $rFile);
		}
	}

	public function testConfigPackIsNotHandedAPortFromTheServerSection(): void {
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$rServers = [SERVER_ID => ['server_ip' => '10.0.0.1'], 7 => ['server_ip' => '10.0.0.7']];

		$rParams = LbInstallFlow::configPackParams($rServers, 7);

		$this->assertArrayNotHasKey('port', $rParams, 'config_pack() packs its default DB port, 3306 (ADR-001), and refuses 0');
		$this->assertSame(['hostname' => '10.0.0.1', 'database' => 'xc_vm', 'server_id' => 7, 'is_lb' => 1], $rParams);
		$this->assertStringNotContainsString('ConfigReader::', (string) file_get_contents(MAIN_HOME . 'Cli/Commands/LbInstallFlow.php'));
	}
}
