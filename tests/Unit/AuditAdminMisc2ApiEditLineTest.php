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
 * A line keeps its username and its password through an edit of the admin
 * API that does not send them: a subscriber's player signs in with both, so
 * they change only when the request carries a new value.
 */
final class AuditAdminMisc2ApiEditLineTest extends TestCase {
	private const KEY = '11111111111111111111111111111111';

	private const LINE = 8;

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
		$this->rDb->exec("INSERT INTO `lines` (`id`, `member_id`, `username`, `password`, `exp_date`, `enabled`, `admin_enabled`, `max_connections`, `bouquet`, `allowed_outputs`, `created_at`) VALUES"
			. ' (8, 1, \'plainname\', \'plainpass\', ' . (time() + 30 * 86400) . ", 1, 1, 1, '[]', '[1]', 1700000000)");

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
	 * Edit the line through the API with these fields of its form: the status of the answer.
	 *
	 * @param array<string, string> $rFields
	 */
	private function edit(array $rFields): ?string {
		RequestManager::set(['api_key' => self::KEY, 'action' => 'edit_line', 'id' => (string) self::LINE] + $rFields + ['bouquets_selected' => '[]', 'isp_clear' => '', 'exp_date' => date('Y-m-d', time() + 60 * 86400)]);
		ob_start();
		try {
			(new AdminApiController())->index();
		} finally {
			$rBody = (string) ob_get_clean();
		}
		return json_decode($rBody, true)['status'] ?? null;
	}

	/** @return array<string, mixed> the line as stored */
	private function line(): array {
		$this->rDb->query('SELECT `username`, `password`, `max_connections` FROM `lines` WHERE `id` = ?', self::LINE);
		return $this->rDb->get_raw_row() ?? [];
	}

	public function testAnEditThatSendsNeitherKeepsBoth(): void {
		$this->assertSame('STATUS_SUCCESS', $this->edit(['max_connections' => '3']));

		$this->assertEquals(['username' => 'plainname', 'password' => 'plainpass', 'max_connections' => 3], $this->line(), 'the rest of the edit is stored');
	}

	public function testAnEditThatSendsOneOfThemKeepsTheOther(): void {
		$this->assertSame('STATUS_SUCCESS', $this->edit(['password' => 'newpass']));
		$this->assertSame(['username' => 'plainname', 'password' => 'newpass'], array_slice($this->line(), 0, 2));

		$this->assertSame('STATUS_SUCCESS', $this->edit(['username' => 'newname']));
		$this->assertSame(['username' => 'newname', 'password' => 'newpass'], array_slice($this->line(), 0, 2));
	}

	public function testAnEditThatSendsBothStoresThem(): void {
		$this->assertSame('STATUS_SUCCESS', $this->edit(['username' => 'othername', 'password' => 'otherpass']));

		$this->assertSame(['username' => 'othername', 'password' => 'otherpass'], array_slice($this->line(), 0, 2));
	}
}
