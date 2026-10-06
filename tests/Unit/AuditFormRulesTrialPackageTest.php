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
 * The package form marks a package as a trial package or as a standard one.
 * A package that is marked trial and not standard gives trials only: a
 * reseller buys no subscription from it, new or for a line it has, as the
 * reseller forms offer none. A trial therefore stays a trial, counted by the
 * trial allowance, until a package that sells subscriptions is bought for
 * it. Every other package of the group sells as before: one marked standard,
 * one marked both, and one marked neither.
 */
final class AuditFormRulesTrialPackageTest extends TestCase {
	private const RESELLER = 5;

	/** Trial only, as the package form leaves the standard part: no price, no term. */
	private const TRIAL_ONLY = 1;
	private const STANDARD = 2;
	private const UNMARKED = 3;
	private const BOTH = 4;

	private const PRICE = 10;
	private const MAC = '00:1A:79:00:00:0A';

	private TestDb $rDb;

	/** @var array<class-string, mixed> the database each of these services held for itself before the test */
	private array $rOwnDb = [];

	/** The panel settings global as the test found it. */
	private mixed $rSettings;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'mag_devices', 'enigma2_devices', 'activation_codes', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		// One trial in a day.
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `total_allowed_gen_trials`, `total_allowed_gen_in`, `minimum_trial_credits`) VALUES (2, 'Resellers', 1, 1, 'day', 0)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_trial`, `is_official`, `trial_credits`, `official_credits`, `trial_duration`, `trial_duration_in`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES"
			. " (1, 'Trial', 1, 0, 0, 0, 1, 'days', 0, 'hours', '[2]', '[]', '[1]', 1, 1, 1, 1),"
			. " (2, 'Month', 0, 1, 0, 10, 0, 'hours', 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 1),"
			. " (3, 'Unmarked', 0, 0, 0, 10, 0, 'hours', 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 1),"
			. " (4, 'Both', 1, 1, 0, 10, 1, 'days', 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 1)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([BouquetService::class, LineService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rOwnDb[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
		$this->rSettings = $GLOBALS['rSettings'] ?? null;
		$GLOBALS['rSettings'] = ['disable_trial' => 0];
		ResellerAPI::$rSettings = ['mag_default_type' => 0];
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = [
			'create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'all_reports' => [],
			'allow_change_username' => 1, 'allow_change_password' => 1, 'minimum_username_length' => 4, 'minimum_password_length' => 4,
			'allow_change_bouquets' => 0, 'allow_restrictions' => 0,
		];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
	}

	protected function tearDown(): void {
		foreach ($this->rOwnDb as $rClass => $rBefore) {
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
	 * The reseller's form for $rKind with $rForm filled in, saved: the status
	 * the panel answers with. The first line or device there is has number 1.
	 *
	 * @param array<string, mixed> $rForm
	 */
	private function save(string $rKind, array $rForm): int {
		$rForm += ['reseller_notes' => '', 'mac' => self::MAC];
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine($rForm + ['username' => 'viewer', 'password' => 'secret', 'contact' => '']),
			'mag' => ResellerAPI::processMAG($rForm + ['parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '']),
			'enigma' => ResellerAPI::processEnigma($rForm + ['modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '']),
		};
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	/** @return list<array<string, mixed>> what every line holds of a trial, a package and a term */
	private function lines(): array {
		$this->rDb->query('SELECT `is_trial`, `package_id`, `exp_date` FROM `lines` ORDER BY `id`');
		return $this->rDb->get_rows();
	}

	private function credits(): int {
		return (int) UserRepository::getRegisteredUserById(self::RESELLER)['credits'];
	}

	/** @return array<string, array{0: string}> */
	public static function kinds(): array {
		return ['a line' => ['line'], 'a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma']];
	}

	/** @return array<string, array{0: string, 1: int}> */
	public static function kindsAndSellingPackages(): array {
		$rCases = [];
		foreach (self::kinds() as $rName => [$rKind]) {
			foreach (['marked standard' => self::STANDARD, 'marked neither' => self::UNMARKED, 'marked both' => self::BOTH] as $rMark => $rPackage) {
				$rCases[$rName . ', a package ' . $rMark] = [$rKind, $rPackage];
			}
		}
		return $rCases;
	}

	// ── a package that gives trials only ────────────────────────────

	#[DataProvider('kinds')]
	public function testATrialIsNotMadeASubscriptionByAPackageThatGivesTrialsOnly(string $rKind): void {
		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['trial' => 1, 'package' => self::TRIAL_ONLY]));
		$rTrial = $this->lines();
		$this->assertFalse(LineService::canGenerateTrials(self::RESELLER), 'the one trial of the day is made');

		$this->assertSame(STATUS_INVALID_PACKAGE, $this->save($rKind, ['edit' => '1', 'package' => self::TRIAL_ONLY]));

		$this->assertEquals($rTrial, $this->lines(), 'still the trial it was made as');
		$this->assertFalse(LineService::canGenerateTrials(self::RESELLER), 'and still the one trial of the day');
	}

	#[DataProvider('kinds')]
	public function testNoSubscriptionIsBoughtFromAPackageThatGivesTrialsOnly(string $rKind): void {
		$this->assertSame(STATUS_INVALID_PACKAGE, $this->save($rKind, ['package' => self::TRIAL_ONLY]));

		$this->assertSame([], $this->lines());
	}

	// ── every other package sells as before ─────────────────────────

	#[DataProvider('kindsAndSellingPackages')]
	public function testASubscriptionIsBoughtFromAnyOtherPackageOfTheGroup(string $rKind, int $rPackage): void {
		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['package' => $rPackage]));

		$rLines = $this->lines();
		$this->assertCount(1, $rLines);
		$this->assertEquals([0, $rPackage], [$rLines[0]['is_trial'], $rLines[0]['package_id']]);
		$this->assertSame(100 - self::PRICE, $this->credits());
	}

	#[DataProvider('kindsAndSellingPackages')]
	public function testATrialIsMadeASubscriptionByAnyOtherPackageOfTheGroup(string $rKind, int $rPackage): void {
		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['trial' => 1, 'package' => self::TRIAL_ONLY]));

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['edit' => '1', 'package' => $rPackage]));

		$rLines = $this->lines();
		$this->assertEquals([0, $rPackage], [$rLines[0]['is_trial'], $rLines[0]['package_id']]);
		$this->assertSame(100 - self::PRICE, $this->credits(), 'paid for');
		$this->assertTrue(LineService::canGenerateTrials(self::RESELLER), 'no trial is held any more');
	}
}
