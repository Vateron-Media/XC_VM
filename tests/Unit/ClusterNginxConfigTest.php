<?php

use PHPUnit\Framework\TestCase;
use XcVm\Cli\Commands\ClusterNginxCommand;
use XcVm\Cli\CronJobs\ClusterCronJob;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\ClusterPool;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * MAIN's rendered nginx config for the cluster API (plan §3, "Transport" and
 * "Endpoints and HTTPS"): the /cluster/v1/ location that the public server
 * includes, a plain-HTTP server of its own on `cluster_api_port`, and the old
 * ports kept for seven days after an endpoint change. apply() writes them
 * atomically, keeps them only once `nginx -t` passes, and reloads nginx.
 *
 * nginx itself, and the port checks around a new dedicated port, are faked in
 * most tests; testRealNginx runs the rendered files through a real `nginx -t`,
 * opt-in: XCVM_TEST_NGINX=/path/to/nginx. Not covered: the lock that
 * serialises callers, and that a write is a temporary file then a rename.
 */
final class ClusterNginxConfigTest extends TestCase {
	private TestDb $rDb;

	private string $rBase;

	private int $rNow = 1800000000;

	/** @var list<list<string>> the nginx commands run, in order */
	private array $rRuns = [];

	/** What the faked `nginx -t` answers: [exit code, output]. */
	private array $rTest = [0, ''];

	/** @var list<int> ports another program listens on: the faked 'free' check fails for them */
	private array $rBusy = [];

	/** @var list<int> ports [::] can be bound on (the faked 'free6' check); none: a machine without IPv6 */
	private array $rFree6 = [];

	/** Whether nginx serves a new port after the reload (the faked 'serving' check). */
	private bool $rServing = true;

	/** @var list<string> the port checks run, as "free:31200" or "serving:31200" */
	private array $rProbes = [];

	private array $rSettingsBefore = [];

	protected function setUp(): void {
		$this->rBase = sys_get_temp_dir() . '/xcvm-nginx-' . bin2hex(random_bytes(4)) . '/';
		mkdir($this->rBase . 'bin/nginx/conf/ports', 0777, true);
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/http.conf', 'listen 25461;');
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/https.conf', 'listen 25463 ssl;');
		copy(dirname(__DIR__, 2) . '/src/bin/nginx/conf/nginx.conf', $this->rBase . 'bin/nginx/conf/nginx.conf');
		$this->rDb = new TestDb();
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		// What apply() without settings renders from: the stored values.
		$this->rDb->exec("CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY, `cluster_api_enabled` int DEFAULT 0, `cluster_api_port` int DEFAULT 0, `cluster_policy_ver` int DEFAULT 1, `cluster_legacy_ports` varchar(255) DEFAULT '', `cluster_legacy_urls` text)");
		$this->rDb->exec('INSERT INTO `settings` (`id`) VALUES (1)');
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix($this->rNow * 1000);
		$this->rSettingsBefore = SettingsManager::getAll();
		// apply() runs only as the user nginx runs as: here, whoever runs the tests.
		ClusterNginxConfig::useBase($this->rBase, self::user());
		ClusterNginxConfig::useRunner(function (array $rArgv): array {
			$this->rRuns[] = $rArgv;
			return in_array('-t', $rArgv, true) ? $this->rTest : [0, ''];
		});
		ClusterNginxConfig::useProbe(function (string $rCheck, int $rPort): bool {
			if ($rCheck === 'free6') {
				return in_array($rPort, $this->rFree6, true);
			}
			$this->rProbes[] = $rCheck . ':' . $rPort;
			return $rCheck === 'free' ? !in_array($rPort, $this->rBusy, true) : $this->rServing;
		});
	}

	protected function tearDown(): void {
		ClusterNginxConfig::useRunner(null);
		ClusterNginxConfig::useProbe(null);
		ClusterNginxConfig::useBase(null);
		SettingsManager::set($this->rSettingsBefore);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
		exec('rm -rf ' . escapeshellarg($this->rBase));
	}

	private static function user(): string {
		$rUser = posix_getpwuid(posix_geteuid());
		return is_array($rUser) ? (string) $rUser['name'] : '';
	}

	private function conf(string $rFile): ?string {
		$rPath = $this->rBase . 'bin/nginx/conf/' . $rFile;
		return is_file($rPath) ? (string) file_get_contents($rPath) : null;
	}

	/** @return list<string> the nginx actions run since the last take: "test" or "reload" */
	private function takeRuns(): array {
		$rOut = [];
		foreach ($this->rRuns as $rArgv) {
			$this->assertSame($this->rBase . 'bin/nginx/sbin/nginx', $rArgv[0], "MAIN's own nginx");
			$this->assertContains($this->rBase . 'bin/nginx/conf/nginx.conf', $rArgv, 'the config under test');
			$rOut[] = in_array('-t', $rArgv, true) ? 'test' : (in_array('reload', $rArgv, true) ? 'reload' : implode(' ', $rArgv));
		}
		$this->rRuns = [];
		return $rOut;
	}

	private function audit(): array {
		$this->rDb->query('SELECT `event`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return $this->rDb->get_rows();
	}

	/** The stored settings row. */
	private function stored(): array {
		$this->rDb->query('SELECT * FROM `settings`');
		return $this->rDb->get_row();
	}

	private function store(string $rColumn, int|string $rValue): void {
		$this->rDb->query('UPDATE `settings` SET `' . $rColumn . '` = ?', $rValue);
	}

	/** @return list<string> the port checks run since the last take */
	private function takeProbes(): array {
		$rOut = $this->rProbes;
		$this->rProbes = [];
		return $rOut;
	}

	public function testTheLocationCarriesThePlansTransportLimits(): void {
		$rConf = ClusterNginxConfig::locations();
		$this->assertMatchesRegularExpression('#^location \^~ /cluster/v1/ \{#m', $rConf);
		$this->assertStringContainsString('limit_req zone=cluster burst=400 nodelay;', $rConf, 'burst 400');
		$this->assertStringContainsString('limit_req_status 429;', $rConf, "nginx's own 429, which agents treat as a transport error");
		$this->assertStringContainsString('client_max_body_size 8m;', $rConf);
		$this->assertStringContainsString('gzip off;', $rConf);
		$this->assertStringContainsString('fastcgi_pass cluster_ctl;', $rConf);
		$this->assertStringContainsString('fastcgi_param SCRIPT_FILENAME /home/xc_vm/Public/cluster/index.php;', $rConf);
		$this->assertStringNotContainsString('fastcgi_pass php;', $rConf, 'never the panel pool directly');

		// The ingest lanes come from the one list the pools use.
		$this->assertSame(1, preg_match('#location ~ \^/cluster/v1/\(([a-z_|]+)\)\$ \{\s*fastcgi_pass cluster_ingest;\s*\}#', $rConf, $rMatch));
		$this->assertSame(ClusterPool::INGEST_OPS, explode('|', $rMatch[1]));
		$this->assertSame(substr_count($rConf, '{'), substr_count($rConf, '}'));
	}

	/** The shipped file is what the renderer writes, so an update and the next render agree. */
	public function testTheShippedLocationsFileIsTheRendering(): void {
		$this->assertSame(ClusterNginxConfig::locations(), (string) file_get_contents(dirname(__DIR__, 2) . '/src/bin/nginx/conf/' . ClusterNginxConfig::LOCATIONS));
	}

	public function testNginxConfIncludesTheRenderedFiles(): void {
		$rRoot = dirname(__DIR__, 2);
		$rConf = (string) file_get_contents($rRoot . '/src/bin/nginx/conf/nginx.conf');

		$this->assertMatchesRegularExpression('#^\s*limit_req_zone \$realip_remote_addr zone=cluster:\d+m rate=100r/s;$#m', $rConf, '100 r/s per TCP peer, whatever X-Forwarded-For says');
		// The public server: the one that includes MAIN's broadcast ports.
		$this->assertSame(1, preg_match('#\n    server \{\n        include ports/\*\.conf;(.*?)\n    \}\n#s', $rConf, $rServer));
		$this->assertStringContainsString("\n        include cluster_locations.conf;\n", $rServer[1], 'the public server{} serves the API on the broadcast ports');
		$this->assertStringNotContainsString('location ^~ /cluster/v1/', $rConf, 'the location is rendered, not fixed');
		$this->assertStringNotContainsString('cluster_legacy', $rConf, "ClusterEndpoint's old file is retired");
		// The API's own port and the old ports: servers at http{} level, after the public one.
		$this->assertMatchesRegularExpression('#\n    \}\n(?:\n    \#[^\n]*)*\n    include cluster\.d/\*\.conf;\n\}\s*$#', $rConf);

		$this->assertStringNotContainsString('cluster', (string) file_get_contents($rRoot . '/lb_configs/nginx.conf'), 'the LB nginx has no cluster route');
	}

	/** cluster_api_port = 0: the API is served on the broadcast ports only, as before. */
	public function testPortZeroServesTheApiOnTheBroadcastPortsOnly(): void {
		$rFiles = ClusterNginxConfig::render(['cluster_api_port' => 0], [25461, 25463]);
		$this->assertSame(ClusterNginxConfig::locations(), $rFiles[ClusterNginxConfig::LOCATIONS]);
		$this->assertNull($rFiles[ClusterNginxConfig::LISTEN], 'no listener of its own');
		$this->assertNull($rFiles[ClusterNginxConfig::OLD_PORT], 'no old port kept');
		$this->assertSame([ClusterNginxConfig::LOCATIONS, ClusterNginxConfig::LISTEN, ClusterNginxConfig::OLD_PORT], array_keys($rFiles));
	}

	public function testADedicatedPortGetsAPlainHttpServerForTheApiAlone(): void {
		$rListen = ClusterNginxConfig::render(['cluster_api_port' => 31200], [25461, 25463])[ClusterNginxConfig::LISTEN];
		$this->assertIsString($rListen);
		$this->assertMatchesRegularExpression('#^server \{\n    listen 31200;\n#m', $rListen, 'plain HTTP');
		$this->assertStringNotContainsString('ssl', $rListen);
		$this->assertStringContainsString("    include cluster_locations.conf;\n", $rListen, 'the same location, so the same pools and limits');
		$this->assertMatchesRegularExpression('#location / \{\s*return 404;\s*\}#', $rListen, 'nothing else on that port');
		$this->assertSame(1, substr_count($rListen, 'server {'));

		// A port MAIN already serves (refused by ClusterSettings) never gets a second server.
		$this->assertNull(ClusterNginxConfig::render(['cluster_api_port' => 25461], [25461, 25463])[ClusterNginxConfig::LISTEN]);
		$this->assertNull(ClusterNginxConfig::render(['cluster_api_port' => 70000], [25461])[ClusterNginxConfig::LISTEN]);
	}

	public function testOldPortsServeTheApiAloneUntilTheyExpire(): void {
		$rSettings = [
			'cluster_api_port' => 31200,
			'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60, 25461 => $this->rNow + 60, 31200 => $this->rNow + 60, 31300 => $this->rNow + 60, 9000 => $this->rNow - 1]),
		];
		$rOld = ClusterNginxConfig::render($rSettings, [25461, 25463])[ClusterNginxConfig::OLD_PORT];
		$this->assertIsString($rOld);
		$this->assertStringContainsString('listen 8080;', $rOld);
		$this->assertStringContainsString('listen 31300;', $rOld);
		$this->assertStringNotContainsString('listen 25461;', $rOld, 'the public server still has it');
		$this->assertStringNotContainsString('listen 31200;', $rOld, 'the dedicated server has it');
		$this->assertStringNotContainsString('listen 9000;', $rOld, 'expired');
		$this->assertSame(2, substr_count($rOld, 'server {'));
		$this->assertSame(2, substr_count($rOld, 'include cluster_locations.conf;'), 'the old ports reach the cluster pools, not the panel pool');
		$this->assertSame(2, preg_match_all('#location / \{\s*return 404;\s*\}#', $rOld));
		$this->assertStringContainsString('# until ' . gmdate('Y-m-d H:i', $this->rNow + 60) . ' UTC', $rOld);

		$this->assertNull(ClusterNginxConfig::render(['cluster_legacy_ports' => (string) json_encode([25461 => $this->rNow + 60])], [25461])[ClusterNginxConfig::OLD_PORT]);
	}

	public function testTheServedPortsAreReadFromThePortsFiles(): void {
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/http.conf', 'listen 80 reuseport; listen 8080;');
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/https.conf', 'listen 443 ssl; listen [::]:8443 ssl;');
		$this->assertSame([80, 443, 8080, 8443], ClusterNginxConfig::servedPorts($this->rBase . 'bin/nginx/conf/'));
		unlink($this->rBase . 'bin/nginx/conf/ports/https.conf');
		$this->assertSame([80, 8080], ClusterNginxConfig::servedPorts($this->rBase . 'bin/nginx/conf/'));
	}

	/** [::] too, on each port it can be bound on or nginx has it already: a listen nginx cannot bind would stop its next start. */
	public function testIpv6IsListenedOnWhereItCanBeBound(): void {
		$rSettings = ['cluster_api_port' => 31200, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])];
		$this->assertTrue(ClusterNginxConfig::apply($rSettings)['ok']);
		$this->assertStringNotContainsString('[::]', (string) $this->conf(ClusterNginxConfig::LISTEN) . $this->conf(ClusterNginxConfig::OLD_PORT), 'no IPv6 on the machine');

		$this->rFree6 = [31200];
		$this->assertTrue(ClusterNginxConfig::apply($rSettings)['changed']);
		$this->assertStringContainsString("    listen 31200;\n    listen [::]:31200;\n", (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertStringNotContainsString('[::]', (string) $this->conf(ClusterNginxConfig::OLD_PORT), 'another program holds [::]:8080');

		// Once nginx has it, it keeps it without asking again.
		$this->rFree6 = [];
		$this->assertFalse(ClusterNginxConfig::apply($rSettings)['changed']);
		$this->assertStringContainsString('    listen [::]:31200;', (string) $this->conf(ClusterNginxConfig::LISTEN));
	}

	public function testApplyWritesTestsAndReloadsOnlyWhatChanged(): void {
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])]);
		$this->assertTrue($rResult['ok']);
		$this->assertTrue($rResult['changed']);
		$this->assertTrue($rResult['reloaded']);
		$this->assertSame(['test', 'reload'], $this->takeRuns(), 'nginx -t before the reload');
		$this->assertSame(ClusterNginxConfig::locations(), $this->conf(ClusterNginxConfig::LOCATIONS));
		$this->assertStringContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertStringContainsString('listen 8080;', (string) $this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame([], glob($this->rBase . 'bin/nginx/conf/{,cluster.d/}{,.}*.tmp', GLOB_BRACE), 'no temporary file left');
		$this->assertSame('cluster.nginx', $this->audit()[0]['event']);

		// Nothing to change: no test, no reload.
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])]);
		$this->assertSame(['ok' => true, 'changed' => false, 'reloaded' => false, 'error' => ''], $rResult);
		$this->assertSame([], $this->takeRuns());

		// Back to the broadcast port, the old port expired: both files go.
		ClusterClock::fix(($this->rNow + 61) * 1000);
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 0, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])], false);
		$this->assertTrue($rResult['ok'] && $rResult['changed']);
		$this->assertFalse($rResult['reloaded'], 'the caller reloads');
		$this->assertSame(['test'], $this->takeRuns());
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame(ClusterNginxConfig::locations(), $this->conf(ClusterNginxConfig::LOCATIONS));
	}

	public function testANginxRefusalRestoresThePreviousFiles(): void {
		ClusterNginxConfig::apply(['cluster_api_port' => 31200]);
		$this->takeRuns();
		file_put_contents($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::LOCATIONS, "# an older release's location\n");
		file_put_contents($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::RETIRED, "server { listen 8080; }\n");
		$rBefore = ['listen' => $this->conf(ClusterNginxConfig::LISTEN), 'locations' => $this->conf(ClusterNginxConfig::LOCATIONS), 'retired' => $this->conf(ClusterNginxConfig::RETIRED)];

		$this->rTest = [1, "nginx: [emerg] duplicate listen options for 0.0.0.0:31300 in cluster.d/listen.conf:4\nnginx: configuration file test failed\n"];
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31300, 'cluster_legacy_ports' => (string) json_encode([31200 => $this->rNow + 60])]);
		$this->assertFalse($rResult['ok']);
		$this->assertFalse($rResult['changed'], 'nothing changed in the end');
		$this->assertFalse($rResult['reloaded']);
		$this->assertStringContainsString('duplicate listen options for 0.0.0.0:31300', $rResult['error'], "nginx's own words");
		$this->assertSame(['test'], $this->takeRuns(), 'never reloaded');
		$this->assertSame($rBefore['listen'], $this->conf(ClusterNginxConfig::LISTEN), 'the old listener is back');
		$this->assertSame($rBefore['locations'], $this->conf(ClusterNginxConfig::LOCATIONS));
		$this->assertSame($rBefore['retired'], $this->conf(ClusterNginxConfig::RETIRED));
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT), 'a file that did not exist is gone again');
		$rAudit = $this->audit();
		$this->assertSame('cluster.nginx', end($rAudit)['event']);
		$this->assertStringContainsString('duplicate listen options', (string) end($rAudit)['detail']);
	}

	/**
	 * ClusterEndpoint's cluster_legacy.conf goes once nginx.conf reads
	 * cluster.d/ instead. An nginx.conf from before (the update rolls back a
	 * release's nginx.conf that fails nginx -t) reads neither cluster.d/ file:
	 * a render that needs one is refused, so no port is stored and announced
	 * that nginx never serves.
	 */
	public function testTheRetiredFileGoesOnlyWithTheNginxConfThatNoLongerReadsIt(): void {
		$rRetired = $this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::RETIRED;
		file_put_contents($rRetired, "server { listen 8080; }\n");
		file_put_contents($this->rBase . 'bin/nginx/conf/nginx.conf', "http {\n    include cluster_legacy*.conf;\n}\n");
		$this->assertTrue(ClusterNginxConfig::apply(['cluster_api_port' => 0])['ok'], 'nothing in cluster.d/ to serve');
		$this->assertFileExists($rRetired, 'still read by that nginx.conf');
		$this->takeRuns();

		foreach ([['cluster_api_port' => 31200], ['cluster_api_port' => 0, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])]] as $rSettings) {
			$rResult = ClusterNginxConfig::apply($rSettings);
			$this->assertFalse($rResult['ok']);
			$this->assertStringContainsString('nginx.conf predates the rendered cluster config', $rResult['error']);
			$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
			$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT));
			$this->assertSame([], $this->takeRuns(), 'nginx -t would pass');
		}
		$rStage = ClusterNginxConfig::stageApiPort(0, 31200, ['cluster_api_enabled' => 1], ['http_broadcast_port' => 25461]);
		$this->assertSame('cluster_error_nginx', $rStage['refused'] ?? null, 'the settings save is refused');
		$this->assertStringContainsString('predates', (string) $rStage['error']);

		copy(dirname(__DIR__, 2) . '/src/bin/nginx/conf/nginx.conf', $this->rBase . 'bin/nginx/conf/nginx.conf');
		$this->assertTrue(ClusterNginxConfig::apply(['cluster_api_port' => 0])['ok']);
		$this->assertFileDoesNotExist($rRetired);
	}

	public function testApplyRunsOnlyAsTheNginxUser(): void {
		ClusterNginxConfig::useBase($this->rBase, 'not-' . self::user());
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200]);
		$this->assertFalse($rResult['ok']);
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
		$this->assertNull($this->conf(ClusterNginxConfig::LOCATIONS));
		$this->assertSame([], $this->rRuns);
	}

	/**
	 * Who renders, and as whom: status at boot and after an update, the root
	 * set_port handler, cron:cluster when an old port expires, and a settings
	 * save that changes cluster_api_port; always as xc_vm.
	 */
	public function testTheCallSitesRenderAsXcVm(): void {
		$rSrc = dirname(__DIR__, 2) . '/src/';

		$rStatus = (string) file_get_contents($rSrc . 'Cli/Commands/StatusCommand.php');
		$this->assertMatchesRegularExpression('#if \(\$rServers\[SERVER_ID\]\[.is_main.\]\) \{[^}]*\$this->ensureClusterNginx\(\);#', $rStatus, 'MAIN only');
		$this->assertStringContainsString("['sudo', '-u', 'xc_vm', PHP_BIN, MAIN_HOME . 'console.php', 'cluster:nginx'", $rStatus, 'status runs as root, the render as xc_vm');
		$this->assertStringNotContainsString('ClusterNginxConfig::apply(', $rStatus);

		$rRoot = (string) file_get_contents($rSrc . 'Cli/CronJobs/RootSignalsCronJob.php');
		$this->assertStringNotContainsString('cluster_legacy.conf', $rRoot, 'root writes nothing under bin/nginx/conf for the cluster');
		$this->assertStringNotContainsString('ClusterNginxConfig::apply(', $rRoot);
		$rRender = "'sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php cluster:nginx --no-reload'";
		$this->assertSame(2, substr_count($rRoot, $rRender));
		// Each render reads the ports file just written (the old port goes to
		// old_port.conf only if the render sees the new one), and comes before
		// the handler's reload request (applied once after the pass,
		// RootSignalsCronJob::reloadAsked()).
		foreach (["file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/http.conf'", "file_put_contents(MAIN_HOME . 'bin/nginx/conf/ports/https.conf'"] as $rPorts) {
			$rWrite = strpos($rRoot, $rPorts);
			$this->assertNotFalse($rWrite, $rPorts);
			$rCall = strpos($rRoot, $rRender, $rWrite);
			$rReload = strpos($rRoot, 'self::$rReloadNginx = true;', $rWrite);
			$this->assertNotFalse($rCall, 'a render after ' . $rPorts);
			$this->assertNotFalse($rReload);
			$this->assertLessThan($rReload, $rCall, 'the render after ' . $rPorts . ' comes before the reload');
			$this->assertSame(1, substr_count(substr($rRoot, $rWrite, $rReload - $rWrite), $rRender), 'one render between ' . $rPorts . ' and its reload');
		}

		// cron:cluster renders every minute, with the API on or off (testCronRendersFromTheStoredSettingsEveryMinute).
		$rCron = (string) file_get_contents($rSrc . 'Cli/CronJobs/ClusterCronJob.php');
		$this->assertStringContainsString("'endpoint' => static fn() => self::endpoint(),", $rCron);
		$this->assertMatchesRegularExpression("#if \\(empty\\(SettingsManager::get\\('cluster_api_enabled'\\)\\)\\) \\{[^}]*static fn\\(\\) => self::endpoint\\(\\)#", $rCron, 'also with the API off');

		$rMake = (string) file_get_contents(dirname($rSrc) . '/Makefile');
		$rGate = (string) file_get_contents(dirname($rSrc) . '/tools/ci/verify-lb-archive.sh');
		foreach (['bin/nginx/conf/cluster_locations.conf', 'Cli/Commands/ClusterNginxCommand.php'] as $rFile) {
			$this->assertMatchesRegularExpression('#^\t' . preg_quote($rFile, '#') . '(?: \\\\)?$#m', $rMake, $rFile . ' is stripped from the LB build');
			$this->assertStringContainsString('"' . $rFile . '"', $rGate, $rFile . ' is on the LEAK list');
		}
	}

	/**
	 * A settings save that changes cluster_api_port: nginx serves the new
	 * port, and keeps the old URL for the nodes, before the value is stored;
	 * nothing is announced yet.
	 */
	public function testAnApiPortSaveServesTheNewPortBeforeItIsStored(): void {
		$rMain = ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461];
		$rCurrent = ['cluster_api_enabled' => 1, 'cluster_api_port' => 0, 'cluster_legacy_ports' => ''];

		// 0 -> 31200: the API's own server. Its old URL is the broadcast port,
		// which the public server keeps serving: no old-port server.
		$rStage = ClusterNginxConfig::stageApiPort(0, 31200, $rCurrent, $rMain);
		$this->assertSame(['refused' => null, 'error' => '', 'record' => [0, 31200, $rCurrent, $rMain]], $rStage);
		$this->assertStringContainsString("    listen 31200;\n", (string) $this->conf(ClusterNginxConfig::LISTEN), 'the new port, not the stored one');
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame(['test', 'reload'], $this->takeRuns());
		$this->assertSame(['free:31200', 'free:31200', 'serving:31200'], $this->takeProbes(), 'free before anything is written, served after the reload');
		$this->assertSame(1, (int) $this->stored()['cluster_policy_ver'], 'nothing announced before the value is stored');

		// 31200 -> 31300: 31200 is kept for the nodes that have not moved.
		$rCurrent = ['cluster_api_port' => 31200] + $rCurrent;
		$rStage = ClusterNginxConfig::stageApiPort(31200, 31300, $rCurrent, $rMain);
		$this->assertIsArray($rStage);
		$this->assertNull($rStage['refused']);
		$this->assertSame([31200, 31300, $rCurrent, $rMain], $rStage['record']);
		$this->assertStringContainsString("    listen 31300;\n", (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertStringNotContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN));
		$rOld = (string) $this->conf(ClusterNginxConfig::OLD_PORT);
		$this->assertStringContainsString("    listen 31200;\n", $rOld);
		$this->assertStringContainsString('# until ' . gmdate('Y-m-d H:i', $this->rNow + ClusterEndpoint::GRACE) . ' UTC', $rOld);
		$this->assertSame(['test', 'reload'], $this->takeRuns());

		// nginx -t refuses 31400: its words, and every file as it was.
		$rFiles = [$this->conf(ClusterNginxConfig::LOCATIONS), $this->conf(ClusterNginxConfig::LISTEN), $this->conf(ClusterNginxConfig::OLD_PORT)];
		$this->rTest = [1, "nginx: [emerg] unknown directive \"oops\" in custom.conf:1\nnginx: configuration file test failed\n"];
		$rStage = ClusterNginxConfig::stageApiPort(31300, 31400, ['cluster_api_port' => 31300] + $rCurrent, $rMain);
		$this->assertSame('cluster_error_nginx', $rStage['refused'] ?? null);
		$this->assertStringContainsString('unknown directive "oops" in custom.conf:1', (string) $rStage['error']);
		$this->assertSame($rFiles, [$this->conf(ClusterNginxConfig::LOCATIONS), $this->conf(ClusterNginxConfig::LISTEN), $this->conf(ClusterNginxConfig::OLD_PORT)]);
		$this->assertSame(['test'], $this->takeRuns(), 'never reloaded');

		$this->assertNull(ClusterNginxConfig::stageApiPort(31300, 31300, $rCurrent, $rMain), 'the port stays');

		// With the API off nothing is announced, and the ports already kept stay served.
		$this->rTest = [0, ''];
		$this->store('cluster_legacy_ports', (string) json_encode([8080 => $this->rNow + 60]));
		$rKept = ['cluster_api_enabled' => 0, 'cluster_api_port' => 31300, 'cluster_legacy_ports' => ''];
		$rStage = ClusterNginxConfig::stageApiPort(31300, 31400, $rKept, $rMain);
		$this->assertIsArray($rStage);
		$this->assertNull($rStage['refused']);
		$this->assertStringContainsString('listen 31400;', (string) $this->conf(ClusterNginxConfig::LISTEN));
		$rOld = (string) $this->conf(ClusterNginxConfig::OLD_PORT);
		$this->assertStringContainsString('listen 8080;', $rOld, 'still kept');
		$this->assertStringNotContainsString('listen 31300;', $rOld, 'no node uses it');
	}

	/**
	 * A settings save staged from settings the process loaded before an old
	 * HTTPS port was kept (ClusterEndpoint) still serves that port: the kept
	 * URLs are read as stored.
	 */
	public function testAnApiPortSaveKeepsServingAnOldHttpsPort(): void {
		$this->store('cluster_legacy_urls', (string) json_encode(['https://panel.example.com:8443/cluster/v1/' => $this->rNow + 60], JSON_UNESCAPED_SLASHES));
		$rCurrent = ['cluster_api_enabled' => 1, 'cluster_api_port' => 0, 'cluster_legacy_ports' => ''];
		$rStage = ClusterNginxConfig::stageApiPort(0, 31200, $rCurrent, ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461]);
		$this->assertIsArray($rStage);
		$this->assertNull($rStage['refused']);
		$this->assertStringContainsString("    listen 31200;\n", (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertStringContainsString("    listen 8443 ssl;\n", (string) $this->conf(ClusterNginxConfig::OLD_PORT));
	}

	/**
	 * A save staged from settings the process loaded before ClusterEndpoint
	 * kept a port: the port stays served while the new one is staged, and
	 * recording the new one keeps it. Both read the kept ports as stored.
	 */
	public function testAnApiPortSaveKeepsAPortKeptMeanwhile(): void {
		$rMain = ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461];
		$this->store('cluster_api_enabled', 1);
		$rCurrent = $this->stored();
		$this->store('cluster_legacy_ports', (string) json_encode([8080 => $this->rNow + 60]));

		$rStage = ClusterNginxConfig::stageApiPort(0, 31200, $rCurrent, $rMain);
		$this->assertIsArray($rStage);
		$this->assertNull($rStage['refused']);
		$this->assertStringContainsString("    listen 8080;\n", (string) $this->conf(ClusterNginxConfig::OLD_PORT), 'served while the new port is staged');

		$this->store('cluster_api_port', 31200);
		$this->assertTrue(ClusterNginxConfig::commitApiPort($rStage, true)['ok']);
		$this->assertSame([8080 => $this->rNow + 60, 25461 => $this->rNow + ClusterEndpoint::GRACE], ClusterEndpoint::legacyPorts($this->stored()), 'recorded with the port kept meanwhile');
		$this->assertStringContainsString("    listen 8080;\n", (string) $this->conf(ClusterNginxConfig::OLD_PORT));
	}

	/**
	 * `nginx -t` passes when another program holds a port, and nginx then
	 * fails at the reload (keeping its previous config) and at its next start.
	 * A new dedicated port must be free before anything is written.
	 */
	public function testAPortAnotherProgramHoldsIsRefused(): void {
		$rMain = ['http_broadcast_port' => 25461];
		$rCurrent = ['cluster_api_enabled' => 1, 'cluster_api_port' => 0, 'cluster_legacy_ports' => ''];
		$this->rBusy = [31200];
		$rStage = ClusterNginxConfig::stageApiPort(0, 31200, $rCurrent, $rMain);
		$this->assertSame('cluster_error_port_busy', $rStage['refused'] ?? null);
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
		$this->assertNull($this->conf(ClusterNginxConfig::LOCATIONS), 'nothing written');
		$this->assertSame([], $this->takeRuns());

		// apply() refuses it too, whoever renders the stored port (status after an update, cron).
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200]);
		$this->assertFalse($rResult['ok']);
		$this->assertSame('port 31200 is in use by another program', $rResult['error']);
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
		$this->assertSame([], $this->takeRuns());

		// A port nginx listens on already (here its old-port server) is nginx's: not checked.
		$this->assertTrue(ClusterNginxConfig::apply(['cluster_api_port' => 0, 'cluster_legacy_ports' => (string) json_encode([31200 => $this->rNow + 60])])['ok']);
		$this->takeProbes();
		$this->store('cluster_legacy_ports', (string) json_encode([31200 => $this->rNow + 60]));
		$rStage = ClusterNginxConfig::stageApiPort(0, 31200, $rCurrent, $rMain);
		$this->assertIsArray($rStage);
		$this->assertNull($rStage['refused']);
		$this->assertSame([], $this->takeProbes());
		$this->assertStringContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT), 'the dedicated server has it now');
	}

	/** The real check: a port a program listens on cannot be bound. */
	public function testTheFreeCheckBindsThePort(): void {
		ClusterNginxConfig::useProbe(null);
		$rHold = stream_socket_server('tcp://0.0.0.0:0', $rErrNo, $rErrStr);
		$this->assertNotFalse($rHold, $rErrStr);
		$rPort = (int) substr((string) strrchr((string) stream_socket_get_name($rHold, false), ':'), 1);
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => $rPort], false);
		fclose($rHold);
		$this->assertSame('port ' . $rPort . ' is in use by another program', $rResult['error']);
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));

		$this->assertTrue(ClusterNginxConfig::apply(['cluster_api_port' => $rPort], false)['ok'], 'free again');
		$this->assertStringContainsString('listen ' . $rPort . ';', (string) $this->conf(ClusterNginxConfig::LISTEN));
	}

	/**
	 * `nginx -s reload` exits 0 once the signal is sent: a new port nginx does
	 * not serve after it puts the previous files back and reloads again.
	 */
	public function testANewPortNginxDoesNotServeAfterTheReloadIsRolledBack(): void {
		$this->assertTrue(ClusterNginxConfig::apply(['cluster_api_port' => 31200])['ok']);
		$this->takeRuns();
		$this->takeProbes();
		$rListen = $this->conf(ClusterNginxConfig::LISTEN);

		$this->rServing = false;
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31300, 'cluster_legacy_ports' => (string) json_encode([31200 => $this->rNow + 60])]);
		$this->assertSame(['ok' => false, 'changed' => false, 'reloaded' => false], array_intersect_key($rResult, ['ok' => 0, 'changed' => 0, 'reloaded' => 0]));
		$this->assertStringContainsString('nginx did not serve port 31300 after the reload', $rResult['error']);
		$this->assertSame(['test', 'reload', 'reload'], $this->takeRuns(), 'the second reload puts nginx back on the previous files');
		$this->assertSame(['free:31300', 'serving:31300'], $this->takeProbes());
		$this->assertSame($rListen, $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT));
		$rAudit = $this->audit();
		$this->assertStringContainsString('did not serve port 31300', (string) end($rAudit)['detail']);

		// A signal nginx never got (it is not running) is no better.
		$this->rServing = true;
		ClusterNginxConfig::useRunner(function (array $rArgv): array {
			$this->rRuns[] = $rArgv;
			return in_array('-t', $rArgv, true) ? [0, ''] : [1, 'nginx: [error] open() "nginx.pid" failed'];
		});
		$this->assertFalse(ClusterNginxConfig::apply(['cluster_api_port' => 31300])['ok']);
		$this->assertSame($rListen, $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertSame(['free:31300'], $this->takeProbes(), 'no point waiting for it');

		// Without a new port there is nothing to wait for (the kept port is nginx's already).
		ClusterNginxConfig::useRunner(fn(array $rArgv): array => [0, '']);
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200, 'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60])]);
		$this->assertTrue($rResult['ok'] && $rResult['reloaded']);
		$this->assertSame([], $this->takeProbes());
	}

	/** A write that fails part-way leaves every file as it was, and nginx untouched. */
	public function testAWriteThatFailsPutsTheEarlierFilesBack(): void {
		file_put_contents($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::LOCATIONS, "# an older release's location\n");
		// cluster_locations.conf is written first; listen.conf cannot be renamed in (a directory holds its name).
		mkdir($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN, 0777, true);
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31200]);
		$this->assertFalse($rResult['ok']);
		$this->assertStringStartsWith('cannot write ' . $this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN, $rResult['error']);
		$this->assertSame("# an older release's location\n", $this->conf(ClusterNginxConfig::LOCATIONS), 'the file written before it is back');
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
		$this->assertSame([], $this->rRuns, 'nginx never ran');
		$this->assertSame([], $this->audit());
	}

	/** cron:cluster retries a failed render every minute; the refusal is audited once. */
	public function testARefusalRetriedEveryMinuteIsAuditedOnce(): void {
		$this->rTest = [1, "nginx: [emerg] unknown directive \"oops\" in custom.conf:1\n"];
		$this->assertFalse(ClusterNginxConfig::apply(['cluster_api_port' => 31200])['ok']);
		$this->assertFalse(ClusterNginxConfig::apply(['cluster_api_port' => 31200])['ok']);
		$this->assertCount(1, $this->audit());
		$this->assertSame(['test', 'test'], $this->takeRuns(), 'tried again all the same');

		$this->rTest = [0, ''];
		$this->assertTrue(ClusterNginxConfig::apply(['cluster_api_port' => 31200])['ok']);
		$this->rTest = [1, "nginx: [emerg] unknown directive \"oops\" in custom.conf:1\n"];
		$this->assertFalse(ClusterNginxConfig::apply(['cluster_api_port' => 31300])['ok']);
		$this->assertSame(['cluster.nginx', 'cluster.nginx', 'cluster.nginx'], array_column($this->audit(), 'event'), 'a success clears it');
	}

	/**
	 * After the settings UPDATE: stored, the nodes hear of the port; either
	 * way nginx follows what is stored, which undoes a render from the old
	 * value that ran in between, or a save that was not stored.
	 */
	public function testNginxFollowsWhatTheSaveStored(): void {
		$rMain = ['server_ip' => '10.0.0.1', 'http_broadcast_port' => 25461];
		$this->store('cluster_api_enabled', 1);
		$rCurrent = $this->stored();

		$rStage = ClusterNginxConfig::stageApiPort(0, 31200, $rCurrent, $rMain);
		// status, set_port or cron:cluster render from the old value before the UPDATE.
		$this->assertTrue(ClusterNginxConfig::apply()['ok']);
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
		$this->takeRuns();

		$this->store('cluster_api_port', 31200);
		$rResult = ClusterNginxConfig::commitApiPort((array) $rStage, true);
		$this->assertTrue($rResult['ok'] && $rResult['reloaded']);
		$this->assertStringContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN), 'back on the stored port');
		$this->assertSame(2, (int) $this->stored()['cluster_policy_ver'], 'the nodes move');
		$this->assertSame([25461 => $this->rNow + ClusterEndpoint::GRACE], ClusterEndpoint::legacyPorts($this->stored()));
		$this->assertSame(['test', 'reload'], $this->takeRuns());
		$this->assertSame(['ok' => true, 'changed' => false, 'reloaded' => false, 'error' => ''], ClusterNginxConfig::apply(), 'current');

		// A save that was not stored: nginx goes back to the stored port, nothing announced.
		$rStage = ClusterNginxConfig::stageApiPort(31200, 31300, $this->stored(), $rMain);
		$this->assertStringContainsString('listen 31300;', (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertTrue(ClusterNginxConfig::commitApiPort((array) $rStage, false)['ok']);
		$this->assertStringContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame(2, (int) $this->stored()['cluster_policy_ver']);
	}

	/**
	 * An old HTTPS port (ClusterEndpoint keeps the https:// URL of MAIN's
	 * previous HTTPS port) is served over TLS for the cluster API alone. The
	 * render reads the kept URLs from the database, not from the settings the
	 * process loaded; cron:cluster drops them after their 7 days.
	 */
	public function testAnOldHttpsPortIsServedFromTheStoredSettings(): void {
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/https.conf', 'listen 8443 ssl;');
		SettingsManager::set($this->stored());
		$this->store('cluster_legacy_urls', (string) json_encode(['https://panel.example.com:25463/cluster/v1/' => $this->rNow + 60], JSON_UNESCAPED_SLASHES));
		$this->assertSame(['ok' => true, 'changed' => true, 'reloaded' => true, 'error' => ''], ClusterNginxConfig::apply());
		$rOld = (string) $this->conf(ClusterNginxConfig::OLD_PORT);
		$this->assertStringContainsString("server {\n    listen 25463 ssl;\n    # until " . gmdate('Y-m-d H:i', $this->rNow + 60) . " UTC\n    server_tokens off;\n    include ssl.conf;\n    include cluster_locations.conf;\n", $rOld);
		$this->assertSame(['test', 'reload'], $this->takeRuns());
		$this->assertSame([], array_filter($this->rProbes, static fn(string $rProbe): bool => str_ends_with($rProbe, ':25463')), 'nginx served it until the change: no port check');

		ClusterClock::fix(($this->rNow + 61) * 1000);
		ClusterCronJob::endpoint();
		$this->assertSame('', (string) $this->stored()['cluster_legacy_urls']);
		$this->assertSame(2, (int) $this->stored()['cluster_policy_ver']);
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame(['test', 'reload'], $this->takeRuns());
	}

	/** cron:cluster, every minute and also with the API off: expired ports go, and nginx follows the stored settings. */
	public function testCronRendersFromTheStoredSettingsEveryMinute(): void {
		$this->store('cluster_legacy_ports', (string) json_encode([8080 => $this->rNow + 60]));
		SettingsManager::set($this->stored());
		ClusterCronJob::endpoint();
		$this->assertStringContainsString('listen 8080;', (string) $this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame(['test', 'reload'], $this->takeRuns());
		ClusterCronJob::endpoint();
		$this->assertSame([], $this->takeRuns(), 'nothing differs: no test, no reload');

		// Its 7 days are up: it leaves the settings (announced) and nginx.
		ClusterClock::fix(($this->rNow + 61) * 1000);
		ClusterCronJob::endpoint();
		$this->assertSame('', $this->stored()['cluster_legacy_ports']);
		$this->assertSame(2, (int) $this->stored()['cluster_policy_ver']);
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame(['test', 'reload'], $this->takeRuns());

		// A render nginx refused is tried again the next minute.
		$this->store('cluster_api_port', 31200);
		$this->rTest = [1, 'nginx: [emerg] something else is wrong'];
		ClusterCronJob::endpoint();
		$this->assertNull($this->conf(ClusterNginxConfig::LISTEN));
		$this->rTest = [0, ''];
		ClusterCronJob::endpoint();
		$this->assertStringContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertSame(['test', 'test', 'reload'], $this->takeRuns());
	}

	/**
	 * cron:cluster releases a kept old port before its 7 days once every
	 * node uses the new URL (ClusterEndpoint::release()), and nginx stops
	 * serving it in the same pass.
	 */
	public function testCronReleasesAnOldPortOnceEveryNodeUsesTheNewUrl(): void {
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY, `state` varchar(16) NOT NULL DEFAULT 'active', `mode` int NOT NULL DEFAULT 1, `enrol_deadline` int DEFAULT NULL, `last_seen_at` bigint DEFAULT NULL, `policy_ver` int NOT NULL DEFAULT 0, `main_port` int DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY)');
		$this->rDb->exec('INSERT INTO `servers` (`id`) VALUES (2)');
		$this->rDb->exec('CREATE TABLE `cluster_enrol_codes` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `exp` int NOT NULL)');
		$this->rDb->exec('CREATE TABLE `cluster_enrol_requests` (`id` INTEGER PRIMARY KEY AUTOINCREMENT, `state` varchar(20) NOT NULL, `created_at` int NOT NULL)');
		$this->store('cluster_policy_ver', 2);
		$this->store('cluster_legacy_ports', (string) json_encode([8080 => $this->rNow + ClusterEndpoint::GRACE]));
		$this->rDb->query('INSERT INTO `cluster_nodes` (`server_id`, `last_seen_at`, `policy_ver`, `main_port`) VALUES (2, ?, 1, 8080)', $this->rNow * 1000 - 1000);
		SettingsManager::set($this->stored());
		ClusterCronJob::endpoint();
		$this->assertStringContainsString('listen 8080;', (string) $this->conf(ClusterNginxConfig::OLD_PORT), 'the node has not moved yet');
		$this->assertSame(['test', 'reload'], $this->takeRuns());

		$this->rDb->query('UPDATE `cluster_nodes` SET `policy_ver` = 2, `main_port` = 25461');
		ClusterCronJob::endpoint();
		$this->assertSame('', $this->stored()['cluster_legacy_ports']);
		$this->assertSame(3, (int) $this->stored()['cluster_policy_ver'], 'announced');
		$this->assertNull($this->conf(ClusterNginxConfig::OLD_PORT));
		$this->assertSame(['test', 'reload'], $this->takeRuns());
	}

	/** cluster:nginx renders the stored settings: --no-reload runs only nginx -t, a refusal exits 1. */
	public function testTheCommand(): void {
		$rRun = static function (array $rArgs): array {
			ob_start();
			$rCode = (new ClusterNginxCommand())->execute($rArgs);
			return [$rCode, (string) ob_get_clean()];
		};
		$this->store('cluster_api_port', 31200);
		$this->assertSame([0, "Cluster API nginx config updated; nginx not reloaded.\n"], $rRun(['--no-reload']));
		$this->assertSame(['test'], $this->takeRuns());
		$this->assertStringContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN));
		$this->assertSame([0, "Cluster API nginx config is current.\n"], $rRun([]));
		$this->assertSame([], $this->takeRuns());

		$this->store('cluster_api_port', 31300);
		$this->assertSame([0, "Cluster API nginx config updated; nginx reloaded.\n"], $rRun([]));
		$this->assertSame(['test', 'reload'], $this->takeRuns());

		$this->store('cluster_api_port', 31400);
		$this->rTest = [1, 'nginx: [emerg] bad'];
		[$rCode, $rOut] = $rRun([]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('nginx: [emerg] bad', $rOut);
		$this->assertStringContainsString('listen 31300;', (string) $this->conf(ClusterNginxConfig::LISTEN));

		// Only as the user nginx runs as.
		$this->takeRuns();
		ClusterNginxConfig::useBase($this->rBase, 'not-' . self::user());
		[$rCode, $rOut] = $rRun([]);
		$this->assertSame(1, $rCode);
		$this->assertStringContainsString('not run as not-', $rOut);
		$this->assertSame([], $this->rRuns);
	}

	/**
	 * The rendered files against a real nginx: the public server with the
	 * location, a dedicated port, an old port and an old HTTPS port (with the
	 * shipped ssl.conf and certificate) pass `nginx -t`; a broken
	 * config elsewhere makes apply() put the previous files back.
	 */
	public function testRealNginx(): void {
		$rNginx = (string) getenv('XCVM_TEST_NGINX');
		if ($rNginx === '' || !is_executable($rNginx)) {
			$this->markTestSkipped('no nginx (set XCVM_TEST_NGINX)');
		}
		ClusterNginxConfig::useRunner(null);
		exec('cp -r ' . escapeshellarg(dirname(__DIR__, 2) . '/src/bin/nginx/conf/.') . ' ' . escapeshellarg($this->rBase . 'bin/nginx/conf/'));
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/http.conf', 'listen 25461;');
		file_put_contents($this->rBase . 'bin/nginx/conf/ports/https.conf', 'listen 25463 ssl;');
		unlink($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::LOCATIONS);
		// The user directive needs root and the xc_vm user; neither is needed to test the syntax.
		$rMain = $this->rBase . 'bin/nginx/conf/nginx.conf';
		file_put_contents($rMain, (string) preg_replace('/^user xc_vm;$/m', '', (string) file_get_contents($rMain)));
		mkdir($this->rBase . 'bin/nginx/sbin', 0777, true);
		mkdir($this->rBase . 'bin/nginx/logs', 0777, true);
		$rBin = $this->rBase . 'bin/nginx/sbin/nginx';
		file_put_contents($rBin, "#!/bin/sh\nexec " . escapeshellarg($rNginx) . ' -p ' . escapeshellarg($this->rBase . 'bin/nginx/') . " \"\$@\"\n");
		chmod($rBin, 0755);

		$rResult = ClusterNginxConfig::apply([
			'cluster_api_port' => 31200,
			'cluster_legacy_ports' => (string) json_encode([8080 => $this->rNow + 60]),
			'cluster_legacy_urls' => (string) json_encode(['https://panel.example.com:8443/cluster/v1/' => $this->rNow + 60], JSON_UNESCAPED_SLASHES),
		], false);
		$this->assertSame(['ok' => true, 'changed' => true, 'reloaded' => false, 'error' => ''], $rResult);
		$this->assertFileExists($this->rBase . 'bin/nginx/conf/' . ClusterNginxConfig::LISTEN);
		$this->assertStringContainsString('listen 8443 ssl;', (string) $this->conf(ClusterNginxConfig::OLD_PORT), 'an old HTTPS port, with the shipped ssl.conf');

		file_put_contents($this->rBase . 'bin/nginx/conf/custom.conf', "this is not nginx;\n");
		$rResult = ClusterNginxConfig::apply(['cluster_api_port' => 31300], false);
		$this->assertFalse($rResult['ok']);
		$this->assertStringContainsString('custom.conf', $rResult['error']);
		$this->assertStringContainsString('listen 31200;', (string) $this->conf(ClusterNginxConfig::LISTEN), 'the previous listener is back');
		$this->assertStringContainsString('listen 8080;', (string) $this->conf(ClusterNginxConfig::OLD_PORT));
	}
}
