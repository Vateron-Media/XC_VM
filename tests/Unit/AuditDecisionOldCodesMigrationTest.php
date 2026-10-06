<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Database\MigrationRunner;
use XcVm\Tests\Support\InstallSchema;

/**
 * Migration 066 gives the activation codes generated before two rules the
 * state those rules give a new code:
 *
 * - A code's subscription starts when the code is redeemed. The line of a
 *   code nobody redeemed is switched off, and with it a line or device paired
 *   with it that took its state; redeeming the code switches its line on.
 * - A code an administrator generated was paid for by nobody: its purchase
 *   cost is 0. A batch a reseller bought left a purchase record in the
 *   reseller log, so a code nobody redeemed, or one back in stock, that has
 *   none loses its cost.
 *
 * A redeemed code's line stays as it is, a line an administrator gave no
 * expiry included, and an active code keeps its cost. The step is applied by
 * the panel's own runner and can run again. A version rollback switches the
 * waiting lines back on, as the released versions need them, restores no
 * cost, and lets the step run once more.
 */
final class AuditDecisionOldCodesMigrationTest extends TestCase {
	private const MIGRATION = '066_hold_unredeemed_activation_codes.sql';

	private const RESELLER = 5;

	/** `lines`.`updated` of every line here: a write to the row moves it. */
	private const NOT_WRITTEN = '2026-01-01 00:00:00';

	private TestDb $rDb;

	private int $rNow;

	private int $rCount = 0;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		// The panel's connection charset (Database::applySessionTimeouts).
		$this->rDb->pdo->exec('SET NAMES utf8mb4');
		foreach (['lines', 'activation_codes', 'users_logs'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rNow = time();
	}

	/** @param array<string, mixed> $rRow */
	private function insert(string $rTable, array $rRow): int {
		$this->rDb->query('INSERT INTO `' . $rTable . '` (`' . implode('`, `', array_keys($rRow)) . '`) VALUES (' . implode(', ', array_fill(0, count($rRow), '?')) . ');', ...array_values($rRow));
		return (int) $this->rDb->last_insert_id();
	}

	/**
	 * A code's line as the panel made them before: switched on, with no expiry.
	 *
	 * @param array<string, mixed> $rSet
	 */
	private function line(array $rSet = []): int {
		return $this->insert('lines', $rSet + ['member_id' => self::RESELLER, 'username' => 'ac_' . ++$this->rCount, 'password' => 'secret', 'exp_date' => null, 'admin_enabled' => 1, 'enabled' => 1, 'is_activecode' => 1, 'updated' => self::NOT_WRITTEN]);
	}

	/**
	 * A code in stock at the package price, as the panel stored every code.
	 *
	 * @param array<string, mixed> $rSet
	 */
	private function code(array $rSet = []): int {
		return $this->insert('activation_codes', $rSet + ['activation_code' => 'CODE' . ++$this->rCount, 'batch_name' => 'BATCH-20260920-AB12C', 'subscriber_id' => 0, 'status' => 1, 'created_by' => self::RESELLER, 'package_id' => 1, 'bouquets' => '[]', 'purchase_cost' => 10, 'created_at' => $this->rNow - 5000, 'activated_at' => null]);
	}

	/** A redeemed code: its status, and when it was redeemed. */
	private function redeemed(): array {
		return ['status' => 2, 'activated_at' => $this->rNow - 100];
	}

	/** The purchase record of a batch, as ActiveCodeService::generateCodes writes it before the codes. */
	private function purchase(string $rBatch, int $rAt, int $rOwner = self::RESELLER): void {
		$this->insert('users_logs', ['owner' => $rOwner, 'type' => 'active_code', 'action' => 'generate', 'package_id' => 1, 'cost' => 10, 'credits_after' => 90, 'date' => $rAt, 'deleted_info' => json_encode(['qty' => 1, 'batch_name' => $rBatch, 'package' => 'Month'])]);
	}

	/** A code of a batch the reseller bought: the record, then the code a moment later. */
	private function bought(string $rBatch, int $rAt, int $rOwner = self::RESELLER): int {
		$this->purchase($rBatch, $rAt, $rOwner);
		return $this->code(['batch_name' => $rBatch, 'created_by' => $rOwner, 'created_at' => $rAt + 1]);
	}

	/** Pending steps, applied as `console.php status` and an update apply them: every other step is already applied. */
	private function migrate(): string {
		$this->rDb->query('CREATE TABLE IF NOT EXISTS `migrations` (`id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, `migration` VARCHAR(255) NOT NULL UNIQUE, `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP);');
		foreach ($this->otherSteps() as $rName) {
			$this->rDb->query('INSERT IGNORE INTO `migrations` (`migration`) VALUES (?);', $rName);
		}
		ob_start();
		MigrationRunner::run($this->rDb);
		return (string) ob_get_clean();
	}

	/** @return list<string> */
	private function otherSteps(): array {
		return array_values(array_diff(array_map('basename', glob(MAIN_HOME . 'migrations/database/up/*.sql') ?: []), [self::MIGRATION]));
	}

	/**
	 * @param list<string> $rColumns
	 * @return array<int, array<string, mixed>> the rows by id
	 */
	private function rows(string $rTable, array $rColumns = ['*']): array {
		$this->rDb->query('SELECT ' . ($rColumns === ['*'] ? '*' : '`id`, `' . implode('`, `', $rColumns) . '`') . ' FROM `' . $rTable . '` ORDER BY `id`;');
		return array_column($this->rDb->get_raw_rows(), null, 'id');
	}

	/** The purchase cost of a code, as a number. */
	private function cost(int $rCode): float {
		return (float) $this->rows('activation_codes', ['purchase_cost'])[$rCode]['purchase_cost'];
	}

	public function testTheLineOfACodeNobodyRedeemedIsSwitchedOff(): void {
		$rStock = $this->line();
		$this->code(['subscriber_id' => $rStock]);
		$rMarkedActive = $this->line();
		$this->code(['subscriber_id' => $rMarkedActive, 'status' => 2]);

		$rOut = $this->migrate();

		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $rOut);
		$rLines = $this->rows('lines');
		foreach ([$rStock => 'a code in stock', $rMarkedActive => 'a code set to active that nobody redeemed'] as $rID => $rWhich) {
			$this->assertSame(0, (int) $rLines[$rID]['enabled'], $rWhich . ': its line is off, as a new code\'s line is');
			$this->assertNull($rLines[$rID]['exp_date'], $rWhich . ': the countdown has not started');
			$this->assertSame(1, (int) $rLines[$rID]['admin_enabled'], $rWhich . ': it is not an administrator\'s ban');
			$this->assertGreaterThan(self::NOT_WRITTEN, $rLines[$rID]['updated'], $rWhich . ': cron:cache_engine rewrites the cached line of a row whose `updated` moved');
		}
	}

	public function testARedeemedCodeAndEveryOtherLineStayAsTheyAre(): void {
		$rKept = [];
		$rKept['redeemed'] = $this->line(['exp_date' => $this->rNow + 86400]);
		$this->code(['subscriber_id' => $rKept['redeemed']] + $this->redeemed());
		$rKept['redeemed, no expiry set by an administrator'] = $this->line();
		$this->code(['subscriber_id' => $rKept['redeemed, no expiry set by an administrator']] + $this->redeemed());
		$rKept['redeemed, returned to stock'] = $this->line(['exp_date' => $this->rNow + 86400]);
		$this->code(['subscriber_id' => $rKept['redeemed, returned to stock'], 'status' => 1, 'activated_at' => $this->rNow - 100]);
		$rKept['in stock, an expiry set by an administrator'] = $this->line(['exp_date' => $this->rNow + 86400]);
		$this->code(['subscriber_id' => $rKept['in stock, an expiry set by an administrator']]);
		$rKept['suspended, already off'] = $this->line(['enabled' => 0]);
		$this->code(['subscriber_id' => $rKept['suspended, already off'], 'status' => 0]);
		$rKept['a line with no expiry that has no code'] = $this->line(['is_activecode' => 0]);
		$rKept['a code line whose code is gone'] = $this->line();
		$rBefore = $this->rows('lines');

		$this->migrate();

		$rAfter = $this->rows('lines');
		foreach ($rKept as $rWhich => $rID) {
			$this->assertSame($rBefore[$rID], $rAfter[$rID], $rWhich);
		}
	}

	public function testALinePairedWithTheLineOfACodeNobodyRedeemedFollowsIt(): void {
		$rStock = $this->line();
		$this->code(['subscriber_id' => $rStock]);
		$rRedeemed = $this->line();
		$this->code(['subscriber_id' => $rRedeemed] + $this->redeemed());

		// A paired line holds a copy of its line's state (MagService::syncLineDevices).
		$rDevice = $this->line(['pair_id' => $rStock, 'is_mag' => 1]);
		$rKept = [];
		$rKept['paired with a redeemed code\'s line'] = $this->line(['pair_id' => $rRedeemed, 'is_mag' => 1]);
		$rKept['paired, with a term of its own'] = $this->line(['pair_id' => $rStock, 'exp_date' => $this->rNow + 86400]);
		$rKept['a redeemed code\'s own line, paired with a line in stock'] = $this->line(['pair_id' => $rStock]);
		$this->code(['subscriber_id' => $rKept['a redeemed code\'s own line, paired with a line in stock']] + $this->redeemed());
		$rBefore = $this->rows('lines');

		$this->migrate();

		$rAfter = $this->rows('lines');
		$this->assertSame(0, (int) $rAfter[$rDevice]['enabled']);
		$this->assertGreaterThan(self::NOT_WRITTEN, $rAfter[$rDevice]['updated']);
		foreach ($rKept as $rWhich => $rID) {
			$this->assertSame($rBefore[$rID], $rAfter[$rID], $rWhich);
		}
	}

	public function testACodeWithoutAPurchaseRecordLosesItsCostAndABoughtCodeKeepsIt(): void {
		$rAt = $this->rNow - 5000;
		$rBought = [];
		// The names a batch can be given: stored at the 100 characters the column
		// holds, and in its character set.
		foreach (['BATCH-20260920-AB12C', 'Été / "promo" ünï', "it's 50% \\ off_", 'Пакет №1', str_repeat('L', 120), str_repeat('é', 120), 'promo 🎉 gold'] as $rBatch) {
			$rBought[$rBatch] = $this->bought($rBatch, $rAt);
		}
		$rBought['the same name, another reseller'] = $this->bought('GIFT', $rAt, 6);
		$rBought['bought and redeemed'] = $this->bought('SOLD', $rAt);
		$this->rDb->query('UPDATE `activation_codes` SET `status` = 2, `activated_at` = ? WHERE `id` = ?;', $this->rNow - 100, $rBought['bought and redeemed']);
		$rBought['bought, redeemed and returned to stock'] = $this->bought('BACK', $rAt);
		$this->rDb->query('UPDATE `activation_codes` SET `activated_at` = ? WHERE `id` = ?;', $this->rNow - 100, $rBought['bought, redeemed and returned to stock']);

		$rIssued = [];
		$rIssued['in stock'] = $this->code(['batch_name' => 'GIFT']);
		$rIssued['suspended, never redeemed'] = $this->code(['batch_name' => 'GIFT', 'status' => 0]);
		// A code in stock refunds on delete, whether or not it was redeemed before.
		$rIssued['redeemed and returned to stock'] = $this->code(['batch_name' => 'GIFT', 'status' => 1, 'activated_at' => $this->rNow - 100]);
		$rIssued['under the name of a batch the reseller bought on another day'] = $this->code(['batch_name' => 'BATCH-20260920-AB12C', 'created_at' => $rAt + 86400]);
		$rIssuedAndRedeemed = $this->code(['batch_name' => 'GIFT'] + $this->redeemed());
		$rFree = $this->code(['batch_name' => 'TRIALS', 'purchase_cost' => 0]);

		// The reseller log holds every other action of the reseller as well.
		$this->insert('users_logs', ['owner' => self::RESELLER, 'type' => 'line', 'action' => 'new', 'log_id' => 1, 'cost' => 10, 'credits_after' => 80, 'date' => $rAt, 'deleted_info' => json_encode(['username' => 'GIFT', 'batch_name' => 'GIFT'])]);
		$this->insert('users_logs', ['owner' => self::RESELLER, 'type' => 'line', 'action' => 'edit', 'log_id' => 1, 'cost' => 0, 'credits_after' => 80, 'date' => $rAt, 'deleted_info' => 'not JSON']);
		$this->insert('users_logs', ['owner' => self::RESELLER, 'type' => 'user', 'action' => 'edit', 'log_id' => 1, 'cost' => 0, 'credits_after' => 80, 'date' => $rAt, 'deleted_info' => null]);
		$rBefore = $this->rows('activation_codes');

		$this->migrate();

		foreach ($rBought as $rWhich => $rID) {
			$this->assertSame(10.0, $this->cost($rID), 'bought: ' . $rWhich);
		}
		foreach ($rIssued as $rWhich => $rID) {
			$this->assertSame(0.0, $this->cost($rID), 'generated by an administrator: ' . $rWhich);
		}
		$this->assertSame(10.0, $this->cost($rIssuedAndRedeemed), 'an active code is left as it is');
		$this->assertSame(0.0, $this->cost($rFree));

		// Nothing but the cost is written, and an active code not at all.
		$rAfter = $this->rows('activation_codes');
		foreach ($rBefore as $rID => $rRow) {
			$rSame = in_array($rID, $rIssued, true) ? ['purchase_cost' => '0.0000'] + $rRow : $rRow;
			$this->assertEquals($rSame, $rAfter[$rID]);
		}
	}

	/** The purchase record is the proof: without it the code counts as generated by an administrator. */
	public function testACodeWhosePurchaseRecordWasClearedLosesItsCost(): void {
		$rCode = $this->bought('BATCH-20260920-AB12C', $this->rNow - 5000);
		$this->rDb->query('TRUNCATE `users_logs`;');

		$this->migrate();

		$this->assertSame(0.0, $this->cost($rCode));
	}

	public function testApplyingTheStepAgainChangesNothing(): void {
		$rStock = $this->line();
		$this->code(['subscriber_id' => $rStock, 'batch_name' => 'GIFT']);
		$this->line(['pair_id' => $rStock, 'is_mag' => 1]);
		$rRedeemed = $this->line();
		$this->code(['subscriber_id' => $rRedeemed, 'batch_name' => 'GIFT'] + $this->redeemed());
		$this->bought('BATCH-20260920-AB12C', $this->rNow - 5000);

		$this->migrate();
		$this->assertStringContainsString('No pending migrations.', $this->migrate(), 'the step is recorded');

		// A step that failed half way is not recorded and runs again from the top.
		$this->rDb->query('UPDATE `lines` SET `updated` = ?;', self::NOT_WRITTEN);
		$rLines = $this->rows('lines');
		$rCodes = $this->rows('activation_codes');
		$this->rDb->query('DELETE FROM `migrations` WHERE `migration` = ?;', self::MIGRATION);

		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $this->migrate());

		$this->assertSame($rLines, $this->rows('lines'), 'no line is written a second time');
		$this->assertSame($rCodes, $this->rows('activation_codes'));
	}

	/**
	 * The released versions start a code's term without switching its line on,
	 * and keep the line of a code in stock on: a line left waiting would stay
	 * off under them after the code is redeemed.
	 */
	public function testAVersionRollbackSwitchesTheWaitingLinesBackOnAndLetsTheStepRunAgain(): void {
		$rStock = $this->line();
		$rCode = $this->code(['subscriber_id' => $rStock]);
		$rMarkedActive = $this->line();
		$this->code(['subscriber_id' => $rMarkedActive, 'status' => 2]);
		$rKept = [];
		$rKept['a suspended code'] = $this->line(['enabled' => 0]);
		$this->code(['subscriber_id' => $rKept['a suspended code'], 'status' => 0]);
		$rKept['a redeemed code whose line is off'] = $this->line(['enabled' => 0]);
		$this->code(['subscriber_id' => $rKept['a redeemed code whose line is off']] + $this->redeemed());
		$rKept['a code in stock whose line is off with a term'] = $this->line(['enabled' => 0, 'exp_date' => $this->rNow + 86400]);
		$this->code(['subscriber_id' => $rKept['a code in stock whose line is off with a term']]);
		$rKept['a line that is off and has no code'] = $this->line(['enabled' => 0, 'is_activecode' => 0]);
		// A paired line follows when the line it is paired with is next saved.
		$rKept['a device paired with the line in stock'] = $this->line(['pair_id' => $rStock, 'is_mag' => 1]);
		$this->migrate();
		$this->rDb->query('UPDATE `lines` SET `updated` = ?;', self::NOT_WRITTEN);
		$rLines = $this->rows('lines');
		$rCodes = $this->rows('activation_codes');
		$this->assertSame(0, (int) $rLines[$rStock]['enabled']);

		$rResult = MigrationRunner::rollback($this->rDb, $this->otherSteps());

		$this->assertSame([self::MIGRATION], $rResult['reversed']);
		$rAfter = $this->rows('lines');
		foreach ([$rStock => 'a code in stock', $rMarkedActive => 'a code set to active that nobody redeemed'] as $rID => $rWhich) {
			$this->assertSame(1, (int) $rAfter[$rID]['enabled'], $rWhich . ': its line is on again');
			$this->assertNull($rAfter[$rID]['exp_date'], $rWhich);
			$this->assertGreaterThan(self::NOT_WRITTEN, $rAfter[$rID]['updated'], $rWhich . ': the cached line follows');
		}
		foreach ($rKept as $rWhich => $rID) {
			$this->assertSame($rLines[$rID], $rAfter[$rID], $rWhich);
		}
		$this->assertSame($rCodes, $this->rows('activation_codes'), 'no purchase cost comes back');
		$this->assertSame(0.0, $this->cost($rCode));

		// The older version generates as it did; the next update applies the step to those codes too.
		$rLater = $this->line();
		$this->code(['subscriber_id' => $rLater]);
		$this->assertStringContainsString('[OK]   ' . self::MIGRATION, $this->migrate());
		$rAgain = $this->rows('lines');
		$this->assertSame(0, (int) $rAgain[$rStock]['enabled']);
		$this->assertSame(0, (int) $rAgain[$rLater]['enabled']);
	}

	/**
	 * The reseller log has no index but its id and grows with every reseller
	 * action: the purchase records are read from it once, not once per code,
	 * and a code looks its record up by owner and batch name together.
	 */
	public function testThePurchaseRecordsAreReadOnceAndLookedUpByOwnerAndBatch(): void {
		$rSql = (string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . self::MIGRATION);
		$rStatements = array_filter(array_map('trim', explode(';', (string) preg_replace('/^\s*--.*$/m', '', $rSql))), static fn(string $rStatement): bool => str_starts_with($rStatement, 'UPDATE `activation_codes`'));
		$this->assertCount(1, $rStatements);

		$rPlan = array_column($this->rDb->pdo->query('EXPLAIN ' . reset($rStatements))->fetchAll(PDO::FETCH_ASSOC), null, 'table');

		$this->assertSame('DERIVED', $rPlan['users_logs']['select_type'] ?? null);
		// A name compared in another collation than the code's column leaves the
		// lookup with the owner alone: every code then reads all its owner's records.
		$rLookup = array_values(array_filter($rPlan, static fn(string $rTable): bool => str_starts_with($rTable, '<derived'), ARRAY_FILTER_USE_KEY));
		$this->assertCount(1, $rLookup);
		$this->assertStringContainsString('c.created_by', (string) $rLookup[0]['ref']);
		$this->assertStringContainsString('c.batch_name', (string) $rLookup[0]['ref']);
	}
}
