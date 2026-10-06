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
 * A reseller who may choose bouquets chooses among those of the package sold.
 * The line is stored with bouquets that package holds, in the bouquets' own
 * order, whatever the request names as its choice.
 */
final class AuditPlayerJsResellerBouquetTest extends TestCase {
	/** The package's bouquets; the panel also has bouquet 1, which the package does not hold. */
	private const PACKAGE = [2, 3];

	private TestDb $rDb;

	/** @var array<class-string, array{0: mixed}> the database each of these services held for itself before the test */
	private array $rOwnDb = [];

	private mixed $rSettings;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `total_allowed_gen_trials`, `minimum_trial_credits`) VALUES (2, 'Resellers', 1, 0, 0)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `is_mag`, `is_e2`, `check_compatible`) VALUES (1, 'Month', 1, 1, 1, 'months', '[2]', '[2,3]', '[1]', 1, 1, 1, 0)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");
		// The bouquets' order: 3, 1, 2.
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
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(5);
		ResellerAPI::$rPermissions = [
			'create_line' => true, 'all_reports' => [],
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
		$GLOBALS['rSettings'] = $this->rSettings;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/**
	 * The reseller sells a line with $rChoice as the bouquets chosen.
	 *
	 * @return list<int> the bouquets the line is stored with
	 */
	private function sell(array $rChoice): array {
		$rResult = ResellerAPI::processLine(['package' => 1, 'username' => 'viewer', 'password' => 'secret', 'contact' => '', 'reseller_notes' => '', 'bouquets_selected' => $rChoice]);
		$this->assertSame(STATUS_SUCCESS, $rResult['status'] ?? null);

		$this->rDb->query('SELECT `bouquet` FROM `lines` WHERE `username` = ?', 'viewer');
		return json_decode((string) $this->rDb->get_col(), true);
	}

	public function testAChoiceAmongThePackagesBouquetsIsStoredInTheBouquetsOrder(): void {
		$this->assertSame([3, 2], $this->sell(['2', '3']));
	}

	public function testAChoiceOfOneBouquetIsStoredAlone(): void {
		$this->assertSame([2], $this->sell(['2']));
	}

	/** @return array<string, array{0: list<mixed>}> */
	public static function choicesOutsideThePackage(): array {
		return [
			'a bouquet the package does not hold' => [['1']],
			'that bouquet beside one it holds' => [['1', '3']],
			'a value that is no id' => [[true]],
			'several of them' => [[true, true, true]],
			'such a value beside a bouquet it holds' => [[true, '3']],
			'a list' => [[['1']]],
		];
	}

	#[DataProvider('choicesOutsideThePackage')]
	public function testALineHoldsNoBouquetThePackageDoesNot(array $rChoice): void {
		$this->assertSame([], array_values(array_diff($this->sell($rChoice), self::PACKAGE)));
	}
}
