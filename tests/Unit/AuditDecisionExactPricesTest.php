<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\ResellerAPIWrapper;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * A reseller pays the price as it is stored: a package's price of a line or
 * of a MAG or Enigma2 device (its trial price, its standard price, or the
 * price set for that reseller) and the group's price of a sub-reseller. A
 * price with a fraction is charged with it, at the four decimals a balance is
 * kept at. The refusal of a balance that does not cover the price, the cost
 * and the credits after in the reseller log, and the price the reseller API
 * lists all go by that charge. A whole price is charged and logged as the
 * integer it always was.
 */
final class AuditDecisionExactPricesTest extends TestCase {
	private const RESELLER = 5;

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
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `total_allowed_gen_trials`, `total_allowed_gen_in`, `minimum_trial_credits`, `create_sub_resellers`, `create_sub_resellers_price`, `subresellers`) VALUES (2, 'Resellers', 1, 5, 'day', 0, 1, 50, '[2]')");
		// Each test stores the one price it buys at: the others stay at 50.
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_trial`, `is_official`, `trial_credits`, `official_credits`, `trial_duration`, `trial_duration_in`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES (1, 'Month', 1, 1, 50, 50, 1, 'days', 1, 'months', '[2]', '[]', '[1]', 1, 1, 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 20, 0, '[]')");

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

	/**
	 * The database the code under test reaches, however it asks for one. Other
	 * tests give BouquetService and LineService a database of their own, which
	 * outlives them: they get this one for the test, and what they held is put
	 * back after it.
	 */
	private function use(XcVm\Core\Database\DatabaseHandler $rDb): void {
		$GLOBALS['db'] = $rDb;
		DatabaseFactory::set($rDb);
		foreach ([BouquetService::class, LineService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rOwnDb[$rClass] ??= [$rOwn->getValue()];
			$rOwn->setValue(null, $rDb);
		}
	}

	/** The reseller's request begins: it reads the account and the group's permissions, as the panel stores them. */
	private function startRequest(): void {
		$this->rDb->query('SELECT `create_sub_resellers_price` FROM `users_groups` WHERE `group_id` = 2');
		$rSubResellerPrice = $this->rDb->get_col();
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = [
			'create_line' => true, 'create_mag' => true, 'create_enigma' => true, 'create_sub_resellers' => 1,
			'create_sub_resellers_price' => $rSubResellerPrice, 'subresellers' => [2], 'all_reports' => [],
			'allow_change_username' => 1, 'allow_change_password' => 1, 'minimum_username_length' => 4, 'minimum_password_length' => 4,
			'allow_change_bouquets' => 0, 'allow_restrictions' => 0,
		];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
	}

	/** The administrator stores $rPrice as the price of $rSource, and the reseller holds $rCredits. */
	private function given(string $rSource, float $rPrice, float $rCredits = 20): void {
		match ($rSource) {
			'standard' => $this->rDb->query('UPDATE `users_packages` SET `official_credits` = ?', $rPrice),
			'trial' => $this->rDb->query('UPDATE `users_packages` SET `trial_credits` = ?', $rPrice),
			'reseller' => $this->rDb->query('UPDATE `users` SET `override_packages` = ? WHERE `id` = ?', json_encode([1 => ['assign' => 1, 'official_credits' => $rPrice]]), self::RESELLER),
			'group' => $this->rDb->query('UPDATE `users_groups` SET `create_sub_resellers_price` = ?', $rPrice),
		};
		$this->rDb->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?', $rCredits, self::RESELLER);
		$this->startRequest();
	}

	/** One purchase of $rKind at the price of $rSource: the status the panel answers with. */
	private function buy(string $rKind, string $rSource): int {
		$rForm = ['package' => 1, 'reseller_notes' => ''] + ($rSource === 'trial' ? ['trial' => 1] : []);
		$rResult = match ($rKind) {
			'line' => ResellerAPI::processLine($rForm + ['username' => 'viewer', 'password' => 'secret', 'contact' => '']),
			'mag' => ResellerAPI::processMAG($rForm + ['mac' => '00:1A:79:00:00:0A', 'parent_password' => '0000', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id' => '', 'device_id2' => '', 'ver' => '']),
			'enigma' => ResellerAPI::processEnigma($rForm + ['mac' => '00:1A:79:00:00:0A', 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => '']),
			'user' => ResellerAPI::processUser(['username' => 'subreseller', 'password' => 'secret', 'email' => '', 'reseller_dns' => '', 'notes' => '', 'owner_id' => 0]),
		};
		$this->assertIsArray($rResult, $rKind);
		return $rResult['status'];
	}

	/**
	 * One purchase that is sold, and what it hands the reseller log.
	 *
	 * @return list<array{0: mixed, 1: mixed}> the cost and the credits after of every row
	 */
	private function logged(string $rKind, string $rSource): array {
		$rLog = new QueryLogDb($this->rDb);
		$rGiven = [];
		$rLog->rBefore = static function (string $rQuery, array $rArgs) use (&$rGiven): void {
			if (str_starts_with($rQuery, 'INSERT INTO `users_logs`')) {
				// ... the cost, the credits after, the date, the record.
				$rGiven[] = array_slice($rArgs, -4, 2);
			}
		};
		$this->use($rLog);

		$this->assertSame(STATUS_SUCCESS, $this->buy($rKind, $rSource));

		return $rGiven;
	}

	/** How many of $rKind the panel holds: what was sold. */
	private function sold(string $rKind): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . ['line' => 'lines', 'mag' => 'mag_devices', 'enigma' => 'enigma2_devices', 'user' => 'users'][$rKind] . '`');
		return (int) $this->rDb->get_col() - ($rKind === 'user' ? 1 : 0);
	}

	/** The reseller's balance as the panel reads it. */
	private function balance(): string {
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = ?', self::RESELLER);
		return (string) (0 + $this->rDb->get_col());
	}

	/** @return array<string, array{0: string, 1: string}> what is bought, and the price it is bought at */
	public static function purchases(): array {
		$rPurchases = ['a sub-reseller' => ['user', 'group']];
		foreach (['line' => 'a line', 'mag' => 'a MAG device', 'enigma' => 'an Enigma2 device'] as $rKind => $rName) {
			$rPurchases += [
				$rName . ' at the standard price' => [$rKind, 'standard'],
				$rName . ' at the price set for the reseller' => [$rKind, 'reseller'],
				$rName . ' as a trial' => [$rKind, 'trial'],
			];
		}
		return $rPurchases;
	}

	#[DataProvider('purchases')]
	public function testAPriceWithAFractionIsChargedInFull(string $rKind, string $rSource): void {
		$this->given($rSource, 10.9);

		$this->assertSame(STATUS_SUCCESS, $this->buy($rKind, $rSource));

		$this->assertSame(1, $this->sold($rKind));
		$this->assertSame('9.1', $this->balance());
	}

	/** A price below one credit is a price: it is not given away. */
	#[DataProvider('purchases')]
	public function testAPriceBelowOneCreditIsCharged(string $rKind, string $rSource): void {
		$this->given($rSource, 0.5);

		$this->assertSame(STATUS_SUCCESS, $this->buy($rKind, $rSource));

		$this->assertSame('19.5', $this->balance());
	}

	#[DataProvider('purchases')]
	public function testABalanceBelowThePriceBuysNothing(string $rKind, string $rSource): void {
		$this->given($rSource, 10.9, 10.5);

		$this->assertSame(STATUS_INSUFFICIENT_CREDITS, $this->buy($rKind, $rSource));

		$this->assertSame(0, $this->sold($rKind));
		$this->assertSame('10.5', $this->balance());
		$this->rDb->query('SELECT COUNT(*) FROM `users_logs`');
		$this->assertSame(0, (int) $this->rDb->get_col());
	}

	/** A balance that shows the same number as the price pays it, and nothing is left. */
	#[DataProvider('purchases')]
	public function testABalanceEqualToThePricePaysIt(string $rKind, string $rSource): void {
		$this->given($rSource, 0.7, 0.7);

		$this->assertSame(STATUS_SUCCESS, $this->buy($rKind, $rSource));

		$this->assertSame(1, $this->sold($rKind));
		$this->assertSame('0', $this->balance());
	}

	/** The reseller log is handed what was charged and what the balance holds after it. */
	#[DataProvider('purchases')]
	public function testTheLogIsGivenTheChargeAndTheBalanceLeft(string $rKind, string $rSource): void {
		$this->given($rSource, 10.9);

		$this->assertSame([[10.9, 9.1]], $this->logged($rKind, $rSource));
	}

	/** A whole price leaves a balance its fraction: the log is handed the balance with it. */
	#[DataProvider('purchases')]
	public function testTheLogIsGivenTheFractionAWholePriceLeaves(string $rKind, string $rSource): void {
		$this->given($rSource, 10, 10.75);

		$this->assertSame([[10, 0.75]], $this->logged($rKind, $rSource));
	}

	/**
	 * A whole price is the integer it always was: charged, handed to the log
	 * and stored there as before.
	 */
	#[DataProvider('purchases')]
	public function testAWholePriceIsChargedAndLoggedAsAnInteger(string $rKind, string $rSource): void {
		$this->given($rSource, 10);

		$this->assertSame([[10, 10]], $this->logged($rKind, $rSource));

		$this->assertSame('10', $this->balance());
		$this->rDb->query('SELECT `cost`, `credits_after` FROM `users_logs`');
		$this->assertSame([['cost' => '10', 'credits_after' => '10']], array_map(static fn(array $rRow): array => array_map('strval', $rRow), $this->rDb->get_rows()));
	}

	/** The purchase could not be stored: the price goes back, and the balance is what it was. */
	#[DataProvider('purchases')]
	public function testAPurchaseThatCannotBeStoredGivesTheFractionBack(string $rKind, string $rSource): void {
		$this->given($rSource, 0.1, 0.3);
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = $rKind === 'user' ? '/^INSERT INTO `users`/' : '/^REPLACE INTO `lines`/';
		$this->use($rLog);

		$this->assertSame(STATUS_FAILURE, $this->buy($rKind, $rSource));

		$this->assertSame(0, $this->sold($rKind));
		$this->assertSame('0.3', $this->balance());
	}

	/**
	 * Another request named an account like the sub-reseller between the
	 * name check and the save: the unique name refuses the purchase, the
	 * price goes back, and the other account stays as it was.
	 */
	public function testASubResellerNamedMeanwhileReplacesNoOtherAccount(): void {
		$this->given('group', 10.9);
		$rDb = $this->rDb;
		$rLog = new QueryLogDb($rDb);
		$rLog->rFailLikeThePanel = true;
		$rLog->rBefore = static function (string $rQuery) use ($rDb): void {
			if (str_starts_with($rQuery, 'INSERT INTO `users`(')) {
				$rDb->query("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`) VALUES (9, 'SubReseller', 2, 3, 0)");
			}
		};
		$this->use($rLog);

		$this->assertNotSame(STATUS_SUCCESS, $this->buy('user', 'group'));

		$this->assertSame('20', $this->balance());
		$this->rDb->query('SELECT `id`, `username`, `credits` FROM `users` ORDER BY `id`');
		$this->assertSame([[5, 'reseller', 20], [9, 'SubReseller', 3]], array_map(static fn(array $rRow): array => [(int) $rRow['id'], $rRow['username'], (int) $rRow['credits']], $this->rDb->get_rows()));
	}

	/** The reseller API lists the price a purchase is charged: the standard one, or the one set for the reseller. */
	public function testTheRestApiListsThePriceThatIsCharged(): void {
		$this->given('standard', 10.9);
		$this->assertSame(10.9, ResellerAPIWrapper::getPackages()['data'][0]['official_credits']);

		$this->given('reseller', 7.25);
		$this->assertSame(7.25, ResellerAPIWrapper::getPackages()['data'][0]['official_credits']);

		$this->given('reseller', 7);
		$rListed = ResellerAPIWrapper::getPackages();
		$this->assertSame(7, $rListed['data'][0]['official_credits']);
		$this->assertStringContainsString('"official_credits":7,', (string) json_encode($rListed));
	}

	/**
	 * @return array<string, array{0: mixed}> a whole amount, as a column, a
	 *         form or a stored override gives it
	 */
	public static function wholeAmounts(): array {
		return [
			'a column' => ['10'], 'no credits' => ['0'], 'an integer' => [10], 'a float' => [10.0], 'zeros after the point' => ['10.0000'],
			'a large balance, as the column shows it' => ['1.23457e6'], 'a debt' => ['-5'], 'nothing' => [null], 'an empty field' => [''], 'no number' => ['abc'],
		];
	}

	/** A whole amount is the integer the panel has always counted with. */
	#[DataProvider('wholeAmounts')]
	public function testAWholeAmountStaysTheIntegerItWas(mixed $rStored): void {
		$this->assertSame(intval($rStored), ResellerAPI::amount($rStored));
	}

	/** An amount is counted at the four decimals a balance is kept at. */
	public function testAnAmountIsCountedAtFourDecimals(): void {
		$this->assertSame(10.9, ResellerAPI::amount('10.9'));
		$this->assertSame(0.3, ResellerAPI::amount(0.1 * 3));
		$this->assertSame(0.1235, ResellerAPI::amount('0.123456'));
		$this->assertSame(10, ResellerAPI::amount('9.99996'));
	}
}
