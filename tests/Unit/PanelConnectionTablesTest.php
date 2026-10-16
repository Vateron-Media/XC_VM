<?php

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

/** Exercise the real table handlers over one shared throwaway MariaDB schema. */
final class PanelConnectionTablesTest extends TestCase {
	private TestDb $db;

	protected function setUp(): void {
		$this->db = new TestDb();
		foreach (['users', 'users_groups', 'lines', 'lines_live', 'lines_activity', 'streams', 'servers', 'mag_devices', 'enigma2_devices'] as $table) {
			$this->db->exec(InstallSchema::table($table));
		}
		$this->db->exec("INSERT INTO users (id, username) VALUES (5, 'owner'), (6, 'other-owner')");
		$this->db->exec("INSERT INTO `lines` (id, member_id, username, is_mag) VALUES (8, 5, 'own-mag', 1), (9, 6, 'other-mag', 1)");
		$this->db->exec("INSERT INTO mag_devices (mag_id, user_id, mac) VALUES (1, 8, 'own-mac'), (2, 9, 'other-mac')");
		$this->db->exec("INSERT INTO streams (id, stream_display_name, type) VALUES (21, 'News', 1), (22, 'Sports', 1)");
		$this->db->exec("INSERT INTO servers (id, server_name) VALUES (1, 'Main')");
		foreach ([[8, 21, '192.0.2.8', '<ISP>', 10, 0], [8, 22, '192.0.2.99', 'Ended', 20, 1], [9, 21, '192.0.2.9', 'Other ISP', 30, 0]] as $row) {
			$this->db->query('INSERT INTO lines_live (user_id, stream_id, user_ip, isp, date_start, hls_end, server_id) VALUES (?, ?, ?, ?, ?, ?, 1)', ...$row);
		}
		foreach ([[8, 21, '2026-10-14'], [8, 22, '2026-10-13'], [9, 21, '2026-10-14']] as [$user, $stream, $day]) {
			$start = strtotime($day . ' 12:00:00');
			$this->db->query('INSERT INTO lines_activity (user_id, stream_id, server_id, date_start, date_end, user_ip) VALUES (?, ?, 1, ?, ?, ?)', $user, $stream, $start, $start + 60, '192.0.2.' . $user);
		}
	}

	private function rows(string $handler, array $request = [], bool $reseller = false): array {
		$code = <<<'PHP'
final class XC_VM { public static function db_connect(bool $migrate = false) { return TestDb::connect($GLOBALS['input']['schema']); } }
require %BOOTSTRAP%;
$input = json_decode($argv[1], true);
\XcVm\Core\Config\SettingsManager::set(['redis_handler' => 0]);
\XcVm\Infrastructure\Database\DatabaseFactory::connect();
$db = \XcVm\Infrastructure\Database\DatabaseFactory::get();
$rSettings = ['date_format' => 'Y-m-d'];
$rUserInfo = ['id' => 5, 'member_group_id' => 1, 'reports' => [5]];
$rPermissions = ['is_admin' => 1, 'advanced' => [], 'create_mag' => 1, 'direct_reports' => [], 'all_reports' => []];
$rProxyServers = [];
\XcVm\Core\Http\RequestManager::set($input['request'] + ['draw' => 1, 'search' => ['value' => '']]);
$return = ['draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []];
if ($input['reseller']) {
    $method = new ReflectionMethod(\XcVm\Public\Controllers\Reseller\ResellerTableController::class, 'handleMags');
    $method->setAccessible(true);
    $method->invoke(null, $return, false, $rUserInfo, $rPermissions, 0, 100);
} else {
    $method = new ReflectionMethod(\XcVm\Public\Controllers\Admin\TableController::class, $input['handler']);
    $method->setAccessible(true);
    $method->invoke(new \XcVm\Public\Controllers\Admin\TableController(), $return, 0, 100, false);
}
PHP;
		$code = str_replace('%BOOTSTRAP%', var_export(dirname(__DIR__) . '/bootstrap.php', true), $code);
		$input = ['schema' => $this->db->schema(), 'handler' => $handler, 'request' => $request, 'reseller' => $reseller];
		$process = proc_open([...xcvm_test_child_php(), '-r', $code, json_encode($input)], [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
		$this->assertIsResource($process);
		$output = stream_get_contents($pipes[1]);
		$error = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);
		$this->assertSame(0, proc_close($process), $output . $error);
		$this->assertSame('', $error);
		$answer = json_decode($output, true);
		$this->assertIsArray($answer, $output);
		return $answer;
	}

	public function testCanonicalActivityFiltersSelectOnlyTheLinkedCustomerStreamAndDates(): void {
		$answer = $this->rows('handleLineActivity', ['user_id' => '8', 'stream_id' => '21', 'server_id' => '1', 'range' => '2026-10-14 - 2026-10-14']);
		$this->assertSame(1, (int) $answer['recordsTotal']);
		$this->assertCount(1, $answer['data']);
		$this->assertSame('own-mag', $answer['data'][0]['user_label']);
	}

	public function testAdminMagRowsExposeActiveIpAndIsp(): void {
		$answer = $this->rows('handleMags');
		$row = array_values(array_filter($answer['data'], static fn(array $row): bool => $row['line_id'] === 8))[0];
		$this->assertSame('192.0.2.8', $row['online_ip']);
		$this->assertSame('<ISP>', $row['online_isp']);
		$this->assertSame(10, $row['online_since']);
	}

	public function testResellerMagRowsExposeOnlyTheirOwnActiveConnection(): void {
		$answer = $this->rows('handleMags', [], true);
		$this->assertCount(1, $answer['data']);
		$this->assertSame('192.0.2.8', $answer['data'][0]['online_ip']);
		$this->assertSame('<ISP>', $answer['data'][0]['online_isp']);
		$this->assertSame(10, $answer['data'][0]['last_active']);
	}
}
