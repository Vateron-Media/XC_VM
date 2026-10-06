<?php

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
 * An administrator's edit of a line changes what it sends and nothing else.
 * The line form has no field for `enabled` or `admin_enabled`: saving a
 * disabled or banned line from it keeps the line off, while a new line
 * starts on. What an edit does not send keeps the value the line holds as
 * stored, text with < and > included. A line without an owner (its owner
 * was deleted) keeps none until a request names one.
 */
final class AuditDecisionAdminLineEditTest extends TestCase {
	private const KEY = '11111111111111111111111111111111';

	private const ADMIN = 1;
	private const OWNER = 5;
	private const LINE = 8;

	/** What the line holds in its text columns, as stored. */
	private const TEXT = [
		'admin_notes' => 'keep <b>this</b> & that',
		'reseller_notes' => 'a -> b <c>',
		'contact' => '<+49 123>',
		'isp_desc' => 'ISP <Example>',
		'custom_data' => '{"live_cat":{"renamed":{"4":"News <HD>"}}}',
		'last_activity_array' => '{"user_agent":"Box <1.0>"}',
	];

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
		$this->rDb->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `status`, `timezone`, `api_key`) VALUES (1, 'admin', 1, 1, '', '" . self::KEY . "'), (5, 'owner', 1, 1, '', NULL)");
		$this->rDb->exec("INSERT INTO `bouquets` (`id`, `bouquet_name`, `bouquet_order`) VALUES (1, 'One', 1)");
		// A disabled and banned line of the owner, with text of its own in every text column.
		$this->rDb->query(
			'INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `exp_date`, `admin_enabled`, `enabled`, `bouquet`, `allowed_outputs`, `max_connections`, `allowed_ips`, `allowed_ua`, `created_at`, `as_number`, `'
			. implode('`, `', array_keys(self::TEXT)) . '`) VALUES (?, ?, ?, ?, NULL, 0, 0, ?, ?, 1, ?, ?, 1700000000, ?, ?, ?, ?, ?, ?, ?)',
			self::LINE,
			self::OWNER,
			'plainname',
			'plainpass',
			'[1]',
			'[1]',
			'[]',
			'[]',
			'AS64500',
			...array_values(self::TEXT)
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
	 * The administrator's line form saved as the panel posts it: every field
	 * of the form, a switch only when it is on. $rFields fills it in.
	 *
	 * @param array<string, mixed> $rFields
	 * @return array<string, mixed> the answer
	 */
	private function form(array $rFields): array {
		$GLOBALS['rUserInfo'] = ['id' => self::ADMIN, 'member_group_id' => 1];
		$GLOBALS['rAdminUserInfo'] = $GLOBALS['rUserInfo'];
		$GLOBALS['rPermissions'] = ['is_admin' => 1, 'advanced' => []];
		return LineService::process($rFields + [
			'bouquets_selected' => '[1]',
			'username' => 'plainname',
			'password' => 'plainpass',
			'member_id' => (string) self::OWNER,
			'exp_date' => '',
			'no_expire' => '1',
			'max_connections' => '1',
			'contact' => self::TEXT['contact'],
			'admin_notes' => self::TEXT['admin_notes'],
			'reseller_notes' => self::TEXT['reseller_notes'],
			'force_server_id' => '0',
			'forced_country' => '',
			'isp_clear' => self::TEXT['isp_desc'],
			'access_token' => '',
			'access_output' => ['1'],
			'category_template_id' => '',
		]);
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

	/** @return array<string, mixed> every column of a line as stored */
	private function line(int $rID = self::LINE): array {
		$this->rDb->query('SELECT * FROM `lines` WHERE `id` = ?', $rID);
		return $this->rDb->get_raw_row() ?? [];
	}

	public function testSavingADisabledAndBannedLineFromTheFormKeepsItOff(): void {
		$this->assertSame(STATUS_SUCCESS, $this->form(['edit' => (string) self::LINE])['status']);

		$this->assertSame([0, 0], [(int) $this->line()['enabled'], (int) $this->line()['admin_enabled']]);
	}

	public function testANewLineFromTheFormIsOn(): void {
		$rResult = $this->form(['username' => 'newname', 'password' => 'newpass']);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$rLine = $this->line((int) $rResult['data']['insert_id']);
		$this->assertSame([1, 1], [(int) $rLine['enabled'], (int) $rLine['admin_enabled']]);
	}

	public function testAnApiEditKeepsTheTextItDoesNotSendAsStored(): void {
		$rStored = $this->line();

		$this->assertSame('STATUS_SUCCESS', $this->edit(['max_connections' => '2']));

		$this->assertEquals(['max_connections' => 2] + $rStored, $this->line());
	}

	public function testAFormSaveKeepsTheLayoutAndTheIspAsStored(): void {
		$this->assertSame(STATUS_SUCCESS, $this->form(['edit' => (string) self::LINE])['status']);

		$rLine = $this->line();
		$this->assertSame([self::TEXT['custom_data'], self::TEXT['isp_desc'], self::TEXT['last_activity_array']], [$rLine['custom_data'], $rLine['isp_desc'], $rLine['last_activity_array']]);
	}

	public function testAnEditKeepsALineWithoutAnOwnerWithoutOne(): void {
		$this->rDb->query('UPDATE `lines` SET `member_id` = NULL WHERE `id` = ?', self::LINE);

		$this->assertSame('STATUS_SUCCESS', $this->edit(['max_connections' => '2']));
		$this->assertNull($this->line()['member_id'], 'through the API');

		$this->assertSame(STATUS_SUCCESS, $this->form(['edit' => (string) self::LINE, 'member_id' => ''])['status']);
		$this->assertNull($this->line()['member_id'], 'from the form');
	}

	public function testAnEditThatNamesAnOwnerGivesTheLineToIt(): void {
		$this->rDb->query('UPDATE `lines` SET `member_id` = NULL WHERE `id` = ?', self::LINE);

		$this->assertSame('STATUS_SUCCESS', $this->edit(['member_id' => (string) self::OWNER]));

		$this->assertSame(self::OWNER, (int) $this->line()['member_id']);
	}

	public function testANewLineWithoutAnOwnerIsTheSavingAdministrators(): void {
		$rResult = $this->form(['username' => 'newname', 'password' => 'newpass', 'member_id' => '']);

		$this->assertSame(STATUS_SUCCESS, $rResult['status']);
		$this->assertSame(self::ADMIN, (int) $this->line((int) $rResult['data']['insert_id'])['member_id']);
	}
}
