<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * The administrator's form of an Enigma2 device that exists saves the device
 * as it saves a new one: the line and the device row. The device row is
 * written with the columns its table has, so the save is stored and answered
 * as stored, whether or not the device's line is paired with another.
 */
final class AuditResellerCoreEnigmaEditTest extends TestCase {
	private const ADMIN = 1;

	/** A device whose line stands alone, and one whose line is paired with line 30. */
	private const ALONE = 8;
	private const PAIRED = 9;

	private const SERVICES = [BouquetService::class, LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class];

	private TestDb $rDb;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['lines', 'lines_live', 'mag_devices', 'enigma2_devices', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$rLater = time() + 30 * 86400;
		$this->rDb->exec("INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `exp_date`, `enabled`, `admin_enabled`, `bouquet`, `allowed_outputs`, `is_mag`, `is_e2`, `pair_id`, `created_at`) VALUES"
			. " (18, 1, 'alone', 'secret', $rLater, 1, 1, '[]', '[1,2]', 0, 1, NULL, 1700000000), (19, 1, 'paired', 'secret', $rLater, 1, 1, '[]', '[1,2]', 0, 1, 30, 1700000000),"
			. " (30, 1, 'viewer', 'secret', $rLater, 1, 1, '[]', '[1,2]', 0, 0, NULL, 1700000000)");
		$this->rDb->exec("INSERT INTO `enigma2_devices` (`device_id`, `user_id`, `mac`, `modem_mac`, `local_ip`) VALUES (8, 18, '00:1A:79:00:00:08', '00:1A:79:AA:AA:08', '192.0.2.8'), (9, 19, '00:1A:79:00:00:09', '00:1A:79:AA:AA:09', '192.0.2.9')");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'settings' => SettingsManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1, 'mag_default_type' => 0]);

		// A statement MySQL refuses is answered with false, as the panel's
		// database answers it: one that names a column the table does not have.
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^REPLACE INTO `enigma2_devices`\(.*`(user|paired)`[,)]/';

		$GLOBALS['db'] = $rLog;
		$GLOBALS['rUserInfo'] = ['id' => self::ADMIN, 'member_group_id' => 1];
		$GLOBALS['rAdminUserInfo'] = $GLOBALS['rUserInfo'];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		DatabaseFactory::set($rLog);
		foreach (self::SERVICES as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $rLog);
		}
	}

	protected function tearDown(): void {
		foreach (self::SERVICES as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		DatabaseFactory::reset();
		SettingsManager::set($this->rBefore['settings']);
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions']);
	}

	/** @return array<string, mixed> the device as stored */
	private function device(int $rDevice): array {
		$this->rDb->query('SELECT `user_id`, `mac`, `modem_mac`, `local_ip`, `lock_device` FROM `enigma2_devices` WHERE `device_id` = ?', $rDevice);
		return $this->rDb->get_row();
	}

	/** @return array<string, array{0: int, 1: int, 2: array<string, mixed>}> the device, its line, and what its form posts for the pairing */
	public static function devices(): array {
		return ['a device whose line stands alone' => [self::ALONE, 18, []], 'a device whose line is paired' => [self::PAIRED, 19, ['pair_id' => '30']]];
	}

	#[DataProvider('devices')]
	public function testTheFormOfADeviceThatExistsIsSaved(int $rDevice, int $rLine, array $rPairing): void {
		$rResult = EnigmaService::process($rPairing + [
			'edit' => (string) $rDevice, 'mac' => '00:1A:79:00:00:AA', 'username' => 'renamed', 'password' => 'secret', 'admin_notes' => 'called', 'lock_device' => 'on',
			'bouquets_selected' => '[]', 'isp_clear' => '', 'exp_date' => date('Y-m-d', time() + 30 * 86400),
		]);

		$this->assertSame(STATUS_SUCCESS, $rResult['status'] ?? null);
		$this->assertSame($rDevice, (int) $rResult['data']['insert_id']);
		$this->assertEquals(
			['user_id' => $rLine, 'mac' => '00:1A:79:00:00:AA', 'modem_mac' => '00:1A:79:AA:AA:0' . $rDevice, 'local_ip' => '192.0.2.' . $rDevice, 'lock_device' => 1],
			$this->device($rDevice),
			'the device has what the form changed and keeps the rest'
		);
		$this->rDb->query('SELECT `username`, `admin_notes` FROM `lines` WHERE `id` = ?', $rLine);
		$this->assertSame(['username' => 'renamed', 'admin_notes' => 'called'], $this->rDb->get_row());
		$this->rDb->query('SELECT COUNT(*) FROM `enigma2_devices`');
		$this->assertSame(2, (int) $this->rDb->get_col());
	}

	public function testANewDeviceIsStillSaved(): void {
		$rResult = EnigmaService::process([
			'mac' => '00:1A:79:00:00:BB', 'username' => 'another', 'password' => 'secret',
			'bouquets_selected' => '[]', 'isp_clear' => '', 'exp_date' => date('Y-m-d', time() + 30 * 86400),
		]);

		$this->assertSame(STATUS_SUCCESS, $rResult['status'] ?? null);
		$this->rDb->query('SELECT COUNT(*) FROM `enigma2_devices`');
		$this->assertSame(3, (int) $this->rDb->get_col());
	}
}
