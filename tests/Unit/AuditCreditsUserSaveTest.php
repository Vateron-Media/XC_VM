<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\UserController;
use XcVm\Tests\Support\InstallSchema;
use XcVm\Tests\Support\QueryLogDb;

/**
 * Saving a user's details does not write the user's credit balance back.
 *
 * The administrator's form shows the balance and lets it be changed: a save
 * moves the balance by what the administrator changed it by (the posted
 * balance against the one the form was opened with), and a save that changes
 * something else leaves it alone, so credits the reseller spent or received
 * while the form was open stay spent or received. A reseller's form for a
 * sub-reseller has no balance field and leaves the balance as it is stored.
 *
 * `users`.`credits` is a FLOAT: read with the other columns of the account it
 * comes back at six significant digits (1234567 as 1234570). The form shows
 * the balance as it is stored, and a balance that is set is set against the
 * stored one, so a large balance ends at the figure that was typed or sent.
 */
final class AuditCreditsUserSaveTest extends TestCase {
	private const ADMIN = 1;
	private const RESELLER = 5;
	private const SUB = 6;

	private TestDb $rDb;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_credits_logs', 'users_logs'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `email`, `override_packages`, `api_key`, `notes`, `reseller_dns`) VALUES"
			. " (1, 'admin', 1, 0, 0, '', '[]', '', '', ''), (5, 'reseller', 2, 100, 0, 'old@example.com', '[]', '', '', ''), (6, 'subreseller', 2, 10, 5, '', '[]', '', '', '')");

		$this->use($this->rDb);
		$GLOBALS['rUserInfo'] = ['id' => self::ADMIN, 'member_group_id' => 1];
		$GLOBALS['rAdminUserInfo'] = $GLOBALS['rUserInfo'];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
	}

	protected function tearDown(): void {
		DatabaseFactory::reset();
		ResellerAPI::$rUserInfo = [];
		ResellerAPI::$rPermissions = [];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions']);
	}

	private function use(XcVm\Core\Database\DatabaseHandler $rDb): void {
		$GLOBALS['db'] = $rDb;
		DatabaseFactory::set($rDb);
	}

	/**
	 * The administrator opens the reseller's form: the fields it will post,
	 * as it shows them. The view sends the balance it showed as `credits_shown`
	 * (testTheEditFormSendsTheBalanceItShowed).
	 *
	 * @return array<string, mixed>
	 */
	private function openForm(): array {
		$rUser = $this->shown();
		return [
			'edit' => (string) $rUser['id'], 'credits_shown' => (string) $rUser['credits'], 'username' => $rUser['username'], 'password' => '',
			'member_group_id' => (string) $rUser['member_group_id'], 'email' => $rUser['email'], 'owner_id' => (string) $rUser['owner_id'],
			'credits' => (string) $rUser['credits'], 'credits_reason' => '', 'reseller_dns' => $rUser['reseller_dns'], 'notes' => $rUser['notes'],
		];
	}

	/**
	 * The reseller's account as the administrator's edit page hands it to its form.
	 *
	 * @return array<string, mixed>
	 */
	private function shown(): array {
		$rPage = new class extends UserController {
			/** @var array<string, mixed> */
			public array $rData = [];

			protected function requirePermission() {
			}

			protected function render(string $view, array $data = []) {
				$this->rData = $data;
			}
		};
		$rRequest = RequestManager::getAll();
		RequestManager::set(['id' => (string) self::RESELLER]);

		try {
			$rPage->index();
		} finally {
			RequestManager::set($rRequest);
		}

		return $rPage->rData['rUser'];
	}

	/** Another request changes a balance while the form is open or the save is under way. */
	private function setBalance(int $rUserID, float $rCredits): void {
		TestDb::connect($this->rDb->schema())->exec('UPDATE `users` SET `credits` = ' . $rCredits . ' WHERE `id` = ' . $rUserID);
	}

	/** @return array<string, mixed> */
	private function user(int $rUserID): array {
		$this->rDb->query('SELECT * FROM `users` WHERE `id` = ?', $rUserID);
		return $this->rDb->get_row();
	}

	/** @return list<array<string, mixed>> the adjustments the credits log holds */
	private function adjustments(): array {
		$this->rDb->query('SELECT `target_id`, `admin_id`, `amount`, `reason` FROM `users_credits_logs` ORDER BY `id`');
		return $this->rDb->get_rows();
	}

	/**
	 * A database that lets another request run $rStatement after this one has
	 * read the user it saves and before it writes that user.
	 */
	private function beforeTheUserIsWritten(string $rStatement): void {
		$rLog = new QueryLogDb($this->rDb);
		$rOther = TestDb::connect($this->rDb->schema());
		$rLog->rBefore = static function (string $rQuery) use ($rLog, $rOther, $rStatement): void {
			if (preg_match('/^(REPLACE INTO|UPDATE) `users`/', $rQuery)) {
				$rLog->rBefore = null;
				$rOther->exec($rStatement);
			}
		};
		$this->use($rLog);
	}

	// ── the administrator's form ────────────────────────────────────

	public function testTheEditFormSendsTheBalanceItShowed(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/admin/user.php');

		$rShown = 'htmlspecialchars((string) $rUser[\'credits\'], ENT_QUOTES)';
		$this->assertTrue(str_contains($rView, '<input type="hidden" name="credits_shown" value="<?= ' . $rShown . '; ?>">'), 'the edit form posts the balance it was opened with');
		$this->assertTrue(str_contains($rView, 'name="credits" value="<?= $rIsEdit ? ' . $rShown), 'the balance field shows the same value');
	}

	/** The reseller spends 50 credits while its form is open; the administrator changes the e-mail. */
	public function testSavingAnotherFieldLeavesTheBalanceAlone(): void {
		$rForm = $this->openForm();
		$this->setBalance(self::RESELLER, 50);

		$rResult = UserService::process(['email' => 'new@example.com'] + $rForm);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(self::RESELLER, $rResult['data']['insert_id']);
		$this->assertSame('new@example.com', $this->user(self::RESELLER)['email']);
		$this->assertEquals(50, $this->user(self::RESELLER)['credits']);
		$this->assertSame([], $this->adjustments());
	}

	/** The reseller spends 50 credits while its form is open; the administrator adds 30. */
	public function testAChangedBalanceMovesByWhatTheAdministratorChanged(): void {
		$rForm = $this->openForm();
		$this->setBalance(self::RESELLER, 50);

		$rResult = UserService::process(['credits' => '130', 'credits_reason' => 'bonus'] + $rForm);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(80, $this->user(self::RESELLER)['credits']);
		$this->assertEquals([['target_id' => self::RESELLER, 'admin_id' => self::ADMIN, 'amount' => 30, 'reason' => 'bonus']], $this->adjustments());
	}

	public function testAChangedBalanceIsStoredAsTypedWhenNothingElseMovedIt(): void {
		$rResult = UserService::process(['credits' => '70', 'credits_reason' => 'correction'] + $this->openForm());

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(70, $this->user(self::RESELLER)['credits']);
		$this->assertEquals([['target_id' => self::RESELLER, 'admin_id' => self::ADMIN, 'amount' => -30, 'reason' => 'correction']], $this->adjustments());
	}

	/** The balance could not be changed: the credits log does not say that it was. */
	public function testAnAdjustmentThatWasNotMadeIsNotLogged(): void {
		$rForm = $this->openForm();
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/FOR UPDATE/';
		$this->use($rLog);

		UserService::process(['credits' => '130', 'credits_reason' => 'bonus'] + $rForm);

		$this->assertEquals(100, $this->user(self::RESELLER)['credits']);
		$this->assertSame([], $this->adjustments());
	}

	/** Credits the reseller spends between the save's read of the user and its write stay spent. */
	public function testASaveDoesNotWriteBackTheBalanceItRead(): void {
		$rForm = $this->openForm();
		$this->beforeTheUserIsWritten('UPDATE `users` SET `credits` = `credits` - 20 WHERE `id` = ' . self::RESELLER);

		$rResult = UserService::process(['notes' => 'called'] + $rForm);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('called', $this->user(self::RESELLER)['notes']);
		$this->assertEquals(80, $this->user(self::RESELLER)['credits']);
	}

	/** The admin API sends the balance to set and none it was shown: the stored one is the reference. */
	public function testACallerThatWasShownNoBalanceSetsTheOneItSends(): void {
		$rForm = $this->openForm();
		unset($rForm['credits_shown']);

		$rResult = UserService::process(['credits' => '70', 'credits_reason' => 'api'] + $rForm);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(70, $this->user(self::RESELLER)['credits']);
		$this->assertEquals([['target_id' => self::RESELLER, 'admin_id' => self::ADMIN, 'amount' => -30, 'reason' => 'api']], $this->adjustments());
	}

	public function testAnEditThatSendsNoBalanceLeavesItAndLogsNothing(): void {
		$rForm = $this->openForm();
		unset($rForm['credits_shown'], $rForm['credits'], $rForm['credits_reason']);

		$rResult = UserService::process(['notes' => 'called'] + $rForm);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame('called', $this->user(self::RESELLER)['notes']);
		$this->assertEquals(100, $this->user(self::RESELLER)['credits']);
		$this->assertSame([], $this->adjustments());
	}

	public function testAnEditKeepsEveryOtherColumnOfTheUser(): void {
		$this->rDb->exec("UPDATE `users` SET `password` = 'hash', `api_key` = '0123456789abcdef0123456789abcdef', `date_registered` = 1700000000, `last_login` = 1700000100, `ip` = '192.0.2.7', `timezone` = 'Europe/Lisbon', `hue` = 'red', `theme` = 1, `lang` = 'pt', `status` = 1 WHERE `id` = " . self::RESELLER);
		$rBefore = $this->user(self::RESELLER);

		$rResult = UserService::process(['email' => 'new@example.com'] + $this->openForm());

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame(array_merge($rBefore, ['email' => 'new@example.com']), $this->user(self::RESELLER));
	}

	// ── a balance of more than six digits ───────────────────────────

	public function testTheFormIsOpenedWithTheBalanceAsItIsStored(): void {
		$this->setBalance(self::RESELLER, 1234567);

		$rForm = $this->openForm();

		$this->assertSame('1234567', $rForm['credits_shown']);
		$this->assertSame('1234567', $rForm['credits']);
	}

	public function testALargeBalanceIsSetToTheFigureTheAdministratorTypes(): void {
		$this->setBalance(self::RESELLER, 1234567);

		$rResult = UserService::process(['credits' => '500000', 'credits_reason' => 'set'] + $this->openForm());

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame(500000.0, UserCredits::balance(self::RESELLER));
		$this->assertEquals([['target_id' => self::RESELLER, 'admin_id' => self::ADMIN, 'amount' => -734567, 'reason' => 'set']], $this->adjustments());
	}

	public function testALargeBalanceIsLeftAloneByASaveThatDoesNotChangeIt(): void {
		$this->setBalance(self::RESELLER, 1234567);

		$rResult = UserService::process(['email' => 'new@example.com'] + $this->openForm());

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame(1234567.0, UserCredits::balance(self::RESELLER));
		$this->assertSame([], $this->adjustments());
	}

	/** The admin API sets a large balance: the caller was shown none, the stored one is the reference. */
	public function testACallerThatWasShownNoBalanceSetsALargeOneToTheFigureItSends(): void {
		$this->setBalance(self::RESELLER, 1234567);
		$rForm = $this->openForm();
		unset($rForm['credits_shown']);

		$rResult = UserService::process(['credits' => '500000', 'credits_reason' => 'api'] + $rForm);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame(500000.0, UserCredits::balance(self::RESELLER));
		$this->assertEquals([['target_id' => self::RESELLER, 'admin_id' => self::ADMIN, 'amount' => -734567, 'reason' => 'api']], $this->adjustments());
	}

	/**
	 * An admin API caller sends the balance the account already has: the stored
	 * figure, or the one the panel reads with the account (what get_user answers).
	 */
	#[DataProvider('theBalanceSentBack')]
	public function testACallerThatSendsTheBalanceBackChangesNothing(bool $rAsRead): void {
		$this->setBalance(self::RESELLER, 1234567);
		$rForm = $this->openForm();
		unset($rForm['credits_shown']);
		$rSent = ($rAsRead ? (string) UserRepository::getRegisteredUserById(self::RESELLER)['credits'] : '1234567');

		$rResult = UserService::process(['credits' => $rSent, 'credits_reason' => 'api'] + $rForm);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame(1234567.0, UserCredits::balance(self::RESELLER));
		$this->assertSame([], $this->adjustments());
	}

	/** @return array<string, array{0: bool}> */
	public static function theBalanceSentBack(): array {
		return ['as it is stored' => [false], 'as it is read with the account' => [true]];
	}

	public function testANewUserStartsWithTheBalanceOfTheForm(): void {
		$rResult = UserService::process(['username' => 'another', 'password' => 'secret-secret', 'member_group_id' => '2', 'email' => '', 'owner_id' => '0', 'credits' => '25', 'credits_reason' => '', 'reseller_dns' => '', 'notes' => '', 'api_key' => str_repeat('0a', 16)]);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$rUser = $this->user((int) $rResult['data']['insert_id']);
		$this->assertSame('another', $rUser['username']);
		$this->assertEquals(25, $rUser['credits']);
	}

	// ── a reseller's form for a sub-reseller ────────────────────────

	/** The reseller takes the sub-reseller's credits back in one request while another saves its details. */
	public function testAResellerSavingASubResellerDoesNotWriteBackItsBalance(): void {
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(self::RESELLER);
		ResellerAPI::$rPermissions = [
			'create_sub_resellers' => 1, 'subresellers' => [2], 'all_reports' => [self::SUB], 'allow_change_username' => 1, 'allow_change_password' => 1,
			'minimum_username_length' => 4, 'minimum_password_length' => 4,
		];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
		$this->beforeTheUserIsWritten('UPDATE `users` SET `credits` = 0 WHERE `id` = ' . self::SUB);

		$rResult = ResellerAPI::processUser(['edit' => (string) self::SUB, 'username' => 'subreseller', 'password' => '', 'email' => 'sub@example.com', 'reseller_dns' => '', 'notes' => '', 'owner_id' => '0']);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertEquals(self::SUB, $rResult['data']['insert_id']);
		$this->assertSame('sub@example.com', $this->user(self::SUB)['email']);
		$this->assertEquals(0, $this->user(self::SUB)['credits']);
		$this->assertEquals(100, $this->user(self::RESELLER)['credits'], 'an edit is free');
	}
}
