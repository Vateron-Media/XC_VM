<?php

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
 * Package* and *Trial Package* on the package form). Codes made from a package
 * that gives trials are trial codes at the trial price; a reseller's come out
 * of its group's trial allowance. The reseller's form names the kind of code
 * and its price before the reseller pays, so the form and the service read a
 * package by one rule: the reseller is charged what the form showed.
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
	 * @return array<string, mixed> the answer
	 */
	private function codes(int $rPackage, int $rCount): array {
		return ActiveCodeService::generateCodes(['package_id' => $rPackage, 'num_codes' => $rCount], UserRepository::getRegisteredUserById(self::RESELLER), false);
	}

	/** @return list<array<string, mixed>> the kinds of code there are, with what each was paid for */
	private function kinds(): array {
		$this->rDb->query('SELECT DISTINCT c.`is_trial`, c.`purchase_cost`, l.`is_trial` AS `line_is_trial` FROM `activation_codes` c JOIN `lines` l ON l.`id` = c.`subscriber_id`');
		return $this->rDb->get_rows();
	}

	/**
	 * What the reseller's form shows for a package: the head of the form, run
	 * on the packages of the panel.
	 *
	 * @return array<string, mixed> the price of one code (`cost`) and whether it is a trial code (`is_trial`)
	 */
	private function shown(int $rPackage): array {
		$rHead = substr(explode('?>', (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/active_code.php'), 2)[0], strlen('<?php'));
		$this->rDb->query('SELECT * FROM `users_packages`');
		$rPackages = $this->rDb->get_rows();
		$rKept = ['rUserInfo' => $GLOBALS['rUserInfo'] ?? null, 'rPermissions' => $GLOBALS['rPermissions'] ?? null];
		$GLOBALS['rUserInfo'] = UserRepository::getRegisteredUserById(self::RESELLER);
		$GLOBALS['rPermissions'] = [];
		try {
			return (static function () use ($rHead, $rPackages): array {
				$rBouquets = [];
				eval($rHead);
				return $packagePrices;
			})()[$rPackage];
		} finally {
			$GLOBALS['rUserInfo'] = $rKept['rUserInfo'];
			$GLOBALS['rPermissions'] = $rKept['rPermissions'];
		}
	}

	/** The form names the kind of code and its price before the reseller pays: the reseller gets that kind at that price. */
	public function testAPackageThatOffersBothIsChargedAsTheFormShowedIt(): void {
		$this->rDb->exec('UPDATE `users_groups` SET `total_allowed_gen_trials` = 5');
		$this->rDb->exec('UPDATE `users_packages` SET `trial_credits` = 1 WHERE `id` = ' . self::BOTH);
		$rShown = $this->shown(self::BOTH);

		$rResult = $this->codes(self::BOTH, 3);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertEquals(3 * $rShown['cost'], $rResult['total_cost'], 'the price the form showed');
		$this->assertEquals([['is_trial' => (int) $rShown['is_trial'], 'purchase_cost' => $rShown['cost'], 'line_is_trial' => (int) $rShown['is_trial']]], $this->kinds(), 'for the kind of code it showed');
		$this->assertSame(100.0 - 3 * $rShown['cost'], UserCredits::balance(self::RESELLER));
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
