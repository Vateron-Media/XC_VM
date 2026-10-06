<?php

use PHPUnit\Framework\TestCase;

/**
 * "Refresh" in the web player drops the caches of the line that is signed in
 * and rebuilds the bouquets. A session on another provider's server is no
 * line of this panel: its placeholder id names none of the panel's caches.
 *
 * The controller answers and exits, so each request runs in a child PHP
 * against its own empty database.
 */
final class AuditReviewPlayersRefreshTest extends TestCase {
	private string $rDir;

	protected function setUp(): void {
		$this->rDir = sys_get_temp_dir() . '/xcvm-player-refresh-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rDir . 'lines', 0700, true);
		mkdir($this->rDir . 'cache', 0700, true);
	}

	protected function tearDown(): void {
		exec('rm -rf ' . escapeshellarg($this->rDir));
	}

	/**
	 * Request /refresh as $rUserInfo on a panel that holds the lines 7 and 999999, their caches and the bouquets cache.
	 *
	 * @return array{status: ?string, line_999999: bool, line_7: bool, bouquets_rebuilt: bool}
	 */
	private function refresh(array $rUserInfo): array {
		$rCode = 'require ' . var_export(dirname(__DIR__) . '/bootstrap.php', true) . ';'
			. 'error_reporting(E_ERROR | E_PARSE);'
			. '$db = new TestDb();'
			. 'foreach (["lines", "bouquets", "mag_devices", "enigma2_devices", "users", "lines_live", "streams", "streams_series", "streams_episodes", "streams_categories", "output_formats", "output_devices", "lines_logs", "servers"] as $rTable) {'
			. ' $db->exec(\XcVm\Tests\Support\InstallSchema::table($rTable));'
			. '}'
			. '$db->query("INSERT INTO `bouquets` (`id`, `bouquet_name`) VALUES (1, ?)", "Basic");'
			. '$db->query("INSERT INTO `lines` (`id`, `username`, `password`, `bouquet`, `enabled`, `admin_enabled`) VALUES (7, ?, ?, ?, 1, 1), (999999, ?, ?, ?, 1, 1)", "viewer", "secret", "[1]", "another", "line", "[1]");'
			. '\XcVm\Infrastructure\Database\DatabaseFactory::set($db);'
			. 'define("CACHE_TMP_PATH", ' . var_export($this->rDir . 'cache/', true) . ');'
			. 'define("LINES_TMP_PATH", ' . var_export($this->rDir . 'lines/', true) . ');'
			. 'define("SERVER_ID", 1);'
			. '$rServers = [1 => ["is_main" => 1]];'
			. '$rSettings = ["enable_cache" => 0];'
			. '\XcVm\Core\Config\SettingsManager::set($rSettings);'
			. 'file_put_contents(LINES_TMP_PATH . "line_i_999999", "line 999999");'
			. 'file_put_contents(LINES_TMP_PATH . "line_i_7", "line 7");'
			. 'file_put_contents(CACHE_TMP_PATH . "bouquets", "as it was");'
			. '$rUserInfo = ' . var_export($rUserInfo, true) . ';'
			. 'register_shutdown_function(static function () {'
			. ' echo "\n" . json_encode(["line_999999" => file_exists(LINES_TMP_PATH . "line_i_999999"), "line_7" => file_exists(LINES_TMP_PATH . "line_i_7"), "bouquets_rebuilt" => @file_get_contents(CACHE_TMP_PATH . "bouquets") !== "as it was"]);'
			. '});'
			. '(new \XcVm\Public\Controllers\PlayerV2\RefreshController())->index();';

		$rProc = proc_open([PHP_BINARY, '-r', $rCode], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
		$rOut = (string) stream_get_contents($rPipes[1]);
		$rErr = stream_get_contents($rPipes[2]);
		proc_close($rProc);

		$rCut = (int) strrpos($rOut, "\n");
		$rAfter = json_decode(substr($rOut, $rCut + 1), true);
		$this->assertIsArray($rAfter, $rOut . $rErr);

		return ['status' => json_decode(substr($rOut, 0, $rCut), true)['status'] ?? null] + $rAfter;
	}

	public function testASessionOnAnExternalServerDropsNoCacheOfThisPanel(): void {
		$rAfter = $this->refresh(['id' => 999999, 'username' => 'ext-user', 'password' => 'ext-pass', 'is_external_xc' => true, 'bouquet' => []]);

		$this->assertSame(['status' => 'success', 'line_999999' => true, 'line_7' => true, 'bouquets_rebuilt' => false], $rAfter);
	}

	public function testALineOfThisPanelRefreshesItsOwnCaches(): void {
		$rAfter = $this->refresh(['id' => 7, 'username' => 'viewer', 'password' => 'secret', 'bouquet' => [1]]);

		$this->assertSame(['status' => 'success', 'line_999999' => true, 'line_7' => false, 'bouquets_rebuilt' => true], $rAfter);
	}
}
