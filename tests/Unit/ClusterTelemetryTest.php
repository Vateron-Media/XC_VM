<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\LocalTelemetry;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\SystemInfo;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\HeartbeatService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Phase 3: an agent's telemetry becomes the server's watchdog_data (in the
 * legacy shape) and its servers_stats rows once the node's TELEMETRY flow is
 * on; the LB side reads its flows from the agent's flows.json.
 */
final class ClusterTelemetryTest extends TestCase {
	private TestDb $rDb;

	private int $rT0 = 1800000000000;

	/** A sample as clusteragent.Sampler produces it. */
	private const SAMPLE = [
		'v' => 1, 'at_ms' => 1800000000000, 'cpu' => 37.5, 'cpu_cores' => 4, 'cpu_name' => 'Test CPU', 'load' => [2.0, 1.0, 0.5],
		'mem_total_kb' => 8000000, 'mem_avail_kb' => 6000000, 'disk_total' => 100000000000, 'disk_free' => 40000000000,
		'kernel' => '6.1.0', 'uptime_s' => 90061, 'stream_producers' => 3, 'php_pids' => [104, 105], 'interfaces' => ['eth0', 'eth1'],
		'net' => [
			'eth1' => ['in_bytes' => 10, 'in_packets' => 1, 'in_errors' => 0, 'out_bytes' => 20, 'out_packets' => 2, 'out_errors' => 0, 'rx_total' => 100, 'tx_total' => 200, 'speed' => 0],
			'eth0' => ['in_bytes' => 1000, 'in_packets' => 10, 'in_errors' => 1, 'out_bytes' => 2000, 'out_packets' => 20, 'out_errors' => 0, 'rx_total' => 3000, 'tx_total' => 6000, 'speed' => 1000],
			'bond0' => ['in_bytes' => 5, 'out_bytes' => 5, 'rx_total' => 5, 'tx_total' => 5, 'speed' => 10000],
		],
		'local' => ['requests_per_second' => 42, 'fanout' => ['running' => true, 'socket' => true, 'connections' => 7, 'memory' => null]],
	];

	/** SystemInfo::getDevices() on a node with a GPU, iostat and a capture card. */
	private const DEVICES = [
		'audio_devices' => ['hw:CARD=Capture,DEV=0'],
		'video_devices' => [['name' => 'USB Capture (usb-0000:00:14.0-1):', 'video_device' => 'video0']],
		'gpu_info' => [
			'attached_gpus' => '1', 'driver_version' => '535.54', 'cuda_version' => '12.2',
			'gpus' => [[
				'name' => 'NVIDIA T4', 'utilisation' => ['gpu_util' => '12 %', 'memory_util' => '3 %', 'encoder_util' => '20 %', 'decoder_util' => '8 %'],
				'memory_usage' => ['total' => '15360 MiB', 'used' => '1024 MiB', 'free' => '14336 MiB'], 'uuid' => 'GPU-1', 'id' => 0,
				'processes' => [['pid' => 123, 'memory' => '100 MiB']],
			]],
		],
		'iostat_info' => [
			'avg-cpu' => ['user' => 2.13, 'nice' => 0.5, 'system' => 0.98, 'iowait' => 7.4, 'steal' => 0.25, 'idle' => 89.49],
			'disk' => [['disk_device' => 'sda', 'tps' => 3.21, 'MB_read/s' => 0.03, 'MB_wrtn/s' => 0.05, 'MB_read' => 1234, 'MB_wrtn' => 2345]],
		],
	];

	/** @var list<string> */
	private array $rTemp = [];

	/** HeartbeatService's shadow copies and stats marker. */
	private string $rState;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec((string) (string) file_get_contents(MAIN_HOME . 'migrations/database/up/029_create_cluster_nodes.sql'));
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `features` varchar(255) DEFAULT NULL'); // 041
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `status` int NOT NULL DEFAULT 0, `watchdog_data` mediumtext, `last_check_ago` int DEFAULT 0, `requests_per_second` int DEFAULT 0, `php_pids` text, `connections` int DEFAULT 0, `users` int DEFAULT 0, `network_interface` varchar(32) DEFAULT NULL)');
		$this->rDb->exec("INSERT INTO `servers` (`id`, `status`, `watchdog_data`, `network_interface`) VALUES (5, 1, '{\"cpu_average_array\":[10,20]}', 'auto')");
		$this->rDb->exec('CREATE TABLE `lines_live` (`activity_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `user_id` int, `server_id` int, `hls_end` int DEFAULT 0)');
		$this->rDb->exec('INSERT INTO `lines_live` (`user_id`, `server_id`, `hls_end`) VALUES (1, 5, 0), (1, 5, 0), (2, 5, 0), (3, 5, 1), (4, 6, 0)');
		$this->rDb->exec('CREATE TABLE `streams` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `type` int)');
		$this->rDb->exec('CREATE TABLE `streams_servers` (`stream_id` int, `server_id` int, `pid` int)');
		$this->rDb->exec('INSERT INTO `streams` (`id`, `type`) VALUES (1, 1), (2, 1), (3, 2)');
		$this->rDb->exec('INSERT INTO `streams_servers` VALUES (1, 5, 10), (2, 5, 0), (3, 5, 11)');
		$this->rDb->exec('CREATE TABLE `servers_stats` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int, `connections` int, `total_users` int, `users` int, `streams` int, `cpu` float, `cpu_cores` int, `cpu_avg` float, `total_mem` int, `total_mem_free` int, `total_mem_used` int, `total_mem_used_percent` float, `total_disk_space` bigint, `uptime` varchar(255), `total_running_streams` int, `bytes_sent` bigint, `bytes_received` bigint, `bytes_sent_total` bigint, `bytes_received_total` bigint, `cpu_load_average` float, `gpu_info` text, `iostat_info` text, `time` int)');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['redis_handler' => 0, 'total_users' => 9]);
		ClusterClock::fix($this->rT0);
		// With TMP_PATH defined (by a test that ran earlier), HeartbeatService
		// keeps its once-a-minute stats marker there, across tests and runs.
		if (defined('TMP_PATH')) {
			@unlink(TMP_PATH . 'cluster/stats_5');
		}
		NodeRegistry::startEnrolment(5, '3b0c1d2e-4f5a-4b6c-8d7e-9f0a1b2c3d4e', str_repeat("\1", 32), str_repeat("\2", 32), 1);
		NodeRegistry::update(5, ['state' => 'active']);
		// The shadow copies and the stats marker: not TMP_PATH, which another test may define.
		$this->rState = $this->temp(true) . '/';
		HeartbeatService::useDir($this->rState);
	}

	protected function tearDown(): void {
		HeartbeatService::useDir(null);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
		NodeFlows::usePath(null);
		foreach ($this->rTemp as $rPath) {
			foreach (is_dir($rPath) ? (glob($rPath . '/*') ?: []) : [] as $rFile) {
				@unlink($rFile);
			}
			is_dir($rPath) ? @rmdir($rPath) : @unlink($rPath);
		}
	}

	/** A path in the system temp directory, removed after the test (a directory's files too). */
	private function temp(bool $rDir = false): string {
		$rPath = sys_get_temp_dir() . '/xcvm_local_' . uniqid('', true);
		if ($rDir) {
			mkdir($rPath);
		}
		$this->rTemp[] = $rPath;
		return $rPath;
	}

	/** The telemetry sample with this local.json, as the agent forwards it. */
	private static function withLocal(array $rLocal): array {
		return ['local' => $rLocal] + self::SAMPLE;
	}

	private function server(): array {
		$this->rDb->query('SELECT * FROM `servers` WHERE `id` = 5');
		return $this->rDb->get_row();
	}

	private function statsRows(): int {
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `servers_stats`');
		return (int) $this->rDb->get_row()['n'];
	}

	public function testWatchdogDataKeepsTheLegacyShape(): void {
		// SystemInfo::getStats()'s keys in the order it first sets them (read
		// from its source: running it would sample this host and the servers cache).
		$rMethod = new ReflectionMethod(SystemInfo::class, 'getStats');
		$rSource = implode('', array_slice(file((string) $rMethod->getFileName()), $rMethod->getStartLine() - 1, $rMethod->getEndLine() - $rMethod->getStartLine() + 1));
		preg_match_all('/\$rJSON\[\'([a-z_]+)\'\]/', $rSource, $rM);
		$rLegacy = array_values(array_unique($rM[1]));
		$this->assertContains('network_info', $rLegacy);
		$rLegacy[] = 'cpu_average_array'; // the watchdog adds these two
		$rLegacy[] = 'fanout';
		$this->assertSame($rLegacy, array_keys(HeartbeatService::toWatchdogData(self::SAMPLE, [])), 'same keys, same order');
	}

	public function testWatchdogDataFromTelemetry(): void {
		$rData = HeartbeatService::toWatchdogData(self::SAMPLE, ['cpu_average_array' => range(1, 30)]);
		$this->assertSame(37.5, $rData['cpu']);
		$this->assertSame(50.0, $rData['cpu_avg'], 'load 2.0 over 4 cores');
		$this->assertSame([8000000, 6000000, 2000000, 25.0], [$rData['total_mem'], $rData['total_mem_free'], $rData['total_mem_used'], $rData['total_mem_used_percent']]);
		$this->assertSame('1d 1h 1m 1s', $rData['uptime']);
		$this->assertSame(3, $rData['total_running_streams']);
		$this->assertSame(['eth0', 'eth1'], array_keys($rData['network_info']), 'bond and lo are left out under auto');
		$this->assertSame([2020, 1010, 6200, 3100, 1000], [$rData['bytes_sent'], $rData['bytes_received'], $rData['bytes_sent_total'], $rData['bytes_received_total'], $rData['network_speed']]);
		$this->assertCount(30, $rData['cpu_average_array']);
		$this->assertSame(37.5, end($rData['cpu_average_array']));
		$this->assertSame(7, $rData['fanout']['connections']);

		$rOne = HeartbeatService::toWatchdogData(self::SAMPLE, [], 'eth1');
		$this->assertSame(['eth1'], array_keys($rOne['network_info']));
		$this->assertSame([20, 0], [$rOne['bytes_sent'], $rOne['network_speed']]);
		$this->assertSame(['bond0'], array_keys(HeartbeatService::toWatchdogData(self::SAMPLE, [], 'bond0')['network_info']), 'a chosen bond is reported');
	}

	public function testDevicesComeFromTheNodesLocalFile(): void {
		$rData = HeartbeatService::toWatchdogData(self::withLocal(self::SAMPLE['local'] + self::DEVICES), []);
		foreach (self::DEVICES as $rKey => $rValue) {
			$this->assertSame($rValue, $rData[$rKey], $rKey);
		}
		// What the dashboard and the server page read (StatsAjaxController, server_view.php).
		$this->assertEquals(7.0, round($rData['iostat_info']['avg-cpu']['iowait'] ?? 0, 0));
		$this->assertSame(['NVIDIA T4'], array_column($rData['gpu_info']['gpus'], 'name'));
		$this->assertSame(7, $rData['fanout']['connections'], 'the fanout status still comes along');

		$rOld = HeartbeatService::toWatchdogData(self::SAMPLE, self::DEVICES);
		$rNone = HeartbeatService::toWatchdogData(['local' => null] + self::SAMPLE, self::DEVICES);
		foreach (array_keys(self::DEVICES) as $rKey) {
			$this->assertSame([], $rOld[$rKey], $rKey . ': a fresh local.json without it means the tool is absent (or the node predates it)');
			$this->assertSame([], $rNone[$rKey], $rKey . ': no local.json, nothing reported, not the last value');
		}
	}

	public function testDeviceSectionsAreCheckedForShape(): void {
		$rData = HeartbeatService::toWatchdogData(self::withLocal([
			'audio_devices' => [7, 'hw:CARD=A,DEV=0', ['x']],
			'video_devices' => ['junk', ['name' => 'Cam', 'video_device' => 'video0']],
			'gpu_info' => ['driver_version' => '1', 'gpus' => ['junk', ['name' => 'A']]],
			'iostat_info' => ['avg-cpu' => ['iowait' => 'lots', 'idle' => '93.5', 'user' => [1]], 'disk' => []],
		]), []);
		$this->assertSame(['hw:CARD=A,DEV=0'], $rData['audio_devices'], 'device names are strings, as a list');
		$this->assertSame([['name' => 'Cam', 'video_device' => 'video0']], $rData['video_devices'], 'video devices are objects, as a list');
		$this->assertSame([['name' => 'A']], $rData['gpu_info']['gpus'], 'GPUs are objects');
		$this->assertSame(['idle' => '93.5'], $rData['iostat_info']['avg-cpu'], 'the CPU figures the dashboard rounds are numbers');

		$rOdd = HeartbeatService::toWatchdogData(self::withLocal(['audio_devices' => 'hw:CARD=A', 'video_devices' => 5, 'gpu_info' => ['gpus' => 'x'], 'iostat_info' => ['avg-cpu' => 'x']]), []);
		$this->assertSame([[], [], ['gpus' => []], ['avg-cpu' => []]], [$rOdd['audio_devices'], $rOdd['video_devices'], $rOdd['gpu_info'], $rOdd['iostat_info']]);
	}

	public function testTheServerAndStatsRowsCarryTheDevices(): void {
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_TELEMETRY]);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => self::withLocal(self::SAMPLE['local'] + self::DEVICES)], $this->rT0);
		$rWd = json_decode($this->server()['watchdog_data'], true);
		foreach (self::DEVICES as $rKey => $rValue) {
			$this->assertSame($rValue, $rWd[$rKey], $rKey);
		}
		$this->rDb->query('SELECT `gpu_info`, `iostat_info` FROM `servers_stats`');
		$rRow = $this->rDb->get_row();
		$this->assertSame(self::DEVICES['gpu_info'], json_decode($rRow['gpu_info'], true));
		$this->assertSame(self::DEVICES['iostat_info'], json_decode($rRow['iostat_info'], true));
		// What ServerViewController and the dashboard's history read.
		$this->assertSame(7.4, floatval(json_decode($rRow['iostat_info'], true)['avg-cpu']['iowait'] ?? 0));
	}

	public function testLocalFileIsRefreshedEveryPassAndProbedEvery30Seconds(): void {
		$rDir = $this->temp(true) . '/';
		$rCache = $this->temp();
		$rCalls = 0;
		$rProbe = function () use (&$rCalls): array {
			$rCalls++;
			return ['iostat_info' => ['avg-cpu' => ['iowait' => (float) $rCalls]]] + self::DEVICES;
		};
		$rLocal = fn(): array => json_decode((string) file_get_contents($rDir . 'local.json'), true);
		// What the file held when the probe started.
		$rBefore = null;
		$rWatched = function () use (&$rBefore, $rDir, $rLocal, $rProbe): array {
			$rBefore = is_file($rDir . 'local.json') ? $rLocal() : null;
			return $rProbe();
		};
		$rFanout = self::SAMPLE['local']['fanout'];

		// The first pass has nothing probed yet: the probe runs, then the file is written.
		$this->assertTrue(LocalTelemetry::refresh($rDir, $rCache, 1000, ['requests_per_second' => 42, 'fanout' => $rFanout], $rWatched));
		$this->assertSame(1, $rCalls);
		$this->assertNull($rBefore);
		$rDevices = array_replace(self::DEVICES, ['iostat_info' => ['avg-cpu' => ['iowait' => 1.0]]]);
		$this->assertSame(['requests_per_second' => 42, 'fanout' => $rFanout] + $rDevices, $rLocal(), 'the probe, in watchdog_data order');

		// The watchdog re-execs after every pass: the next pass reads the cache file, not a variable.
		$this->assertTrue(LocalTelemetry::refresh($rDir, $rCache, 1010, ['requests_per_second' => 7, 'fanout' => null], $rWatched));
		$this->assertSame(1, $rCalls, 'reused for 30 s');
		$this->assertSame([7, 1.0], [$rLocal()['requests_per_second'], $rLocal()['iostat_info']['avg-cpu']['iowait']], 'the file is still rewritten every pass');
		LocalTelemetry::refresh($rDir, $rCache, 1029, ['requests_per_second' => 8, 'fanout' => null], $rWatched);
		$this->assertSame(1, $rCalls);

		// Due: the file is written with the last probe first, so a slow probe never ages it, then again.
		$this->assertTrue(LocalTelemetry::refresh($rDir, $rCache, 1030, ['requests_per_second' => 9, 'fanout' => null], $rWatched));
		$this->assertSame(2, $rCalls);
		$this->assertSame([9, 1.0], [$rBefore['requests_per_second'], $rBefore['iostat_info']['avg-cpu']['iowait']], 'this pass, the last probe');
		$this->assertSame([9, 2.0], [$rLocal()['requests_per_second'], $rLocal()['iostat_info']['avg-cpu']['iowait']], 'this pass, the new probe');

		// A probe a minute old or more is not reported meanwhile: the probe runs first.
		LocalTelemetry::refresh($rDir, $rCache, 1090, ['requests_per_second' => 11, 'fanout' => null], $rWatched);
		$this->assertSame(3, $rCalls);
		$this->assertSame(9, $rBefore['requests_per_second'], 'not rewritten before the probe');
		$this->assertSame([11, 3.0], [$rLocal()['requests_per_second'], $rLocal()['iostat_info']['avg-cpu']['iowait']]);
		LocalTelemetry::refresh($rDir, $rCache, 1000, ['requests_per_second' => 12, 'fanout' => null], $rWatched);
		$this->assertSame([4, 11], [$rCalls, $rBefore['requests_per_second']], 'a clock that went back probes first');
		file_put_contents($rCache, '{broken');
		LocalTelemetry::refresh($rDir, $rCache, 1001, ['requests_per_second' => 13, 'fanout' => null], $rWatched);
		$this->assertSame([5, 12], [$rCalls, $rBefore['requests_per_second']], 'so does an unreadable cache');
		$this->assertSame(5, (int) (json_decode((string) file_get_contents($rCache), true)['devices']['iostat_info']['avg-cpu']['iowait'] ?? 0), 'and it is replaced');

		$this->assertFalse(LocalTelemetry::refresh($rDir . 'missing/', $rCache, 1100, ['requests_per_second' => 1], $rWatched));
		$this->assertSame(5, $rCalls, 'no agent directory: no file, no probe');
		$rOther = $this->temp(true) . '/';
		LocalTelemetry::refresh($rOther, $this->temp(), 1000, ['requests_per_second' => 1], fn(): array => ['gpu_info' => 'n/a']);
		$this->assertSame(['requests_per_second' => 1] + array_fill_keys(LocalTelemetry::DEVICES, []), json_decode((string) file_get_contents($rOther . 'local.json'), true), 'a missing or odd section is empty');
		$this->assertTrue(LocalTelemetry::refresh($rOther, $this->temp() . '/no/such/dir', 1000, ['requests_per_second' => 1], fn(): array => self::DEVICES));
		$this->assertSame(self::DEVICES['audio_devices'], json_decode((string) file_get_contents($rOther . 'local.json'), true)['audio_devices'], 'an unwritable cache still reports');
	}

	public function testLocalFileStaysUnderTheAgentsCap(): void {
		$this->assertLessThanOrEqual(61440, LocalTelemetry::MAX_BYTES, 'the agent drops a local.json over 64 KiB; keep a margin');
		$rDoc = ['requests_per_second' => 42, 'fanout' => self::SAMPLE['local']['fanout']] + self::DEVICES;
		$this->assertSame($rDoc, json_decode(LocalTelemetry::encode($rDoc), true), 'a usual document goes whole');

		// Exactly at the cap goes whole, GPU processes too; a byte over loses them.
		$rEdge = ['fanout' => ['memory' => '']] + $rDoc;
		$rEdge['fanout']['memory'] = str_repeat('x', LocalTelemetry::MAX_BYTES - strlen(LocalTelemetry::encode($rEdge)));
		$this->assertSame(LocalTelemetry::MAX_BYTES, strlen(LocalTelemetry::encode($rEdge)));
		$this->assertSame($rEdge, json_decode(LocalTelemetry::encode($rEdge), true), 'exactly at the cap goes whole');
		$rEdge['fanout']['memory'] .= 'x';
		$rTrimmed = $rEdge;
		$rTrimmed['gpu_info']['gpus'][0]['processes'] = [];
		$this->assertSame($rTrimmed, json_decode(LocalTelemetry::encode($rEdge), true), 'a byte over does not');

		// A busy transcoder: one GPU process per stream, on each GPU.
		$rBusy = $rDoc;
		$rBusy['gpu_info']['gpus'][1] = ['name' => 'NVIDIA T4 #2'] + $rBusy['gpu_info']['gpus'][0];
		foreach ([0, 1] as $rGPU) {
			$rBusy['gpu_info']['gpus'][$rGPU]['processes'] = array_fill(0, 1500, ['pid' => 123456, 'memory' => '100 MiB']);
		}
		$rJson = LocalTelemetry::encode($rBusy);
		$this->assertLessThanOrEqual(LocalTelemetry::MAX_BYTES, strlen($rJson));
		$rOut = json_decode($rJson, true);
		$this->assertSame([[], []], array_column($rOut['gpu_info']['gpus'], 'processes'), 'the process lists go first, every GPU\'s');
		$this->assertSame(['NVIDIA T4', 'NVIDIA T4 #2'], array_column($rOut['gpu_info']['gpus'], 'name'), 'the GPUs themselves stay');
		$this->assertSame(self::DEVICES['iostat_info'], $rOut['iostat_info']);

		// Then the largest section: hundreds of loop devices in iostat.
		$rDisks = $rBusy;
		$rDisks['iostat_info']['disk'] = array_fill(0, 2000, self::DEVICES['iostat_info']['disk'][0]);
		$rOut = json_decode(LocalTelemetry::encode($rDisks), true);
		$this->assertSame([], $rOut['iostat_info']);
		$this->assertSame(self::DEVICES['video_devices'], $rOut['video_devices']);
		$this->assertSame('NVIDIA T4', $rOut['gpu_info']['gpus'][0]['name']);
		$this->assertSame(42, $rOut['requests_per_second']);

		// Everything oversized: only PHP's own figures remain.
		$rHuge = $rDisks;
		$rHuge['audio_devices'] = array_fill(0, 5000, 'hw:CARD=Capture,DEV=0');
		$rHuge['video_devices'] = array_fill(0, 5000, self::DEVICES['video_devices'][0]);
		$rHuge['gpu_info']['gpus'] = array_fill(0, 1000, self::DEVICES['gpu_info']['gpus'][0]);
		$rJson = LocalTelemetry::encode($rHuge);
		$this->assertLessThanOrEqual(LocalTelemetry::MAX_BYTES, strlen($rJson));
		$this->assertSame(['requests_per_second' => 42, 'fanout' => $rDoc['fanout'], 'audio_devices' => [], 'video_devices' => [], 'gpu_info' => [], 'iostat_info' => []], json_decode($rJson, true));

		// Never over, whatever is in it: then only the requests per second, and fanout is dropped.
		$rJson = LocalTelemetry::encode(['requests_per_second' => 42, 'fanout' => ['memory' => str_repeat('x', 70000)]] + self::DEVICES);
		$this->assertSame(['requests_per_second' => 42, 'audio_devices' => [], 'video_devices' => [], 'gpu_info' => [], 'iostat_info' => []], json_decode($rJson, true));
	}

	public function testLocalFileIsWrittenWholeAndReadBackOnMain(): void {
		$rDir = $this->temp(true) . '/';
		$rDoc = ['requests_per_second' => 42, 'fanout' => null] + self::DEVICES;
		$rDoc['iostat_info']['avg-cpu']['iowait'] = 7.0;
		$this->assertTrue(LocalTelemetry::write($rDir, $rDoc));
		$this->assertSame(['local.json'], array_values(array_diff((array) scandir($rDir), ['.', '..'])), 'no temporary file is left');
		// The agent parses the file and re-encodes it (clusteragent.Sampler.local):
		// object keys come out sorted and 7.0 as 7. MAIN relies on neither.
		$rAgent = static function (mixed $rValue) use (&$rAgent): mixed {
			if (!is_array($rValue)) {
				return $rValue;
			}
			if (!array_is_list($rValue)) {
				ksort($rValue, SORT_STRING);
			}
			return array_map($rAgent, $rValue);
		};
		$rLocal = json_decode((string) json_encode($rAgent(json_decode((string) file_get_contents($rDir . 'local.json'), true))), true);
		$rData = HeartbeatService::toWatchdogData(self::withLocal($rLocal), []);
		foreach (LocalTelemetry::DEVICES as $rKey) {
			$this->assertEquals($rDoc[$rKey], $rData[$rKey], $rKey);
		}
		$this->assertSame(7, $rData['iostat_info']['avg-cpu']['iowait'], 'an int is a number too');
		$this->assertFalse(LocalTelemetry::write($rDir . 'missing/', ['requests_per_second' => 1]), 'no agent directory, no file');
	}

	public function testALocalOverTheAgentsCapIsIgnoredOnMain(): void {
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_TELEMETRY]);
		// A local.json at the agent's 64 KiB cap, as MAIN encodes it.
		$rLocal = self::SAMPLE['local'] + self::DEVICES;
		$rSize = static fn(array $rDoc): int => strlen((string) json_encode($rDoc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
		$rLocal['audio_devices'][] = str_repeat('a', HeartbeatService::MAX_LOCAL - $rSize($rLocal) - 3);
		$this->assertSame(HeartbeatService::MAX_LOCAL, $rSize($rLocal));
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => self::withLocal($rLocal)], $this->rT0);
		$this->assertCount(2, json_decode($this->server()['watchdog_data'], true)['audio_devices'], 'at the cap it is read');
		$this->assertSame(42, (int) $this->server()['requests_per_second']);
		// The shadow copy takes it along with the rest of the sample.
		$rShadow = json_decode((string) @file_get_contents($this->rState . 'tel_5.json'), true);
		$this->assertSame($rLocal, $rShadow['telemetry']['local'] ?? null, 'MAX_TELEMETRY holds a local.json at the cap and the sample');

		// A node that bypasses its agent: over the cap, as if there were no local.json.
		$rLocal['audio_devices'][1] .= 'a';
		ClusterClock::fix($this->rT0 + 60000);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => self::withLocal($rLocal)], $this->rT0 + 60000);
		$rWd = json_decode($this->server()['watchdog_data'], true);
		$this->assertSame([[], [], [], []], [$rWd['audio_devices'], $rWd['video_devices'], $rWd['gpu_info'], $rWd['iostat_info']]);
		$this->assertSame([0, 7], [(int) $this->server()['requests_per_second'], $rWd['fanout']['connections']], 'no requests per second; the last fanout status');
		$this->rDb->query('SELECT `gpu_info`, `iostat_info` FROM `servers_stats` ORDER BY `id` DESC LIMIT 1');
		$this->assertSame(['gpu_info' => '[]', 'iostat_info' => '[]'], $this->rDb->get_row(), 'nothing of it is kept in servers_stats');
	}

	public function testGetStatsAndLocalTelemetryProbeTheSameWay(): void {
		// getStats() takes its four device sections from getDevices(), the probe
		// the watchdog's local.json uses, so the two cannot drift apart.
		$rSource = static function (string $rMethod): string {
			$rM = new ReflectionMethod(SystemInfo::class, $rMethod);
			return implode('', array_slice(file((string) $rM->getFileName()), $rM->getStartLine() - 1, $rM->getEndLine() - $rM->getStartLine() + 1));
		};
		$this->assertStringContainsString('self::getDevices()', $rSource('getStats'));
		$rDevices = $rSource('getDevices');
		foreach (['which iostat' => 'getIO', 'which nvidia-smi' => 'getGPUInfo', 'which v4l2-ctl' => 'getVideoDevices', 'which arecord' => 'getAudioDevices'] as $rCheck => $rProbe) {
			$this->assertMatchesRegularExpression('/' . preg_quote($rCheck, '/') . '.*\s+.*self::' . $rProbe . '\(\$rTimeout\)/', $rDevices, $rProbe . ' only when its tool is installed');
		}
		// getStats() waits for each tool, as before; the watchdog's local.json bounds them.
		$rBounded = new ReflectionMethod(SystemInfo::class, 'bounded');
		$this->assertSame('nvidia-smi -x -q', $rBounded->invoke(null, 'nvidia-smi -x -q', 0));
		$this->assertSame('timeout -k 1 5 nvidia-smi -x -q', $rBounded->invoke(null, 'nvidia-smi -x -q', LocalTelemetry::PROBE_TIMEOUT));
		$rWatchdog = (string) file_get_contents(MAIN_HOME . 'Cli/Commands/WatchdogCommand.php');
		$this->assertStringContainsString('LocalTelemetry::refresh(', $rWatchdog);
		$this->assertStringContainsString('SystemInfo::getDevices(LocalTelemetry::PROBE_TIMEOUT)', $rWatchdog);
	}

	public function testNothingIsWrittenWhileTheFlowIsOff(): void {
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => self::SAMPLE], $this->rT0);
		$this->assertSame(0, (int) $this->server()['last_check_ago']);
		$this->assertSame(0, $this->statsRows());
	}

	public function testTelemetryFlowWritesTheServerRow(): void {
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_TELEMETRY]);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => self::SAMPLE], $this->rT0);
		$rServer = $this->server();
		$this->assertSame(1800000000, (int) $rServer['last_check_ago']);
		$this->assertSame(42, (int) $rServer['requests_per_second']);
		$this->assertSame('[104,105]', $rServer['php_pids']);
		$this->assertSame([3, 2], [(int) $rServer['connections'], (int) $rServer['users']], 'live rows of this server, users grouped');
		$rWd = json_decode($rServer['watchdog_data'], true);
		$this->assertSame([10, 20, 37.5], $rWd['cpu_average_array'], 'the history continues');

		$this->rDb->query('SELECT * FROM `servers_stats`');
		$rStats = $this->rDb->get_row();
		$this->assertSame([5, 3, 9, 2, 1], [(int) $rStats['server_id'], (int) $rStats['connections'], (int) $rStats['total_users'], (int) $rStats['users'], (int) $rStats['streams']]);
		$this->assertEquals(22.5, (float) $rStats['cpu'], 'the average of the history, as cron:servers stored it');

		// Heartbeats come every 2 s; the row is written every 5 s.
		ClusterClock::fix($this->rT0 + 2000);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => ['cpu' => 99.0] + self::SAMPLE], $this->rT0 + 2000);
		$this->assertSame(1800000000, (int) $this->server()['last_check_ago']);
		ClusterClock::fix($this->rT0 + 5000);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => ['cpu' => 99.0] + self::SAMPLE], $this->rT0 + 5000);
		$this->assertSame(1800000005, (int) $this->server()['last_check_ago']);
		$this->assertEquals(99.0, json_decode($this->server()['watchdog_data'], true)['cpu']);
	}

	public function testRedisHandlerLeavesTheCountsToMainsWatchdog(): void {
		SettingsManager::set(['redis_handler' => 1]);
		$this->rDb->exec('UPDATE `servers` SET `connections` = 11, `users` = 4 WHERE `id` = 5');
		NodeRegistry::update(5, ['flows' => NodeRegistry::FLOW_TELEMETRY]);
		HeartbeatService::record(NodeRegistry::byServer(5), ['telemetry' => self::SAMPLE], $this->rT0);
		$this->assertSame([11, 4], [(int) $this->server()['connections'], (int) $this->server()['users']]);
		$this->rDb->query('SELECT `connections`, `users` FROM `servers_stats`');
		$this->assertSame([11, 4], array_map('intval', array_values($this->rDb->get_row())));
	}

	public function testNodeFlowsReadsTheAgentsFile(): void {
		$rPath = tempnam(sys_get_temp_dir(), 'flows');
		file_put_contents($rPath, '{"flows":1,"mode":1,"state":"active"}');
		NodeFlows::usePath($rPath);
		$this->assertTrue(NodeFlows::on(NodeFlows::TELEMETRY));
		$this->assertFalse(NodeFlows::on(NodeFlows::COMMANDS));

		file_put_contents($rPath, '{"flows":1,"mode":0,"state":"active"}');
		NodeFlows::usePath($rPath);
		$this->assertFalse(NodeFlows::on(NodeFlows::TELEMETRY), 'mode 0 is legacy whatever the bits');
		file_put_contents($rPath, '{"flows":1,"mode":1,"state":"revoked"}');
		NodeFlows::usePath($rPath);
		$this->assertFalse(NodeFlows::on(NodeFlows::TELEMETRY));
		unlink($rPath);
		NodeFlows::usePath($rPath);
		$this->assertFalse(NodeFlows::on(NodeFlows::TELEMETRY), 'no file: legacy');
	}

	public function testAdminTogglesTheFlow(): void {
		$rServers = [1 => ['is_main' => 1, 'server_type' => 0], 5 => ['is_main' => 0, 'server_type' => 0, 'server_name' => 'LB-5']];
		$rAct = fn(string $rAction) => \XcVm\Domain\Cluster\ClusterAdmin::act(new \XcVm\Tests\Support\FakeClusterCrypto(), ['cluster_action' => $rAction, 'server_id' => 5], $rServers, 1, [], 3);
		$this->assertSame('cluster_telemetry_on_done', $rAct('telemetry_on')['message']);
		$this->assertSame(NodeRegistry::FLOW_TELEMETRY, (int) NodeRegistry::byServer(5)['flows']);
		$this->assertSame('cluster_telemetry_off_done', $rAct('telemetry_off')['message']);
		$this->assertSame(0, (int) NodeRegistry::byServer(5)['flows']);
		$rAct('logs_on');
		$this->assertSame('cluster_streams_on_done', $rAct('streams_on')['message']);
		$this->assertSame(NodeRegistry::FLOW_LOGS | NodeRegistry::FLOW_STREAMS, (int) NodeRegistry::byServer(5)['flows']);
		$rAct('logs_off');
		$this->assertSame(NodeRegistry::FLOW_STREAMS, (int) NodeRegistry::byServer(5)['flows']);
		$this->assertSame('cluster_content_on_done', $rAct('content_on')['message']);
		$this->assertSame('cluster_flow_needs', $rAct('connections_on')['message'], 'Connections needs Commands too');
		$rAct('commands_on');
		$this->assertSame('cluster_connections_on_done', $rAct('connections_on')['message']);
		$this->assertSame('cluster_flow_needs', $rAct('commands_off')['message'], 'and cannot lose it while on');
		$rAct('connections_off');
		$rAct('commands_off');
		$this->assertSame(NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT, (int) NodeRegistry::byServer(5)['flows']);
		// The data plane points the node's encoders at its agent's relay proxy:
		// only for an agent that says it runs one.
		NodeRegistry::update(5, ['features' => 'hls_reaper,streams']);
		$this->assertSame('cluster_dataplane_needs_relay', $rAct('dataplane_on')['message'], 'an agent without the relay proxy');
		$this->assertSame(NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT, (int) NodeRegistry::byServer(5)['flows']);
		$this->assertFalse(\XcVm\Domain\Cluster\ClusterAdmin::relayAdvertised(NodeRegistry::byServer(5)));
		NodeRegistry::update(5, ['features' => 'hls_reaper,streams,relay']);
		$this->assertTrue(\XcVm\Domain\Cluster\ClusterAdmin::relayAdvertised(NodeRegistry::byServer(5)));
		$this->assertSame('cluster_dataplane_on_done', $rAct('dataplane_on')['message']);
		NodeRegistry::update(5, ['features' => '']);
		$this->assertSame('cluster_dataplane_off_done', $rAct('dataplane_off')['message'], 'off whatever the agent says');
		$this->assertSame(NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT, (int) NodeRegistry::byServer(5)['flows']);
		NodeRegistry::update(5, ['state' => 'revoked']);
		$this->assertSame('cluster_not_enrolled', $rAct('telemetry_on')['message']);
	}
}
