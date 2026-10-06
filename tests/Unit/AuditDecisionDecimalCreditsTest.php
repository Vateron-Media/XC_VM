<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Database\MigrationRunner;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Balances and prices are DECIMAL(16,4): users.credits,
 * users_credits_logs.amount, users_groups.create_sub_resellers_price,
 * users_packages.trial_credits and official_credits, and what an activation
 * code was paid for. The database gives such a column as text with its four
 * decimals ('10.0000'); wherever the panel reads one it is the number it
 * always was: a whole amount the integer (10, shown and answered as 10), a
 * fraction its exact value (10.9). Migration 070 converts the FLOAT values of
 * an older panel to the nearest four-decimal value, and its down step gives
 * the older version its types back.
 */
final class AuditDecisionDecimalCreditsTest extends TestCase {
	private const MIGRATION = '070_decimal_credits_and_prices.sql';

	private const TABLES = ['users', 'users_groups', 'users_packages', 'users_credits_logs', 'activation_codes'];

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (self::TABLES as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		unset($GLOBALS['db']);
	}

	private function seed(): void {
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `create_sub_resellers`, `create_sub_resellers_price`, `subresellers`) VALUES (2, 'Resellers', 1, 1, 50, '[2]'), (3, 'Cheap', 1, 1, 0.5, '[3]')");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `trial_credits`, `official_credits`, `groups`, `bouquets`) VALUES (1, 'Month', 1, 10, '[2]', '[]'), (2, 'Week', 0.25, 7.25, '[2]', '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`) VALUES (5, 'whole', 2, 10, 0), (6, 'fraction', 2, 10.9, 5), (7, 'none', 2, NULL, 5), (8, 'large', 2, 16777217, 5)");
	}

	/** @return array<string, string> column => type, as the server describes it */
	private function types(): array {
		$this->rDb->query("SELECT CONCAT(`TABLE_NAME`, '.', `COLUMN_NAME`) AS `name`, `COLUMN_TYPE` AS `type` FROM `information_schema`.`COLUMNS` WHERE `TABLE_SCHEMA` = DATABASE() AND `COLUMN_NAME` IN ('credits', 'amount', 'create_sub_resellers_price', 'trial_credits', 'official_credits', 'purchase_cost') ORDER BY `name`");
		return array_column($this->rDb->get_rows(), 'type', 'name');
	}

	public function testTheInstallSchemaKeepsAmountsAtFourDecimals(): void {
		$this->assertSame(array_fill_keys(['activation_codes.purchase_cost', 'users.credits', 'users_credits_logs.amount', 'users_groups.create_sub_resellers_price', 'users_packages.official_credits', 'users_packages.trial_credits'], 'decimal(16,4)'), $this->types());
	}

	public function testAWholeAmountReadsAsTheIntegerAndAFractionAsItsExactValue(): void {
		$this->seed();

		$this->assertSame(10, UserRepository::getRegisteredUserById(5)['credits']);
		$this->assertSame(10.9, UserRepository::getRegisteredUserById(6)['credits']);
		$this->assertNull(UserRepository::getRegisteredUserById(7)['credits'], 'a balance that holds nothing stays as it is');
		$this->assertSame(16777217, UserRepository::getRegisteredUserById(8)['credits'], 'a whole amount past what a FLOAT holds');
		$rAll = array_column(UserRepository::getRegisteredUsers(), 'credits', 'id');
		ksort($rAll);
		$this->assertSame([5 => 10, 6 => 10.9, 7 => null, 8 => 16777217], $rAll);
		$rReports = array_column(UserRepository::getDirectReports(['direct_reports' => [5]], ['id' => 0], false), 'credits', 'id');
		ksort($rReports);
		$this->assertSame([6 => 10.9, 7 => null, 8 => 16777217], $rReports);

		$this->assertSame(50, AuthRepository::getPermissions(2)['create_sub_resellers_price']);
		$this->assertSame(0.5, AuthRepository::getPermissions(3)['create_sub_resellers_price']);
		$this->assertSame(50, GroupService::getById(2)['create_sub_resellers_price']);
		$this->assertSame([2 => 50, 3 => 0.5], array_column(GroupService::getAll(), 'create_sub_resellers_price', 'group_id'));

		$this->assertSame([1, 10], [PackageService::getById(1)['trial_credits'], PackageService::getById(1)['official_credits']]);
		$this->assertSame([0.25, 7.25], [PackageService::getById(2)['trial_credits'], PackageService::getById(2)['official_credits']]);
		$this->assertSame([1 => 10, 2 => 7.25], array_column(PackageService::getAll(), 'official_credits', 'id'));
	}

	/** What a page prints and an API answers for a whole amount is what it was: 10, a number. */
	public function testAWholeAmountIsPrintedAndAnsweredAsBefore(): void {
		$this->seed();
		$rUser = UserRepository::getRegisteredUserById(5);
		$rGroup = AuthRepository::getPermissions(2);
		$rPackage = PackageService::getById(1);

		$this->assertSame('10|50|10', $rUser['credits'] . '|' . $rGroup['create_sub_resellers_price'] . '|' . $rPackage['official_credits']);
		$this->assertSame('{"credits":10,"price":50,"official":10,"fraction":10.9}', json_encode(['credits' => $rUser['credits'], 'price' => $rGroup['create_sub_resellers_price'], 'official' => $rPackage['official_credits'], 'fraction' => UserRepository::getRegisteredUserById(6)['credits']]));
	}

	/**
	 * Readers that print a raw row or answer it to a script: the admin users
	 * table answered to the admin API (and its credit log, which the CSV and
	 * JSON exports are made from), the reseller users table, the reseller
	 * dashboard and its statistics, a package's price on the line, MAG and
	 * Enigma2 pages, and the legacy admin API's user list.
	 */
	public function testEveryReaderOfARawRowTakesItsAmountsAsNumbers(): void {
		$rRead = static fn(string $rFile): string => (string) file_get_contents(MAIN_HOME . $rFile);

		$rTable = $rRead('Public/Controllers/Admin/TableController.php');
		$this->assertStringContainsString('"amount"          => ResellerAPI::amount($rRow["amount"]),', $rTable);
		$this->assertStringContainsString('"credits"         => ResellerAPI::amount($rRow["credits"]),', $rTable);
		$this->assertStringContainsString('$rRows = array_map([UserCredits::class, \'amounts\'], $db->get_rows());', $rTable);

		$this->assertStringContainsString("'credits' => ResellerAPI::amount(\$rRow['credits']),", $rRead('Infrastructure/ResellerTableRenderer.php'));

		$rDispatcher = $rRead('Infrastructure/ResellerApiDispatcher.php');
		$this->assertStringContainsString("\$rReturn['credits'] = ResellerAPI::amount(\$db->get_row()['credits']);", $rDispatcher);
		$this->assertStringContainsString('$rRow = UserCredits::amounts($db->get_row());', $rDispatcher);
		$this->assertSame(2, substr_count($rDispatcher, '$rData = UserCredits::amounts($db->get_row());'), 'the price of a package and of its trial');

		$this->assertSame(2, substr_count($rRead('Public/Controllers/Admin/Ajax/PackageAjaxController.php'), '$rData = UserCredits::amounts($db->get_row());'));
		$this->assertStringContainsString("\$rResults = array_map([UserCredits::class, 'amounts'], \$db->get_rows());", $rRead('Public/admin/api.php'));
	}

	/** A sum of balances answers a number: 0 when there is none, never the text '0.0000'. */
	public function testASumOfBalancesIsANumber(): void {
		$this->seed();
		$this->rDb->query('SELECT SUM(`credits`) AS `credits` FROM `users` WHERE `id` IN (5, 6)');
		$this->assertSame('20.9000', $this->rDb->get_row()['credits'], 'as the database gives it');
		$this->assertSame(['credits' => 20.9], UserCredits::amounts(['credits' => '20.9000']));
		$this->assertSame(['credits' => 0, 'amount' => null, 'username' => '10.0000'], UserCredits::amounts(['credits' => '0.0000', 'amount' => null, 'username' => '10.0000']));
	}

	/** Balances change in the database at four decimals, and a balance short of a price by 0.0001 is refused. */
	public function testBalancesChangeAtFourDecimals(): void {
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `credits`) VALUES (5, 'a', 0.3), (6, 'b', 0), (7, 'c', 16777216)");

		foreach ([1, 2, 3] as $rTime) {
			$this->assertTrue(UserCredits::debit(5, 0.1), 'debit ' . $rTime);
		}
		$this->assertFalse(UserCredits::debit(5, 0.0001), 'nothing is left');
		$this->assertTrue(UserCredits::credit(6, 10.9));
		$this->assertFalse(UserCredits::debit(6, 10.9001));
		$this->assertTrue(UserCredits::transfer(6, 5, 0.9));
		$this->assertTrue(UserCredits::credit(7, 1));

		$this->rDb->query('SELECT `id`, `credits` FROM `users` ORDER BY `id`');
		$this->assertSame([5 => '0.9000', 6 => '10.0000', 7 => '16777217.0000'], array_column($this->rDb->get_rows(), 'credits', 'id'));
		$this->assertSame(10.0, UserCredits::balance(6));
	}

	/** @return list<string> every migration step but 070 */
	private function otherSteps(): array {
		return array_values(array_diff(array_map('basename', glob(MAIN_HOME . 'migrations/database/up/*.sql') ?: []), [self::MIGRATION]));
	}

	private function migrate(): string {
		$this->rDb->query('CREATE TABLE IF NOT EXISTS `migrations` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `migration` VARCHAR(255) NOT NULL UNIQUE, `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP);');
		foreach ($this->otherSteps() as $rName) {
			$this->rDb->query('INSERT IGNORE INTO `migrations` (`migration`) VALUES (?);', $rName);
		}
		ob_start();
		MigrationRunner::run($this->rDb);
		return (string) ob_get_clean();
	}

	/**
	 * An older panel's FLOAT values become the nearest four-decimal value
	 * (10.9, stored as 10.8999996, is 10.9000); a whole amount stays whole, an
	 * empty one empty. The down step gives the older version its types back.
	 */
	public function testTheMigrationConvertsFloatValuesToTheNearestFourDecimalValue(): void {
		// An older panel's tables: the install schema with 070 rolled back.
		$this->migrate();
		$this->assertSame([self::MIGRATION], MigrationRunner::rollback($this->rDb, $this->otherSteps())['reversed']);
		$this->assertSame(['activation_codes.purchase_cost' => 'decimal(10,2)', 'users.credits' => 'float', 'users_credits_logs.amount' => 'float', 'users_groups.create_sub_resellers_price' => 'float', 'users_packages.official_credits' => 'float', 'users_packages.trial_credits' => 'float'], $this->types());
		$this->seed();
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `credits`) VALUES (9, 'odd', 123456.78), (10, 'small', 0.1), (11, 'negative', -5.3)");
		$this->rDb->exec("INSERT INTO `users_credits_logs` (`id`, `amount`) VALUES (1, 10.9), (2, NULL)");
		$this->rDb->exec("INSERT INTO `activation_codes` (`activation_code`, `purchase_cost`) VALUES ('A', 10.12)");
		$this->rDb->query('SELECT `credits` + 0E0 AS `stored` FROM `users` WHERE `id` = 6');
		$this->assertNotSame(10.9, (float) $this->rDb->get_col(), 'a FLOAT does not hold 10.9');

		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $this->migrate());

		$this->assertSame(array_fill_keys(['activation_codes.purchase_cost', 'users.credits', 'users_credits_logs.amount', 'users_groups.create_sub_resellers_price', 'users_packages.official_credits', 'users_packages.trial_credits'], 'decimal(16,4)'), $this->types());
		$this->rDb->query('SELECT `id`, `credits` FROM `users` ORDER BY `id`');
		$this->assertSame([5 => '10.0000', 6 => '10.9000', 7 => null, 8 => '16777216.0000', 9 => '123456.7813', 10 => '0.1000', 11 => '-5.3000'], array_column($this->rDb->get_rows(), 'credits', 'id'), 'a FLOAT never held 16777217: it held 16777216');
		$this->rDb->query('SELECT `amount` FROM `users_credits_logs` ORDER BY `id`');
		$this->assertSame(['10.9000', null], $this->rDb->get_column());
		$this->rDb->query('SELECT `create_sub_resellers_price` FROM `users_groups` ORDER BY `group_id`');
		$this->assertSame(['50.0000', '0.5000'], $this->rDb->get_column());
		$this->rDb->query('SELECT CONCAT(`trial_credits`, \'/\', `official_credits`) FROM `users_packages` ORDER BY `id`');
		$this->assertSame(['1.0000/10.0000', '0.2500/7.2500'], $this->rDb->get_column());
		$this->rDb->query('SELECT `purchase_cost` FROM `activation_codes`');
		$this->assertSame('10.1200', $this->rDb->get_col());

		// Run again (a step that failed half way is not recorded): nothing changes.
		$this->rDb->query('DELETE FROM `migrations` WHERE `migration` = ?;', self::MIGRATION);
		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $this->migrate());
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = 6');
		$this->assertSame('10.9000', $this->rDb->get_col());

		$this->assertSame([self::MIGRATION], MigrationRunner::rollback($this->rDb, $this->otherSteps())['reversed']);
		$this->assertSame('float', $this->types()['users.credits']);
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = 6');
		$this->assertSame(10.9, $this->rDb->get_col(), 'the older version reads 10.9 as it did');
	}
}
