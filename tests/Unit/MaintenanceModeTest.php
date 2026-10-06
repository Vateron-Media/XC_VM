<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\Maintenance;

/**
 * Maintenance mode (Core\Config\Maintenance): on until its end time, a
 * message of its own or the default, and every client API and reseller path
 * asking it.
 */
final class MaintenanceModeTest extends TestCase {
	private const NOW = 1800000000;

	public function testItIsOnUntilItsEnd(): void {
		$this->assertFalse(Maintenance::active([], self::NOW));
		$this->assertFalse(Maintenance::active(['maintenance_mode' => '0', 'maintenance_until' => self::NOW + 60], self::NOW));
		$this->assertTrue(Maintenance::active(['maintenance_mode' => '1', 'maintenance_until' => '0'], self::NOW), 'no end');
		$this->assertTrue(Maintenance::active(['maintenance_mode' => 1, 'maintenance_until' => self::NOW + 1], self::NOW));
		$this->assertFalse(Maintenance::active(['maintenance_mode' => 1, 'maintenance_until' => self::NOW], self::NOW), 'ended');
	}

	public function testTheMessageAndWhenToRetry(): void {
		$this->assertSame('Back at 10:00', Maintenance::message(['maintenance_message' => ' Back at 10:00 ']));
		$this->assertStringContainsString('maintenance', Maintenance::message(['maintenance_message' => '']));
		$this->assertNull(Maintenance::retryAfter([], self::NOW));
		$this->assertSame(600, Maintenance::retryAfter(['maintenance_until' => self::NOW + 600], self::NOW));
		$this->assertSame(60, Maintenance::retryAfter(['maintenance_until' => self::NOW + 5], self::NOW), 'at least a minute');
	}

	public function testEveryClientApiAndResellerPathAsksIt(): void {
		foreach ([
			'Public/Controllers/Api/PlayerApiController.php',
			'Public/Controllers/Api/PlaylistApiController.php',
			'Public/Controllers/Api/EpgApiController.php',
			'Public/Controllers/Api/Enigma2ApiController.php',
			'Ministra/portal.php',
			'Infrastructure/Bootstrap/ResellerScopeBootstrap.php',
			'Public/Controllers/Reseller/ResellerLoginController.php',
			'Public/Controllers/Api/ResellerRestApiController.php',
			'Public/Controllers/Api/ActiveCodeApiController.php',
		] as $rFile) {
			$this->assertStringContainsString('Maintenance::active(', (string) file_get_contents(MAIN_HOME . $rFile), $rFile);
		}
		// The load balancers serve the client APIs: their settings replica carries the switch.
		$rKeys = require MAIN_HOME . 'Core/Cluster/lb_settings_keys.php';
		foreach (['maintenance_mode', 'maintenance_until', 'maintenance_message'] as $rKey) {
			$this->assertContains($rKey, (array) ($rKeys['keys'] ?? $rKeys), $rKey);
		}
	}
}
