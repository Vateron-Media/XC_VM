<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Public\Controllers\Api\AdminAPIWrapper;

/**
 * ServerRepository::getAll keeps servers.php_pids out of the rows it returns
 * and of the 'servers' cache that every stream request reads. The column holds
 * one pid per live PHP-FPM worker of a node; only MAIN's cron:users needs it,
 * and it reads the column itself. (CacheCronJob's unset($rServers['php_pids'])
 * meant to strip it, but the map is keyed by server id, so it did nothing.)
 * The Simple readers every admin request runs, and the admin API's
 * get_server, leave it out too. getById keeps it: ServerService writes that
 * row back whole with REPLACE.
 */
final class ServerRepositoryPhpPidsTest extends TestCase {

	private TestDb $db;

	private $rSettingsBackup;

	private $rSchemeBackup;

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			$rDir = dirname(__DIR__) . '/.tmp/cache/';
			@mkdir($rDir, 0755, true);
			define('CACHE_TMP_PATH', $rDir);
		}
		@mkdir(CACHE_TMP_PATH, 0755, true);
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}

		$this->rSettingsBackup = $GLOBALS['rSettings'] ?? null;
		$this->rSchemeBackup = $_SERVER['REQUEST_SCHEME'] ?? null;
		$GLOBALS['rSettings'] = ['live_streaming_pass' => 'x'];

		$this->db = new TestDb();
		$this->db->exec(
			'CREATE TABLE servers (id INTEGER PRIMARY KEY AUTO_INCREMENT, server_type INTEGER, is_main INTEGER, enabled INTEGER, status INTEGER, last_check_ago INTEGER, parent_id TEXT, domain_name TEXT, server_ip TEXT, private_ip TEXT, enable_https INTEGER, http_broadcast_port INTEGER, https_broadcast_port INTEGER, rtmp_port INTEGER, geoip_countries TEXT, isp_names TEXT, watchdog_data TEXT, php_pids TEXT, `order` INTEGER);'
		);
		$this->db->query(
			'INSERT INTO servers (id, server_type, is_main, enabled, status, last_check_ago, parent_id, domain_name, server_ip, private_ip, enable_https, http_broadcast_port, https_broadcast_port, rtmp_port, geoip_countries, isp_names, watchdog_data, php_pids, `order`) VALUES
			 (7, 0, 0, 1, 1, ?, NULL, "", "10.0.0.7", "", 0, 80, 443, 8880, "", "", "{}", "[11,12]", 0),
			 (8, 1, 0, 1, 1, ?, "[7]", "", "10.0.0.8", "", 0, 80, 443, 8880, "", "", "{}", "[21]", 0);',
			time(),
			time()
		);
		ServerRepository::setDb($this->db);
	}

	protected function tearDown(): void {
		FileCache::delCache('servers');
		(new ReflectionProperty(ServerRepository::class, 'db'))->setValue(null, null);
		$GLOBALS['rSettings'] = $this->rSettingsBackup;
		if ($this->rSchemeBackup === null) {
			unset($_SERVER['REQUEST_SCHEME']);
		} else {
			$_SERVER['REQUEST_SCHEME'] = $this->rSchemeBackup;
		}
	}

	public function testReturnedRowsCarryNoPhpPids(): void {
		$rServers = ServerRepository::getAll(true);

		$this->assertArrayHasKey(7, $rServers);
		$this->assertSame('10.0.0.7', $rServers[7]['server_ip'], 'the row itself is still loaded');
		$this->assertArrayNotHasKey('php_pids', $rServers[7]);
	}

	public function testCachedRowsCarryNoPhpPids(): void {
		ServerRepository::getAll(true);

		$rCache = FileCache::getCache('servers');
		$this->assertIsArray($rCache);
		$this->assertArrayHasKey(7, $rCache);
		$this->assertArrayNotHasKey('php_pids', $rCache[7]);
	}

	public function testSimpleReadersCarryNoPhpPids(): void {
		$rAll = ServerRepository::getAllSimple();
		$rStreaming = ServerRepository::getStreamingSimple(null, 'all');
		$rProxies = ServerRepository::getProxySimple();

		$this->assertSame([7, 8], array_keys($rAll));
		$this->assertArrayNotHasKey('php_pids', $rAll[7]);
		$this->assertArrayNotHasKey('php_pids', $rAll[8]);
		$this->assertSame([7], array_keys($rStreaming));
		$this->assertArrayNotHasKey('php_pids', $rStreaming[7]);
		$this->assertSame([8], array_keys($rProxies));
		$this->assertArrayNotHasKey('php_pids', $rProxies[8]);
	}

	public function testAdminApiServerCarriesNoPhpPids(): void {
		$rResult = AdminAPIWrapper::getServer(7);

		$this->assertSame('STATUS_SUCCESS', $rResult['status']);
		$this->assertSame('10.0.0.7', $rResult['data']['server_ip']);
		$this->assertArrayNotHasKey('php_pids', $rResult['data']);
	}

	public function testGetByIdKeepsTheFullRow(): void {
		$this->assertSame('[11,12]', ServerRepository::getById(7)['php_pids']);
	}

	public function testClusterHealthDecidesOnlineForAgentNodes(): void {
		$rPath = sys_get_temp_dir() . '/health_' . bin2hex(random_bytes(4)) . '.json';
		\XcVm\Core\Cluster\ClusterHealth::usePath($rPath);
		try {
			// Fresh last_check_ago, but MAIN's liveness loop says offline.
			\XcVm\Core\Cluster\ClusterHealth::write([7 => 'offline'], false);
			$this->assertFalse(ServerRepository::getAll(true)[7]['server_online']);
			// A stale last_check_ago (legacy: offline after 90 s), but the loop says suspect.
			$this->db->query('UPDATE servers SET last_check_ago = ? WHERE id = 7', time() - 600);
			\XcVm\Core\Cluster\ClusterHealth::write([7 => 'suspect'], false);
			$rServers = ServerRepository::getAll(true);
			$this->assertTrue($rServers[7]['server_online']);
			$this->assertSame('suspect', $rServers[7]['cluster_health']);
			// Not judged by the loop: the legacy rule.
			\XcVm\Core\Cluster\ClusterHealth::write([], false);
			$this->assertFalse(ServerRepository::getAll(true)[7]['server_online']);
		} finally {
			@unlink($rPath);
			\XcVm\Core\Cluster\ClusterHealth::usePath(null);
		}
	}
}
