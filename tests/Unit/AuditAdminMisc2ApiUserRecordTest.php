<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\AdminApiRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\AdminApiController;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * The record of a user the admin API answers with (get_user, and the answers
 * of create_user and edit_user, which are that record) holds what the panel
 * shows of an account: not its password hash, and its API key only when the
 * key that asks is that account's own, as the panel shows a key on its
 * holder's profile alone. The account itself keeps both.
 */
final class AuditAdminMisc2ApiUserRecordTest extends TestCase {
	private const ADMIN = 1;
	private const SUPPORT = 2;
	private const RESELLER = 5;

	/** Per account: its API key. */
	private const KEYS = [
		self::ADMIN => '11111111111111111111111111111111',
		self::SUPPORT => '33333333333333333333333333333333',
		self::RESELLER => '55555555555555555555555555555555',
	];

	private TestDb $rDb;

	/** @var array<string, mixed> */
	private array $rRequest;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES"
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]'), (2, 'Resellers', 0, 1, '[]', 0, '[]'), (3, 'Support', 1, 0, '[\"mng_regusers\",\"edit_reguser\",\"add_reguser\"]', 1, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `password`, `member_group_id`, `status`, `timezone`, `api_key`, `owner_id`, `credits`) VALUES"
			. " (1, 'admin', 'hash-of-admin', 1, 1, '', '" . self::KEYS[self::ADMIN] . "', 0, 0),"
			. " (2, 'support', 'hash-of-support', 3, 1, '', '" . self::KEYS[self::SUPPORT] . "', 0, 0),"
			. " (5, 'reseller', 'hash-of-reseller', 2, 1, '', '" . self::KEYS[self::RESELLER] . "', 0, 100)");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		AdminApiRegistry::reset();
		$this->rRequest = RequestManager::getAll();
	}

	protected function tearDown(): void {
		RequestManager::set($this->rRequest);
		AdminApiRegistry::reset();
		DatabaseFactory::reset();
		AdminAPIWrapper::$db = null;
		AdminAPIWrapper::$rKey = null;
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['_ERRORS']);
	}

	/**
	 * Ask the API for an action with the key of an account: its answer.
	 *
	 * @param array<string, string> $rData
	 * @return array<string, mixed>
	 */
	private function ask(int $rAs, string $rAction, array $rData): array {
		RequestManager::set(['api_key' => self::KEYS[$rAs], 'action' => $rAction] + $rData);
		ob_start();
		try {
			(new AdminApiController())->index();
		} finally {
			$rBody = (string) ob_get_clean();
		}
		$rAnswer = json_decode($rBody, true);
		$this->assertIsArray($rAnswer, $rBody);
		return $rAnswer;
	}

	/** @return array<string, mixed> the account as stored */
	private function stored(int $rUserID): array {
		$this->rDb->query('SELECT `password`, `api_key` FROM `users` WHERE `id` = ?', $rUserID);
		return $this->rDb->get_raw_row() ?? [];
	}

	/** @return array<string, array{0: int, 1: int}> the account whose key asks, and the account it reads */
	public static function otherAccounts(): array {
		return [
			'an administrator held to a list reads the first administrator' => [self::SUPPORT, self::ADMIN],
			'an administrator held to a list reads a reseller' => [self::SUPPORT, self::RESELLER],
			'the first administrator reads another administrator' => [self::ADMIN, self::SUPPORT],
			'the first administrator reads a reseller' => [self::ADMIN, self::RESELLER],
		];
	}

	#[DataProvider('otherAccounts')]
	public function testTheRecordOfAnotherAccountHoldsNeitherItsPasswordNorItsKey(int $rAs, int $rUserID): void {
		$rAnswer = $this->ask($rAs, 'get_user', ['id' => (string) $rUserID]);

		$this->assertSame('STATUS_SUCCESS', $rAnswer['status']);
		$this->assertEquals($rUserID, $rAnswer['data']['id'], 'the rest of the record is there');
		$this->assertSame([], array_keys(array_intersect_key($rAnswer['data'], ['password' => 0, 'api_key' => 0])));
		$this->assertStringNotContainsString(self::KEYS[$rUserID], (string) json_encode($rAnswer));
	}

	public function testAKeyReadsItsOwnInItsOwnRecord(): void {
		$rAnswer = $this->ask(self::SUPPORT, 'get_user', ['id' => (string) self::SUPPORT]);

		$this->assertSame(self::KEYS[self::SUPPORT], $rAnswer['data']['api_key']);
		$this->assertArrayNotHasKey('password', $rAnswer['data']);
	}

	public function testTheAnswerOfAnEditHoldsNeither(): void {
		$rAnswer = $this->ask(self::SUPPORT, 'edit_user', ['id' => (string) self::RESELLER, 'username' => 'reseller', 'password' => '', 'member_group_id' => '2', 'notes' => 'called']);

		$this->assertSame('STATUS_SUCCESS', $rAnswer['status']);
		$this->assertSame('called', $rAnswer['data']['notes'], 'the record as the edit left it');
		$this->assertSame([], array_keys(array_intersect_key($rAnswer['data'], ['password' => 0, 'api_key' => 0])));
		$this->assertSame(['password' => 'hash-of-reseller', 'api_key' => self::KEYS[self::RESELLER]], $this->stored(self::RESELLER), 'the account keeps both');
	}
}
