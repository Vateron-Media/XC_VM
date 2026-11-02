<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\ConstantsInitializer;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

final class ResellerLineCacheRefreshTest extends TestCase {
	private TestDb $db;

	private array $before;

	protected function setUp(): void {
		foreach (ConstantsInitializer::statuses() + ['SERVER_ID' => 1] as $name => $value) {
			defined($name) || define($name, $value);
		}
		$this->db = new TestDb();
		foreach (['users', 'users_groups', 'users_packages', 'users_logs', 'users_credits_logs', 'lines', 'lines_live', 'lines_logs', 'lines_activity', 'mag_devices', 'enigma2_devices', 'bouquets', 'signals'] as $table) {
			$this->db->exec(InstallSchema::table($table));
		}
		$this->db->exec(InstallSchema::migration('021_add_category_templates'));
		$this->db->exec(InstallSchema::migration('024_add_custom_data_to_lines'));
		$this->db->exec("INSERT INTO `users_groups` (`group_id`, `is_reseller`) VALUES (2, 1)");
		$this->db->exec("INSERT INTO `users_packages` (`id`, `package_name`, `is_official`, `official_credits`, `official_duration`, `official_duration_in`, `groups`, `bouquets`, `output_formats`, `is_line`) VALUES (1, 'Month', 1, 10, 1, 'months', '[2]', '[]', '[1]', 1)");
		$this->db->exec("INSERT INTO `users` (`id`, `username`, `member_group_id`, `credits`, `owner_id`, `override_packages`) VALUES (5, 'reseller', 2, 100, 0, '[]')");
		$this->before = ['settings' => SettingsManager::getAll(), 'servers' => $GLOBALS['rServers'] ?? null, 'settingsGlobal' => $GLOBALS['rSettings'] ?? null];
		$GLOBALS['rServers'] = [1 => ['is_main' => 1, 'server_type' => 0]];
		$GLOBALS['rSettings'] = ['disable_trial' => 0, 'redis_handler' => 0];
		SettingsManager::set(['enable_cache' => 1, 'redis_handler' => 0]);
		$GLOBALS['db'] = $this->db;
		DatabaseFactory::set($this->db);
		foreach ([LineService::class, BouquetService::class, ConnectionTracker::class] as $class) {
			$property = new ReflectionProperty($class, 'db');
			$this->before[$class] = $property->getValue();
			$property->setValue(null, $this->db);
		}
		ResellerAPI::$rUserInfo = UserRepository::getRegisteredUserById(5);
		ResellerAPI::$rPermissions = ['create_line' => true, 'all_reports' => [], 'allow_change_username' => 1, 'allow_change_password' => 1, 'minimum_username_length' => 4, 'minimum_password_length' => 4, 'allow_change_bouquets' => 0, 'allow_restrictions' => 0];
		$GLOBALS['rUserInfo'] = ResellerAPI::$rUserInfo;
		$GLOBALS['rPermissions'] = ResellerAPI::$rPermissions;
	}

	protected function tearDown(): void {
		foreach ([LineService::class, BouquetService::class, ConnectionTracker::class] as $class) {
			(new ReflectionProperty($class, 'db'))->setValue(null, $this->before[$class]);
		}
		DatabaseFactory::reset();
		SettingsManager::set($this->before['settings']);
		$GLOBALS['rServers'] = $this->before['servers'];
		$GLOBALS['rSettings'] = $this->before['settingsGlobal'];
		ResellerAPI::$rUserInfo = ResellerAPI::$rPermissions = ResellerAPI::$rSettings = [];
		unset($GLOBALS['db'], $GLOBALS['rUserInfo'], $GLOBALS['rPermissions']);
	}

	private function save(array $data): array {
		$result = ResellerAPI::processLine($data + ['username' => 'viewer', 'password' => 'secret', 'contact' => '', 'reseller_notes' => '']);
		$this->assertIsArray($result);
		return $result;
	}

	private function refreshes(): array {
		$this->db->query('SELECT `custom_data` FROM `signals` WHERE `cache` = 1 ORDER BY `signal_id`');
		return array_map(static fn(string $job): array => json_decode($job, true), $this->db->get_column());
	}

	public function testAnExpiredLineRenewalRefreshesItsCachedExpiry(): void {
		$this->assertSame(STATUS_SUCCESS, $this->save(['package' => 1])['status']);
		$this->assertSame([['type' => 'update_line', 'id' => 1]], $this->refreshes(), 'new lines also reach the cache');
		$this->db->exec('DELETE FROM `signals`');
		$this->db->query('UPDATE `lines` SET `exp_date` = ? WHERE `id` = 1', time() - 86400);
		$this->assertSame(STATUS_SUCCESS, $this->save(['edit' => 1, 'package' => 1])['status']);
		$this->db->query('SELECT `exp_date` FROM `lines` WHERE `id` = 1');
		$this->assertGreaterThan(time() + 27 * 86400, (int) $this->db->get_col());
		$this->assertSame([['type' => 'update_line', 'id' => 1]], $this->refreshes());
	}

	public function testRenewalDoesNotEnableAManuallyDisabledLine(): void {
		$this->assertSame(STATUS_SUCCESS, $this->save(['package' => 1])['status']);
		$this->db->exec('DELETE FROM `signals`');
		$this->db->query('UPDATE `lines` SET `enabled` = 0, `exp_date` = ? WHERE `id` = 1', time() - 86400);
		$this->assertSame(STATUS_SUCCESS, $this->save(['edit' => 1, 'package' => 1])['status']);
		$this->db->query('SELECT `enabled` FROM `lines` WHERE `id` = 1');
		$this->assertSame(0, (int) $this->db->get_col());
		$this->assertSame([['type' => 'update_line', 'id' => 1]], $this->refreshes());
	}

	public function testUncachedSavesDoNotRequireCacheRouting(): void {
		SettingsManager::set(['enable_cache' => 0, 'redis_handler' => 0]);
		unset($GLOBALS['rServers']);
		$this->assertSame(STATUS_SUCCESS, $this->save(['package' => 1])['status']);
		$this->assertSame([], $this->refreshes());
	}

	public function testARejectedPurchaseDoesNotQueueARefresh(): void {
		$this->db->query('UPDATE `users` SET `credits` = 0 WHERE `id` = 5');
		$this->assertSame(STATUS_INSUFFICIENT_CREDITS, $this->save(['package' => 1])['status']);
		$this->assertSame([], $this->refreshes());
	}
}
