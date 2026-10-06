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
use XcVm\Tests\Support\InstallSchema;

/**
 * A reseller group's trial allowance: so many trials in a day, or in a month.
 *
 * The period is the one the group names, whatever the number allowed. The
 * allowance counts the trials a reseller holds, the trial activation codes it
 * issues among them: a trial is held by the reseller that makes it, whoever
 * the request names as owner, and stays with its holder while it is a trial.
 * The trials of the resellers under it are theirs and use their allowance,
 * not its own. A trial is made from a package that offers trials. It is not
 * paired with another line, nor another line with it: a trial stays the trial
 * it was made as, and no other line takes its term.
 */
final class AuditLineUserTrialQuotaTest extends TestCase {
	private const RESELLER = 5;
	private const SUB = 6;
	private const TRIAL_PACKAGE = 1;
	private const OFFICIAL_PACKAGE = 2;

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
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `total_allowed_gen_trials`, `total_allowed_gen_in`, `minimum_trial_credits`, `create_sub_resellers`, `subresellers`) VALUES (2, 'Resellers', 1, 2, 'day', 0, 1, '[2]')");
		// The official package still holds the trial term it had while it offered trials.
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_trial`, `is_official`, `trial_credits`, `official_credits`, `trial_duration`, `trial_duration_in`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES"
			. " (1, 'Trial', 1, 0, 0, 0, 1, 'days', 0, NULL, '[2]', '[]', '[1]', 1, 1, 1, 0), (2, 'Month', 0, 1, 0, 10, 7, 'days', 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]'), (6, 'subreseller', 2, 0, 5, '[]')");

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
			'create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'all_reports' => [self::SUB],
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

	/** The group allows $rTrials trials in a $rPeriod. */
	private function allow(int $rTrials, string $rPeriod): void {
		$this->rDb->query('UPDATE `users_groups` SET `total_allowed_gen_trials` = ?, `total_allowed_gen_in` = ? WHERE `group_id` = 2', $rTrials, $rPeriod);
	}

	/** $rOwner holds a trial made $rHoursAgo hours ago. */
	private function trial(int $rOwner, float $rHoursAgo): void {
		$this->rDb->query("INSERT INTO `lines` (`member_id`, `username`, `password`, `is_trial`, `created_at`) VALUES (?, ?, 'secret', 1, ?)", $rOwner, 'trial' . $this->lines(), time() - (int) ($rHoursAgo * 3600));
	}

	private function lines(): int {
		$this->rDb->query('SELECT COUNT(*) FROM `lines`');
		return (int) $this->rDb->get_col();
	}

	/** The reseller asks for a trial line for $rOwner: the status the panel answers with. */
	private function trialLine(int $rOwner, int $rPackage = self::TRIAL_PACKAGE): int {
		return ResellerAPI::processLine(['trial' => 1, 'package' => $rPackage, 'member_id' => $rOwner, 'username' => 'viewer' . $this->lines(), 'password' => 'secret', 'contact' => '', 'reseller_notes' => ''])['status'];
	}

	/** @return array<string, mixed> the reseller's account as a request of it reads it */
	private function reseller(): array {
		return UserRepository::getRegisteredUserById(self::RESELLER);
	}

	// ── the period ──────────────────────────────────────────────────

	public function testADailyAllowanceCountsTheTrialsOfTheLastDay(): void {
		$this->allow(2, 'day');
		$this->trial(self::RESELLER, 3);
		$this->trial(self::RESELLER, 30);

		$this->assertTrue(LineService::canGenerateTrials(self::RESELLER), 'one of the two trials is older than a day');

		$this->trial(self::RESELLER, 5);
		$this->assertFalse(LineService::canGenerateTrials(self::RESELLER));
	}

	public function testAMonthlyAllowanceCountsTheTrialsOfTheLastMonth(): void {
		$this->allow(2, 'month');
		$this->trial(self::RESELLER, 24 * 40);
		$this->trial(self::RESELLER, 24 * 20);

		$this->assertTrue(LineService::canGenerateTrials(self::RESELLER), 'one of the two trials is older than a month');

		$this->trial(self::RESELLER, 24 * 3);
		$this->assertFalse(LineService::canGenerateTrials(self::RESELLER));
	}

	public function testThePeriodDoesNotGrowWithTheNumberAllowed(): void {
		$this->allow(10, 'day');
		for ($i = 0; $i < 10; $i++) {
			$this->trial(self::RESELLER, 48);
		}

		$this->assertTrue(LineService::canGenerateTrials(self::RESELLER), 'ten trials, each two days old');
	}

	public function testAGroupThatAllowsNoTrialsGetsNone(): void {
		$this->allow(0, 'day');

		$this->assertFalse(LineService::canGenerateTrials(self::RESELLER));
	}

	// ── whose trials count ──────────────────────────────────────────

	public function testTheTrialsOfASubResellerDoNotUseTheAllowanceOfTheResellerAboveIt(): void {
		$this->trial(self::SUB, 1);
		$this->trial(self::SUB, 2);

		$this->assertTrue(LineService::canGenerateTrials(self::RESELLER));
		$this->assertFalse(LineService::canGenerateTrials(self::SUB));
	}

	public function testTheTrialsOfAResellerDoNotCountForTheSubResellersUnderIt(): void {
		$this->trial(self::RESELLER, 1);
		$this->trial(self::RESELLER, 2);

		$this->assertTrue(LineService::canGenerateTrials(self::SUB));
	}

	public function testATrialAskedForASubResellerUsesTheAllowanceOfTheResellerThatMakesIt(): void {
		$this->assertSame(STATUS_SUCCESS, $this->trialLine(self::SUB));
		$this->assertSame(STATUS_SUCCESS, $this->trialLine(self::SUB));
		$this->assertSame([self::RESELLER, self::RESELLER], $this->owners());

		$this->assertSame(STATUS_NO_TRIALS, $this->trialLine(self::SUB));
		$this->assertSame(STATUS_NO_TRIALS, $this->trialLine(self::RESELLER));
		$this->assertSame(2, $this->lines());
		$this->assertTrue(LineService::canGenerateTrials(self::SUB), 'the sub-reseller has made none');
	}

	/** @return list<int> the owner of every line, oldest first */
	private function owners(): array {
		$this->rDb->query('SELECT `member_id` FROM `lines` ORDER BY `id`');
		return array_map('intval', $this->rDb->get_column());
	}

	/**
	 * The reseller saves $rKind number $rID again, naming $rOwner as its owner
	 * (and buying $rPackage, when one is named): the status the panel answers with.
	 */
	private function give(string $rKind, int $rID, int $rOwner, ?int $rPackage = null): int {
		$rForm = ['edit' => (string) $rID, 'member_id' => (string) $rOwner, 'reseller_notes' => ''] + ($rPackage ? ['package' => $rPackage] : []);
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine($rForm + ['username' => 'viewer', 'password' => 'secret', 'contact' => '']),
			'mag' => ResellerAPI::processMAG($rForm + ['mac' => '00:1A:79:00:00:01', 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '']),
			'enigma' => ResellerAPI::processEnigma($rForm + ['mac' => '00:1A:79:00:00:01', 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '']),
		};
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	#[DataProvider('kinds')]
	public function testATrialIsHeldByTheResellerThatMakesItAndStaysThere(string $rKind): void {
		$this->assertSame(STATUS_SUCCESS, $this->trialOf($rKind, self::TRIAL_PACKAGE, self::SUB));
		$this->assertSame([self::RESELLER], $this->owners(), 'whoever the request names');

		$this->assertSame(STATUS_SUCCESS, $this->give($rKind, 1, self::SUB));
		$this->assertSame([self::RESELLER], $this->owners(), 'a trial is not handed to another reseller');
		$this->assertSame(STATUS_SUCCESS, $this->trialOf($rKind === 'line' ? 'mag' : 'line', self::TRIAL_PACKAGE, self::SUB));
		$this->assertFalse(LineService::canGenerateTrials(self::RESELLER), 'both trials are the reseller\'s');
	}

	/** A trial a sub-reseller holds stays the sub-reseller's when the reseller above it saves it. */
	public function testATrialOfASubResellerStaysWithIt(): void {
		$this->trial(self::SUB, 1);

		$this->assertSame(STATUS_SUCCESS, $this->give('line', 1, self::RESELLER));

		$this->assertSame([self::SUB], $this->owners());
	}

	#[DataProvider('kinds')]
	public function testALineThatIsNotATrialIsGivenToTheResellerTheRequestNames(string $rKind): void {
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine(['package' => self::OFFICIAL_PACKAGE, 'member_id' => (string) self::SUB, 'username' => 'viewer', 'password' => 'secret', 'contact' => '', 'reseller_notes' => '']),
			'mag' => ResellerAPI::processMAG(['package' => self::OFFICIAL_PACKAGE, 'member_id' => (string) self::SUB, 'mac' => '00:1A:79:00:00:01', 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '', 'reseller_notes' => '']),
			'enigma' => ResellerAPI::processEnigma(['package' => self::OFFICIAL_PACKAGE, 'member_id' => (string) self::SUB, 'mac' => '00:1A:79:00:00:01', 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '', 'reseller_notes' => '']),
		};

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame([self::SUB], $this->owners());

		$this->assertSame(STATUS_SUCCESS, $this->give($rKind, 1, self::RESELLER));
		$this->assertSame([self::RESELLER], $this->owners());
	}

	/** Bought as a subscription, a trial is one no longer: it goes where the same save sends it. */
	public function testATrialThatIsBoughtIsGivenToTheResellerTheRequestNames(): void {
		$this->assertSame(STATUS_SUCCESS, $this->trialLine(self::RESELLER));

		$this->assertSame(STATUS_SUCCESS, $this->give('line', 1, self::SUB, self::OFFICIAL_PACKAGE));

		$this->assertSame([self::SUB], $this->owners());
		$this->rDb->query('SELECT `is_trial` FROM `lines`');
		$this->assertEquals(0, $this->rDb->get_row()['is_trial']);
	}

	// ── a trial and pairing ─────────────────────────────────────────

	private const MAC = '00:1A:79:00:00:0A';

	/**
	 * The reseller's form for $rKind with $rForm filled in, saved: the status
	 * the panel answers with. A form that edits a device names its address.
	 *
	 * @param array<string, mixed> $rForm
	 */
	private function save(string $rKind, array $rForm): int {
		$rForm += ['reseller_notes' => '', 'mac' => '00:1A:79:00:00:0' . (1 + $this->lines())];
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine($rForm + ['username' => 'viewer' . $this->lines(), 'password' => 'secret', 'contact' => '']),
			'mag' => ResellerAPI::processMAG($rForm + ['parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '']),
			'enigma' => ResellerAPI::processEnigma($rForm + ['modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '']),
		};
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	/** A subscription the reseller buys: the first line there is. */
	private function boughtLine(): int {
		$this->assertSame(STATUS_SUCCESS, $this->save('line', ['package' => self::OFFICIAL_PACKAGE]));
		return 1;
	}

	/** The reseller saves a line again as it is: the lines paired with it take what it holds. */
	private function saveAgain(int $rLineID): void {
		$this->assertSame(STATUS_SUCCESS, $this->save('line', ['edit' => (string) $rLineID, 'username' => '', 'password' => '']));
	}

	/** @return array<string, mixed> what line $rLineID holds of a pair and of a term */
	private function held(int $rLineID): array {
		$this->rDb->query('SELECT `is_trial`, `pair_id`, `exp_date` FROM `lines` WHERE `id` = ?', $rLineID);
		return $this->rDb->get_row();
	}

	/** @return array<string, array{0: string}> */
	public static function devices(): array {
		return ['a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma']];
	}

	#[DataProvider('kinds')]
	public function testATrialAskedForWithAPairIsATrialThatUsesTheAllowance(string $rKind): void {
		$rBought = $this->boughtLine();

		for ($rTrial = 2; $rTrial <= 3; $rTrial++) {
			$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['trial' => 1, 'package' => self::TRIAL_PACKAGE, 'pair_id' => (string) $rBought]));
			$this->saveAgain($rBought);

			$this->assertEquals([1, null], array_slice(array_values($this->held($rTrial)), 0, 2), 'a trial, paired with nothing');
		}

		$this->assertSame(STATUS_NO_TRIALS, $this->save($rKind, ['trial' => 1, 'package' => self::TRIAL_PACKAGE, 'pair_id' => (string) $rBought]));
		$this->assertSame(3, $this->lines());
	}

	#[DataProvider('devices')]
	public function testADeviceIsNotPairedWhileItIsATrial(string $rKind): void {
		$rBought = $this->boughtLine();
		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['trial' => 1, 'package' => self::TRIAL_PACKAGE, 'mac' => self::MAC]));

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['edit' => '1', 'pair_id' => (string) $rBought, 'mac' => self::MAC]));
		$this->saveAgain($rBought);

		$this->assertEquals([1, null], array_slice(array_values($this->held(2)), 0, 2));
		$this->trial(self::RESELLER, 1);
		$this->assertFalse(LineService::canGenerateTrials(self::RESELLER), 'the device is one of the two trials allowed');

		// Bought, it is a trial no longer and takes the pair the same save names.
		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['edit' => '1', 'package' => self::OFFICIAL_PACKAGE, 'pair_id' => (string) $rBought, 'mac' => self::MAC]));
		$this->assertEquals([0, $rBought], array_slice(array_values($this->held(2)), 0, 2));
	}

	/** A device whose subscription ended long ago takes no trial term from a trial made today. */
	#[DataProvider('devices')]
	public function testNothingIsPairedWithATrial(string $rKind): void {
		$rEnded = time() - 30 * 86400;
		$this->rDb->query("INSERT INTO `lines` (`member_id`, `username`, `password`, `is_mag`, `is_e2`, `exp_date`, `created_at`, `package_id`, `bouquet`, `allowed_outputs`) VALUES (?, 'device', 'secret', ?, ?, ?, ?, ?, '[]', '[]')", self::RESELLER, (int) ($rKind === 'mag'), (int) ($rKind === 'enigma'), $rEnded, $rEnded - 86400, self::OFFICIAL_PACKAGE);
		$this->rDb->query('INSERT INTO `' . ($rKind === 'mag' ? 'mag_devices' : 'enigma2_devices') . '` (`user_id`, `mac`) VALUES (1, ?)', self::MAC);
		$this->assertSame(STATUS_SUCCESS, $this->trialLine(self::RESELLER));

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['edit' => '1', 'pair_id' => '2', 'mac' => self::MAC]));
		$this->saveAgain(2);

		$this->assertEquals(['is_trial' => 0, 'pair_id' => null, 'exp_date' => $rEnded], $this->held(1));
	}

	#[DataProvider('kinds')]
	public function testASubscriptionThatIsBoughtIsPairedWithAnotherThatIsNoTrial(string $rKind): void {
		$rBought = $this->boughtLine();

		$this->assertSame(STATUS_SUCCESS, $this->save($rKind, ['package' => self::OFFICIAL_PACKAGE, 'pair_id' => (string) $rBought]));

		$this->assertEquals([0, $rBought], array_slice(array_values($this->held(2)), 0, 2));
	}

	// ── the package ─────────────────────────────────────────────────

	/** @return array<string, array{0: string}> */
	public static function kinds(): array {
		return ['a line' => ['line'], 'a MAG device' => ['mag'], 'an Enigma2 device' => ['enigma']];
	}

	/** One trial of $rKind on $rPackage, for $rOwner when one is named: the status the panel answers with. */
	private function trialOf(string $rKind, int $rPackage, ?int $rOwner = null): int {
		$rForm = ['trial' => 1, 'package' => $rPackage, 'reseller_notes' => ''] + ($rOwner ? ['member_id' => (string) $rOwner] : []);
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine($rForm + ['username' => 'viewer' . $this->lines(), 'password' => 'secret', 'contact' => '']),
			'mag' => ResellerAPI::processMAG($rForm + ['mac' => '00:1A:79:00:00:0' . (1 + $this->lines()), 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '']),
			'enigma' => ResellerAPI::processEnigma($rForm + ['mac' => '00:1A:79:00:00:0' . (1 + $this->lines()), 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '']),
		};
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	#[DataProvider('kinds')]
	public function testATrialIsMadeFromAPackageThatOffersTrials(string $rKind): void {
		$this->assertSame(STATUS_SUCCESS, $this->trialOf($rKind, self::TRIAL_PACKAGE));

		$this->rDb->query('SELECT `is_trial`, `package_id` FROM `lines`');
		$this->assertEquals([['is_trial' => 1, 'package_id' => self::TRIAL_PACKAGE]], $this->rDb->get_rows());
	}

	#[DataProvider('kinds')]
	public function testNoTrialIsMadeFromAPackageThatOffersNone(string $rKind): void {
		$this->assertSame(STATUS_INVALID_PACKAGE, $this->trialOf($rKind, self::OFFICIAL_PACKAGE));

		$this->assertSame(0, $this->lines());
	}

	// ── trial activation codes ──────────────────────────────────────

	public function testTrialCodesUseTheAllowance(): void {
		$rResult = ActiveCodeService::generateCodes(['package_id' => self::TRIAL_PACKAGE, 'num_codes' => 3], $this->reseller(), false);
		$this->assertSame('ERROR', $rResult['status'], 'three codes, two trials allowed');
		$this->assertSame(0, $this->lines());

		$rResult = ActiveCodeService::generateCodes(['package_id' => self::TRIAL_PACKAGE, 'num_codes' => 2], $this->reseller(), false);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(2, $this->lines());

		$rResult = ActiveCodeService::generateCodes(['package_id' => self::TRIAL_PACKAGE, 'num_codes' => 1], $this->reseller(), false);
		$this->assertSame('ERROR', $rResult['status']);
		$this->assertSame(2, $this->lines());
		$this->assertFalse(LineService::canGenerateTrials(self::RESELLER), 'the codes are trials the reseller holds');
	}

	public function testNoTrialCodesWhileTrialsAreSwitchedOff(): void {
		$GLOBALS['rSettings'] = ['disable_trial' => 1];

		$rResult = ActiveCodeService::generateCodes(['package_id' => self::TRIAL_PACKAGE, 'num_codes' => 1], $this->reseller(), false);

		$this->assertSame('ERROR', $rResult['status']);
		$this->assertSame(0, $this->lines());
	}

	public function testCodesOfAnOfficialPackageDoNotUseTheAllowance(): void {
		$this->allow(0, 'day');

		$rResult = ActiveCodeService::generateCodes(['package_id' => self::OFFICIAL_PACKAGE, 'num_codes' => 3], $this->reseller(), false);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(3, $this->lines());
	}

	public function testAnAdministratorIssuesTrialCodesWithoutAnAllowance(): void {
		$GLOBALS['rSettings'] = ['disable_trial' => 1];

		$rResult = ActiveCodeService::generateCodes(['package_id' => self::TRIAL_PACKAGE, 'num_codes' => 3], ['id' => 1, 'member_group_id' => 1], true);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(3, $this->lines());
	}
}
