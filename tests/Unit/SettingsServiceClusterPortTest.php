<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamArgumentsChangedEvent;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Server\SettingsService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\InstallSchema;

if (!defined('STATUS_FAILURE')) {
	define('STATUS_FAILURE', 0);
}
if (!defined('STATUS_SUCCESS')) {
	define('STATUS_SUCCESS', 1);
}
if (!defined('STATUS_INVALID_DATA')) {
	define('STATUS_INVALID_DATA', 17);
}

/**
 * A settings save that changes `cluster_api_port` (plan §3: refused on port
 * collisions, applied only after `nginx -t` passes). When nginx cannot take
 * the new port, SettingsService::edit() refuses the whole save: the stored
 * port and `cluster_policy_ver` stay, so no node is sent to it.
 *
 * Runs edit() on the TestDb, whose information_schema QueryHelper::verifyPostTable()
 * reads. nginx and the port checks are ClusterNginxConfig's fakes.
 */
final class SettingsServiceClusterPortTest extends TestCase {
	private TestDb $rDb;

	private string $rDir;

	/** What the faked `nginx -t` answers: [exit code, output]. */
	private array $rTest = [0, ''];

	/** @var list<int> */
	private array $rBusy = [];

	/** @var list<string> the nginx actions run: "test" or "reload" */
	private array $rRuns = [];

	private array $rSettingsBefore = [];

	/** The settings columns the save touches: name => [data_type, default]. */
	private const COLUMNS = [
		'cluster_api_enabled' => ['int', '0'],
		'cluster_api_port' => ['int', '0'],
		'cluster_policy_ver' => ['int', '1'],
		'cluster_legacy_ports' => ['varchar', ''],
		'search_items' => ['int', '15'],
		'disable_table_responsive' => ['int', '0'],
		'allowed_stb_types_for_local_recording' => ['text', ''],
		'allowed_stb_types' => ['text', ''],
		'maxmind_editions' => ['text', ''],
		'shared_mount_prefixes' => ['text', ''],
		'allow_countries' => ['text', ''],
		'update_channel_main' => ['varchar', 'stable'],
		'update_channel_fanout' => ['varchar', 'stable'],
		'update_channel' => ['varchar', 'stable'],
	];

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-settings-port-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'bin/nginx/conf/ports', 0777, true);
		mkdir($this->rDir . 'cache', 0777, true);
		// A save that succeeds drops the cached settings file.
		if (!defined('CACHE_TMP_PATH')) {
			define('CACHE_TMP_PATH', $this->rDir . 'cache/');
		}
		file_put_contents($this->rDir . 'bin/nginx/conf/ports/http.conf', 'listen 25461;');
		copy(MAIN_HOME . 'bin/nginx/conf/nginx.conf', $this->rDir . 'bin/nginx/conf/nginx.conf');

		$this->rDb = new TestDb();
		$rDdl = [];
		foreach (self::COLUMNS as $rName => [$rType, $rDefault]) {
			$rDdl[] = '`' . $rName . '` ' . ($rType === 'int' ? 'int' : 'varchar(255)') . " DEFAULT '" . $rDefault . "'";
		}
		$this->rDb->exec('CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, ' . implode(', ', $rDdl) . ')');
		$this->rDb->exec('INSERT INTO `settings` (`id`, `cluster_api_enabled`) VALUES (1, 1)');
		$this->rDb->exec('CREATE TABLE `streams_arguments` (`argument_key` varchar(64), `argument_default_value` varchar(255))');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
		// QueryHelper::verifyPostTable() reads the global handler.
		$GLOBALS['db'] = $this->rDb;

		// The main server row, from the servers cache.
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, new FileCache($this->rDir . 'cache/'));
		FileCache::setCache('servers', [1 => ['id' => 1, 'is_main' => 1, 'server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461]]);
		$this->rSettingsBefore = SettingsManager::getAll();
		$this->rDb->query('SELECT * FROM `settings`');
		SettingsManager::set($this->rDb->get_row());
		unset($_COOKIE['lang']);
		Translator::init(MAIN_HOME . 'Core/Localization/lang');

		ClusterClock::fix(1800000000 * 1000);
		ClusterNginxConfig::useBase($this->rDir, (string) (posix_getpwuid(posix_geteuid())['name'] ?? ''));
		ClusterNginxConfig::useRunner(function (array $rArgv): array {
			$this->rRuns[] = in_array('-t', $rArgv, true) ? 'test' : 'reload';
			return in_array('-t', $rArgv, true) ? $this->rTest : [0, ''];
		});
		ClusterNginxConfig::useProbe(fn(string $rCheck, int $rPort): bool => $rCheck === 'free' ? !in_array($rPort, $this->rBusy, true) : true);
	}

	protected function tearDown(): void {
		ClusterNginxConfig::useRunner(null);
		ClusterNginxConfig::useProbe(null);
		ClusterNginxConfig::useBase(null);
		ClusterClock::fix(null);
		SettingsManager::set($this->rSettingsBefore);
		(new \ReflectionProperty(FileCache::class, 'defaultInstance'))->setValue(null, null);
		unset($GLOBALS['db']);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/** @return array{0: int, 1: int} the stored cluster_api_port and cluster_policy_ver */
	private function stored(): array {
		$this->rDb->query('SELECT `cluster_api_port`, `cluster_policy_ver` FROM `settings`');
		$rRow = $this->rDb->get_row();
		return [(int) $rRow['cluster_api_port'], (int) $rRow['cluster_policy_ver']];
	}

	/** The handler, with the save's own settings UPDATE failing the way DatabaseHandler fails: false. */
	private function failingSave(): DatabaseHandler {
		return new class ($this->rDb) extends DatabaseHandler {
			public function __construct(private TestDb $rInner) {
			}

			public function query($query, ...$args): bool {
				return !str_contains((string) $query, 'SET `search_items` = ?') && $this->rInner->query($query, ...$args);
			}

			public function get_rows($use_id = false, $column_as_id = '', $unique_row = true, $sub_row_id = '') {
				return $this->rInner->get_rows($use_id, $column_as_id, $unique_row, $sub_row_id);
			}

			public function get_row() {
				return $this->rInner->get_row();
			}

			public function num_rows(): int {
				return $this->rInner->num_rows();
			}
		};
	}

	private function save(string $rPort): array {
		return SettingsService::edit(['user_agent' => '', 'http_proxy' => '', 'cookie' => '', 'headers' => '', 'search_items' => '15', 'cluster_api_port' => $rPort]);
	}

	private function legacyChannel(): ?string {
		$this->rDb->query('SELECT * FROM `settings`');
		return $this->rDb->get_row()['update_channel'] ?? null;
	}

	/**
	 * A load balancer still on stable 2.3.9 reads `update_channel` and knows
	 * 'stable' and 'unstable' only: the save writes the panel's channel there,
	 * whatever the request sends for the column.
	 */
	public function testThePanelChannelIsKeptForServersOnAnOlderRelease(): void {
		foreach (['beta' => 'unstable', 'stable' => 'stable', 'dev' => 'unstable'] as $rChannel => $rLegacy) {
			$rResult = SettingsService::edit(['user_agent' => '', 'http_proxy' => '', 'cookie' => '', 'headers' => '', 'search_items' => '15', 'update_channel_main' => $rChannel, 'update_channel' => 'beta']);
			$this->assertSame(STATUS_SUCCESS, $rResult['status']);
			$this->assertSame($rLegacy, $this->legacyChannel(), $rChannel);
		}

		// A save that does not post the channel leaves the column alone.
		SettingsService::edit(['user_agent' => '', 'http_proxy' => '', 'cookie' => '', 'headers' => '', 'search_items' => '15', 'update_channel' => 'stable']);
		$this->assertSame('unstable', $this->legacyChannel());
	}

	/** The migration brings the column back on a panel that dropped it (016), from the panel's channel. */
	public function testTheMigrationRestoresTheColumnFromThePanelChannel(): void {
		foreach (['beta' => 'unstable', 'stable' => 'stable', 'dev' => 'unstable'] as $rChannel => $rLegacy) {
			$this->rDb->exec('ALTER TABLE `settings` DROP COLUMN IF EXISTS `update_channel`');
			$this->rDb->query('UPDATE `settings` SET `update_channel_main` = ?', $rChannel);
			$this->rDb->exec(InstallSchema::migration('090_restore_legacy_update_channel'));
			$this->assertSame($rLegacy, $this->legacyChannel(), $rChannel);
		}

		$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/down/090_restore_legacy_update_channel.sql'));
		$this->assertNull($this->legacyChannel());
	}

	public function testAPortNginxRefusesFailsTheSave(): void {
		$this->rTest = [1, "nginx: [emerg] unknown directive \"oops\" in custom.conf:1\nnginx: configuration file test failed\n"];
		$rResult = $this->save('31200');
		$this->assertSame(STATUS_INVALID_DATA, $rResult['status']);
		$this->assertStringStartsWith('The cluster API port was not changed: nginx refused it', $rResult['data']['message']);
		$this->assertStringContainsString('unknown directive &quot;oops&quot; in custom.conf:1', $rResult['data']['message'], "nginx's words, escaped");
		$this->assertSame([0, 1], $this->stored(), 'the stored port and the policy version stay');
		$this->assertFileDoesNotExist($this->rDir . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN);
	}

	public function testAPortAnotherProgramHoldsFailsTheSave(): void {
		$this->rBusy = [31200];
		$rResult = $this->save('31200');
		$this->assertSame(STATUS_INVALID_DATA, $rResult['status']);
		$this->assertSame('The cluster API port was not changed: another program on MAIN already listens on it.', $rResult['data']['message']);
		$this->assertSame([0, 1], $this->stored());
		$this->assertFileDoesNotExist($this->rDir . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN);
		$this->assertSame([], $this->rRuns);
	}

	/**
	 * The stream arguments' defaults a save stores first, apart from the
	 * settings row (whose UPDATE fails here, so nothing else of the save
	 * runs): it announces only those whose default changed, which their
	 * streams' R2 records carry.
	 */
	public function testASaveAnnouncesOnlyTheArgumentDefaultsItChanged(): void {
		$this->rDb->exec("INSERT INTO `streams_arguments` VALUES ('user_agent', 'VLC'), ('http_proxy', NULL), ('cookie', NULL), ('headers', NULL)");
		$rDb = $this->failingSave();
		DatabaseFactory::set($rDb);
		$GLOBALS['db'] = $rDb;
		$rSeen = [];
		EventDispatcher::resetInstance();
		EventDispatcher::listen(StreamArgumentsChangedEvent::class, static function (StreamArgumentsChangedEvent $rEvent) use (&$rSeen): void {
			$rSeen[] = $rEvent->argumentKeys;
		});
		try {
			$rForm = ['user_agent' => 'VLC', 'http_proxy' => '', 'cookie' => '', 'headers' => '', 'search_items' => '15'];
			SettingsService::edit($rForm);
			$this->assertSame([], $rSeen, 'nothing changed');
			SettingsService::edit(['user_agent' => 'curl/8', 'cookie' => 'a=b'] + $rForm);
			$this->assertSame([['user_agent', 'cookie']], $rSeen);
			$this->rDb->query("SELECT `argument_default_value` FROM `streams_arguments` WHERE `argument_key` = 'cookie'");
			$this->assertSame('a=b', $this->rDb->get_row()['argument_default_value']);
		} finally {
			EventDispatcher::resetInstance();
		}
	}

	/** A save whose UPDATE fails: nothing is announced, and nginx goes back to the stored port. */
	public function testASaveThatIsNotStoredPutsNginxBackOnTheStoredPort(): void {
		$rDb = $this->failingSave();
		DatabaseFactory::set($rDb);
		$GLOBALS['db'] = $rDb;
		$this->assertSame(['status' => STATUS_FAILURE], $this->save('31200'));
		$this->assertSame([0, 1], $this->stored(), 'nothing announced');
		$this->assertFileDoesNotExist($this->rDir . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN, 'staged, then taken back');
		$this->assertSame(['test', 'reload', 'test', 'reload'], $this->rRuns);
	}
}
