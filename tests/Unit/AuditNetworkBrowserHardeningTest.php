<?php

use PHPUnit\Framework\TestCase;

final class AuditNetworkBrowserHardeningTest extends TestCase {
	public function testInternalPortsAndProxyHeadersAreRestrictedByDefault(): void {
		$rSchema = (string) file_get_contents(MAIN_HOME . 'bin/install/database.sql');
		$this->assertMatchesRegularExpression("/`cluster_db_allowlist` tinyint\(1\) DEFAULT '1'/", $rSchema);
		$this->assertStringContainsString('listen 127.0.0.1:31210;', (string) file_get_contents(MAIN_HOME . 'bin/nginx_rtmp/conf/nginx.conf'));

		foreach (['bin/nginx/conf/nginx.conf', '../lb_configs/nginx.conf'] as $rFile) {
			$rNginx = (string) file_get_contents(MAIN_HOME . $rFile);
			$this->assertStringContainsString('real_ip_recursive on;', $rNginx, $rFile);
			$this->assertStringContainsString('add_header X-Content-Type-Options nosniff always;', $rNginx, $rFile);
			$this->assertStringContainsString('add_header X-Frame-Options SAMEORIGIN always;', $rNginx, $rFile);
			$this->assertStringContainsString('add_header Content-Security-Policy', $rNginx, $rFile);
			$this->assertStringContainsString('add_header Strict-Transport-Security $xc_hsts always;', $rNginx, $rFile);
		}
		$this->assertStringContainsString('XC_PEER_ADDR       $realip_remote_addr', (string) file_get_contents(MAIN_HOME . 'bin/nginx/conf/fastcgi_params'));
	}

	public function testRemoteUpdateTextAndLinksAreRenderedAsData(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/settings.php');
		$this->assertGreaterThanOrEqual(3, substr_count($rView, 'htmlspecialchars('));
		$this->assertStringContainsString("['host'] ?? '')) !== 'github.com'", $rView);
		$this->assertStringContainsString('rel="noopener noreferrer"', $rView);
	}

	public function testLogoutRoutesArePostOnly(): void {
		foreach (['admin.php', 'reseller.php', 'player.php', 'player_v2.php'] as $rRoute) {
			$rSource = (string) file_get_contents(MAIN_HOME . 'Public/routes/' . $rRoute);
			$this->assertStringContainsString("->post('logout'", $rSource, $rRoute);
			$this->assertStringNotContainsString("->get('logout'", $rSource, $rRoute);
		}
	}
}
