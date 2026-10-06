<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ResellerAPIWrapper;
use XcVm\Public\Controllers\Reseller\ResellerActiveCodeController;
use XcVm\Tests\Support\InstallSchema;

/**
 * A reseller buys from a package what its switches sell. *Standard Package*
 * sells what is paid for: the term of a line, of a MAG or Enigma2 device, and
 * official activation codes. *Trial Package* gives trials. A package with
 * neither switch on sells a reseller nothing: not on a form, not through the
 * reseller API, not as activation codes, and it is not listed to the reseller.
 * An administrator issues codes from any package.
 */
final class AuditDecisionPackagesOfficialTest extends TestCase {
	private const RESELLER = 5;

	private const TRIAL_ONLY = 1;
	private const STANDARD = 2;
	private const UNMARKED = 3;
	private const BOTH = 4;

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
		$this->rDb->exec(InstallSchema::migration('021_add_category_templates'));
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		// One trial in a day.
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `total_allowed_gen_trials`, `total_allowed_gen_in`, `minimum_trial_credits`) VALUES (2, 'Resellers', 1, 1, 'day', 0)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_trial`, `is_official`, `trial_credits`, `official_credits`, `trial_duration`, `trial_duration_in`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES"
			. " (1, 'Trial', 1, 0, 0, 0, 1, 'days', 0, 'hours', '[2]', '[]', '[1]', 1, 1, 1, 1),"
			. " (2, 'Month', 0, 1, 0, 10, 0, 'hours', 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 1),"
			. " (3, 'Unmarked', 0, 0, 0, 10, 1, 'days', 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 1),"
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

	/**
	 * The reseller asks for a code of $rPackage.
	 *
	 * @param array<string, mixed> $rAsked what else the request names
	 * @return array<string, mixed> the answer
	 */
	private function code(int $rPackage, array $rAsked = []): array {
		return ActiveCodeService::generateCodes($rAsked + ['package_id' => $rPackage, 'num_codes' => 1], UserRepository::getRegisteredUserById(self::RESELLER), false);
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

	/**
	 * Every kind of purchase from every kind of package, asked for as a
	 * subscription and as a trial, and whether the package sells it.
	 *
	 * @return array<string, array{0: string, 1: int, 2: bool, 3: bool}>
	 */
	public static function purchases(): array {
		$rSells = [
			'that gives trials only' => [self::TRIAL_ONLY, false, true],
			'that sells subscriptions only' => [self::STANDARD, true, false],
			'with neither switch on' => [self::UNMARKED, false, false],
			'with both switches on' => [self::BOTH, true, true],
		];
		$rCases = [];
		foreach (self::kinds() as $rName => [$rKind]) {
			foreach ($rSells as $rMark => [$rPackage, $rSubscription, $rTrial]) {
				$rCases[$rName . ' from a package ' . $rMark] = [$rKind, $rPackage, false, $rSubscription];
				$rCases[$rName . ' as a trial from a package ' . $rMark] = [$rKind, $rPackage, true, $rTrial];
			}
		}
		return $rCases;
	}

	// ── lines and devices ───────────────────────────────────────────

	#[DataProvider('purchases')]
	public function testAPackageSellsWhatItsSwitchesSay(string $rKind, int $rPackage, bool $rAsTrial, bool $rSold): void {
		$rStatus = $this->save($rKind, ['package' => $rPackage] + ($rAsTrial ? ['trial' => 1] : []));

		if (!$rSold) {
			$this->assertSame(STATUS_INVALID_PACKAGE, $rStatus);
			$this->assertSame([], $this->lines(), 'nothing is made');
			$this->assertSame(100, $this->credits(), 'and nothing is charged');
			return;
		}
		$this->assertSame(STATUS_SUCCESS, $rStatus);
		$rLines = $this->lines();
		$this->assertCount(1, $rLines);
		$this->assertEquals([(int) $rAsTrial, $rPackage], [$rLines[0]['is_trial'], $rLines[0]['package_id']]);
		$this->assertSame($rAsTrial ? 100 : 90, $this->credits());
	}

	/** An edit that names a package buys its term: one that sells none leaves the trial as it is. */
	#[DataProvider('kinds')]
	public function testATrialIsNotMadeASubscriptionByAPackageWithNeitherSwitchOn(string $rKind): void {
		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['trial' => 1, 'package' => self::TRIAL_ONLY]));
		$rTrial = $this->lines();

		$this->assertSame(STATUS_INVALID_PACKAGE, $this->save($rKind, ['edit' => '1', 'package' => self::UNMARKED]));

		$this->assertEquals($rTrial, $this->lines(), 'still the trial it was made as');
		$this->assertFalse(LineService::canGenerateTrials(self::RESELLER), 'and still the one trial of the day');
		$this->assertSame(100, $this->credits());
	}

	// ── activation codes ────────────────────────────────────────────

	public function testNoCodesAreSoldFromAPackageWithNeitherSwitchOn(): void {
		foreach ([[], ['is_trial' => 1]] as $rAsked) {
			$this->assertSame('ERROR', $this->code(self::UNMARKED, $rAsked)['status']);
		}

		$this->assertSame([], $this->lines());
		$this->assertSame(100, $this->credits());
	}

	/** Trial codes are asked for with `is_trial`, the field an administrator's request names them with. */
	public function testNoTrialCodesAreSoldFromAPackageThatGivesNoTrials(): void {
		$this->assertSame('ERROR', $this->code(self::STANDARD, ['is_trial' => 1])['status']);

		$this->assertSame([], $this->lines(), 'and no official codes in their place');
		$this->assertSame(100, $this->credits());
	}

	public function testOfficialCodesAreSoldFromAPackageThatSellsSubscriptions(): void {
		$rResult = $this->code(self::STANDARD);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertEquals([0, self::STANDARD], [$this->lines()[0]['is_trial'], $this->lines()[0]['package_id']]);
		$this->assertSame(90, $this->credits());
	}

	/** `is_trial` sent switched off asks for no trial codes: the package sells its official codes. */
	public function testOfficialCodesAreSoldWhenTheTrialFieldIsSentSwitchedOff(): void {
		foreach (['0', 'false', false] as $rSent) {
			$rResult = $this->code(self::STANDARD, ['is_trial' => $rSent]);
			$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		}

		$this->assertSame([0, 0, 0], array_map('intval', array_column($this->lines(), 'is_trial')));
		$this->assertSame(70, $this->credits());
	}

	public function testAnAdministratorIssuesCodesFromAnyPackage(): void {
		foreach ([self::TRIAL_ONLY, self::STANDARD, self::UNMARKED, self::BOTH] as $rPackage) {
			$rResult = ActiveCodeService::generateCodes(['package_id' => $rPackage, 'num_codes' => 1, 'created_by' => self::RESELLER], ['id' => 1, 'member_group_id' => 1], true);
			$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		}

		$this->assertSame(100, $this->credits());
	}

	// ── what the reseller is shown ──────────────────────────────────

	public function testTheResellerApiListsThePackagesThatSell(): void {
		$rListed = ResellerAPIWrapper::getPackages();

		$this->assertSame('STATUS_SUCCESS', $rListed['status']);
		$this->assertSame([self::TRIAL_ONLY, self::STANDARD, self::BOTH], array_map('intval', array_column($rListed['data'], 'id')));
	}

	public function testTheCodeFormIsHandedThePackagesThatSell(): void {
		$rPage = new class extends ResellerActiveCodeController {
			/** @var array<string, mixed> */
			public array $rData = [];

			protected function requirePermission() {
			}

			protected function render(string $view, array $data = []) {
				$this->rData = $data;
			}
		};

		$rPage->index();

		$this->assertSame([self::TRIAL_ONLY, self::STANDARD, self::BOTH], array_map('intval', array_column($rPage->rData['rPackages'], 'id')));
	}
}
