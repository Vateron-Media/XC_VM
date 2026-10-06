<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Administrator accounts and administrator groups are a full administrator's
 * to manage (GroupService::reservedGroups), and a page offers an administrator
 * held to a permission list only what a save takes from it: no administrator
 * group to give a user or a reseller group, no change or deletion of one, and
 * no row action on an administrator's account. The same for a reseller with an
 * administrator's account in its tree. A full administrator is offered all.
 *
 * A reseller's forms follow the trial rules of the save as well: a new trial
 * has no owner to pick, and a trial package cannot be picked for activation
 * codes while the trial allowance is used up.
 *
 * A view is a template: it is rendered in a child PHP, without the layout
 * around it, over a panel with these groups and users.
 */
final class AuditAdminUsersViewsTest extends TestCase {
	private const ADMIN = 1;
	private const SUPPORT = 2;
	private const MANAGER = 3;
	private const RESELLER = 5;

	/** The groups: 1, 3, 4 and 8 are administrator groups (8 is a reseller group too), 3 has a permission list. */
	private const GROUPS = [1, 2, 3, 4, 7, 8];
	private const OTHER_GROUPS = [2, 7];

	/** A trial line of the reseller, as its form is opened for it, and the device of such a line. */
	private const LINE = ['id' => 7, 'member_id' => self::RESELLER, 'is_trial' => 1, 'username' => 'line', 'password' => 'secret', 'package_id' => 0, 'bouquet' => '[]', 'exp_date' => null, 'max_connections' => 1, 'contact' => '', 'reseller_notes' => '', 'custom_data' => '', 'allowed_ips' => '[]', 'allowed_ua' => '[]', 'bypass_ua' => 0, 'is_isplock' => 0, 'isp_desc' => ''];
	private const DEVICE = ['mag_id' => 3, 'device_id' => 3, 'mac' => '00:1A:79:00:00:01', 'parent_password' => '', 'sn' => '', 'stb_type' => '', 'image_version' => '', 'hw_version' => '', 'device_id2' => '', 'ver' => '', 'modem_mac' => '', 'local_ip' => '', 'enigma_version' => '', 'cpu' => '', 'lversion' => '', 'token' => ''];

	private const CHILD = <<<'PHP'
<?php
namespace XcVm\Core\Util {
	/** The view closes the layout itself: there is none around it here. */
	final class LayoutRenderer {
		public static function renderFooter(string $rScope = 'admin', array $rVars = []): void {
		}
	}
}

namespace {
	/** A view's `$language`: every text is its key. */
	final class AuditAdminUsersViewsLanguage {
		public static function get(string $rKey, array $rReplace = []): string {
			return $rKey;
		}
	}

	require getenv('XCVM_TEST_BOOTSTRAP');
	foreach (\XcVm\Core\Config\ConstantsInitializer::statuses() as $rName => $rValue) {
		defined($rName) || define($rName, $rValue);
	}

	$rIn = json_decode($argv[1], true);
	// Texts a view takes from the translator itself are looked up in a directory of this test.
	\XcVm\Core\Localization\Translator::init($rIn['dir']);

	$db = new \TestDb();
	foreach (['users', 'users_groups', 'users_packages'] as $rTable) {
		$db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));
	}
	$db->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`, `can_delete`, `subresellers`, `create_sub_resellers`, `delete_users`) VALUES"
		. " (1, 'Administrators', 1, 0, '[]', 0, '[]', 0, 0), (2, 'Resellers', 0, 1, '[]', 0, '[2,8]', 1, 1),"
		. " (3, 'Support', 1, 0, '[\"mng_regusers\",\"add_reguser\",\"edit_reguser\",\"mass_edit_users\",\"mng_groups\",\"add_group\",\"edit_group\"]', 1, '[]', 0, 0),"
		. " (4, 'Managers', 1, 0, '[]', 1, '[]', 0, 0), (7, 'Agents', 0, 1, '[]', 1, '[]', 0, 0), (8, 'Staff', 1, 1, '[\"users\"]', 1, '[]', 0, 0)");
	$db->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `status`, `email`, `notes`, `reseller_dns`, `override_packages`) VALUES"
		. " (1, 'admin', 1, 0, 0, 1, '', '', '', '[]'), (2, 'support', 3, 0, 0, 1, '', '', '', '[]'), (3, 'manager', 4, 0, 0, 1, '', '', '', '[]'),"
		. " (5, 'reseller', 2, 100, 0, 1, '', '', '', '[]'), (6, 'sub', 2, 10, 5, 1, '', '', '', '[]'), (9, 'staff', 8, 0, 5, 1, '', '', '', '[]')");
	\XcVm\Infrastructure\Database\DatabaseFactory::set($db);

	// What the panel leaves a page: the user whose request it is, and its group's permissions.
	$rUserInfo = \XcVm\Domain\User\UserRepository::getRegisteredUserById($rIn['actor']);
	$rPermissions = \XcVm\Core\Auth\AuthRepository::getPermissions((int) $rUserInfo['member_group_id']);
	$rPermissions['advanced'] = json_decode($rPermissions['allowed_pages'], true);
	if ($rPermissions['is_reseller']) {
		$rPermissions['users'] = \XcVm\Domain\User\UserRepository::getSubUsers($rUserInfo['id']);
		$rPermissions['direct_reports'] = $rPermissions['all_reports'] = array_keys($rPermissions['users']);
	}
	$rSettings = ['default_entries' => 25, 'disable_trial' => 0];
	$language = AuditAdminUsersViewsLanguage::class;
	$rRequest = $rIn['request'] ?? [];
	$rGenTrials = $rIn['trials'] ?? true;
	$categoryTemplates = [];
	$rBouquets = [];

	// What the page's controller hands the view.
	$rPackages = $rIn['packages'] ?? [];
	$rGroupIDs = $rPackageIDs = [];
	$rNotice = '';
	if (isset($rIn['user'])) {
		$rUser = \XcVm\Domain\User\UserRepository::getRegisteredUserById($rIn['user']);
	}
	if (isset($rIn['group'])) {
		$rGroup = \XcVm\Domain\User\GroupService::getById($rIn['group']);
	}
	if (isset($rIn['line'])) {
		$rLine = $rIn['line'];
		$rDevice = $rIn['device'];
	}

	require MAIN_HOME . 'Public/Views/' . $rIn['view'] . '.php';
}
PHP;

	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-admin-users-views-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'lang', 0700, true);
		file_put_contents($this->rDir . 'lang/en.ini', '');
		file_put_contents($this->rDir . 'run.php', self::CHILD);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * A page as the panel shows it to a user.
	 *
	 * @param array<string, mixed> $rShown the user or the group the page is opened for, or the request it is opened with
	 */
	private function page(string $rView, int $rActor, array $rShown = []): string {
		$rIn = ['view' => $rView, 'actor' => $rActor, 'dir' => $this->rDir . 'lang'] + $rShown;
		$rProc = proc_open([...xcvm_test_child_php(), '-d', 'display_errors=stderr', '-d', 'short_open_tag=1', $this->rDir . 'run.php', (string) json_encode($rIn)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes, $this->rDir, ['XCVM_TEST_BOOTSTRAP' => dirname(__DIR__) . '/bootstrap.php', 'PATH' => (string) getenv('PATH')] + TestDb::env());
		$this->assertIsResource($rProc);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = (string) stream_get_contents($rPipes[2]);
		proc_close($rProc);
		$this->assertSame('', $rErr, $rView);
		$this->assertStringContainsString('</html>', $rOut, $rView . ' was rendered to its end');
		return $rOut;
	}

	/** @return list<int> the numbers $rPattern finds in a page, in order */
	private static function found(string $rPattern, string $rPage): array {
		preg_match_all($rPattern, $rPage, $rFound);
		return array_map('intval', $rFound[1]);
	}

	/** @return list<int> the groups a page's member group select offers */
	private static function groupsOffered(string $rPage): array {
		preg_match('/<select[^>]*name="member_group_id".*?<\/select>/s', $rPage, $rSelect);
		return self::found('/<option value="(\d+)"/', $rSelect[0] ?? '');
	}

	/** @return list<int> what a page's script holds in the list it calls $rName */
	private static function scriptList(string $rName, string $rPage): array {
		preg_match('/var ' . $rName . ' = (\[[^\]]*\]);/', $rPage, $rList);
		self::assertNotEmpty($rList, 'the page has a list ' . $rName);
		return array_map('intval', json_decode($rList[1], true));
	}

	/** @return array<string, array{0: int}> */
	public static function fullAdministrators(): array {
		return ['a member of the first group' => [self::ADMIN], 'a member of an administrator group without a list' => [self::MANAGER]];
	}

	// ── an administrator held to a permission list ──────────────────

	public function testTheUserFormOffersNoAdministratorGroup(): void {
		$this->assertSame(self::OTHER_GROUPS, self::groupsOffered($this->page('admin/user', self::SUPPORT)));
		$this->assertSame(self::OTHER_GROUPS, self::groupsOffered($this->page('admin/user', self::SUPPORT, ['user' => self::RESELLER])));
	}

	/** The form of an administrator's account shows the group it is in, and offers no other administrator group. */
	public function testTheUserFormShowsTheGroupTheUserIsIn(): void {
		$rPage = $this->page('admin/user', self::SUPPORT, ['user' => self::MANAGER]);

		$this->assertSame([2, 4, 7], self::groupsOffered($rPage));
		$this->assertMatchesRegularExpression('/<option value="4" selected>/', $rPage);
	}

	public function testMassEditUsersOffersNoAdministratorGroup(): void {
		$this->assertSame(self::OTHER_GROUPS, self::groupsOffered($this->page('admin/user_mass', self::SUPPORT)));
	}

	public function testTheUsersListOffersNoRowActionOnAnAdministratorsAccount(): void {
		$rPage = $this->page('admin/users', self::SUPPORT);

		$this->assertSame([1, 3, 4, 8], self::scriptList('reservedGroups', $rPage));
		// A row of one of those groups gets what a row gets without the permission to edit: its note.
		$this->assertStringContainsString('if (!canEdit || reservedGroups.indexOf(Number(row.member_group_id)) !== -1) {', $rPage);
	}

	public function testTheGroupsListOffersNoChangeOfAnAdministratorGroup(): void {
		$rPage = $this->page('admin/groups', self::SUPPORT);

		$this->assertSame(self::GROUPS, self::found('/<td class="text-center">(\d+)<\/td>/', $rPage), 'every group is listed');
		$this->assertSame(self::OTHER_GROUPS, self::found('/href="group\?id=(\d+)"/', $rPage));
		$this->assertSame([7], self::found('/js-del" data-id="(\d+)"/', $rPage));
	}

	public function testTheGroupFormOffersNoAdministratorGroupToSubResellers(): void {
		$this->assertSame([2], self::found('/group-subreseller-cb" type="checkbox" value="(\d+)"/', $this->page('admin/group', self::SUPPORT, ['group' => 7])));
		$this->assertSame([2, 7], self::found('/group-subreseller-cb" type="checkbox" value="(\d+)"/', $this->page('admin/group', self::SUPPORT)));
	}

	/** An administrator group is made by a full administrator: the group form leaves that switch to one. */
	public function testTheGroupFormLeavesTheAdministratorSwitchToAFullAdministrator(): void {
		$rSwitch = static function (string $rPage): string {
			preg_match('/<input[^>]*id="is_admin"[^>]*>/', $rPage, $rInput);
			self::assertNotEmpty($rInput, 'the form has the switch');
			return $rInput[0];
		};

		$this->assertStringContainsString('disabled', $rSwitch($this->page('admin/group', self::SUPPORT)));
		$this->assertStringContainsString('disabled', $rSwitch($this->page('admin/group', self::SUPPORT, ['group' => 7])));

		foreach (self::fullAdministrators() as [$rActor]) {
			$this->assertStringNotContainsString('disabled', $rSwitch($this->page('admin/group', $rActor)));
			$this->assertStringNotContainsString('disabled', $rSwitch($this->page('admin/group', $rActor, ['group' => 7])));
		}
	}

	// ── a full administrator ────────────────────────────────────────

	#[DataProvider('fullAdministrators')]
	public function testAFullAdministratorIsOfferedEveryGroupAndEveryAction(int $rActor): void {
		$this->assertSame(self::GROUPS, self::groupsOffered($this->page('admin/user', $rActor)));
		$this->assertSame(self::GROUPS, self::groupsOffered($this->page('admin/user_mass', $rActor)));
		$this->assertSame([], self::scriptList('reservedGroups', $this->page('admin/users', $rActor)));

		$rPage = $this->page('admin/groups', $rActor);
		$this->assertSame(self::GROUPS, self::found('/href="group\?id=(\d+)"/', $rPage));
		$this->assertSame([3, 4, 7, 8], self::found('/js-del" data-id="(\d+)"/', $rPage), 'every group that can be deleted');

		$this->assertSame([2, 8], self::found('/group-subreseller-cb" type="checkbox" value="(\d+)"/', $this->page('admin/group', $rActor, ['group' => 7])));
	}

	// ── a reseller ──────────────────────────────────────────────────

	/** @return array<string, array{0: string}> */
	public static function lineForms(): array {
		return ['a line' => ['reseller/line'], 'a MAG device' => ['reseller/mag'], 'an Enigma device' => ['reseller/enigma']];
	}

	/** A new trial is held by the reseller that makes it: its form has no owner to pick. */
	#[DataProvider('lineForms')]
	public function testTheFormOfANewTrialOffersNoOwner(string $rView): void {
		$rTrial = $this->page($rView, self::RESELLER, ['request' => ['trial' => '1']]);

		$this->assertStringContainsString('name="trial" value="1"', $rTrial);
		$this->assertStringNotContainsString('name="member_id"', $rTrial);

		$this->assertStringContainsString('name="member_id"', $this->page($rView, self::RESELLER), 'a line that is sold has one');
	}

	/** A trial stays with its holder until a package makes it official: under the owner, its form says so. */
	#[DataProvider('lineForms')]
	public function testTheFormOfATrialLineSaysWhenItsOwnerApplies(string $rView): void {
		$rTrial = $this->page($rView, self::RESELLER, ['line' => self::LINE, 'device' => self::DEVICE]);

		$this->assertStringContainsString('name="edit" value="', $rTrial);
		$this->assertStringContainsString('name="member_id"', $rTrial, 'a package bought in the same save gives it the owner picked');
		$this->assertStringContainsString('trial_owner_kept', $rTrial);

		$rSold = $this->page($rView, self::RESELLER, ['line' => ['is_trial' => 0] + self::LINE, 'device' => self::DEVICE]);
		$this->assertStringContainsString('name="member_id"', $rSold);
		$this->assertStringNotContainsString('trial_owner_kept', $rSold, 'a line that is sold takes the owner picked');
	}

	/** A trial package is offered while the reseller's trial allowance takes another trial (LineService::canGenerateTrials). */
	public function testTheCodeFormOffersATrialPackageWhileTrialsAreAllowed(): void {
		$rPackage = ['package_name' => 'Month', 'is_trial' => 0, 'is_official' => 1, 'trial_credits' => 0, 'official_credits' => 5, 'trial_duration' => 1, 'trial_duration_in' => 'days', 'official_duration' => 1, 'official_duration_in' => 'months', 'max_connections' => 1, 'forced_country' => '', 'bouquets' => '[]'];
		$rPackages = [['id' => 1] + $rPackage, ['id' => 2, 'package_name' => 'Day', 'is_trial' => 1, 'is_official' => 0] + $rPackage];
		$rOffered = static fn(string $rPage): array => self::found('/<option value="(\d+)" data-cost="[^"]*" data-trial="\d">/', $rPage);

		$rPage = $this->page('reseller/active_code', self::RESELLER, ['packages' => $rPackages, 'trials' => false]);
		$this->assertSame([1], $rOffered($rPage));
		$this->assertSame([2], self::found('/<option value="(\d+)" data-cost="[^"]*" data-trial="1" disabled>/', $rPage), 'the trial package is shown, and cannot be picked');

		$this->assertSame([1, 2], $rOffered($this->page('reseller/active_code', self::RESELLER, ['packages' => $rPackages, 'trials' => true])));
	}

	public function testAResellersUsersListOffersNoRowActionOnAnAdministratorsAccount(): void {
		$rPage = $this->page('reseller/users', self::RESELLER);

		$this->assertSame([9], self::scriptList('reservedUsers', $rPage), 'the administrator account in its tree');
		$this->assertStringContainsString('if (reservedUsers.indexOf(Number(row.id)) !== -1) {', $rPage);

		$this->assertSame([], self::scriptList('reservedUsers', $this->page('reseller/users', 6)), 'a tree without one');
	}
}
