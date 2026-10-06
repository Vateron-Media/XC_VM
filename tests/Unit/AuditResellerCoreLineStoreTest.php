<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * A reseller's save of a line it already sold is written over the line as the
 * request read it. When another request has moved the line's expiry since (an
 * extension that was paid for), the save stores nothing: the term bought stays
 * on the line. A device is its line and its device row: a save that cannot
 * store the device row leaves the line as it was, and costs nothing.
 */
final class AuditResellerCoreLineStoreTest extends TestCase {
	private const RESELLER = 5;
	private const PRICE = 10;

	private TestDb $rDb;

	/** @var array<class-string, array{0: mixed}> the database each of these services held for itself before the test */
	private array $rOwnDb = [];

	/** The panel settings global as the test found it. */
	private mixed $rSettings;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'mag_devices', 'enigma2_devices', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `total_allowed_gen_trials`, `minimum_trial_credits`) VALUES (2, 'Resellers', 1, 0, 0)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES (1, 'Month', 1, " . self::PRICE . ", 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 30, 0, '[]')");

		$this->use($this->rDb);
		$this->rSettings = $GLOBALS['rSettings'] ?? null;
		$GLOBALS['rSettings'] = ['disable_trial' => 0];
		ResellerAPI::$rSettings = ['mag_default_type' => 0];
		$this->startRequest();
	}

	protected function tearDown(): void {
		foreach ($this->rOwnDb as $rClass => [$rBefore]) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $rBefore);
		}
		DatabaseFactory::reset();
		ResellerAPI::$rUserInfo = [];
		ResellerAPI::$rPermissions = [];
		ResellerAPI::$rSettings = [];
		$GLOBALS['rSettings'] = $this->rSettings;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** The database the code under test reaches, however it asks for one. */
	private function use(XcVm\Core\Database\DatabaseHandler $rDb): void {
		$GLOBALS['db'] = $rDb;
		DatabaseFactory::set($rDb);
		foreach ([BouquetService::class, LineService::class] as $rClass) {
			$rProperty = new ReflectionProperty($rClass, 'db');
			$this->rOwnDb[$rClass] ??= [$rProperty->getValue()];
			$rProperty->setValue(null, $rDb);
		}
	}

	/** The reseller's request begins: it reads the account once, as every reseller request does. */
	private function startRequest(): void {
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = [
			'create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'all_reports' => [],
			'allow_change_username' => 1, 'allow_change_password' => 1, 'minimum_username_length' => 4, 'minimum_password_length' => 4,
			'allow_change_bouquets' => 0, 'allow_restrictions' => 0,
		];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
	}

	/**
	 * The reseller saves the one $rKind it has sold, or a first one, with
	 * $rForm filled in: the status the panel answers with.
	 *
	 * @param array<string, mixed> $rForm
	 */
	private function save(string $rKind, array $rForm): int {
		$rForm += ['reseller_notes' => ''];
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine($rForm + ['username' => 'viewer', 'password' => 'secret', 'contact' => '']),
			'mag' => ResellerAPI::processMAG($rForm + ['mac' => '00:1A:79:00:00:01', 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '']),
			'enigma' => ResellerAPI::processEnigma($rForm + ['mac' => '00:1A:79:00:00:01', 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '']),
		};
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	/** The reseller has sold one $rKind that runs out at $rExpires, and begins another request: the id its form names. */
	private function sold(string $rKind, int $rExpires): int {
		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['package' => 1]));
		$this->rDb->exec('UPDATE `lines` SET `exp_date` = ' . $rExpires);
		$this->rDb->exec('UPDATE `users` SET `credits` = 20 WHERE `id` = ' . self::RESELLER);
		$this->startRequest();

		[$rTable, $rKey] = ['line' => ['lines', 'id'], 'mag' => ['mag_devices', 'mag_id'], 'enigma' => ['enigma2_devices', 'device_id']][$rKind];
		$this->rDb->query('SELECT `' . $rKey . '` FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	/** @return array<string, mixed> the one line the panel holds, as stored */
	private function line(): array {
		$this->rDb->query('SELECT `exp_date`, `reseller_notes` FROM `lines`');
		$this->assertSame(1, $this->rDb->num_rows());
		return $this->rDb->get_row();
	}

	private function balance(): float {
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = ?', self::RESELLER);
		return (float) $this->rDb->get_col();
	}

	/** @return array<string, array{0: string}> what a reseller sells */
	public static function kinds(): array {
		return ['a line' => ['line'], 'a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma']];
	}

	/** @return array<string, array{0: string}> what is kept in a line and a device row */
	public static function devices(): array {
		return ['a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma']];
	}

	// ── a save beside an extension ──────────────────────────────────

	/**
	 * The save read the line; another request extends it, and pays, before
	 * the save writes. The term bought stays, and the save stores nothing.
	 */
	#[DataProvider('kinds')]
	public function testASaveDoesNotUndoAnExtensionAnotherRequestHasJustSold(string $rKind): void {
		$rExpires = time() + 86400;
		$rExtended = strtotime('+1 months', $rExpires);
		$rID = $this->sold($rKind, $rExpires);

		$rLog = new QueryLogDb($this->rDb);
		$rOther = TestDb::connect($this->rDb->schema());
		$rLog->rBefore = static function (string $rQuery) use ($rLog, $rOther, $rExtended): void {
			if (preg_match('/^(REPLACE INTO|UPDATE) `lines`/', $rQuery)) {
				$rLog->rBefore = null;
				$rOther->exec('UPDATE `lines` SET `exp_date` = ' . $rExtended);
				$rOther->exec('UPDATE `users` SET `credits` = `credits` - ' . self::PRICE . ' WHERE `id` = ' . self::RESELLER);
			}
		};
		$this->use($rLog);

		$rStatus = $this->save($rKind, ['edit' => $rID, 'reseller_notes' => 'called']);

		$this->assertSame(['exp_date' => (string) $rExtended, 'reseller_notes' => ''], array_map('strval', $this->line()));
		$this->assertSame(STATUS_FAILURE, $rStatus);
		$this->assertSame(10.0, $this->balance());
	}

	#[DataProvider('kinds')]
	public function testASaveIsStoredOnTheLineAsItWasRead(string $rKind): void {
		$rExpires = time() + 86400;
		$rID = $this->sold($rKind, $rExpires);

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['edit' => $rID, 'reseller_notes' => 'called']));

		$this->assertSame(['exp_date' => (string) $rExpires, 'reseller_notes' => 'called'], array_map('strval', $this->line()));
		$this->assertSame(20.0, $this->balance());
	}

	/** Nothing on the form differs from the line: there is nothing to write, and the save is answered as one that was. */
	#[DataProvider('kinds')]
	public function testASaveThatChangesNothingSucceeds(string $rKind): void {
		$rExpires = time() + 86400;
		$rID = $this->sold($rKind, $rExpires);

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['edit' => $rID]));
		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['edit' => $rID]));

		$this->assertSame(['exp_date' => (string) $rExpires, 'reseller_notes' => ''], array_map('strval', $this->line()));
	}

	public function testASaveOfALineWithoutAnExpiryIsStored(): void {
		$rID = $this->sold('line', 0);
		$this->rDb->exec('UPDATE `lines` SET `exp_date` = NULL');
		$this->startRequest();

		$this->assertSame(STATUS_SUCCESS, $this->save('line', ['edit' => $rID, 'reseller_notes' => 'called']));
		$this->assertSame(STATUS_SUCCESS, $this->save('line', ['edit' => $rID, 'reseller_notes' => 'called']));

		$this->assertSame(['exp_date' => null, 'reseller_notes' => 'called'], $this->line());
	}

	// ── a device row that cannot be stored ──────────────────────────

	/** The extension of a device whose row cannot be stored: the line keeps its expiry, the reseller the credits. */
	#[DataProvider('devices')]
	public function testAnExtensionWhoseDeviceRowCannotBeStoredAddsNoTerm(string $rKind): void {
		$rExpires = time() + 86400;
		$rID = $this->sold($rKind, $rExpires);
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^REPLACE INTO `(mag|enigma2)_devices`/';
		$this->use($rLog);

		$this->assertSame(STATUS_FAILURE, $this->save($rKind, ['edit' => $rID, 'package' => 1]));

		$this->assertSame((string) $rExpires, (string) $this->line()['exp_date']);
		$this->assertSame(20.0, $this->balance());
	}

	#[DataProvider('devices')]
	public function testASaveWhoseDeviceRowCannotBeStoredLeavesTheLineAsItWas(string $rKind): void {
		$rID = $this->sold($rKind, time() + 86400);
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^REPLACE INTO `(mag|enigma2)_devices`/';
		$this->use($rLog);

		$this->assertSame(STATUS_FAILURE, $this->save($rKind, ['edit' => $rID, 'reseller_notes' => 'called']));

		$this->assertSame('', $this->line()['reseller_notes']);
	}

	/** A new device whose row cannot be stored leaves no line behind, and costs nothing. */
	#[DataProvider('devices')]
	public function testANewDeviceWhoseRowCannotBeStoredLeavesNoLine(string $rKind): void {
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^REPLACE INTO `(mag|enigma2)_devices`/';
		$this->use($rLog);

		$this->assertSame(STATUS_FAILURE, $this->save($rKind, ['package' => 1]));

		$this->rDb->query('SELECT COUNT(*) FROM `lines`');
		$this->assertSame(0, (int) $this->rDb->get_col());
		$this->assertSame(30.0, $this->balance());
		$this->assertFalse($this->rDb->isInTransaction());
	}

	/** A device that is stored is stored with its line, and the transaction that held both is over. */
	#[DataProvider('devices')]
	public function testAnExtensionOfADeviceIsStoredWithItsLine(string $rKind): void {
		$rExpires = time() + 86400;
		$rID = $this->sold($rKind, $rExpires);

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['edit' => $rID, 'package' => 1]));

		$this->assertSame((string) strtotime('+1 months', $rExpires), (string) $this->line()['exp_date']);
		$this->assertSame(10.0, $this->balance());
		$this->assertFalse($this->rDb->isInTransaction());
		$this->rDb->query('SELECT `log_id` FROM `users_logs` ORDER BY `id` DESC LIMIT 1');
		$this->assertSame($rID, (int) $this->rDb->get_col(), 'the log names the device');
	}
}
