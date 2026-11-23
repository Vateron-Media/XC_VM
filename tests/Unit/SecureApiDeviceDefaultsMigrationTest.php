<?php

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

final class SecureApiDeviceDefaultsMigrationTest extends TestCase {
	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `settings` (`api_legacy_keys` tinyint(1) DEFAULT 1)');
		$this->rDb->exec('CREATE TABLE `mag_devices` (`lock_device` tinyint(4) DEFAULT 0)');
		$this->rDb->exec('CREATE TABLE `enigma2_devices` (`lock_device` tinyint(4) DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `settings` VALUES (1)');
		$this->rDb->exec('INSERT INTO `mag_devices` VALUES (0)');
		$this->rDb->exec('INSERT INTO `enigma2_devices` VALUES (0)');
	}

	/** @return array{string, string, string} */
	private function defaults(): array {
		$rDefaults = [];
		foreach ([['settings', 'api_legacy_keys'], ['mag_devices', 'lock_device'], ['enigma2_devices', 'lock_device']] as [$rTable, $rColumn]) {
			$this->rDb->query('SHOW COLUMNS FROM `' . $rTable . '` LIKE ?', $rColumn);
			$rDefaults[] = (string) $this->rDb->get_row()['Default'];
		}
		return $rDefaults;
	}

	/** @return array{int, int, int} */
	private function values(): array {
		$rValues = [];
		foreach ([['settings', 'api_legacy_keys'], ['mag_devices', 'lock_device'], ['enigma2_devices', 'lock_device']] as [$rTable, $rColumn]) {
			$this->rDb->query('SELECT `' . $rColumn . '` FROM `' . $rTable . '`');
			$rValues[] = (int) $this->rDb->get_col();
		}
		return $rValues;
	}

	public function testUpAndDownChangeDefaultsWithoutChangingExistingRows(): void {
		$this->rDb->exec(InstallSchema::migration('107_secure_api_device_defaults'));
		$this->assertSame(['0', '1', '1'], $this->defaults());
		$this->assertSame([1, 0, 0], $this->values());

		$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/down/107_secure_api_device_defaults.sql'));
		$this->assertSame(['1', '0', '0'], $this->defaults());
		$this->assertSame([1, 0, 0], $this->values());
	}
}
