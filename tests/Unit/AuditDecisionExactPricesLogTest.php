<?php

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/**
 * The reseller log keeps the cost and the credits after a purchase as they
 * were charged, with their fraction: an install's table does, and migration
 * 069 makes an updated panel's do. A whole amount reads as it always did, a
 * row logged before the update included.
 */
final class AuditDecisionExactPricesLogTest extends TestCase {
	private const MIGRATION = '069_exact_reseller_log_amounts';

	/** The table as panels before 069 have it. */
	private const BEFORE = 'CREATE TABLE `users_logs` (`id` int(11) NOT NULL AUTO_INCREMENT, `owner` int(11) DEFAULT NULL, `type` varchar(255) DEFAULT NULL, `action` varchar(255) DEFAULT NULL, `log_id` int(11) DEFAULT NULL, `package_id` int(11) DEFAULT NULL, `cost` int(16) DEFAULT NULL, `credits_after` int(16) DEFAULT NULL, `date` int(30) DEFAULT NULL, `deleted_info` longtext, PRIMARY KEY (`id`))';

	private TestDb $rDb;

	protected function setUp(): void {
		$this->rDb = new TestDb();
	}

	private function log(mixed $rCost, mixed $rAfter): void {
		$this->rDb->query("INSERT INTO `users_logs` (`owner`, `type`, `action`, `cost`, `credits_after`, `date`) VALUES (5, 'line', 'new', ?, ?, 0)", $rCost, $rAfter);
	}

	/** @return list<array{cost: ?string, credits_after: ?string}> */
	private function rows(): array {
		$this->rDb->query('SELECT `cost`, `credits_after` FROM `users_logs` ORDER BY `id`');
		return array_map(static fn(array $rRow): array => array_map(static fn($rValue) => $rValue === null ? null : (string) $rValue, $rRow), $this->rDb->get_rows());
	}

	public function testAnInstallLogsTheAmountsAsCharged(): void {
		$this->rDb->exec(InstallSchema::table('users_logs'));

		$this->log(10.9, 9.1);
		$this->log(0.125, 19.875);
		$this->log(10, 10);

		$this->assertSame([['cost' => '10.9', 'credits_after' => '9.1'], ['cost' => '0.125', 'credits_after' => '19.875'], ['cost' => '10', 'credits_after' => '10']], $this->rows());
	}

	public function testTheUpdateKeepsTheOldRowsAndLogsFractionsFromThen(): void {
		$this->rDb->exec(self::BEFORE);
		$this->log(10.9, 9.1);
		$this->log(10, 10);
		$this->log(null, null);
		$this->assertSame(['cost' => '11', 'credits_after' => '9'], $this->rows()[0], 'the table before the step keeps fractions');

		$this->rDb->exec(InstallSchema::migration(self::MIGRATION));
		$this->log(10.9, 9.1);

		$this->assertSame([
			['cost' => '11', 'credits_after' => '9'],
			['cost' => '10', 'credits_after' => '10'],
			['cost' => null, 'credits_after' => null],
			['cost' => '10.9', 'credits_after' => '9.1'],
		], $this->rows());
	}

	/** An install and an update end with the same two columns. */
	public function testAnInstallAndAnUpdateHaveTheSameColumns(): void {
		$this->rDb->exec(InstallSchema::table('users_logs'));
		$this->rDb->query("SHOW COLUMNS FROM `users_logs` WHERE `Field` IN ('cost', 'credits_after')");
		$rInstall = $this->rDb->get_rows();
		$this->rDb->exec('DROP TABLE `users_logs`');

		$this->rDb->exec(self::BEFORE);
		$this->rDb->exec(InstallSchema::migration(self::MIGRATION));
		$this->rDb->query("SHOW COLUMNS FROM `users_logs` WHERE `Field` IN ('cost', 'credits_after')");

		$this->assertSame($rInstall, $this->rDb->get_rows());
	}
}
