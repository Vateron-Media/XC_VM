<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * What an activation code entitles its subscriber to is what its reseller
 * paid for: the package's term, connections, bouquets and country lock. A
 * reseller does not add days to a code, move it to another package, set its
 * expiry or connections, or return a redeemed code to stock so that it starts
 * a new term; and the codes it generates carry bouquets of the package only,
 * a selection among them when its group may change bouquets. An
 * administrator decides all of these as before.
 */
final class AuditActiveCodeEntitlementTest extends TestCase {
	private const RESELLER = 5;

	private TestDb $rDb;

	/** @var array<string, mixed> the reseller's account as its request read it */
	private array $rReseller;

	/** @var array<string, mixed> */
	private array $rAdmin = ['id' => 1, 'member_group_id' => 1];

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		defined('SERVER_ID') || define('SERVER_ID', 1);

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'lines_live', 'activation_codes', 'access_codes', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_reseller`, `allow_change_bouquets`) VALUES (2, 'Resellers', 1, 1)");
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`, `max_connections`, `forced_country`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[1,2]', '[1]', 1, 1, 'DE'), (2, 'Year', 1, 100, 12, 'months', '[2]', '[1,2,3]', '[1]', 1, 4, NULL)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'rSettings' => $GLOBALS['rSettings'] ?? null, 'settings' => SettingsManager::getAll(), 'host' => $_SERVER['HTTP_HOST'] ?? null];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1, 'server_type' => 0, 'server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443]];
		$GLOBALS['rSettings'] = ['keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'redis_handler' => 0];
		$_SERVER['HTTP_HOST'] = 'panel.test';
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach ([LineService::class, ConnectionTracker::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
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

	/** One code of the Month package: its row. $rAs generates it, the reseller unless told. */
	private function generate(array $rAsked = [], ?array $rAs = null): array {
		$rResult = ActiveCodeService::generateCodes($rAsked + ['package_id' => 1, 'num_codes' => 1], $rAs ?? $this->rReseller, $rAs !== null);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		return ActiveCodeService::getByCode($rResult['codes'][0]['code']);
	}

	/** A code the reseller bought and a subscriber redeemed. */
	private function redeemed(): array {
		$rCode = $this->generate();
		$rResult = ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9']);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message'] ?? '');
		return $this->code($rCode);
	}

	/** @return array<string, mixed> the code as stored now */
	private function code(array $rCode): array {
		return ActiveCodeService::getById((int) $rCode['id']);
	}

	/** @return array<string, mixed> the code's line as stored now */
	private function line(array $rCode): array {
		return UserRepository::getLineById($rCode['subscriber_id']);
	}

	// ── mass actions ────────────────────────────────────────────────

	public function testAResellerDoesNotAddDaysToACode(): void {
		$rCode = $this->redeemed();
		$rExpires = (int) $this->line($rCode)['exp_date'];

		foreach (['extend', 'mass_extend'] as $rAction) {
			$rResult = ActiveCodeService::massAction($rAction, [$rCode['id']], $this->rReseller, false, ['days' => 365]);

			$this->assertSame('ERROR', $rResult['status'], $rAction);
			$this->assertSame($rExpires, (int) $this->line($rCode)['exp_date'], $rAction);
		}
	}

	public function testAResellerDoesNotMoveACodeToAnotherPackage(): void {
		$rCode = $this->generate();

		foreach (['change_package', 'mass_change_package'] as $rAction) {
			$rResult = ActiveCodeService::massAction($rAction, [$rCode['id']], $this->rReseller, false, ['package_id' => 2]);

			$this->assertSame('ERROR', $rResult['status'], $rAction);
			$this->assertSame(1, (int) $this->code($rCode)['package_id'], $rAction);
			$this->assertSame(1, (int) $this->line($rCode)['package_id'], $rAction);
			$this->assertSame('[1,2]', $this->line($rCode)['bouquet'], $rAction);
		}
	}

	public function testAnAdministratorStillExtendsAndMovesCodes(): void {
		$rCode = $this->redeemed();
		$rExpires = (int) $this->line($rCode)['exp_date'];

		$this->assertSame('SUCCESS', ActiveCodeService::massAction('extend', [$rCode['id']], $this->rAdmin, true, ['days' => 30])['status']);
		$this->assertSame($rExpires + 30 * 86400, (int) $this->line($rCode)['exp_date']);

		$this->assertSame('SUCCESS', ActiveCodeService::massAction('change_package', [$rCode['id']], $this->rAdmin, true, ['package_id' => 2])['status']);
		$this->assertSame(2, (int) $this->code($rCode)['package_id']);
		$this->assertSame('[1,2,3]', $this->line($rCode)['bouquet']);
	}

	// ── the edit ────────────────────────────────────────────────────

	public function testAResellersEditLeavesWhatTheCodeWasSoldWith(): void {
		$rCode = $this->redeemed();
		$rExpires = (int) $this->line($rCode)['exp_date'];

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['batch_name' => 'Spring', 'exp_date' => (string) ($rExpires + 3650 * 86400), 'max_connections' => 50, 'package_id' => 2], $this->rReseller, false);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame('Spring', $this->code($rCode)['batch_name'], 'what a reseller may edit is stored');
		$rLine = $this->line($rCode);
		$this->assertSame($rExpires, (int) $rLine['exp_date']);
		$this->assertSame(1, (int) $rLine['max_connections']);
		$this->assertSame(1, (int) $this->code($rCode)['max_connections']);
		$this->assertSame(1, (int) $rLine['package_id']);
		$this->assertSame(1, (int) $this->code($rCode)['package_id']);
		$this->assertSame('[1,2]', $rLine['bouquet']);
	}

	public function testAResellerDoesNotReturnARedeemedCodeToStock(): void {
		$rCode = $this->redeemed();
		$this->rDb->query('UPDATE `lines` SET `exp_date` = `exp_date` - 86400 WHERE `id` = ?', $rCode['subscriber_id']);
		$rExpires = (int) $this->line($rCode)['exp_date'];

		ActiveCodeService::updateCode((int) $rCode['id'], ['status' => 1], $this->rReseller, false);

		$this->assertSame(2, (int) $this->code($rCode)['status']);
		$rAgain = ActiveCodeService::activateCode($rCode['activation_code'], ['ip' => '192.0.2.9']);
		$this->assertFalse($rAgain['is_new_activation']);
		$this->assertSame($rExpires, (int) $this->line($rCode)['exp_date'], 'the term it was sold with is not started again');
	}

	/** Suspending a code and lifting the suspension is the reseller's to do. */
	public function testAResellerStillSuspendsAndRestoresACode(): void {
		$rCode = $this->redeemed();

		ActiveCodeService::updateCode((int) $rCode['id'], ['status' => 0], $this->rReseller, false);
		$this->assertSame(0, (int) $this->code($rCode)['status']);
		$this->assertSame(0, (int) $this->line($rCode)['enabled']);

		ActiveCodeService::updateCode((int) $rCode['id'], ['status' => 1], $this->rReseller, false);
		$this->assertSame(2, (int) $this->code($rCode)['status'], 'restored as the redeemed code it is');
		$this->assertSame(1, (int) $this->line($rCode)['enabled']);
	}

	/** The rule is for a status the reseller asks for: an edit that names none keeps the stored one. */
	public function testAResellersEditKeepsACodeAnAdministratorReturnedToStock(): void {
		$rCode = $this->redeemed();
		ActiveCodeService::updateCode((int) $rCode['id'], ['status' => 1], $this->rAdmin, true);
		$this->assertSame(1, (int) $this->code($rCode)['status']);

		foreach ([['batch_name' => 'Spring'], ['mac' => '00:1A:79:00:00:01'], ['status' => 1]] as $rEdit) {
			$rResult = ActiveCodeService::updateCode((int) $rCode['id'], $rEdit, $this->rReseller, false);

			$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
			$this->assertSame(1, (int) $this->code($rCode)['status'], 'after an edit of ' . key($rEdit));
		}
		$this->assertSame('Spring', $this->code($rCode)['batch_name']);
	}

	public function testAnAdministratorsEditStillSetsThem(): void {
		$rCode = $this->redeemed();
		$rExpires = time() + 90 * 86400;

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['exp_date' => (string) $rExpires, 'max_connections' => 3, 'package_id' => 2, 'status' => 1], $this->rAdmin, true);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$rLine = $this->line($rCode);
		$this->assertSame($rExpires, (int) $rLine['exp_date']);
		$this->assertSame(3, (int) $rLine['max_connections']);
		$this->assertSame(2, (int) $rLine['package_id']);
		$this->assertSame('[1,2,3]', $rLine['bouquet']);
		$this->assertSame(1, (int) $this->code($rCode)['status'], 'an administrator re-arms a code');
	}

	// ── generation ──────────────────────────────────────────────────

	public function testAResellersCodesCarryOnlyBouquetsOfThePackage(): void {
		$rCode = $this->generate(['bouquets_selected' => ['2', '3', '9']]);

		$this->assertSame('[2]', $this->line($rCode)['bouquet']);
		$this->assertSame('[2]', $rCode['bouquets']);
	}

	public function testASelectionWithNothingOfThePackageGivesThePackage(): void {
		$rCode = $this->generate(['bouquets_selected' => ['3', '9']]);

		$this->assertSame('[1,2]', $this->line($rCode)['bouquet']);
	}

	public function testAGroupThatMayNotChangeBouquetsGetsThePackage(): void {
		$this->rDb->exec('UPDATE `users_groups` SET `allow_change_bouquets` = 0');

		$rCode = $this->generate(['bouquets_selected' => ['2']]);

		$this->assertSame('[1,2]', $this->line($rCode)['bouquet']);
	}

	public function testAResellersCodesKeepThePackagesCountry(): void {
		foreach (['', 'US'] as $rAsked) {
			$rCode = $this->generate(['forced_country' => $rAsked]);

			$this->assertSame('DE', $this->line($rCode)['forced_country'], 'asked for "' . $rAsked . '"');
			$this->assertSame('DE', $rCode['forced_country']);
		}
	}

	public function testAnAdministratorStillChoosesBouquetsAndCountry(): void {
		$rCode = $this->generate(['bouquets_selected' => ['3', '9'], 'forced_country' => 'US'], $this->rAdmin);
		$this->assertSame('[3,9]', $this->line($rCode)['bouquet']);
		$this->assertSame('US', $this->line($rCode)['forced_country']);

		$rCode = $this->generate(['forced_country' => ''], $this->rAdmin);
		$this->assertNull($this->line($rCode)['forced_country'], 'and lifts the country lock');
	}

	// ── the reseller's form ─────────────────────────────────────────

	/**
	 * The head of the reseller's form, run on what its controller hands it:
	 * every bouquet of the panel and the packages the reseller sells.
	 *
	 * @return array{0: list<int>, 1: mixed} the bouquets it lists, and whether it lets them be chosen
	 */
	private function form(int $rAllowChange): array {
		$rHead = substr(explode('?>', (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/active_code.php'), 2)[0], strlen('<?php'));
		$rKept = ['rUserInfo' => $GLOBALS['rUserInfo'] ?? null, 'rPermissions' => $GLOBALS['rPermissions'] ?? null];
		$GLOBALS['rUserInfo'] = $this->rReseller;
		$GLOBALS['rPermissions'] = ['allow_change_bouquets' => $rAllowChange];
		try {
			return (static function () use ($rHead): array {
				$rPackages = [['id' => 1, 'package_name' => 'Month', 'official_credits' => 10, 'official_duration' => 1, 'official_duration_in' => 'months', 'max_connections' => 1, 'bouquets' => '[1,2]'], ['id' => 2, 'package_name' => 'Year', 'official_credits' => 100, 'official_duration' => 12, 'official_duration_in' => 'months', 'max_connections' => 4, 'bouquets' => '[1,2,3]']];
				$rBouquets = array_map(static fn(int $rID): array => ['id' => $rID, 'bouquet_name' => 'Bouquet ' . $rID], [1, 2, 3, 8, 9]);
				eval($rHead);
				return [array_map('intval', array_column($rBouquets, 'id')), $allowBouquetChange ?? null];
			})();
		} finally {
			$GLOBALS['rUserInfo'] = $rKept['rUserInfo'];
			$GLOBALS['rPermissions'] = $rKept['rPermissions'];
		}
	}

	public function testTheResellersFormListsTheBouquetsOfItsPackages(): void {
		[$rListed, $rChoice] = $this->form(1);

		$this->assertSame([1, 2, 3], $rListed, 'not the bouquets of packages it does not sell');
		$this->assertTrue($rChoice);
	}

	public function testTheFormOffersNoChoiceToAGroupThatMayNotChangeBouquets(): void {
		$this->assertFalse($this->form(0)[1]);

		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/active_code.php');
		$rGate = strpos($rView, '<?php if ($allowBouquetChange): ?>');
		$this->assertNotFalse($rGate);
		$this->assertGreaterThan($rGate, strpos($rView, 'name="bouquets_selected[]"'), 'the choice is rendered behind it');
		$this->assertSame(1, substr_count($rView, 'name="bouquets_selected[]"'));
	}

	/** The country lock comes with the package: the reseller's form asks for none. */
	public function testTheResellersFormAsksForNoCountry(): void {
		$this->assertStringNotContainsString('name="forced_country"', (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/active_code.php'));
	}

	/** Adding days to a code or moving it to another package is not offered where it is refused. */
	public function testTheResellersListOffersNeitherExtensionNorAnotherPackage(): void {
		$rView = (string) file_get_contents(MAIN_HOME . 'Public/Views/reseller/active_codes.php');

		foreach (['mass_extend', 'btn-mass-extend', 'extendModal', 'change_package'] as $rControl) {
			$this->assertStringNotContainsString($rControl, $rView);
		}
	}
}
