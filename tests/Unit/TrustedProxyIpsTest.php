<?php

use PHPUnit\Framework\TestCase;
use XcVm\Domain\Server\ServerRepository;

final class TrustedProxyIpsTest extends TestCase {
	public function testAdminAllowlistIsNotTrustedAsARealIpProxy(): void {
		global $rServers, $rSettings;
		$rCron = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringContainsString('ServerRepository::getAllowedIPs(rIncludeAdmin: false)', $rCron);
		$rOldServers = $rServers ?? null;
		$rOldSettings = $rSettings ?? null;
		defined('SERVER_ID') || define('SERVER_ID', 1);
		$rServers = [
			1 => ['server_ip' => '192.0.2.1', 'private_ip' => '10.0.0.1', 'whitelist_ips' => '["198.51.100.1"]'],
		];
		$rSettings = ['allowed_ips_admin' => '203.0.113.50'];
		try {
			$rIPs = ServerRepository::getAllowedIPs(true, false);
		} finally {
			$rServers = $rOldServers;
			$rSettings = $rOldSettings;
		}
		$this->assertContains('192.0.2.1', $rIPs);
		$this->assertContains('10.0.0.1', $rIPs);
		$this->assertContains('198.51.100.1', $rIPs);
		$this->assertNotContains('203.0.113.50', $rIPs);
	}
}
