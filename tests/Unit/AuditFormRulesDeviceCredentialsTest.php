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
 * A MAG or Enigma2 device has a line of its own, and the administrator's
 * device form and API set that line's username and password. Each is one
 * segment of the line's playback addresses (/live/<username>/<password>/<id>),
 * so the device forms hold them to the rule of the line form: neither is set
 * to a value that holds the character that separates the segments. A device
 * whose line has such a value from before is saved again as it is, and keeps
 * the value until it is changed.
 */
final class AuditFormRulesDeviceCredentialsTest extends TestCase {
	private const ADMIN = 1;

	/** A device made before the rule: its line has the separator in both values. */
	private const BEFORE = 7;

	/** A device whose line's values hold no separator. */
	private const PLAIN = 8;

	/** What each kind of device is saved by, the table it is kept in and that table's key. */
	private const KINDS = [
		'a MAG device' => [MagService::class, 'mag_devices', 'mag_id'],
		'an Enigma2 device' => [EnigmaService::class, 'enigma2_devices', 'device_id'],
	];

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
		// Each kind has the same two devices, on lines of its own.
		$rLater = time() + 30 * 86400;
		$this->rDb->exec("INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `exp_date`, `enabled`, `admin_enabled`, `bouquet`, `allowed_outputs`, `is_mag`, `is_e2`, `created_at`) VALUES"
			. " (7, 1, 'old/name', 'old/pass', $rLater, 1, 1, '[]', '[1,2]', 1, 0, 1700000000), (8, 1, 'plainname', 'plainpass', $rLater, 1, 1, '[]', '[1,2]', 1, 0, 1700000000),"
			. " (17, 1, 'old/name', 'old/pass', $rLater, 1, 1, '[]', '[1,2]', 0, 1, 1700000000), (18, 1, 'plainname', 'plainpass', $rLater, 1, 1, '[]', '[1,2]', 0, 1, 1700000000)");
		$this->rDb->exec("INSERT INTO `mag_devices` (`mag_id`, `user_id`, `mac`) VALUES (7, 7, '00:1A:79:00:00:07'), (8, 8, '00:1A:79:00:00:08')");
		$this->rDb->exec("INSERT INTO `enigma2_devices` (`device_id`, `user_id`, `mac`) VALUES (7, 17, '00:1A:79:00:00:07'), (8, 18, '00:1A:79:00:00:08')");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'settings' => SettingsManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1, 'mag_default_type' => 0]);

		// A statement MySQL refuses is answered with false, as the panel's
		// database answers it: the save of an Enigma2 device that exists names
		// columns its table does not have, after the line is stored.
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^REPLACE INTO `enigma2_devices`\(.*`user`[,)]/';

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

	/**
	 * The administrator's form of a device of $rKind with $rForm filled in,
	 * saved: the status the panel answers with.
	 *
	 * @param array<string, mixed> $rForm
	 */
	private function save(string $rKind, array $rForm): int {
		$rResult = self::KINDS[$rKind][0]::process($rForm + ['mac' => '00:1A:79:00:00:' . sprintf('%02d', $rForm['edit'] ?? 99), 'bouquets_selected' => '[]', 'isp_clear' => '', 'exp_date' => date('Y-m-d', time() + 30 * 86400)]);
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	/**
	 * The line of the device $rDevice of $rKind as stored.
	 *
	 * @return array<string, mixed> its username and password, then its note
	 */
	private function line(string $rKind, int $rDevice): array {
		[, $rTable, $rKey] = self::KINDS[$rKind];
		$this->rDb->query('SELECT `username`, `password`, `admin_notes` FROM `lines` WHERE `id` = (SELECT `user_id` FROM `' . $rTable . '` WHERE `' . $rKey . '` = ?)', $rDevice);
		return $this->rDb->get_raw_row() ?? [];
	}

	private function rows(string $rTable): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> the device, the value that holds the separator, the status that refuses it */
	public static function refusals(): array {
		$rOut = [];
		foreach (array_keys(self::KINDS) as $rKind) {
			$rOut[$rKind . ', the username'] = [$rKind, 'username', 'STATUS_INVALID_USERNAME'];
			$rOut[$rKind . ', the password'] = [$rKind, 'password', 'STATUS_INVALID_PASSWORD'];
		}
		return $rOut;
	}

	/** @return array<string, array{0: string}> */
	public static function kinds(): array {
		return ['a MAG device' => ['a MAG device'], 'an Enigma2 device' => ['an Enigma2 device']];
	}

	#[DataProvider('refusals')]
	public function testNoDeviceIsAddedWithTheSeparatorInItsUsernameOrPassword(string $rKind, string $rField, string $rStatus): void {
		$rAnswer = $this->save($rKind, [$rField => 'view/er1'] + ['username' => 'viewer1', 'password' => 'secret1']);

		$this->assertSame(4, $this->rows('lines'), 'the four lines there were');
		$this->assertSame(2, $this->rows(self::KINDS[$rKind][1]), 'the two devices there were');
		$this->assertSame(constant($rStatus), $rAnswer);
	}

	#[DataProvider('refusals')]
	public function testAnEditDoesNotGiveADeviceSuchAUsernameOrPassword(string $rKind, string $rField, string $rStatus): void {
		$rAnswer = $this->save($rKind, [$rField => 'plain/er'] + ['edit' => (string) self::PLAIN, 'username' => 'plainname', 'password' => 'plainpass']);

		$this->assertSame(['username' => 'plainname', 'password' => 'plainpass'], array_slice($this->line($rKind, self::PLAIN), 0, 2));
		$this->assertSame(constant($rStatus), $rAnswer);
	}

	/** The form posts the username and the password it showed: the ones the device's line has. */
	#[DataProvider('kinds')]
	public function testADeviceThatHasSuchValuesIsSavedAgainAsItIs(string $rKind): void {
		$rAnswer = $this->save($rKind, ['edit' => (string) self::BEFORE, 'username' => 'old/name', 'password' => 'old/pass', 'admin_notes' => 'called']);

		$this->assertNotContains($rAnswer, [STATUS_INVALID_USERNAME, STATUS_INVALID_PASSWORD]);
		$this->assertSame(['username' => 'old/name', 'password' => 'old/pass', 'admin_notes' => 'called'], $this->line($rKind, self::BEFORE), 'the rest of the save is stored');
	}

	#[DataProvider('refusals')]
	public function testSuchAValueIsNotChangedForAnotherOne(string $rKind, string $rField, string $rStatus): void {
		$rAnswer = $this->save($rKind, [$rField => 'new/value'] + ['edit' => (string) self::BEFORE, 'username' => 'old/name', 'password' => 'old/pass']);

		$this->assertSame(['username' => 'old/name', 'password' => 'old/pass'], array_slice($this->line($rKind, self::BEFORE), 0, 2));
		$this->assertSame(constant($rStatus), $rAnswer);
	}

	#[DataProvider('kinds')]
	public function testSuchAValueIsChangedForOneWithoutTheSeparator(string $rKind): void {
		$rAnswer = $this->save($rKind, ['edit' => (string) self::BEFORE, 'username' => 'newname', 'password' => 'newpass']);

		$this->assertNotContains($rAnswer, [STATUS_INVALID_USERNAME, STATUS_INVALID_PASSWORD]);
		$this->assertSame(['username' => 'newname', 'password' => 'newpass'], array_slice($this->line($rKind, self::BEFORE), 0, 2));
	}

	#[DataProvider('kinds')]
	public function testAnyOtherUsernameAndPasswordAreStored(string $rKind): void {
		$rAnswer = $this->save($rKind, ['username' => 'View.er-1_a', 'password' => 'Ab.c-d_9!']);

		$this->assertSame(STATUS_SUCCESS, $rAnswer);
		$this->assertSame(['username' => 'View.er-1_a', 'password' => 'Ab.c-d_9!'], array_slice($this->line($rKind, 9), 0, 2));
	}
}
