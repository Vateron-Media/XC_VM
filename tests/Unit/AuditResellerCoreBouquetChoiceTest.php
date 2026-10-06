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

/**
 * A reseller who may choose bouquets sends its choice as a list of bouquet
 * ids. A request that sends no such list has chosen nothing, and so has a
 * list that names no bouquet: the line is saved with the bouquets of its
 * package, as it is when the reseller may not choose. The line form always
 * sends the field, empty when no bouquet is ticked.
 */
final class AuditResellerCoreBouquetChoiceTest extends TestCase {
	/** The package's bouquets, in the bouquets' own order. */
	private const PACKAGE = [3, 2];

	private TestDb $rDb;

	/** @var array<class-string, array{0: mixed}> the database each of these services held for itself before the test */
	private array $rOwnDb = [];

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
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES (1, 'Month', 1, 1, 1, 'months', '[2]', '[2,3]', '[1]', 1, 1, 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");
		// The bouquets' order: 3, 1, 2. The package does not hold bouquet 1.
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_order`) VALUES (1, 'Extra', 2), (2, 'Basic', 3), (3, 'Sports', 1)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([BouquetService::class, LineService::class] as $rClass) {
			$rProperty = new ReflectionProperty($rClass, 'db');
			$this->rOwnDb[$rClass] = [$rProperty->getValue()];
			$rProperty->setValue(null, $this->rDb);
		}

		$this->rSettings = $GLOBALS['rSettings'] ?? null;
		$GLOBALS['rSettings'] = ['disable_trial' => 0];
		ResellerAPI::$rSettings = ['mag_default_type' => 0];
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(5);
		ResellerAPI::$rPermissions = [
			'create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'all_reports' => [],
			'allow_change_username' => 1, 'allow_change_password' => 1, 'minimum_username_length' => 4, 'minimum_password_length' => 4,
			'allow_change_bouquets' => 1, 'allow_restrictions' => 0,
		];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
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

	/**
	 * The reseller saves a $rKind with $rChoice as the bouquets chosen, and
	 * $rForm beside it.
	 *
	 * @param array<string, mixed> $rForm
	 * @return list<int> the bouquets the one line is stored with
	 */
	private function save(string $rKind, mixed $rChoice, array $rForm = ['package' => 1]): array {
		$rForm += ['bouquets_selected' => $rChoice, 'reseller_notes' => ''];
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine($rForm + ['username' => 'viewer', 'password' => 'secret', 'contact' => '']),
			'mag' => ResellerAPI::processMAG($rForm + ['mac' => '00:1A:79:00:00:01', 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '']),
			'enigma' => ResellerAPI::processEnigma($rForm + ['mac' => '00:1A:79:00:00:01', 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '']),
		};
		$this->assertSame(STATUS_SUCCESS, $rResult['status'] ?? null, $rKind);

		$this->rDb->query('SELECT `bouquet` FROM `lines`');
		return json_decode((string) $this->rDb->get_col(), true);
	}

	private function id(string $rTable, string $rColumn): int {
		$this->rDb->query('SELECT `' . $rColumn . '` FROM `' . $rTable . '`');
		return (int) $this->rDb->get_col();
	}

	/** @return array<string, array{0: string, 1: string, 2: string}> what is saved, the table it is kept in and that table's key */
	public static function kinds(): array {
		return ['a line' => ['line', 'lines', 'id'], 'a MAG device' => ['mag', 'mag_devices', 'mag_id'], 'an Enigma2 device' => ['enigma', 'enigma2_devices', 'device_id']];
	}

	#[DataProvider('kinds')]
	public function testAFormWithNoBouquetTickedSellsThePackagesBouquets(string $rKind): void {
		$this->assertSame(self::PACKAGE, $this->save($rKind, ''));
	}

	#[DataProvider('kinds')]
	public function testSavedAgainWithNoBouquetTickedItHasThePackagesBouquets(string $rKind, string $rTable, string $rKey): void {
		$this->assertSame([2], $this->save($rKind, ['2']));

		$this->assertSame(self::PACKAGE, $this->save($rKind, '', ['edit' => $this->id($rTable, $rKey)]));
	}

	/** @return array<string, array{0: mixed}> */
	public static function choicesThatNameNoBouquet(): array {
		return [
			'a value that is no list' => ['2'],
			'a list of a value that is no id' => [[true]],
			'a list of several of them' => [[true, null, 'all']],
			'a list of a list' => [[['2']]],
			'an empty list' => [[]],
		];
	}

	#[DataProvider('choicesThatNameNoBouquet')]
	public function testAChoiceThatNamesNoBouquetSellsThePackagesBouquets(mixed $rChoice): void {
		$this->assertSame(self::PACKAGE, $this->save('line', $rChoice));
	}

	public function testABouquetIdBesideSuchAValueIsTheChoice(): void {
		$this->assertSame([3], $this->save('line', [true, '3']));
	}
}
