<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cache\FileCache;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Public\Controllers\Admin\DashboardController;

/**
 * The dashboard's status rows judge every server, keyed by its id: the admin's
 * $rServers holds the online streaming servers only, so a server that went
 * silent was never named, and the Cluster row read names by position.
 */
final class DashboardStatusFeedTest extends TestCase {
	private TestDb $db;
	private $rSettingsBackup;

	protected function setUp(): void {
		if (!defined('CACHE_TMP_PATH')) {
			$rDir = sys_get_temp_dir() . '/xcvm-p6-cache/';
			@mkdir($rDir, 0755, true);
			define('CACHE_TMP_PATH', $rDir);
		}
		@mkdir(CACHE_TMP_PATH, 0755, true);
		if (!defined('SERVER_ID')) {
			define('SERVER_ID', 1);
		}
		$this->rSettingsBackup = $GLOBALS['rSettings'] ?? null;
		$GLOBALS['rSettings'] = ['live_streaming_pass' => 'x'];
		$this->db = new TestDb();
		$this->db->exec('CREATE TABLE servers (id INTEGER PRIMARY KEY AUTO_INCREMENT, server_name TEXT, server_type INTEGER, is_main INTEGER, enabled INTEGER, status INTEGER, last_check_ago INTEGER, parent_id TEXT, domain_name TEXT, server_ip TEXT, private_ip TEXT, enable_https INTEGER, http_broadcast_port INTEGER, https_broadcast_port INTEGER, rtmp_port INTEGER, geoip_countries TEXT, isp_names TEXT, watchdog_data TEXT, php_pids TEXT, `order` INTEGER);');
		$rRow = '?, 0, ?, 1, 1, ?, NULL, "", "10.0.0.1", "", 0, 80, 443, 8880, "", "", "{}", "[]", ?';
		$this->db->query('INSERT INTO servers (id, server_name, server_type, is_main, enabled, status, last_check_ago, parent_id, domain_name, server_ip, private_ip, enable_https, http_broadcast_port, https_broadcast_port, rtmp_port, geoip_countries, isp_names, watchdog_data, php_pids, `order`) VALUES (' . SERVER_ID . ', ' . $rRow . '), (' . (SERVER_ID + 1) . ', ' . $rRow . '), (' . (SERVER_ID + 2) . ', ' . $rRow . ');',
			'Main', 1, time(), 0,
			'LB-2', 0, time() - 3600, 1,
			'LB-3', 0, time(), 2);
		ServerRepository::setDb($this->db);
	}

	protected function tearDown(): void {
		FileCache::delCache('servers');
		(new ReflectionProperty(ServerRepository::class, 'db'))->setValue(null, null);
		$GLOBALS['rSettings'] = $this->rSettingsBackup;
	}

	public function testTheStatusRowsSeeTheServerThatWentSilent(): void {
		FileCache::delCache('servers');
		$rServers = DashboardController::statusServers();

		$this->assertSame('LB-2', $rServers[SERVER_ID + 1]['server_name'], 'keyed by server id');
		$rCheck = DashboardController::serversCheck($rServers);
		$this->assertSame('fail', $rCheck['state']);
		$this->assertStringContainsString('LB-2', $rCheck['detail']);
		$this->assertStringNotContainsString('LB-3', $rCheck['detail']);
	}
}
