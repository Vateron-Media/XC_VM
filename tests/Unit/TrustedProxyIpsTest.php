<?php

use PHPUnit\Framework\TestCase;

final class TrustedProxyIpsTest extends TestCase {
	public function testAdminAllowlistIsNotTrustedAsARealIpProxy(): void {
		$rCron = (string) file_get_contents(MAIN_HOME . 'Cli/CronJobs/RootSignalsCronJob.php');
		$rRepo = (string) file_get_contents(MAIN_HOME . 'Domain/Server/ServerRepository.php');
		$this->assertStringContainsString('ServerRepository::getAllowedIPs(false, false)', $rCron);
		$this->assertStringContainsString('if ($rIncludeAdmin && !empty($rSettings[\'allowed_ips_admin\']))', $rRepo);
	}
}
