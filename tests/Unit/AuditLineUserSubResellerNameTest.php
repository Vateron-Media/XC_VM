<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * A panel username belongs to one account.
 *
 * A reseller that renames a sub-reseller gives it a name no other account
 * has, as creating one does, and a save that sends no name keeps the name
 * the sub-reseller has.
 */
final class AuditLineUserSubResellerNameTest extends TestCase {
	private const RESELLER = 5;
	private const SUB = 6;
	private const OTHER = 7;

	private TestDb $rDb;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_logs'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`) VALUES"
			. " (1, 'Administrators', 1, 0, '[]', 0, '[]'), (2, 'Resellers', 0, 1, '[]', 0, '[2]')");
		$this->rDb->exec("INSERT INTO `users`(`id`, `username`, `member_group_id`, `credits`, `owner_id`, `email`, `override_packages`, `api_key`, `notes`, `reseller_dns`) VALUES"
			. " (1, 'admin', 1, 0, 0, '', '[]', '', '', ''), (5, 'reseller', 2, 100, 0, '', '[]', '', '', ''), (6, 'subreseller', 2, 0, 5, '', '[]', '', '', ''), (7, 'another', 2, 0, 0, '', '[]', '', '', '')");

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = [
			'create_sub_resellers' => 1, 'create_sub_resellers_price' => 0, 'subresellers' => [2], 'all_reports' => [self::SUB],
			'allow_change_username' => 1, 'allow_change_password' => 1, 'minimum_username_length' => 4, 'minimum_password_length' => 4,
		];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		ResellerAPI::$rUserInfo = [];
		ResellerAPI::$rPermissions = [];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	/** @return array<string, mixed> the sub-reseller's form with $rUsername in the name field */
	private function form(string $rUsername): array {
		return ['edit' => (string) self::SUB, 'username' => $rUsername, 'password' => '', 'email' => '', 'reseller_dns' => '', 'notes' => '', 'owner_id' => '0'];
	}

	private function username(int $rUserID): string {
		$this->rDb->query('SELECT `username` FROM `users` WHERE `id` = ?', $rUserID);
		return (string) $this->rDb->get_row()['username'];
	}

	public function testASubResellerIsNotRenamedToTheNameOfAnotherAccount(): void {
		$rResult = ResellerAPI::processUser($this->form('another'));

		$this->assertSame(STATUS_EXISTS_USERNAME, $rResult['status']);
		$this->assertSame('subreseller', $this->username(self::SUB));
		$this->assertSame('another', $this->username(self::OTHER));
	}

	public function testASubResellerIsRenamedToAFreeName(): void {
		$rResult = ResellerAPI::processUser($this->form('renamed'));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('renamed', $this->username(self::SUB));
	}

	public function testASaveThatKeepsTheNameIsAccepted(): void {
		$rResult = ResellerAPI::processUser(['notes' => 'called'] + $this->form('subreseller'));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('subreseller', $this->username(self::SUB));
	}

	public function testASaveThatSendsNoNameKeepsTheStoredOne(): void {
		$rResult = ResellerAPI::processUser($this->form(''));

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('subreseller', $this->username(self::SUB));
	}

	public function testANewSubResellerDoesNotTakeTheNameOfAnotherAccount(): void {
		$rForm = ['username' => 'another', 'password' => 'secret-secret'] + $this->form('');
		unset($rForm['edit']);

		$rResult = ResellerAPI::processUser($rForm);

		$this->assertSame(STATUS_EXISTS_USERNAME, $rResult['status']);
		$this->rDb->query("SELECT COUNT(*) AS `count` FROM `users` WHERE `username` = 'another'");
		$this->assertEquals(1, $this->rDb->get_row()['count']);
	}
}
