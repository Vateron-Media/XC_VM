<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\LogSink;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * A mass action on activation codes that changes their lines sends the line
 * signal every other writer of a line sends: a line that was switched off
 * loses its sessions and the line cache follows. A code that is deleted takes
 * its line with it as lines are deleted (sessions, cache entry, logs, pairing)
 * when the line has been on, has played or has a line paired with it.
 */
final class AuditActiveCodeLineSignalTest extends TestCase {
	private const RESELLER = 5;

	private TestDb $rDb;

	private QueryLogDb $rLog;

	/** @var array<string, mixed> the reseller's account as its request read it */
	private array $rReseller;

	/** @var array<string, mixed> */
	private array $rAdmin = ['id' => 1, 'member_group_id' => 1];

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);

		$this->rDb = new TestDb();
		foreach (['users', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'lines_live', 'lines_logs', 'lines_activity', 'activation_codes', 'access_codes', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[1]', '[1]', 1), (2, 'Year', 1, 100, 12, 'months', '[2]', '[1,2]', '[1]', 1)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'rSettings' => $GLOBALS['rSettings'] ?? null, 'settings' => SettingsManager::getAll(), 'host' => $_SERVER['HTTP_HOST'] ?? null];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1, 'server_type' => 0, 'server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443]];
		$GLOBALS['rSettings'] = ['keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'redis_handler' => 0];
		$_SERVER['HTTP_HOST'] = 'panel.test';
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
		$GLOBALS['rSettings'] = $this->rBefore['rSettings'];
		if ($this->rBefore['host'] === null) {
			unset($_SERVER['HTTP_HOST']);
		} else {
			$_SERVER['HTTP_HOST'] = $this->rBefore['host'];
		}
		unset($GLOBALS['db']);
	}

	/** The reseller buys a code: its row. Nothing it caused is pending when the test acts. */
	private function bought(bool $rRedeemed): array {
		$rResult = ActiveCodeService::generateCodes(['package_id' => 1, 'num_codes' => 1], $this->rReseller, false);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		if ($rRedeemed) {
			$this->assertSame('SUCCESS', ActiveCodeService::activateCode($rResult['codes'][0]['code'], ['ip' => '192.0.2.9'])['status']);
		}
		$this->rDb->exec('DELETE FROM `signals`');
		$this->rLog->rQueries = [];
		return ActiveCodeService::getByCode($rResult['codes'][0]['code']);
	}

	/** @return list<array<string, mixed>> the cache jobs queued for this server */
	private function signalled(): array {
		$this->rDb->query('SELECT `custom_data` FROM `signals` WHERE `server_id` = 1 AND `cache` = 1 ORDER BY `signal_id`');
		return array_map(static fn(string $rJob): array => json_decode($rJob, true), $this->rDb->get_column());
	}

	/** @return list<string> the lookups of a line's live sessions, made to close them */
	private function sessionLookups(): array {
		return array_values(array_filter($this->rLog->rQueries, static fn(string $rQuery): bool => str_contains($rQuery, 'FROM `lines_live` WHERE `user_id`')));
	}

	private function rows(string $rTable, string $rWhere = '1'): int {
		$this->rDb->query('SELECT COUNT(*) FROM `' . $rTable . '` WHERE ' . $rWhere);
		return (int) $this->rDb->get_col();
	}

	public function testDisablingCodesTakesTheirLinesSessionsAndRefreshesTheCache(): void {
		$rCode = $this->bought(true);

		$rResult = ActiveCodeService::massAction('disable', [$rCode['id']], $this->rReseller, false);

		$this->assertSame('SUCCESS', $rResult['status']);
		$this->assertSame([['type' => 'update_lines', 'id' => [(int) $rCode['subscriber_id']]]], $this->signalled());
		$this->assertCount(1, $this->sessionLookups(), 'the line is off: its sessions are read to be closed');
	}

	public function testEveryMassActionThatChangesLinesSignalsThem(): void {
		$rCode = $this->bought(true);

		foreach ([['enable', $this->rReseller, false, []], ['extend', $this->rAdmin, true, ['days' => 30]], ['change_package', $this->rAdmin, true, ['package_id' => 2]]] as [$rAction, $rUser, $rIsAdmin, $rExtra]) {
			$this->rDb->exec('DELETE FROM `signals`');

			$rResult = ActiveCodeService::massAction($rAction, [$rCode['id']], $rUser, $rIsAdmin, $rExtra);

			$this->assertSame('SUCCESS', $rResult['status'], $rAction);
			$this->assertSame([['type' => 'update_lines', 'id' => [(int) $rCode['subscriber_id']]]], $this->signalled(), $rAction);
		}
		$this->assertSame([], $this->sessionLookups(), 'the line stayed on: no session is closed');
	}

	public function testDeletingARedeemedCodeDeletesItsLineAsLinesAreDeleted(): void {
		$rCode = $this->bought(true);
		$rLine = (int) $rCode['subscriber_id'];
		$this->rDb->query('INSERT INTO `lines_logs` (`user_id`, `client_status`) VALUES (?, ?)', $rLine, 'USER_EXPIRED');
		$this->rDb->query('INSERT INTO `lines_activity` (`user_id`, `stream_id`) VALUES (?, 3)', $rLine);

		$rResult = ActiveCodeService::massAction('delete', [$rCode['id']], $this->rReseller, false, ['refund_credits' => true]);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(0, $this->rows('activation_codes'));
		$this->assertSame(0, $this->rows('lines'));
		$this->assertSame(0, $this->rows('lines_logs'), 'its logs go with it');
		$this->assertSame(1, $this->rows('lines_activity', '`user_id` = 0'), 'its history stays, named to no line');
		$this->assertSame([['type' => 'update_line', 'id' => $rLine]], $this->signalled(), 'the cache drops the line');
		$this->assertCount(1, $this->sessionLookups(), 'its sessions are read to be closed');
	}

	/**
	 * A line that is off with no expiry is not always one that has nothing but
	 * its row: the line of a code generated before lines were kept off was on
	 * from the start and may have played, and a line can be paired with one in
	 * stock.
	 */
	public function testDeletingACodeInStockClearsWhatItsLineLeft(): void {
		$rPlayed = $this->bought(false);
		$rPaired = $this->bought(false);
		$rLine = (int) $rPlayed['subscriber_id'];
		$this->rDb->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?', $rLine);
		$this->assertTrue(LogSink::insert('activity', [['server_id' => 1, 'user_id' => $rLine, 'stream_id' => 3, 'date_start' => time() - 60, 'date_end' => time(), 'user_ip' => '192.0.2.9']], $this->rDb));
		$this->rDb->query('INSERT INTO `lines_logs` (`user_id`, `client_status`) VALUES (?, ?)', $rLine, 'USER_DISABLED');
		$this->rDb->query('INSERT INTO `lines` (`member_id`, `username`, `password`, `pair_id`) VALUES (?, ?, ?, ?)', self::RESELLER, 'paired', 'secret', $rPaired['subscriber_id']);
		$this->assertSame('SUCCESS', ActiveCodeService::massAction('disable', [$rPlayed['id']], $this->rReseller, false)['status']);
		$this->rDb->exec('DELETE FROM `signals`');

		$rResult = ActiveCodeService::massAction('delete', [$rPlayed['id'], $rPaired['id']], $this->rReseller, false, ['refund_credits' => true]);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(0, $this->rows('lines', '`is_activecode` = 1'));
		$this->assertSame(0, $this->rows('lines_logs'), 'its logs go with it');
		$this->assertSame(1, $this->rows('lines_activity', '`user_id` = 0'), 'its history stays, named to no line');
		$this->assertSame(1, $this->rows('lines', '`username` = \'paired\' AND `pair_id` IS NULL'), 'a line paired with one of them is paired with none');
		$this->assertContains(['type' => 'update_line', 'id' => $rLine], $this->signalled(), 'the cache drops the line');
	}

	/** Nobody redeemed the code: its line was never on, and has nothing but its row. */
	public function testDeletingCodesInStockRemovesTheirLines(): void {
		$rFirst = $this->bought(false);
		$rSecond = $this->bought(false);

		$rResult = ActiveCodeService::massAction('delete', [$rFirst['id'], $rSecond['id']], $this->rReseller, false, ['refund_credits' => true]);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame(0, $this->rows('lines'));
		$this->assertSame(100.0, (float) UserRepository::getRegisteredUserById(self::RESELLER)['credits']);
	}
}
