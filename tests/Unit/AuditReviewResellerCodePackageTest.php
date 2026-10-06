<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * What a reseller's activation codes are, by the package they are made from.
 *
 * A package sells subscriptions, gives trials, or does both (*Standard
 * Package* and *Trial Package* on the package form). A reseller's codes follow
 * the rule its lines follow: a package that sells subscriptions sells official
 * codes at the official price; a package that gives trials gives trial codes
 * at the trial price, when it gives nothing else or when the request asks for
 * them (`is_trial`). Trial codes use the group's trial allowance; official
 * codes do not. The reseller's form names the kind of code and its price
 * before the reseller pays, so the form and the service read a package by one
 * rule: the reseller is charged what the form showed. An administrator's
 * codes on a package that gives trials are trial codes.
 */
final class AuditReviewResellerCodePackageTest extends TestCase {
	private const RESELLER = 5;
	private const TRIAL_ONLY = 1;
	private const BOTH = 2;

	private TestDb $rDb;

	/** @var array<class-string, mixed> the database each of these services held for itself before the test */
	private array $rOwnDb = [];

	/** The panel settings global as the test found it. */
	private mixed $rSettings;

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'activation_codes', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		// The group is allowed no trials.
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `total_allowed_gen_trials`, `total_allowed_gen_in`, `minimum_trial_credits`) VALUES (2, 'Resellers', 1, 0, 'day', 0)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_trial`, `is_official`, `trial_credits`, `official_credits`, `trial_duration`, `trial_duration_in`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES"
			. " (1, 'Trial', 1, 0, 0, 0, 1, 'days', 0, NULL, '[2]', '[]', '[1]', 1, 1, 1, 0), (2, 'Month', 1, 1, 0, 10, 1, 'days', 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 0)");
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
	}

	protected function tearDown(): void {
		foreach ($this->rOwnDb as $rClass => $rBefore) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $rBefore);
		}
		DatabaseFactory::reset();
		$GLOBALS['rSettings'] = $this->rSettings;
		unset($GLOBALS['db']);
	}

	/**
	 * The reseller asks for $rCount codes of $rPackage.
	 *
	 * @param array<string, mixed> $rAsked what else the request names
	 * @return array<string, mixed> the answer
	 */
	private function codes(int $rPackage, int $rCount, array $rAsked = []): array {
		return ActiveCodeService::generateCodes($rAsked + ['package_id' => $rPackage, 'num_codes' => $rCount], UserRepository::getRegisteredUserById(self::RESELLER), false);
	}

	/**
	 * @param string|null $rBatch one batch, or every code
	 * @return list<array<string, mixed>> the kinds of code there are, with what each was paid for
	 */
	private function kinds(?string $rBatch = null): array {
		$this->rDb->query('SELECT DISTINCT c.`is_trial`, c.`purchase_cost`, l.`is_trial` AS `line_is_trial` FROM `activation_codes` c JOIN `lines` l ON l.`id` = c.`subscriber_id` WHERE ? IS NULL OR c.`batch_name` = ?', $rBatch, $rBatch);
		return $this->rDb->get_rows();
	}

	/**
	 * What the reseller's form offers: its package list, rendered on the
	 * packages of the panel for the trial allowance the reseller has left.
	 *
	 * @return list<array{package: int, cost: float, is_trial: bool, disabled: bool}> its options, in order
	 */
	private function offered(): array {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/active_code.php');
		$rHead = substr(explode('?>', $rView, 2)[0], strlen('<?php'));
		$this->assertSame(1, preg_match('/<select id="package_id".*?<\/select>/s', $rView, $rList));
		$this->rDb->query('SELECT * FROM `users_packages`');
		$rPackages = $this->rDb->get_rows();
		$rKept = ['rUserInfo' => $GLOBALS['rUserInfo'] ?? null, 'rPermissions' => $GLOBALS['rPermissions'] ?? null, 'rGenTrials' => $GLOBALS['rGenTrials'] ?? null];
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById(self::RESELLER);
		$GLOBALS['rPermissions'] = [];
		$GLOBALS['rGenTrials'] = LineService::canGenerateTrials(self::RESELLER);
		ob_start();
		try {
			(static function () use ($rHead, $rList, $rPackages): void {
				$rBouquets = [];
				$language = new class {
					public static function get(string $rKey): string {
						return $rKey;
					}
				};
				eval($rHead);
				eval('?>' . $rList[0]);
			})();
		} finally {
			$rHtml = (string) ob_get_clean();
			foreach ($rKept as $rName => $rValue) {
				$GLOBALS[$rName] = $rValue;
			}
		}
		preg_match_all('/<option value="(\d+)" data-cost="([^"]*)" data-trial="(\d)"( disabled)?>/', $rHtml, $rOptions, PREG_SET_ORDER);
		return array_map(static fn(array $rOption): array => ['package' => (int) $rOption[1], 'cost' => (float) $rOption[2], 'is_trial' => $rOption[3] === '1', 'disabled' => isset($rOption[4])], $rOptions);
	}

	// ── the reseller's form ─────────────────────────────────────────

	public function testAPackageThatOffersBothIsOfferedAsOfficialCodesAndAsTrialCodes(): void {
		$this->rDb->exec('UPDATE `users_groups` SET `total_allowed_gen_trials` = 5');
		$this->rDb->exec('UPDATE `users_packages` SET `trial_credits` = 1 WHERE `id` = ' . self::BOTH);

		$this->assertSame([
			['package' => self::TRIAL_ONLY, 'cost' => 0.0, 'is_trial' => true, 'disabled' => false],
			['package' => self::BOTH, 'cost' => 10.0, 'is_trial' => false, 'disabled' => false],
			['package' => self::BOTH, 'cost' => 1.0, 'is_trial' => true, 'disabled' => false],
		], $this->offered());
	}

	/** The form names the kind of code and its price before the reseller pays: the reseller gets that kind at that price. */
	public function testAPackageThatOffersBothIsChargedAsTheFormShowedIt(): void {
		$this->rDb->exec('UPDATE `users_groups` SET `total_allowed_gen_trials` = 5');
		$this->rDb->exec('UPDATE `users_packages` SET `trial_credits` = 1 WHERE `id` = ' . self::BOTH);
		$rOffers = array_values(array_filter($this->offered(), static fn(array $rOffer): bool => $rOffer['package'] === self::BOTH));
		$this->assertNotEmpty($rOffers);

		foreach ($rOffers as $rOffer) {
			$rBalance = UserCredits::balance(self::RESELLER);

			// The form sends `is_trial` with an option it shows as a trial.
			$rResult = $this->codes(self::BOTH, 3, $rOffer['is_trial'] ? ['is_trial' => 1] : []);

			$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
			$this->assertEquals(3 * $rOffer['cost'], $rResult['total_cost'], 'the price the form showed');
			$this->assertEquals([['is_trial' => (int) $rOffer['is_trial'], 'purchase_cost' => $rOffer['cost'], 'line_is_trial' => (int) $rOffer['is_trial']]], $this->kinds($rResult['batch_name']), 'for the kind of code it showed');
			$this->assertSame($rBalance - 3 * $rOffer['cost'], UserCredits::balance(self::RESELLER));
		}
	}

	/** A trial is offered while the reseller's trial allowance takes another one; what is paid for is offered without it. */
	public function testTheFormOffersNoTrialCodesWithoutATrialAllowance(): void {
		$this->assertSame([
			['package' => self::TRIAL_ONLY, 'cost' => 0.0, 'is_trial' => true, 'disabled' => true],
			['package' => self::BOTH, 'cost' => 10.0, 'is_trial' => false, 'disabled' => false],
			['package' => self::BOTH, 'cost' => 0.0, 'is_trial' => true, 'disabled' => true],
		], $this->offered());
	}

	/**
	 * The option picked carries the price and the kind: the form counts with that
	 * price and asks for that kind. A check of the script's text, as there is no
	 * browser to run it in.
	 */
	public function testTheFormAsksForTheKindOfCodeItShows(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/active_code.php');

		$this->assertStringContainsString("costPerCode = parseFloat(jQuery('#package_id option:selected').attr('data-cost')) || 0;", $rView);
		$this->assertMatchesRegularExpression("/if \(jQuery\('#package_id option:selected'\)\.attr\('data-trial'\) === '1'\) \{\s+postData\.is_trial = 1;/", $rView);
	}

	// ── the codes ───────────────────────────────────────────────────

	public function testCodesOfAPackageThatOffersBothAreOfficial(): void {
		$rResult = $this->codes(self::BOTH, 3);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertEquals(30, $rResult['total_cost']);
		$this->assertEquals([['is_trial' => 0, 'purchase_cost' => 10, 'line_is_trial' => 0]], $this->kinds());
		$this->assertSame(70.0, UserCredits::balance(self::RESELLER));
	}

	public function testCodesOfAPackageThatOffersBothDoNotUseTheTrialAllowance(): void {
		$this->rDb->exec('UPDATE `users_groups` SET `total_allowed_gen_trials` = 2');

		$this->assertSame('SUCCESS', $this->codes(self::BOTH, 3)['status']);

		$this->assertTrue(LineService::canGenerateTrials(self::RESELLER, 2), 'the allowance is untouched');
	}

	public function testTrialCodesOfAPackageThatOffersBothAreAskedForAndUseTheTrialAllowance(): void {
		$this->rDb->exec('UPDATE `users_packages` SET `trial_credits` = 1 WHERE `id` = ' . self::BOTH);
		$this->assertSame('ERROR', $this->codes(self::BOTH, 1, ['is_trial' => 1])['status'], 'the group is allowed no trials');
		$this->assertSame([], $this->kinds());

		$this->rDb->exec('UPDATE `users_groups` SET `total_allowed_gen_trials` = 2');
		$rResult = $this->codes(self::BOTH, 2, ['is_trial' => 1]);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertEquals(2, $rResult['total_cost']);
		$this->assertEquals([['is_trial' => 1, 'purchase_cost' => 1, 'line_is_trial' => 1]], $this->kinds());
		$this->assertSame(98.0, UserCredits::balance(self::RESELLER));
		$this->assertSame('ERROR', $this->codes(self::BOTH, 1, ['is_trial' => 1])['status'], 'the allowance is used up');
		$this->assertSame('SUCCESS', $this->codes(self::BOTH, 1)['status'], 'official codes are still sold');
	}

	/** @return array<string, array{0: mixed, 1: bool}> what a request sends as `is_trial`, and whether that asks for trial codes */
	public static function trialFields(): array {
		return [
			'1, as the form sends it' => ['1', true],
			'the number 1' => [1, true],
			'true' => [true, true],
			'the word true' => ['true', true],
			'the word on' => ['on', true],
			'0' => ['0', false],
			'false' => [false, false],
			'the word false' => ['false', false],
			'the word False' => ['False', false],
			'the word off' => ['off', false],
			'nothing' => ['', false],
		];
	}

	/** `is_trial` is a switch: sent switched off, it asks for the official codes a request without it asks for. */
	#[DataProvider('trialFields')]
	public function testTheTrialFieldIsReadAsASwitch(mixed $rSent, bool $rTrial): void {
		$this->rDb->exec('UPDATE `users_groups` SET `total_allowed_gen_trials` = 5');
		$this->rDb->exec('UPDATE `users_packages` SET `trial_credits` = 1 WHERE `id` = ' . self::BOTH);

		$rResult = $this->codes(self::BOTH, 1, ['is_trial' => $rSent]);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertEquals([['is_trial' => (int) $rTrial, 'purchase_cost' => $rTrial ? 1 : 10, 'line_is_trial' => (int) $rTrial]], $this->kinds());
		$this->assertSame($rTrial ? 99.0 : 90.0, UserCredits::balance(self::RESELLER));
	}

	public function testCodesOfAPackageThatGivesTrialsOnlyAreTrials(): void {
		$this->assertSame('ERROR', $this->codes(self::TRIAL_ONLY, 1)['status'], 'the group is allowed no trials');
		$this->assertSame([], $this->kinds());

		$this->rDb->exec('UPDATE `users_groups` SET `total_allowed_gen_trials` = 2');
		$rResult = $this->codes(self::TRIAL_ONLY, 2);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertEquals([['is_trial' => 1, 'purchase_cost' => 0, 'line_is_trial' => 1]], $this->kinds());
		$this->assertSame(100.0, UserCredits::balance(self::RESELLER));
	}

	public function testAnAdministratorsCodesOnAPackageThatOffersBothAreTrials(): void {
		$rResult = ActiveCodeService::generateCodes(['package_id' => self::BOTH, 'num_codes' => 1, 'created_by' => self::RESELLER], ['id' => 1, 'member_group_id' => 1], true);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertEquals([['is_trial' => 1, 'purchase_cost' => 0, 'line_is_trial' => 1]], $this->kinds());
	}
}
