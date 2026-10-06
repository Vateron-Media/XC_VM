<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

/**
 * Enabling activation codes lifts a suspension: a code that is not suspended
 * keeps the status it has, so a redeemed code an administrator returned to
 * stock stays in stock when it is in a selection that is enabled. Renaming a
 * code renames the line that carries the code as its username, and no two
 * lines share a username: a code is not renamed to the username of another
 * line.
 */
final class AuditResellerCoreCodeEditTest extends TestCase {
	private const RESELLER = 5;

	private const SERVICES = [LineService::class, ConnectionTracker::class, MagService::class, EnigmaService::class];

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
		foreach (['users', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'lines_live', 'activation_codes', 'access_codes', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[]', '[1]', 1)");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");
		// A line of the panel that carries no code.
		$this->rDb->exec("INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `created_at`) VALUES (900, 5, 'LIVINGROOM', 'secret', 1700000000)");

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'rSettings' => $GLOBALS['rSettings'] ?? null, 'settings' => SettingsManager::getAll(), 'host' => $_SERVER['HTTP_HOST'] ?? null];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1, 'server_type' => 0, 'server_protocol' => 'http', 'enable_proxy' => 0, 'domain_name' => 'panel.test', 'server_ip' => '192.0.2.1', 'http_broadcast_port' => 80, 'https_broadcast_port' => 443]];
		$GLOBALS['rSettings'] = ['keep_protocol' => 0, 'use_mdomain_in_lists' => 0, 'redis_handler' => 0];
		$_SERVER['HTTP_HOST'] = 'panel.test';
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		foreach (self::SERVICES as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
		$this->rReseller = UserRepository::getRegisteredUserById(self::RESELLER) + ['reports' => [self::RESELLER]];
	}

	protected function tearDown(): void {
		foreach (self::SERVICES as $rClass) {
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

	/**
	 * The reseller buys one code: its row.
	 *
	 * @param array<string, mixed> $rForm
	 * @return array<string, mixed>
	 */
	private function buy(array $rForm = []): array {
		$rResult = ActiveCodeService::generateCodes($rForm + ['package_id' => 1, 'num_codes' => 1], $this->rReseller, false);
		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		return ActiveCodeService::getByCode($rResult['codes'][0]['code']);
	}

	/** @return array<string, mixed> the code as stored */
	private function code(array $rCode): array {
		return ActiveCodeService::getById((int) $rCode['id']);
	}

	private function username(array $rCode): string {
		return (string) UserRepository::getLineById($rCode['subscriber_id'])['username'];
	}

	/** @return array<string, array{0: bool}> who presses Enable */
	public static function users(): array {
		return ['a reseller' => [false], 'an administrator' => [true]];
	}

	// ── enabling a selection ────────────────────────────────────────

	#[DataProvider('users')]
	public function testEnablingASelectionLeavesACodeReturnedToStockInStock(bool $rIsAdmin): void {
		$rReturned = $this->buy();
		ActiveCodeService::activateCode($rReturned['activation_code'], ['ip' => '192.0.2.9']);
		ActiveCodeService::updateCode((int) $rReturned['id'], ['status' => 1], $this->rAdmin, true);
		$this->assertSame(1, (int) $this->code($rReturned)['status']);
		$this->assertNotNull($this->code($rReturned)['activated_at'], 'it was redeemed once');

		$rSuspended = $this->buy();
		ActiveCodeService::activateCode($rSuspended['activation_code'], ['ip' => '192.0.2.9']);
		ActiveCodeService::massAction('disable', [$rSuspended['id']], $this->rAdmin, true);
		$rUnsold = $this->buy();
		ActiveCodeService::massAction('disable', [$rUnsold['id']], $this->rAdmin, true);

		$rResult = ActiveCodeService::massAction('enable', [$rReturned['id'], $rSuspended['id'], $rUnsold['id']], $rIsAdmin ? $this->rAdmin : $this->rReseller, $rIsAdmin);

		$this->assertSame('SUCCESS', $rResult['status']);
		$this->assertSame(1, (int) $this->code($rReturned)['status'], 'the code returned to stock');
		$this->assertSame(2, (int) $this->code($rSuspended)['status'], 'a suspended code that was redeemed is active again');
		$this->assertSame(1, (int) $this->code($rUnsold)['status'], 'a suspended code nobody redeemed is back in stock');
	}

	// ── renaming a code ─────────────────────────────────────────────

	#[DataProvider('users')]
	public function testACodeIsNotRenamedToTheUsernameOfAnotherLine(bool $rIsAdmin): void {
		$rCode = $this->buy();
		$rUsername = $this->username($rCode);

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['activation_code' => 'LIVINGROOM'], $rIsAdmin ? $this->rAdmin : $this->rReseller, $rIsAdmin);

		$this->rDb->query("SELECT COUNT(*) FROM `lines` WHERE `username` = 'LIVINGROOM'");
		$this->assertSame(1, (int) $this->rDb->get_col(), 'one line has the username');
		$this->assertSame('ERROR', $rResult['status']);
		$this->assertSame($rUsername, $this->username($rCode));
		$this->assertSame($rCode['activation_code'], $this->code($rCode)['activation_code'], 'and the code keeps its name');
		$this->assertFalse($this->rDb->isInTransaction());
	}

	/** The username is compared as the panel compares usernames when a line signs in. */
	public function testNorToThatUsernameInAnotherCase(): void {
		$rCode = $this->buy();

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['activation_code' => 'LivingRoom'], $this->rAdmin, true);

		$this->assertSame('ERROR', $rResult['status']);
		$this->rDb->query("SELECT COUNT(*) FROM `lines` WHERE `username` = 'LIVINGROOM'");
		$this->assertSame(1, (int) $this->rDb->get_col());
	}

	public function testACodeIsRenamedToANameNoLineHas(): void {
		$rCode = $this->buy();

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['activation_code' => 'KITCHEN22'], $this->rReseller, false);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame('KITCHEN22', $this->code($rCode)['activation_code']);
		$this->assertSame('KITCHEN22', $this->username($rCode));
	}

	/** Renamed once, the line carries the code as its username: the code is renamed again, and to its own name in another case. */
	public function testACodeWhoseLineCarriesItIsRenamedAgain(): void {
		$rCode = $this->buy();
		ActiveCodeService::updateCode((int) $rCode['id'], ['activation_code' => 'KITCHEN22'], $this->rAdmin, true);

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['activation_code' => 'KITCHEN23'], $this->rAdmin, true);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame('KITCHEN23', $this->username($rCode));
	}

	/** A line with a username of its own does not take the code's name: the code is free to have any. */
	public function testACodeWhoseLineKeepsItsOwnUsernameIsRenamedFreely(): void {
		$rCode = $this->buy(['streaming_username' => 'bedroom.tv']);

		$rResult = ActiveCodeService::updateCode((int) $rCode['id'], ['activation_code' => 'LIVINGROOM'], $this->rAdmin, true);

		$this->assertSame('SUCCESS', $rResult['status'], $rResult['message']);
		$this->assertSame('LIVINGROOM', $this->code($rCode)['activation_code']);
		$this->assertSame('bedroom.tv', $this->username($rCode));
	}
}
