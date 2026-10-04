<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Cluster\ClusterBus;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Cluster\ClusterRoute;
use XcVm\Domain\Cluster\ClusterSemaphore;
use XcVm\Domain\Cluster\ConnectionDigest;
use XcVm\Domain\Cluster\LivenessService;
use XcVm\Domain\Cluster\NodeRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\BusServer;
use XcVm\Tests\Support\FakeClusterCrypto;

/**
 * The Cluster Nodes page's fence windows, banners, figures and "Rotate all
 * tokens now" (plan section 11; ADR 0004, Phase 9), and the Servers list's
 * cluster row actions that post to it.
 */
final class ClusterOverviewTest extends TestCase {
	private const NOW = 1_800_000_000;

	private TestDb $rDb;

	private FakeClusterCrypto $rCrypto;

	protected function setUp(): void {
		$this->rDb = new TestDb();
		foreach (['029_create_cluster_nodes', '030_create_cluster_commands', '032_create_cluster_audit'] as $rName) {
			$this->rDb->exec((string) file_get_contents(MAIN_HOME . 'migrations/database/up/' . $rName . '.sql'));
		}
		$this->rDb->exec('ALTER TABLE `cluster_nodes` ADD COLUMN `root_ready` tinyint(1) NOT NULL DEFAULT 0');
		DatabaseFactory::set($this->rDb);
		SettingsManager::set(['cluster_api_enabled' => 1]);
		ClusterClock::fix(self::NOW * 1000);
		ClusterBus::useSocket(sys_get_temp_dir() . '/no-such-bus-' . bin2hex(random_bytes(4)) . '/cluster.sock');
		$this->rCrypto = new FakeClusterCrypto();
		ClusterRoute::useCrypto(fn() => $this->rCrypto);
	}

	protected function tearDown(): void {
		ClusterRoute::useCrypto(null);
		ClusterBus::useSocket(null);
		ClusterClock::fix(null);
		ClusterHealth::usePath(null);
		DatabaseFactory::reset();
		SettingsManager::set([]);
	}


	private function node(int $rServerID, string $rState, int $rFlows, int $rTokenExp = self::NOW + 3600): void {
		$this->rDb->query(
			'INSERT INTO `cluster_nodes` (`server_id`, `node_uuid`, `state`, `mode`, `flows`, `gen`, `epoch`, `token_exp`, `created_at`, `updated_at`) VALUES (?, ?, ?, 1, ?, 1, 1, ?, ?, ?);',
			$rServerID,
			sprintf('00000000-0000-4000-8000-%012d', $rServerID),
			$rState,
			$rFlows,
			$rTokenExp,
			self::NOW,
			self::NOW
		);
	}

	private function command(int $rServerID, string $rState, int $rCreated, ?int $rDelivered, ?int $rAcked): void {
		static $rSeq = 0;
		$rSeq++;
		$this->rDb->query(
			'INSERT INTO `cluster_commands` (`server_id`, `seq`, `cmd_id`, `type`, `payload`, `sig`, `state`, `created_at`, `exp`, `delivered_at`, `acked_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
			$rServerID,
			$rSeq,
			bin2hex(random_bytes(16)),
			'node.rpc',
			'{}',
			str_repeat("\0", 64),
			$rState,
			$rCreated,
			$rCreated + 600,
			$rDelivered,
			$rAcked
		);
	}

	// ── Banners ─────────────────────────────────────────────────────────

	public function testNoBannerWhileLicensedAndTheCertificateIsFar(): void {
		$rMain = ['enable_https' => 1, 'certbot_ssl' => json_encode(['expiration' => self::NOW + 30 * 86400])];
		$this->assertSame([], ClusterOverview::banners(true, $rMain, ['cluster_api_enabled' => 1, 'cluster_transport' => 'https_required'], [], self::NOW));
		$this->assertSame([], ClusterOverview::banners(false, $rMain, ['cluster_api_enabled' => 0], [], self::NOW), 'the API off: nothing to say');
	}

	public function testALapsedLicenceSaysWhenTheFleetStops(): void {
		$rNodes = [
			['server_id' => 2, 'state' => 'active', 'token_exp' => self::NOW + 600],
			['server_id' => 3, 'state' => 'active', 'token_exp' => self::NOW + 1800],
			['server_id' => 4, 'state' => 'revoked', 'token_exp' => self::NOW + 99999],
		];
		$rSettings = ['cluster_api_enabled' => 1, 'lb_lease_fence' => 1, 'lb_partition_tolerance_h' => 2, 'lb_fence_drain_min' => 10];
		$rBanners = ClusterOverview::banners(false, [], $rSettings, $rNodes, self::NOW);
		$this->assertCount(1, $rBanners);
		$this->assertSame('cluster_banner_licence_stop', $rBanners[0]['key']);
		$this->assertSame('danger', $rBanners[0]['type']);
		$this->assertSame(gmdate('Y-m-d H:i', self::NOW + 1800 + 7200 + 600) . ' UTC', $rBanners[0]['vars']['{TIME}'], 'the last active node\'s drain; a revoked node counts for nothing');

		$this->assertSame('cluster_banner_licence_no_fence', ClusterOverview::banners(false, [], ['lb_lease_fence' => 0] + $rSettings, $rNodes, self::NOW)[0]['key']);
		$this->assertSame('cluster_banner_licence', ClusterOverview::banners(false, [], $rSettings, [], self::NOW)[0]['key'], 'no active node');
	}

	public function testMainsCertificateExpiringWhileNodesDialHttps(): void {
		$rMain = ['enable_https' => 1, 'certbot_ssl' => json_encode(['expiration' => self::NOW + 10 * 86400])];
		$rSettings = ['cluster_api_enabled' => 1];
		$this->assertSame([], ClusterOverview::banners(true, $rMain, ['cluster_transport' => 'http'] + $rSettings, [], self::NOW), 'plain HTTP: the certificate does not matter');
		$this->assertSame([], ClusterOverview::banners(true, ['enable_https' => 0] + $rMain, ['cluster_transport' => 'https_required'] + $rSettings, [], self::NOW), 'HTTPS off on MAIN');

		$rWarn = ClusterOverview::banners(true, $rMain, ['cluster_transport' => 'https_preferred'] + $rSettings, [], self::NOW);
		$this->assertSame([['type' => 'warning', 'key' => 'cluster_banner_cert_expiring', 'vars' => ['{DATE}' => gmdate('Y-m-d H:i', self::NOW + 10 * 86400) . ' UTC', '{DAYS}' => '10', '{TRANSPORT}' => 'https_preferred']]], $rWarn);
		$this->assertSame('danger', ClusterOverview::banners(true, $rMain, ['cluster_transport' => 'https_required'] + $rSettings, [], self::NOW)[0]['type'], 'required: an expiry cuts the fleet off');
		$this->assertSame('warning', ClusterOverview::banners(true, $rMain, $rSettings, [], self::NOW)[0]['type'], 'auto dials HTTPS when MAIN has it');

		$rExpired = ClusterOverview::banners(true, ['certbot_ssl' => json_encode(['expiration' => self::NOW - 60])] + $rMain, ['cluster_transport' => 'https_preferred'] + $rSettings, [], self::NOW);
		$this->assertSame(['danger', 'cluster_banner_cert_expired'], [$rExpired[0]['type'], $rExpired[0]['key']]);
	}

	// ── Figures ─────────────────────────────────────────────────────────

	public function testTheFenceWindowOfEachNode(): void {
		$rWindows = ClusterOverview::fenceWindows([['server_id' => 2, 'token_exp' => self::NOW], ['server_id' => 3, 'token_exp' => null]], ['lb_partition_tolerance_h' => 1, 'lb_fence_drain_min' => 5]);
		$this->assertSame(self::NOW + 3600, $rWindows[2]['lease_until']);
		$this->assertSame(self::NOW + 3600 + 300, $rWindows[2]['drain_until']);
		$this->assertNull($rWindows[3]['lease_until']);
	}

	public function testPercentilesAreNearestRank(): void {
		$this->assertNull(ClusterOverview::percentile([], 50));
		$this->assertSame(3, ClusterOverview::percentile([5, 1, 3, 2, 4], 50));
		$this->assertSame(100, ClusterOverview::percentile([...array_fill(0, 99, 1), 100], 99 + 1));
		$this->assertSame(1, ClusterOverview::percentile([...array_fill(0, 99, 1), 100], 99));
	}

	public function testCommandQueueAndLatency(): void {
		$this->command(2, 'queued', self::NOW - 30, null, null);
		$this->command(2, 'delivered', self::NOW - 10, self::NOW - 9, null);
		$this->command(3, 'queued', self::NOW - 5, null, null);
		$this->command(3, 'queued', self::NOW - 1000, null, null); // expired (exp = created + 600): not in the queue
		foreach ([1, 1, 2, 4] as $rI => $rDelay) {
			$this->command(2, 'acked', self::NOW - 100 - $rI, self::NOW - 100 - $rI + $rDelay, self::NOW - 100 - $rI + $rDelay + 1);
		}
		$this->command(2, 'acked', self::NOW - 7200, self::NOW - 7190, self::NOW - 7100); // outside the hour

		$rOut = ClusterOverview::commandMetrics(self::NOW);
		$this->assertSame(3, $rOut['depth']);
		$this->assertSame([2 => 2, 3 => 1], $rOut['per_node']);
		$this->assertSame(self::NOW - 30, $rOut['oldest']);
		$this->assertSame(4, $rOut['samples']);
		$this->assertSame(1, $rOut['deliver_p50']);
		$this->assertSame(4, $rOut['deliver_p99']);
		$this->assertSame(2, $rOut['ack_p50']);
		$this->assertSame(5, $rOut['ack_p99']);
	}

	public function testTheRecentAudit(): void {
		foreach (['node.flows', 'node.mode', 'node.token_rotate'] as $rI => $rEvent) {
			$this->rDb->query('INSERT INTO `cluster_audit` (`time`, `server_id`, `actor`, `event`, `detail`) VALUES (?, ?, ?, ?, ?);', self::NOW + $rI, 2, 'admin:1', $rEvent, '{}');
		}
		$rRows = ClusterOverview::audit(2);
		$this->assertSame(['node.token_rotate', 'node.mode'], array_column($rRows, 'event'), 'newest first, as many as asked');
		$this->assertSame(2, $rRows[0]['server_id']);
	}

	public function testSaturationWithoutTheBus(): void {
		$rPath = sys_get_temp_dir() . '/xcvm-health-' . bin2hex(random_bytes(4)) . '.json';
		ClusterHealth::usePath($rPath);
		$rNowMs = self::NOW * 1000;
		ClusterHealth::write([], true, [], [ClusterHealth::GUARD_CTL_QUEUE], ['since' => $rNowMs - 7000, 'at' => $rNowMs - 1000]);
		$rOut = ClusterOverview::saturation([], $rNowMs);
		$this->assertNull($rOut['ingest'], 'no bus: nothing is limited');
		$this->assertSame(6000, $rOut['ctl_queue_ms']);
		$this->assertNull(ClusterOverview::saturation([], $rNowMs + 60000)['ctl_queue_ms'], 'a queue last seen a minute ago is over');
		@unlink($rPath);
	}

	public function testIngestPermitsInUseOnTheBus(): void {
		$rBus = BusServer::start('overview');
		if ($rBus === null) {
			$this->markTestSkipped('no redis-server or phpredis');
		}
		try {
			ClusterBus::useSocket($rBus->socket());
			$this->assertSame(['p0' => 0, 'bulk' => 0, 'permits' => ['p0' => 3, 'bulk' => 3, 'total' => 6]], ClusterSemaphore::ingestInUse(null));
			$rP0 = ClusterSemaphore::acquireIngest(ClusterSemaphore::LANE_P0, null);
			$this->assertIsString($rP0);
			$this->assertIsString(ClusterSemaphore::acquireIngest(ClusterSemaphore::LANE_BULK, null));
			$this->assertIsString(ClusterSemaphore::acquireIngest(ClusterSemaphore::LANE_BULK, null));
			$rUse = ClusterSemaphore::ingestInUse(4);
			$this->assertSame([1, 2, ['p0' => 2, 'bulk' => 2, 'total' => 4]], [$rUse['p0'], $rUse['bulk'], $rUse['permits']]);
			ClusterSemaphore::releaseIngest(ClusterSemaphore::LANE_P0, $rP0);
			$this->assertSame(0, ClusterSemaphore::ingestInUse(4)['p0']);
		} finally {
			ClusterBus::useSocket(null);
			$rBus->stop();
		}
	}

	// ── Rotate all tokens now ───────────────────────────────────────────

	public function testRotateAllQueuesForEveryActiveNodeThatTakesCommands(): void {
		$this->node(2, 'active', NodeRegistry::FLOW_COMMANDS);
		$this->node(3, 'active', NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_TELEMETRY);
		$this->node(4, 'active', NodeRegistry::FLOW_TELEMETRY); // no commands: skipped
		$this->node(5, 'revoked', NodeRegistry::FLOW_COMMANDS); // not active: not asked

		$this->assertSame(['queued' => 2, 'no_commands' => 1, 'failed' => 0], ClusterOverview::rotateAll(7));
		$this->rDb->query("SELECT `server_id`, `type`, `dedupe_key` FROM `cluster_commands` ORDER BY `server_id`;");
		$this->assertSame([[2, 'token.rotate_now'], [3, 'token.rotate_now']], array_map(static fn(array $rRow): array => [(int) $rRow['server_id'], $rRow['type']], $this->rDb->get_rows()));
		$this->rDb->query("SELECT `server_id`, `actor`, `detail` FROM `cluster_audit` WHERE `event` = 'node.token_rotate' ORDER BY `server_id`;");
		$rAudit = $this->rDb->get_rows();
		$this->assertSame(['2', '3'], array_map(static fn(array $rRow): string => (string) $rRow['server_id'], $rAudit));
		$this->assertSame('admin:7', $rAudit[0]['actor']);
		$this->assertSame(['queued' => true, 'all' => true], json_decode($rAudit[0]['detail'], true));

		ClusterOverview::rotateAll(7);
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_commands`;');
		$this->assertSame(2, (int) $this->rDb->get_row()['n'], 'a second click replaces the rotation still waiting (its dedupe key), rather than adding one');
	}

	public function testTheDashboardShowsThePagesBannersFromTheNodesTable(): void {
		ClusterCryptoFactory::useProbe(static fn(): array => ['api' => 1]);
		LivenessService::useLicence(static fn(): bool => false);
		try {
			$this->node(2, 'active', NodeRegistry::FLOW_COMMANDS, self::NOW + 600);
			$this->node(3, 'revoked', NodeRegistry::FLOW_COMMANDS, self::NOW + 99999);
			$rSettings = ['cluster_api_enabled' => 1, 'lb_lease_fence' => 1, 'lb_partition_tolerance_h' => 2, 'lb_fence_drain_min' => 10];
			$rBanners = ClusterOverview::dashboardBanners([], $rSettings, self::NOW);
			$this->assertSame(ClusterOverview::banners(false, [], $rSettings, [['state' => 'active', 'token_exp' => self::NOW + 600]], self::NOW), $rBanners, 'the page\'s, from each node\'s state and token expiry');
			$this->assertSame('cluster_banner_licence_stop', $rBanners[0]['key']);

			LivenessService::useLicence(static fn(): bool => true);
			$this->assertSame([], ClusterOverview::dashboardBanners([], $rSettings, self::NOW), 'licensed');
			LivenessService::useLicence(static fn(): bool => false);
			$this->assertSame([], ClusterOverview::dashboardBanners([], ['cluster_api_enabled' => 0] + $rSettings, self::NOW), 'the API off');
			ClusterCryptoFactory::useProbe(static fn(): ?array => null);
			$this->assertSame([], ClusterOverview::dashboardBanners([], $rSettings, self::NOW), 'no extension');
		} finally {
			ClusterCryptoFactory::useProbe(null);
			LivenessService::useLicence(null);
		}
	}

	public function testAClockOffTheMainsIsBadgedNeverFenced(): void {
		$this->assertNull(ClusterOverview::clockBadge(null), 'not reported');
		$this->assertNull(ClusterOverview::clockBadge(-30000), 'within 30 s');
		$this->assertSame(['tone' => 'warning', 'key' => 'cluster_clock_off', 'vars' => ['{OFFSET}' => '+45s']], ClusterOverview::clockBadge('45000'));
		$this->assertSame(['tone' => 'danger', 'key' => 'cluster_clock_degraded', 'vars' => ['{OFFSET}' => '-600s']], ClusterOverview::clockBadge(-600000));
		$this->assertNull(ClusterOverview::clockBadge('bogus'), 'not a number');
	}

	public function testAConnectionMismatchOrResyncIsBadged(): void {
		$rNow = self::NOW * 1000;
		$this->assertNull(ClusterOverview::divergenceBadge(null, $rNow), 'no digest, no check');
		$this->assertNull(ClusterOverview::divergenceBadge(['at' => $rNow - 4000, 'miss' => 0, 'asked' => 0], $rNow), 'agreeing');
		$this->assertSame('cluster_conn_differ', ClusterOverview::divergenceBadge(['at' => $rNow - 4000, 'miss' => 1, 'asked' => 0], $rNow)['key'] ?? null);
		$this->assertNull(ClusterOverview::divergenceBadge(['at' => $rNow - 61000, 'miss' => 1, 'asked' => 0], $rNow), 'a check over a minute old: the node sends no digest now');
		$this->assertSame(['tone' => 'warning', 'key' => 'cluster_conn_resynced', 'vars' => ['{AGO}' => '42s']], ClusterOverview::divergenceBadge(['at' => $rNow, 'miss' => 0, 'asked' => $rNow - 42000], $rNow));
		$this->assertNull(ClusterOverview::divergenceBadge(['at' => $rNow, 'miss' => 0, 'asked' => $rNow - ClusterOverview::RESYNC_SHOWN_MS], $rNow), 'resynced long ago');

		// The Servers list's badges read the digest's own state file.
		$rDir = sys_get_temp_dir() . '/xcvm-digest-' . bin2hex(random_bytes(4)) . '/';
		mkdir($rDir);
		ConnectionDigest::useState($rDir);
		try {
			file_put_contents($rDir . '5.json', json_encode(['at' => $rNow, 'miss' => 0, 'asked' => $rNow - 1000]));
			$rBadges = ClusterOverview::nodeBadges(['server_id' => 5, 'clock_offset_ms' => 45000], $rNow);
			$this->assertSame(['cluster_clock_off', 'cluster_conn_resynced'], array_column($rBadges, 'key'));
			$this->assertSame(['cluster_clock_help', 'cluster_conn_help'], array_column($rBadges, 'help'));
			$this->assertSame([], ClusterOverview::nodeBadges(['server_id' => 6, 'clock_offset_ms' => 0], $rNow));
		} finally {
			ConnectionDigest::useState(null);
			exec('rm -rf ' . escapeshellarg($rDir));
		}
	}

	public function testTheInfoTabShowsTheGateTheKeysAndTheNextRefresh(): void {
		$this->node(2, 'active', NodeRegistry::FLOW_COMMANDS);
		$this->node(3, 'active', NodeRegistry::FLOW_COMMANDS);
		$this->node(4, 'enrolling', 0);
		foreach ([[2, self::NOW + 900], [3, self::NOW + 300], [4, self::NOW + 60]] as [$rSid, $rRefresh]) {
			$this->rDb->query('INSERT INTO `cluster_node_epochs` (`server_id`, `epoch`, `record`, `nbf`, `exp`, `refresh_at`, `created_at`) VALUES (?, 1, ?, ?, ?, ?, ?)', $rSid, '', self::NOW, self::NOW + 3600, $rRefresh, self::NOW);
		}
		ClusterCryptoFactory::useProbe(static fn(): array => ['api' => 1, 'ext_version' => '2.4.0', 'licensed' => true, 'kid' => 'a1b2c3d4', 'clock_ok' => true, 'initialised' => true, 'panel_fp' => str_repeat("\xab", 32)]);
		try {
			$rRows = array_column((array) ClusterOverview::infoRows(['cluster_api_enabled' => 1, 'live_streaming_pass' => 'pass']), 1, 0);
			$this->assertSame(['On', 'Open', 'a1b2c3d4'], [$rRows['Cluster API'], $rRows['Licence gate'], $rRows['Licence kid']]);
			$this->assertSame(str_repeat('ab', 32), $rRows['Panel key'], 'what cluster:pin-root takes');
			$this->assertSame('2 active, 1 enrolling', $rRows['Nodes']);
			$this->assertSame(gmdate('Y-m-d H:i', self::NOW + 300) . ' UTC', $rRows['Next token refresh'], 'the soonest active node\'s; an enrolling one does not count');
			$this->assertSame('2.4.0 (API 1; the panel takes ' . ClusterCryptoFactory::status()['range'] . ')', $rRows['Extension']);
			$this->assertSame('OK', $rRows['Extension clock']);
			$this->assertSame('kid ' . ReplicaSections::kid('live_streaming_pass', 'pass') . ', never rotated', $rRows['Stream secret']);

			ClusterCryptoFactory::useProbe(static fn(): array => ['api' => 1, 'ext_version' => '2.4.0', 'licensed' => false, 'clock_ok' => false, 'initialised' => false, 'root_error' => 'NO_ROOT']);
			$rRows = array_column((array) ClusterOverview::infoRows([]), 1, 0);
			$this->assertSame(['Off', 'Closed: no token is issued', '—', 'Not initialised: NO_ROOT', 'Rolled back: cluster calls are refused'], [$rRows['Cluster API'], $rRows['Licence gate'], $rRows['Licence kid'], $rRows['Panel key'], $rRows['Extension clock']]);

			ClusterCryptoFactory::useProbe(static fn(): ?array => null);
			$this->assertNull(ClusterOverview::infoRows([]), 'no extension, no block');
		} finally {
			ClusterCryptoFactory::useProbe(null);
		}
	}

	public function testANewLicenceKeyRotatesEveryToken(): void {
		// The ajax action ends the request, so its source: a key other than the
		// one on disk, once the extension accepts it, sends every active node
		// token.rotate_now (another key gives the chain another base).
		$rSrc = (string) file_get_contents(MAIN_HOME . 'Public/Controllers/Admin/Ajax/MiscAjaxController.php');
		$rBody = substr($rSrc, (int) strpos($rSrc, 'function saveActivationKey'), 3000);
		$this->assertMatchesRegularExpression('/\$rOld = .*file_put_contents\(\$rPath.*LicenseGate::licensed\(\).*\$rOld !== \$rKey \? ClusterOverview::rotateAll\(null\) : null;/s', $rBody);
	}

	public function testThePagesActionNeedsNoServer(): void {
		$this->node(2, 'active', NodeRegistry::FLOW_COMMANDS);
		$rFlash = ClusterAdmin::act($this->rCrypto, ['cluster_action' => 'rotate_all'], [], 1, [], 7);
		$this->assertSame('success', $rFlash['type']);
		$this->assertSame('cluster_rotate_all_done', $rFlash['message']);
		$this->assertSame(['{QUEUED}' => '1', '{SKIPPED}' => '0', '{FAILED}' => '0'], $rFlash['vars']);
		$this->assertSame('success', ClusterAdmin::act($this->rCrypto, ['cluster_action' => 'rotate_all'], [], 1, [], 7)['type'], 'queued again: it replaces the one still waiting');
		$this->rDb->query('SELECT COUNT(*) AS `n` FROM `cluster_commands`;');
		$this->assertSame(1, (int) $this->rDb->get_row()['n']);
		$this->node(3, 'active', NodeRegistry::FLOW_TELEMETRY);
		$this->rDb->query("UPDATE `cluster_nodes` SET `state` = 'revoked' WHERE `server_id` = 2;");
		$this->assertSame(['info', ['{QUEUED}' => '0', '{SKIPPED}' => '1', '{FAILED}' => '0']], array_values(array_intersect_key(ClusterAdmin::act($this->rCrypto, ['cluster_action' => 'rotate_all'], [], 1, [], 7), ['type' => 1, 'vars' => 1])), 'none took it');
	}

	// ── The pages ───────────────────────────────────────────────────────

	public function testThePagesCarryTheActionsAndEveryStringExists(): void {
		$rRoot = MAIN_HOME;
		$rView = (string) file_get_contents($rRoot . 'Public/Views/admin/cluster_nodes.php');
		$rServers = (string) file_get_contents($rRoot . 'Public/Views/admin/servers.php');
		$this->assertStringContainsString('value="rotate_all"', $rView);
		$this->assertStringContainsString('id="node-<?= (int) $rNode[\'server_id\']; ?>"', $rView, 'the servers list links to a node\'s row');
		foreach (['mode_up', 'mode_down', 'code', 'rotate_now'] as $rAction) {
			$this->assertMatchesRegularExpression('#action="cluster_nodes">.*value="' . $rAction . '"#', $rServers, $rAction . ' posts to the Cluster Nodes page');
		}
		$this->assertStringContainsString('cluster_nodes#node-', $rServers, 'mode & flows link to the node');
		$this->assertStringContainsString('cluster:reenrol <?= (int) $rServer[\'id\']; ?>', $rServers, 're-enrol shows the SSH command');

		$rEn = (string) file_get_contents($rRoot . 'Core/Localization/lang/en.ini');
		preg_match_all("/language::get\('(cluster_[a-z0-9_]+)'/", $rView . $rServers, $rKeys);
		preg_match_all("/'key' => '(cluster_[a-z0-9_]+)'|'message' => '(cluster_rotate_all_done)'/", (string) file_get_contents($rRoot . 'Domain/Cluster/ClusterOverview.php') . (string) file_get_contents($rRoot . 'Domain/Cluster/ClusterAdmin.php'), $rMore);
		foreach (array_unique(array_filter([...$rKeys[1], ...$rMore[1], ...$rMore[2]])) as $rKey) {
			$this->assertMatchesRegularExpression('/^' . preg_quote($rKey, '/') . ' = /m', $rEn, $rKey . ' is in en.ini');
		}
	}
}
