<?php

use PHPUnit\Framework\TestCase;
use XcVm\Tests\Support\InstallSchema;

final class DashboardUserCountsTest extends TestCase {
	public function testCountsExcludeEndedSessionsAndDoNotAddServerTotalsToUniqueUsers(): void {
		$rDb = new TestDb();
		foreach (['lines_live', 'streams', 'streams_servers'] as $rTable) {
			$rDb->exec(InstallSchema::table($rTable));
		}
		// One user on two servers, a second active user, and an ended-only user.
		foreach ([[1, 1, 0], [1, 2, 0], [2, 1, 0], [3, 1, 1]] as [$rUser, $rServer, $rEnded]) {
			$rDb->query('INSERT INTO `lines_live` (`user_id`, `server_id`, `hls_end`) VALUES (?, ?, ?)', $rUser, $rServer, $rEnded);
		}
		$rScript = <<<'CODE'
<?php
namespace XcVm\Domain\Server {
	class ServerRepository {
		public static function getAll($force = false) {
			$base = ['server_online' => true, 'server_type' => 0, 'requests_per_second' => 0, 'network_guaranteed_speed' => 1000, 'watchdog_data' => '{"uptime":"1 day","total_mem_used_percent":0,"cpu":0,"bytes_sent":0,"bytes_received":0}'];
			return [1 => $base + ['connections' => 2, 'users' => 2], 2 => $base + ['connections' => 1, 'users' => 1]];
		}
	}
}
namespace {
	require $argv[1];
	ini_set('error_log', '/dev/stderr');
	ini_set('display_errors', 'stderr');
	$db = new class(TestDb::connect($argv[2])) {
		private $result;
		public function __construct(private \PDO $pdo) {}
		public function query($sql, ...$args) {
			$this->result = $this->pdo->prepare($sql);
			return $this->result->execute($args);
		}
		public function get_row() { return $this->result->fetch(\PDO::FETCH_ASSOC); }
		public function get_rows() { return $this->result->fetchAll(\PDO::FETCH_ASSOC); }
		public function num_rows() { return $this->result->rowCount(); }
	};
	\XcVm\Core\Config\SettingsManager::set(['redis_handler' => (int) $argv[3], 'total_users' => (int) $argv[3] ? 2 : 999]);
	\XcVm\Core\Http\RequestManager::set([]);
	$controller = new class extends \XcVm\Public\Controllers\Admin\Ajax\StatsAjaxController {
		protected function requireXhr(): void {}
		protected function gate(string $type, string $key): void {}
	};
	$controller->stats();
}
CODE;
		$rPath = tempnam(sys_get_temp_dir(), 'xcvm-dashboard-users-');
		file_put_contents($rPath, $rScript);
		try {
			foreach ([0, 1] as $rRedis) {
				$rProc = proc_open([PHP_BINARY, $rPath, dirname(__DIR__) . '/bootstrap.php', $rDb->schema(), (string) $rRedis], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $rPipes);
				$rOut = stream_get_contents($rPipes[1]);
				$rErr = stream_get_contents($rPipes[2]);
				fclose($rPipes[1]);
				fclose($rPipes[2]);
				$this->assertSame(0, proc_close($rProc), $rErr);
				$rResult = json_decode($rOut, true, 512, JSON_THROW_ON_ERROR);
				$this->assertSame(2, (int) $rResult['online_users'], 'unique users across servers, not the stale setting or sum of per-server users');
				$this->assertSame(2, (int) $rResult['total_users']);
				$this->assertSame(2, (int) $rResult['servers'][0]['online_users'], 'ended-only user is excluded');
				$this->assertSame(1, (int) $rResult['servers'][1]['online_users']);
			}
		} finally {
			unlink($rPath);
		}
	}
}
