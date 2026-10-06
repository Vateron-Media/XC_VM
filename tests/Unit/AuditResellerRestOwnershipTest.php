<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ResellerAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * The reseller REST API changes the lines and devices the reseller panel
 * changes for the same reseller: its own and those of the sub-resellers in
 * its tree (Authorization::check('line')). A line or device of anyone else is
 * neither deleted, switched off or on, nor converted, and none of its data is
 * returned.
 *
 * It asks for the group permission the panel's same action asks for
 * (ResellerApiDispatcher::handleLine, handleMag, handleEnigma): create_line
 * for a line, create_mag and create_enigma for a device.
 */
final class AuditResellerRestOwnershipTest extends TestCase {
	private const RESELLER = 5;
	private const SUB = 6;
	private const OTHER = 7;

	private TestDb $rDb;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);

		$this->rDb = new TestDb();
		foreach (['users', 'lines', 'lines_live', 'lines_logs', 'lines_activity', 'mag_devices', 'mag_claims', 'mag_events', 'mag_logs', 'enigma2_devices', 'enigma2_actions', 'activation_codes', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`) VALUES (1, 'admin', 1, 0, 0), (5, 'reseller', 2, 100, 0), (6, 'sub', 2, 10, 5), (7, 'other', 2, 10, 0)");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'rSettings' => $GLOBALS['rSettings'] ?? null, 'settings' => SettingsManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1, 'server_type' => 0]];
		$GLOBALS['rSettings'] = ['redis_handler' => 0];
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		ResellerAPIWrapper::$db = $this->rDb;
		foreach ([LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
		// What the API key's session leaves the request (ResellerAPIWrapper::createSession).
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById(self::RESELLER);
		$GLOBALS['rPermissions'] = ['create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'all_reports' => [self::SUB]];
	}

	protected function tearDown(): void {
		foreach ([LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		DatabaseFactory::reset();
		ResellerAPIWrapper::$db = null;
		SettingsManager::set($this->rBefore['settings']);
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		$GLOBALS['rSettings'] = $this->rBefore['rSettings'];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** A line of $rOwner, switched on or off: its id. */
	private function line(int $rOwner, int $rEnabled, string $rDevice = ''): int {
		$this->rDb->query('INSERT INTO `lines` (`member_id`, `username`, `password`, `exp_date`, `enabled`, `is_mag`, `is_e2`, `bouquet`, `allowed_outputs`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)', $rOwner, 'line_' . bin2hex(random_bytes(4)), 'secret', time() + 30 * 86400, $rEnabled, (int) ($rDevice === 'mag_devices'), (int) ($rDevice === 'enigma2_devices'), '[]', '[1]');
		return (int) $this->rDb->last_insert_id();
	}

	/**
	 * A device on a line of $rOwner.
	 *
	 * @return array{0: int, 1: int} the device's id and its line's id
	 */
	private function device(string $rTable, int $rOwner, int $rEnabled): array {
		$rLine = $this->line($rOwner, $rEnabled, $rTable);
		$this->rDb->query('INSERT INTO `' . $rTable . '` (`user_id`, `mac`) VALUES (?, ?)', $rLine, '00:1A:79:00:00:' . sprintf('%02X', $rLine));
		return [(int) $this->rDb->last_insert_id(), $rLine];
	}

	/** @return array<string, mixed> the line as stored, [] when it is gone */
	private function stored(int $rLine): array {
		return UserRepository::getLineById($rLine) ?? [];
	}

	private function devices(string $rTable): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	/** @return array<string, array{0: string, 1: int}> the action, and whether the line is on before it */
	public static function lineActions(): array {
		return ['delete_line' => ['deleteLine', 1], 'disable_line' => ['disableLine', 1], 'enable_line' => ['enableLine', 0]];
	}

	/** @return array<string, array{0: string, 1: string, 2: int}> the action, the device's table, and whether its line is on before it */
	public static function deviceActions(): array {
		return [
			'delete_mag' => ['deleteMAG', 'mag_devices', 1], 'disable_mag' => ['disableMAG', 'mag_devices', 1], 'enable_mag' => ['enableMAG', 'mag_devices', 0], 'convert_mag' => ['convertMAG', 'mag_devices', 1],
			'delete_enigma' => ['deleteEnigma', 'enigma2_devices', 1], 'disable_enigma' => ['disableEnigma', 'enigma2_devices', 1], 'enable_enigma' => ['enableEnigma', 'enigma2_devices', 0], 'convert_enigma' => ['convertEnigma', 'enigma2_devices', 1],
		];
	}

	// ── a line or device outside the reseller's tree ────────────────

	#[DataProvider('lineActions')]
	public function testALineOfAnotherResellerOrOfTheAdministratorIsLeftAsItIs(string $rAction, int $rEnabled): void {
		foreach ([self::OTHER, 1] as $rOwner) {
			$rLine = $this->line($rOwner, $rEnabled);
			$rBefore = $this->stored($rLine);

			$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::$rAction((string) $rLine));

			$this->assertSame($rBefore, $this->stored($rLine));
		}
	}

	#[DataProvider('deviceActions')]
	public function testADeviceOfAnotherResellerOrOfTheAdministratorIsLeftAsItIs(string $rAction, string $rTable, int $rEnabled): void {
		foreach ([self::OTHER, 1] as $rOwner) {
			[$rDevice, $rLine] = $this->device($rTable, $rOwner, $rEnabled);
			$rBefore = $this->stored($rLine);
			$rDevices = $this->devices($rTable);

			$this->assertSame(['status' => 'STATUS_FAILURE'], ResellerAPIWrapper::$rAction((string) $rDevice), 'refused, and nothing of the line is returned');

			$this->assertSame($rDevices, $this->devices($rTable), 'the device is there');
			$this->assertSame($rBefore, $this->stored($rLine));
		}
	}

	// ── the group permissions ───────────────────────────────────────

	#[DataProvider('lineActions')]
	public function testAGroupThatMakesNoLinesChangesNone(string $rAction, int $rEnabled): void {
		$GLOBALS['rPermissions']['create_line'] = false;
		$rLine = $this->line(self::RESELLER, $rEnabled);
		$rBefore = $this->stored($rLine);

		$this->assertSame(['status' => 'STATUS_NO_PERMISSIONS'], ResellerAPIWrapper::$rAction((string) $rLine));

		$this->assertSame($rBefore, $this->stored($rLine));
	}

	/** The permission is the device kind's own: the group still makes lines, and devices of the other kind. */
	#[DataProvider('deviceActions')]
	public function testAGroupThatMakesNoDevicesOfAKindChangesNone(string $rAction, string $rTable, int $rEnabled): void {
		$GLOBALS['rPermissions'][$rTable === 'mag_devices' ? 'create_mag' : 'create_enigma'] = false;
		[$rDevice, $rLine] = $this->device($rTable, self::RESELLER, $rEnabled);
		$rBefore = $this->stored($rLine);

		$this->assertSame(['status' => 'STATUS_NO_PERMISSIONS'], ResellerAPIWrapper::$rAction((string) $rDevice), 'refused, and nothing of the line is returned');

		$this->assertSame(1, $this->devices($rTable), 'the device is there');
		$this->assertSame($rBefore, $this->stored($rLine));
	}

	// ── the reseller's own, and its sub-resellers' ──────────────────

	#[DataProvider('lineActions')]
	public function testALineInTheResellersTreeIsChanged(string $rAction, int $rEnabled): void {
		foreach ([self::RESELLER, self::SUB] as $rOwner) {
			$rLine = $this->line($rOwner, $rEnabled);

			$this->assertSame(['status' => 'STATUS_SUCCESS'], ResellerAPIWrapper::$rAction((string) $rLine));

			$this->assertSame($rAction === 'deleteLine' ? null : 1 - $rEnabled, $this->stored($rLine)['enabled'] ?? null);
		}
	}

	#[DataProvider('deviceActions')]
	public function testADeviceInTheResellersTreeIsChanged(string $rAction, string $rTable, int $rEnabled): void {
		foreach ([self::RESELLER, self::SUB] as $rOwner) {
			[$rDevice, $rLine] = $this->device($rTable, $rOwner, $rEnabled);
			$rDevices = $this->devices($rTable);

			$rAnswer = ResellerAPIWrapper::$rAction((string) $rDevice);

			$this->assertSame('STATUS_SUCCESS', $rAnswer['status']);
			if (str_starts_with($rAction, 'delete')) {
				$this->assertSame([], $this->stored($rLine), 'a deleted device takes its line with it');
			} elseif (str_starts_with($rAction, 'convert')) {
				$this->assertSame($rDevices - 1, $this->devices($rTable));
				$this->assertSame($rLine, (int) $rAnswer['data']['id'], 'the line the device became');
				$this->assertSame(0, (int) $this->stored($rLine)['is_mag'] + (int) $this->stored($rLine)['is_e2']);
			} else {
				$this->assertSame(1 - $rEnabled, (int) $this->stored($rLine)['enabled']);
			}
		}
	}
}
