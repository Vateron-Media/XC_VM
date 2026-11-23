<?php

use PHPUnit\Framework\TestCase;
use XcVm\Public\Controllers\Api\XPluginApiController;

final class XPluginDeviceIdentityTest extends TestCase {
	private function identityMatches(array $rDevice, string $rModemMac): bool {
		$rMethod = new ReflectionMethod(XPluginApiController::class, 'identityMatches');
		$rMethod->setAccessible(true);
		return $rMethod->invoke(null, $rDevice, $rModemMac);
	}

	public function testLockedDeviceNeedsMoreThanItsPublicMac(): void {
		$this->assertFalse($this->identityMatches(['lock_device' => 1, 'modem_mac' => ''], ''));
		$this->assertFalse($this->identityMatches(['lock_device' => 1, 'modem_mac' => ''], 'made-up-id'));
		$this->assertTrue($this->identityMatches(['lock_device' => 1, 'modem_mac' => ''], '00:11:22:33:44:55'));
		$this->assertTrue($this->identityMatches(['lock_device' => 1, 'modem_mac' => '00:11:22:33:44:55'], '00:11:22:33:44:55'));
		$this->assertFalse($this->identityMatches(['lock_device' => 1, 'modem_mac' => '00:11:22:33:44:55'], 'AA:BB:CC:DD:EE:FF'));
		$this->assertFalse($this->identityMatches(['lock_device' => 0, 'modem_mac' => ''], ''));
		$this->assertTrue($this->identityMatches(['lock_device' => 0, 'modem_mac' => ''], '00:11:22:33:44:55'));
	}

	public function testNewDevicesCannotOverrideTheSafeLockDefaultByOmission(): void {
		foreach (['MagService.php', 'EnigmaService.php'] as $rFile) {
			$rSource = (string) file_get_contents(MAIN_HOME . 'Domain/Device/' . $rFile);
			$this->assertStringContainsString("isset(\$rData['lock_device']) || !isset(\$rData['edit'])", $rSource, $rFile);
		}
	}
}
