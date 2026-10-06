<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * A reseller who deletes activation codes it has not used gets back what it
 * paid for them, once: the refund is counted on the codes as they are stored
 * when they are deleted, held until they are gone, so a code another request
 * has deleted or a subscriber has activated in the meantime pays nothing
 * back. A code an administrator issued to the reseller was not paid for and
 * carries no price to refund.
 */
final class AuditCreditsCodeRefundTest extends TestCase {
	private const RESELLER = 5;
	private const PRICE = 10;

	private TestDb $rDb;

	/** @var array<string, mixed> the reseller's account as its request read it */
	private array $rReseller;

	/** The servers global as the test found it. */
	private mixed $rServers;

	/** @var array<string, mixed> the panel settings as the test found them */
	private array $rSettings = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		// A deleted code's line leaves as lines do: its sessions and its logs are looked up.
		foreach (['users', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'lines_live', 'lines_logs', 'lines_activity', 'activation_codes'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`) VALUES (1, 'Month', 1, " . self::PRICE . ", 1, 'months', '[2]', '[]', '[1]', 1)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 50, 0, '[]')");

		$this->rServers = $GLOBALS['rServers'] ?? null;
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		// Where that is looked up is a panel setting: here the sessions are in the database and the line cache is off.
		$this->rSettings = SettingsManager::getAll();
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$this->use($this->rDb);
		$this->rReseller = UserRepository::getRegisteredUserById(self::RESELLER) + ['reports' => [self::RESELLER]];
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		SettingsManager::set($this->rSettings);
		$GLOBALS['rServers'] = $this->rServers;
		unset($GLOBALS['db']);
	}

	private function use(XcVm\Core\Database\DatabaseHandler $rDb): void {
		$GLOBALS['db'] = $rDb;
		DatabaseFactory::set($rDb);
	}

	private function balance(): float {
		$this->rDb->query('SELECT `credits` FROM `users` WHERE `id` = ?', self::RESELLER);
		return (float) $this->rDb->get_col();
	}

	/** @return list<int> the ids of the codes the panel holds */
	private function codeIds(): array {
		$this->rDb->query('SELECT `id` FROM `activation_codes` ORDER BY `id`');
		return array_map('intval', $this->rDb->get_column());
	}

	/** The reseller buys $rCount codes: their ids. */
	private function buy(int $rCount): array {
		$rResult = ActiveCodeService::generateCodes(['package_id' => 1, 'num_codes' => $rCount], $this->rReseller, false);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		return $this->codeIds();
	}

	/** The reseller deletes codes and asks for the refund. */
	private function deleteWithRefund(array $rIDs): array {
		return ActiveCodeService::massAction('delete', $rIDs, $this->rReseller, false, ['refund_credits' => true]);
	}

	/**
	 * Lets another request change the database after this one has read the
	 * codes it was asked to delete and before it writes anything.
	 */
	private function meanwhile(string ...$rStatements): QueryLogDb {
		$rLog = new QueryLogDb($this->rDb);
		$rOther = TestDb::connect($this->rDb->schema());
		$rRead = false;
		$rLog->rBefore = static function (string $rQuery) use ($rLog, $rOther, $rStatements, &$rRead): void {
			if (!$rRead) {
				$rRead = str_starts_with($rQuery, 'SELECT * FROM `activation_codes` WHERE `id` IN');
				return;
			}
			$rLog->rBefore = null;
			foreach ($rStatements as $rStatement) {
				$rOther->exec($rStatement);
			}
		};
		$this->use($rLog);
		return $rLog;
	}

	public function testUnusedCodesAreRefundedWhenDeleted(): void {
		$rIDs = $this->buy(2);
		$this->assertSame(30.0, $this->balance());

		$rResult = $this->deleteWithRefund($rIDs);

		$this->assertSame('SUCCESS', $rResult['status']);
		$this->assertSame(50.0, $this->balance());
		$this->assertSame([], $this->codeIds());
		$this->assertStringContainsString('Refunded 20 credits', $rResult['message']);
	}

	/** Two requests delete the same unused code at once: the other one is done first, and has paid. */
	public function testACodeAnotherRequestHasDeletedIsNotRefundedAgain(): void {
		$rIDs = $this->buy(1);
		$this->meanwhile(
			'UPDATE `users` SET `credits` = `credits` + ' . self::PRICE . ' WHERE `id` = ' . self::RESELLER,
			'DELETE FROM `activation_codes` WHERE `id` = ' . $rIDs[0],
		);

		$this->deleteWithRefund($rIDs);

		$this->assertSame(50.0, $this->balance());
	}

	/** A subscriber activates the code while the reseller's delete is under way: it was used, not stock. */
	public function testACodeActivatedMeanwhileIsNotRefunded(): void {
		$rIDs = $this->buy(1);
		$this->meanwhile('UPDATE `activation_codes` SET `status` = 2, `activated_at` = UNIX_TIMESTAMP() WHERE `id` = ' . $rIDs[0]);

		$this->deleteWithRefund($rIDs);

		$this->assertSame(40.0, $this->balance());
	}

	/** The codes whose price is refunded are held from the count to their delete. */
	public function testTheRefundIsCountedOnCodesHeldUntilTheyAreDeleted(): void {
		$rIDs = $this->buy(1);
		$rLog = new QueryLogDb($this->rDb);
		$this->use($rLog);

		$this->deleteWithRefund($rIDs);

		$rHeld = array_values(array_filter($rLog->rQueries, static fn(string $rQuery): bool => str_contains($rQuery, '`activation_codes`') && str_contains($rQuery, 'FOR UPDATE')));
		$this->assertCount(1, $rHeld, implode("\n", $rLog->rQueries));
		$rCredit = array_keys(array_filter($rLog->rQueries, static fn(string $rQuery): bool => str_starts_with($rQuery, 'UPDATE `users`')));
		$this->assertGreaterThan(array_search($rHeld[0], $rLog->rQueries, true), $rCredit[0] ?? -1, 'the balance changes after the codes are held');
		$this->assertSame(50.0, $this->balance());
	}

	/** The refund and the delete go together: a refund the database refuses leaves the codes. */
	public function testCodesStayWhenTheirRefundCannotBeCredited(): void {
		$rIDs = $this->buy(1);
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/^UPDATE `users`/';
		$this->use($rLog);

		$rResult = $this->deleteWithRefund($rIDs);

		$this->assertSame('ERROR', $rResult['status']);
		$this->assertSame($rIDs, $this->codeIds());
		$this->assertSame(40.0, $this->balance());
	}

	public function testACodeAnAdministratorIssuedCarriesNoPriceToRefund(): void {
		$rResult = ActiveCodeService::generateCodes(['package_id' => 1, 'num_codes' => 2, 'created_by' => self::RESELLER], ['id' => 1, 'member_group_id' => 1], true);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(50.0, $this->balance(), 'issued, not bought');
		$this->rDb->query('SELECT DISTINCT `created_by` FROM `activation_codes`');
		$this->assertEquals([self::RESELLER], $this->rDb->get_column(), 'the codes are the reseller\'s');

		$this->deleteWithRefund($this->codeIds());

		$this->assertSame([], $this->codeIds());
		$this->assertSame(50.0, $this->balance());
	}

	public function testACodeTheResellerBoughtKeepsItsPrice(): void {
		$this->buy(1);

		$this->rDb->query('SELECT `purchase_cost` FROM `activation_codes`');
		$this->assertSame((float) self::PRICE, (float) $this->rDb->get_col());
	}
}
