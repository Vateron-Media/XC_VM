<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * A code that is deleted takes its line with it as lines are deleted, whatever
 * the line has been through: also the line of a code nobody redeemed, which
 * was never on. Such a line can still have left something behind: its address
 * was tried while it was off and the refusals were logged, and the line cache
 * holds it. The logs go with the line and the cache is told. The codes that
 * are not deleted keep their lines and what those left.
 */
final class AuditResellerFollowCodeDeleteTest extends TestCase {
	private const RESELLER = 5;

	private TestDb $rDb;

	private QueryLogDb $rLog;

	/** @var array<string, mixed> the reseller's account as its request read it */
	private array $rReseller;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);

		$this->rDb = new TestDb();
		foreach (['users', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'lines_live', 'lines_logs', 'lines_activity', 'activation_codes', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[1]', '[1]', 1)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'settings' => SettingsManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		SettingsManager::set(['enable_cache' => 1, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$this->rLog = new QueryLogDb($this->rDb);
		$GLOBALS['db'] = $this->rLog;
		DatabaseFactory::set($this->rLog);
		foreach ([LineService::class, ConnectionTracker::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rLog);
		}
		$this->rReseller = UserRepository::getRegisteredUserById(self::RESELLER) + ['reports' => [self::RESELLER]];
	}

	protected function tearDown(): void {
		foreach ([LineService::class, ConnectionTracker::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		DatabaseFactory::reset();
		SettingsManager::set($this->rBefore['settings']);
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		unset($GLOBALS['db']);
	}

	/**
	 * The reseller buys a code nobody redeems: its row. The line's address was
	 * tried once while the line was off. Nothing it caused is pending when the
	 * test acts.
	 */
	private function inStock(): array {
		$rResult = ActiveCodeService::generateCodes(['package_id' => 1, 'num_codes' => 1], $this->rReseller, false);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$rCode = ActiveCodeService::getByCode($rResult['codes'][0]['code']);
		$this->rDb->query('INSERT INTO `lines_logs` (`user_id`, `client_status`) VALUES (?, ?)', $rCode['subscriber_id'], 'USER_DISABLED');
		$this->rDb->exec('DELETE FROM `signals`');
		$this->rLog->rQueries = [];
		return $rCode;
	}

	private function delete(array ...$rCodes): array {
		return ActiveCodeService::massAction('delete', array_column($rCodes, 'id'), $this->rReseller, false, ['refund_credits' => true]);
	}

	/** @return list<array<string, mixed>> the cache jobs queued for this server */
	private function signalled(): array {
		$this->rDb->query('SELECT `custom_data` FROM `signals` WHERE `server_id` = 1 AND `cache` = 1 ORDER BY `signal_id`');
		return array_map(static fn(string $rJob): array => json_decode($rJob, true), $this->rDb->get_column());
	}

	private function rows(string $rTable, string $rWhere = '1'): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '` WHERE ' . $rWhere);
		return (int) $this->rDb->get_col();
	}

	public function testTheLineOfACodeNobodyRedeemedLeavesAsLinesDo(): void {
		$rFirst = $this->inStock();
		$rSecond = $this->inStock();

		$rResult = $this->delete($rFirst, $rSecond);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(0, $this->rows('activation_codes'));
		$this->assertSame(0, $this->rows('lines'));
		$this->assertSame(0, $this->rows('lines_logs'), 'the refusals logged for the lines go with them');
		$this->assertSame([['type' => 'update_line', 'id' => (int) $rFirst['subscriber_id']], ['type' => 'update_line', 'id' => (int) $rSecond['subscriber_id']]], $this->signalled(), 'the cache drops the lines');
		$this->assertSame(100.0, (float) UserRepository::getRegisteredUserById(self::RESELLER)['credits'], 'and their price is back');
	}

	public function testTheCodesThatStayKeepTheirLinesAndWhatTheyLeft(): void {
		$rDeleted = $this->inStock();
		$rKept = $this->inStock();

		$this->assertSame('SUCCESS', $this->delete($rDeleted)['status']);

		$this->assertSame(1, $this->rows('lines', '`id` = ' . (int) $rKept['subscriber_id']));
		$this->assertSame(1, $this->rows('lines_logs', '`user_id` = ' . (int) $rKept['subscriber_id']));
		$this->assertSame(1, $this->rows('lines'));
		$this->assertSame(1, $this->rows('lines_logs'));
	}

	/** The line of the code is gone already: the code is deleted all the same. */
	public function testACodeWhoseLineIsGoneIsDeleted(): void {
		$rCode = $this->inStock();
		$this->rDb->query('DELETE FROM `lines` WHERE `id` = ?', $rCode['subscriber_id']);

		$rResult = $this->delete($rCode);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(0, $this->rows('activation_codes'));
	}

	/** The delete is one piece of work with the refund: when it fails, the lines and their logs are there. */
	public function testNothingOfTheLinesIsLostWhenTheDeleteFails(): void {
		$rCode = $this->inStock();
		$this->rLog->rRefuse = '/^UPDATE `users`/';

		$rResult = $this->delete($rCode);

		$this->assertSame('ERROR', $rResult['status']);
		$this->assertSame(1, $this->rows('lines'));
		$this->assertSame(1, $this->rows('lines_logs'));
		$this->assertSame([], $this->signalled());
	}
}
