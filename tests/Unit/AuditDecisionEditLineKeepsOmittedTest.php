<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\AdminApiRegistry;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Api\AdminApiController;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;
use XcVm\Tests\Support\InstallSchema;

/**
 * An edit of a line through the admin API keeps every field the request
 * leaves out: the line form posts all of them, a request of the API only the
 * ones it changes. A request clears a field by sending it empty, and switches
 * one of the line's five switches off with 0 as well.
 */
final class AuditDecisionEditLineKeepsOmittedTest extends TestCase {
	private const KEY = '11111111111111111111111111111111';

	private const LINE = 8;

	private const SWITCHES = ['is_stalker', 'is_restreamer', 'is_trial', 'is_isplock', 'bypass_ua'];

	private TestDb $rDb;

	/** @var array<string, mixed> what this test replaced, put back after it */
	private array $rBefore = [];

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $rName => $rValue) {
			defined($rName) || define($rName, $rValue);
		}

		$this->rDb = new TestDb();
		foreach (['users', 'users_groups', 'lines', 'lines_live', 'bouquets', 'signals'] as $rTable) {
			$this->rDb->exec(InstallSchema::table($rTable));
		}
		$this->rDb->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->rDb->exec("INSERT INTO `users_groups` (`group_id`, `group_name`, `is_admin`, `is_reseller`, `allowed_pages`) VALUES (1, 'Administrators', 1, 0, '[]')");
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `timezone`, `api_key`) VALUES (1, 'admin', 1, 1, '', '" . self::KEY . "')");
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_order`) VALUES (1, 'One', 1), (2, 'Two', 2), (3, 'Three', 3)");
		// A line with a value of its own in every field of the form: owned by
		// another user, disabled and banned, with an expiry, a locked ISP and
		// each switch on.
		$this->rDb->query(
			'INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `exp_date`, `admin_enabled`, `enabled`, `admin_notes`, `reseller_notes`, `bouquet`, `allowed_outputs`, `max_connections`,'
			. ' `is_restreamer`, `is_trial`, `is_stalker`, `is_isplock`, `bypass_ua`, `allowed_ips`, `allowed_ua`, `created_at`, `force_server_id`, `as_number`, `isp_desc`, `forced_country`,'
			. ' `package_id`, `access_token`, `contact`, `custom_data`) VALUES (?, 5, ?, ?, 1900000000, 0, 0, ?, ?, ?, ?, 4, 1, 1, 1, 1, 1, ?, ?, 1700000000, 3, ?, ?, ?, 7, ?, ?, ?)',
			self::LINE,
			'plainname',
			'plainpass',
			'for the admin',
			'for the reseller',
			'[2,3]',
			'[1,2]',
			'["192.0.2.7"]',
			'["VLC"]',
			'AS64500',
			'Example ISP',
			'DE',
			'0123456789abcdef0123456789abcdef',
			'+491234567890',
			'{"live_cat":{"hide_ids":[4]}}'
		);

		$this->rBefore = ['rServers' => $GLOBALS['rServers'] ?? null, 'settings' => SettingsManager::getAll(), 'request' => RequestManager::getAll()];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1]];
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0, 'cluster_kill_on_line_disable' => 1]);

		$GLOBALS['db'] = $this->rDb;
		DatabaseFactory::set($this->rDb);
		AdminApiRegistry::reset();
		foreach ([BouquetService::class, LineService::class, ConnectionTracker::class, MagService::class] as $rClass) {
			$rOwn = new ReflectionProperty($rClass, 'db');
			$this->rBefore[$rClass] = $rOwn->getValue();
			$rOwn->setValue(null, $this->rDb);
		}
	}

	protected function tearDown(): void {
		foreach ([BouquetService::class, LineService::class, ConnectionTracker::class, MagService::class] as $rClass) {
			(new ReflectionProperty($rClass, 'db'))->setValue(null, $this->rBefore[$rClass]);
		}
		RequestManager::set($this->rBefore['request']);
		AdminApiRegistry::reset();
		DatabaseFactory::reset();
		SettingsManager::set($this->rBefore['settings']);
		AdminAPIWrapper::$db = null;
		AdminAPIWrapper::$rKey = null;
		$GLOBALS['rServers'] = $this->rBefore['rServers'];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rAdminUserInfo'], $GLOBALS['rPermissions'], $GLOBALS['_ERRORS']);
	}

	/**
	 * Edit the line through the API with these fields and no other: the status of the answer.
	 *
	 * @param array<string, mixed> $rFields
	 */
	private function edit(array $rFields): ?string {
		RequestManager::set(['api_key' => self::KEY, 'action' => 'edit_line', 'id' => (string) self::LINE] + $rFields);
		ob_start();
		try {
			(new AdminApiController())->index();
		} finally {
			$rBody = (string) ob_get_clean();
		}
		return json_decode($rBody, true)['status'] ?? null;
	}

	/** @return array<string, mixed> every column of the line as stored */
	private function line(): array {
		$this->rDb->query('SELECT * FROM `lines` WHERE `id` = ?', self::LINE);
		return $this->rDb->get_raw_row() ?? [];
	}

	public function testAnEditThatSendsOneFieldKeepsEveryOther(): void {
		$rStored = $this->line();

		$this->assertSame('STATUS_SUCCESS', $this->edit(['admin_notes' => 'changed']));

		$this->assertEquals(['admin_notes' => 'changed'] + $rStored, $this->line());
	}

	public function testAnEditThatSendsTheBouquetsKeepsTheExpiryTheSwitchesAndTheLists(): void {
		$rStored = $this->line();

		$this->assertSame('STATUS_SUCCESS', $this->edit(['bouquets_selected' => '[2]']));

		$this->assertEquals(['bouquet' => '[2]'] + $rStored, $this->line());
	}

	public function testALineWithoutAnExpiryKeepsNone(): void {
		$this->rDb->query('UPDATE `lines` SET `exp_date` = NULL WHERE `id` = ?', self::LINE);

		$this->assertSame('STATUS_SUCCESS', $this->edit(['max_connections' => '2']));

		$this->assertSame([null, 2], [$this->line()['exp_date'], (int) $this->line()['max_connections']]);
	}

	/** @return array<string, array{string}> */
	public static function switches(): array {
		return array_combine(self::SWITCHES, array_map(static fn(string $rSwitch): array => [$rSwitch], self::SWITCHES));
	}

	#[DataProvider('switches')]
	public function testASwitchIsOffAtZeroOrEmptyAndOnAtOne(string $rSwitch): void {
		$rStored = $this->line();

		$this->assertSame('STATUS_SUCCESS', $this->edit([$rSwitch => '0']));
		$this->assertEquals([$rSwitch => 0] + $rStored, $this->line(), 'off at 0, the other switches as they were');

		$this->assertSame('STATUS_SUCCESS', $this->edit([$rSwitch => '1']));
		$this->assertEquals($rStored, $this->line(), 'on again at 1');

		$this->assertSame('STATUS_SUCCESS', $this->edit([$rSwitch => '']));
		$this->assertEquals([$rSwitch => 0] + $rStored, $this->line(), 'off when sent empty');
	}

	public function testAFieldSentEmptyIsCleared(): void {
		$rStored = $this->line();

		$this->assertSame('STATUS_SUCCESS', $this->edit(['exp_date' => '', 'bouquets_selected' => '', 'allowed_ips' => '', 'allowed_ua' => '', 'access_output' => '', 'isp_clear' => '', 'admin_notes' => '']));

		$this->assertEquals(
			['exp_date' => null, 'bouquet' => '[]', 'allowed_ips' => '[]', 'allowed_ua' => '[]', 'allowed_outputs' => '[]', 'isp_desc' => '', 'as_number' => null, 'admin_notes' => ''] + $rStored,
			$this->line()
		);
	}

	public function testAFieldThatIsSentIsStored(): void {
		$rStored = $this->line();

		$this->assertSame('STATUS_SUCCESS', $this->edit([
			'exp_date' => '2031-02-03 04:05:06',
			'bouquets_selected' => '[3,1]',
			'allowed_ips' => ['198.51.100.9'],
			'allowed_ua' => ['Kodi'],
			'access_output' => ['2'],
			'max_connections' => '9',
			'enabled' => '1',
			'admin_enabled' => '1',
		]));

		$this->assertEquals(
			['exp_date' => (new DateTime('2031-02-03 04:05:06'))->format('U'), 'bouquet' => '[1,3]', 'allowed_ips' => '["198.51.100.9"]', 'allowed_ua' => '["Kodi"]', 'allowed_outputs' => '[2]', 'max_connections' => 9, 'enabled' => 1, 'admin_enabled' => 1] + $rStored,
			$this->line()
		);
	}

	public function testNeverExpiresStillRemovesTheExpiry(): void {
		$this->assertSame('STATUS_SUCCESS', $this->edit(['no_expire' => '1']));

		$this->assertNull($this->line()['exp_date']);
	}

	public function testNeverExpiresSentOffLeavesTheDateInForce(): void {
		foreach (['0', ''] as $rOff) {
			$this->assertSame('STATUS_SUCCESS', $this->edit(['no_expire' => $rOff, 'exp_date' => '2031-02-03 04:05:06']));

			$this->assertSame((new DateTime('2031-02-03 04:05:06'))->format('U'), (string) $this->line()['exp_date']);
		}
	}
}
