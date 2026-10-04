<?php

use PHPUnit\Framework\TestCase;
use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Cluster\ClusterClock;
use XcVm\Domain\Cluster\ClusterEndpoint;
use XcVm\Domain\Cluster\ClusterNginxConfig;
use XcVm\Domain\Cluster\ClusterPolicy;
use XcVm\Domain\Cluster\EnrolCodeService;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Tests\Support\QueryLogDb;

/**
 * An old port MAIN keeps after an endpoint change is released before its 7
 * days once every node uses the new URL (plan §3, "Endpoint changes"): every
 * node that may use MAIN's URLs (mode ≥ 1, not revoked) is heard, has
 * adopted the current policy (the `policy_ver` its hello and heartbeat carry)
 * and last reached MAIN on another port (the port nginx took them on), and
 * no enrolment by code may still dial an old URL. A node that is offline,
 * never heard, behind, or an agent that says nothing keeps the 7 days, and
 * so does a plain-HTTP port under `https_required`.
 */
final class ClusterEndpointReleaseTest extends TestCase {
	private TestDb $rDb;

	private int $rNow = 1800000000;

	/** MAIN's `servers` row. */
	private array $rMain = [
		'id' => 1, 'is_main' => 1, 'server_ip' => '10.0.0.1', 'private_ip' => '192.168.0.1', 'domain_name' => 'panel.example.com',
		'enable_https' => 1, 'http_broadcast_port' => 25461, 'https_broadcast_port' => 25463,
	];

	private array $rSettingsBefore = [];

	protected function setUp(): void {
		$this->rDb = new TestDb();
		$this->rDb->exec("CREATE TABLE `settings` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `cluster_api_enabled` int DEFAULT 0, `cluster_api_port` int DEFAULT 0, `cluster_transport` varchar(16) DEFAULT 'auto', `cluster_main_host` varchar(255) DEFAULT '', `cluster_policy_ver` int DEFAULT 1, `cluster_legacy_ports` varchar(255) DEFAULT '', `cluster_legacy_urls` text, `cluster_offline_after_sec` int DEFAULT 30)");
		$this->rDb->exec('INSERT INTO `settings` (`id`) VALUES (1)');
		$this->rDb->exec('CREATE TABLE `cluster_audit` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `time` int, `server_id` int, `actor` varchar(64), `event` varchar(64), `detail` text, `ip` varchar(64))');
		$this->rDb->exec("CREATE TABLE `cluster_nodes` (`server_id` INTEGER PRIMARY KEY AUTO_INCREMENT, `state` varchar(16) NOT NULL DEFAULT 'enrolling', `mode` int NOT NULL DEFAULT 1, `enrol_deadline` int DEFAULT NULL, `last_seen_at` bigint DEFAULT NULL, `policy_ver` int NOT NULL DEFAULT 0, `main_port` int DEFAULT NULL, `updated_at` int NOT NULL DEFAULT 0)");
		$this->rDb->exec('CREATE TABLE `servers` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT)');
		$this->rDb->exec('CREATE TABLE `cluster_enrol_codes` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int NOT NULL, `created_at` int NOT NULL DEFAULT 0, `exp` int NOT NULL, `used_at` int DEFAULT NULL)');
		$this->rDb->exec("CREATE TABLE `cluster_enrol_requests` (`id` INTEGER PRIMARY KEY AUTO_INCREMENT, `server_id` int NOT NULL, `state` varchar(20) NOT NULL DEFAULT 'pending_approval', `created_at` int NOT NULL)");
		DatabaseFactory::set($this->rDb);
		ClusterClock::fix($this->rNow * 1000);
		$this->rSettingsBefore = SettingsManager::getAll();
	}

	protected function tearDown(): void {
		SettingsManager::set($this->rSettingsBefore);
		ClusterClock::fix(null);
		DatabaseFactory::reset();
	}

	private function settings(): array {
		$this->rDb->query('SELECT * FROM `settings`');
		return $this->rDb->get_row();
	}

	/**
	 * The stored settings with the cluster API on, and $rOver stored on top:
	 * ClusterEndpoint reads the transport again from the database.
	 */
	private function live(array $rOver = []): array {
		foreach ($rOver as $rColumn => $rValue) {
			$this->rDb->query('UPDATE `settings` SET `' . $rColumn . '` = ?', $rValue);
		}
		return ['cluster_api_enabled' => 1] + $this->settings();
	}

	private function ver(): int {
		return (int) $this->settings()['cluster_policy_ver'];
	}

	/**
	 * A node MAIN heard $rAgoMs ago (null: never), which said it uses policy
	 * $rVer (0: nothing, an older agent) and reached MAIN on $rPort, and its
	 * server.
	 */
	private function node(int $rServerID, int $rVer, ?int $rPort, ?int $rAgoMs = 1000, string $rState = 'active', int $rMode = 1): void {
		$this->rDb->query('DELETE FROM `servers` WHERE `id` = ?', $rServerID);
		$this->rDb->query('INSERT INTO `servers` (`id`) VALUES (?)', $rServerID);
		$this->rDb->query('DELETE FROM `cluster_nodes` WHERE `server_id` = ?', $rServerID);
		$this->rDb->query(
			'INSERT INTO `cluster_nodes` (`server_id`, `state`, `mode`, `last_seen_at`, `policy_ver`, `main_port`) VALUES (?, ?, ?, ?, ?, ?)',
			$rServerID,
			$rState,
			$rMode,
			$rAgoMs === null ? null : $this->rNow * 1000 - $rAgoMs,
			$rVer,
			$rPort
		);
	}

	/** @return list<array{event: string, actor: string, detail: array<string, mixed>}> */
	private function audit(): array {
		$this->rDb->query('SELECT `event`, `actor`, `detail` FROM `cluster_audit` ORDER BY `id`');
		return array_map(static fn(array $rRow): array => ['event' => (string) $rRow['event'], 'actor' => (string) $rRow['actor'], 'detail' => (array) json_decode((string) $rRow['detail'], true)], $this->rDb->get_rows());
	}

	/** MAIN's HTTP broadcast port moves 25461 → 8080 with node 2 on it: announced at version 2, 25461 kept. */
	private function movePort(): array {
		$this->node(2, 1, 25461);
		$rNew = ['http_broadcast_port' => 8080] + $this->rMain;
		$this->assertTrue(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $this->live()));
		$this->assertSame([2, [25461]], [$this->ver(), array_keys(ClusterEndpoint::legacyPorts($this->settings()))]);
		return $rNew;
	}

	public function testAnOldPortIsReleasedOnceEveryNodeUsesTheNewUrl(): void {
		$rNew = $this->movePort();
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'the node has not fetched the new policy yet');

		// It adopts version 2 and moves to the new port. A node in mode 0 that
		// MAIN has not heard and a revoked node say nothing, and nothing waits
		// for them.
		$this->node(2, 2, 8080);
		$this->node(3, 0, null, null, 'active', 0);
		$this->node(4, 0, null, null, 'revoked');
		$this->assertTrue(ClusterEndpoint::release($this->live()));
		$this->assertSame('', $this->settings()['cluster_legacy_ports'], 'released before its 7 days');
		$this->assertSame(3, $this->ver(), 'announced: the nodes refetch a policy without it');
		$this->assertSame(
			['http://192.168.0.1:8080/cluster/v1/', 'http://10.0.0.1:8080/cluster/v1/'],
			ClusterPolicy::current($this->live(), $rNew)['main_urls']
		);
		$rAudit = $this->audit();
		$this->assertSame(['cluster.endpoint_change', 'cluster.endpoint_released'], array_column($rAudit, 'event'));
		$this->assertSame(['ports' => [25461], 'kept' => [], 'kept_urls' => [], 'policy_ver' => 2], $rAudit[1]['detail']);
		$this->assertSame('cron', $rAudit[1]['actor']);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'nothing left to release');
	}

	/** A node behind the current policy, or ahead of MAIN's own version (a restored MAIN), keeps the port. */
	public function testANodeThatHasNotAdoptedTheCurrentPolicyKeepsThePort(): void {
		$this->movePort();
		$this->node(2, 2, 8080);
		$this->node(3, 1, 25461);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'node 3 lags');
		$this->node(3, 7, 8080);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'node 3 says a version MAIN never announced');
		$this->assertSame([25461], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
		$this->assertSame(2, $this->ver());

		// The current version is at or above the one that announced the change.
		$this->rDb->query('UPDATE `settings` SET `cluster_policy_ver` = 5');
		$this->node(2, 5, 8080);
		$this->node(3, 5, 8080);
		$this->assertTrue(ClusterEndpoint::release($this->live()));
	}

	/** Never while a node is offline, never heard, or still enrolling. */
	public function testAnOfflineOrUnknownNodeKeepsThePort(): void {
		$this->movePort();
		$this->node(2, 2, 8080, 31000);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'offline: silent over cluster_offline_after_sec');
		$this->node(2, 2, 8080, null);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'never heard');
		$this->node(2, 2, null);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'no port recorded: before migration 046, or no SERVER_PORT from nginx');
		$this->node(2, 2, 8080, 15000);
		$this->node(3, 0, null, null, 'enrolling');
		$this->rDb->query('UPDATE `cluster_nodes` SET `enrol_deadline` = ? WHERE `server_id` = 3', $this->rNow + 60);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'a node still enrolling dials the URLs of its cluster.json');
		$this->rDb->query('UPDATE `cluster_nodes` SET `enrol_deadline` = ? WHERE `server_id` = 3', $this->rNow);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'its last second: enrol_complete still accepts it');
		$this->assertSame([25461], array_keys(ClusterEndpoint::legacyPorts($this->settings())));

		// Its enrolment expired: it can no longer complete, and a new one
		// sends the current URLs. A node silent for 15 s (suspect) is heard.
		$this->rDb->query('UPDATE `cluster_nodes` SET `enrol_deadline` = ? WHERE `server_id` = 3', $this->rNow - 1);
		$this->assertTrue(ClusterEndpoint::release($this->live()));

		// The offline window is the admin's.
		$this->movePortOn();
		$this->node(2, $this->ver(), 9090, 45000);
		$this->assertFalse(ClusterEndpoint::release($this->live()));
		$this->assertTrue(ClusterEndpoint::release($this->live(['cluster_offline_after_sec' => 60])));
	}

	/** The offline window is 10 s at least and 300 s at most, as for liveness. */
	public function testTheOfflineWindowIsBounded(): void {
		$this->movePort();
		$this->node(2, 2, 8080, 301000);
		$this->assertFalse(ClusterEndpoint::release($this->live(['cluster_offline_after_sec' => 1000])), 'silent past 300 s: offline');
		$this->node(2, 2, 8080, 8000);
		$this->assertTrue(ClusterEndpoint::release($this->live(['cluster_offline_after_sec' => 5])), 'silent 8 s: heard');
	}

	/** A quarantined node still says hello and heartbeats, so it is waited for like any other. */
	public function testAQuarantinedNodeKeepsThePort(): void {
		$this->movePort();
		$this->node(2, 2, 8080);
		$this->node(3, 1, 25461, 1000, 'quarantined');
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'behind');
		$this->node(3, 2, 25461, 1000, 'quarantined');
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'on the old port');
		$this->node(3, 2, 8080, 1000, 'quarantined');
		$this->assertTrue(ClusterEndpoint::release($this->live()));
	}

	/**
	 * A node in mode 0 is not waited for, but its agent still heartbeats
	 * MAIN's URLs: while it is heard, the port it reaches MAIN on stays.
	 */
	public function testANodeInModeZeroKeepsThePortItIsHeardOn(): void {
		$this->movePort();
		$this->node(2, 2, 8080);
		$this->node(3, 0, 25461, 1000, 'active', 0);
		$this->assertFalse(ClusterEndpoint::release($this->live()));
		$this->node(3, 0, 25461, 31000, 'active', 0);
		$this->assertTrue(ClusterEndpoint::release($this->live()), 'offline, and its version is never asked');
	}

	/**
	 * A row whose server was deleted (ServerRepository::deleteById() leaves
	 * `cluster_nodes`) is no node of MAIN's, and never holds a port.
	 */
	public function testANodeWhoseServerWasDeletedKeepsNothing(): void {
		$this->movePort();
		$this->node(2, 2, 8080);
		$this->node(9, 0, null, 30 * 86400 * 1000);
		$this->assertFalse(ClusterEndpoint::release($this->live()));
		$this->rDb->query('DELETE FROM `servers` WHERE `id` = 9');
		$this->assertTrue(ClusterEndpoint::release($this->live()));
	}

	/**
	 * An enrolment by code dials the one URL its code carries, the policy's
	 * first when it was issued: while a code lives, or a request made with
	 * one waits for approval (the agent polls for as long as a code lives),
	 * nothing goes. Once approved, the node's enrolling row holds the ports.
	 */
	public function testAnEnrolmentByCodeKeepsThePorts(): void {
		$this->movePort();
		$this->node(2, 2, 8080);
		$this->rDb->query('INSERT INTO `cluster_enrol_codes` (`server_id`, `exp`) VALUES (3, ?)', $this->rNow + 1500);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'a code nobody used yet');
		$this->rDb->query('UPDATE `cluster_enrol_codes` SET `used_at` = ?, `exp` = ?', $this->rNow - 60, $this->rNow);
		$this->rDb->query("INSERT INTO `cluster_enrol_requests` (`server_id`, `state`, `created_at`) VALUES (3, 'pending_approval', ?)", $this->rNow - 60);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'the code expired; its request still waits for approval');
		$this->rDb->query('UPDATE `cluster_enrol_requests` SET `created_at` = ?', $this->rNow - EnrolCodeService::TTL);
		$this->assertTrue(ClusterEndpoint::release($this->live()), 'the agent has stopped waiting');
	}

	/** 8080 → 9090 after the release above: 8080 kept, announced at the current version. */
	private function movePortOn(): void {
		$rFrom = ['http_broadcast_port' => 8080] + $this->rMain;
		$this->assertTrue(ClusterEndpoint::recordMainChange($rFrom, ['http_broadcast_port' => 9090] + $rFrom, $this->live()));
		$this->assertSame([8080], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
	}

	/**
	 * A node that adopted the new policy but reaches MAIN only on the old
	 * port (its new URL fails, so it dials the kept one) keeps that port;
	 * another kept port no node arrives on goes.
	 */
	public function testANodeStillOnTheOldPortKeepsIt(): void {
		$this->node(2, 1, 25463);
		$rSettings = $this->live(['cluster_transport' => 'https_preferred']);
		$rNew = ['http_broadcast_port' => 8080, 'https_broadcast_port' => 8443] + $this->rMain;
		$this->assertTrue(ClusterEndpoint::recordMainChange($this->rMain, $rNew, $rSettings));
		$this->assertSame([25461], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
		$this->assertSame(['https://panel.example.com:25463/cluster/v1/'], array_keys(ClusterEndpoint::legacyUrls($this->settings())));

		$this->node(2, 2, 25461);
		$this->node(3, 2, 8443);
		$this->assertTrue(ClusterEndpoint::release($this->live(['cluster_transport' => 'https_preferred'])));
		$this->assertSame([25461], array_keys(ClusterEndpoint::legacyPorts($this->settings())), 'node 2 still arrives on it');
		$this->assertSame([], ClusterEndpoint::legacyUrls($this->settings()), 'the old HTTPS port goes');
		$this->assertSame(['ports' => [25463], 'kept' => [25461], 'kept_urls' => [], 'policy_ver' => 2], $this->audit()[1]['detail']);
		$this->assertSame(3, $this->ver());

		// Node 2 moves on, and refetches the policy the release announced.
		$this->node(2, 3, 8080);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'node 3 has not fetched version 3 yet');
		$this->node(3, 3, 8443);
		$this->assertTrue(ClusterEndpoint::release($this->live()));
		$this->assertSame('', $this->settings()['cluster_legacy_ports']);
	}

	/**
	 * A release that frees one kept port keeps the kept URLs on a port a node
	 * still reaches MAIN on: the old HTTPS port of a kept URL stays served.
	 */
	public function testAKeptUrlOnAPortStillInUseStays(): void {
		$this->rDb->query("UPDATE `settings` SET `cluster_transport` = 'https_preferred'");
		$this->node(2, 1, 25463);
		$this->assertTrue(ClusterEndpoint::recordMainChange($this->rMain, ['http_broadcast_port' => 8080, 'https_broadcast_port' => 8443] + $this->rMain, $this->live()));
		$this->node(2, 2, 25463);
		$this->node(3, 2, 8080);
		$this->assertTrue(ClusterEndpoint::release($this->live()));
		$this->assertSame('', $this->settings()['cluster_legacy_ports']);
		$this->assertSame(['https://panel.example.com:25463/cluster/v1/'], array_keys(ClusterEndpoint::legacyUrls($this->settings())), 'node 2 still arrives on the old HTTPS port');
		$this->assertSame(['ports' => [25461], 'kept' => [], 'kept_urls' => ['https://panel.example.com:25463/cluster/v1/'], 'policy_ver' => 2], $this->audit()[1]['detail']);
	}

	/**
	 * Under https_required a kept plain-HTTP port or http:// URL keeps its 7
	 * days: no node reports a plain port, yet the plain-HTTP URLs a node has
	 * known are its way back when HTTPS fails (the signed challenge). A kept
	 * https:// URL still goes early, and the plain ones once the transport
	 * lists plain HTTP again.
	 */
	public function testUnderHttpsRequiredAPlainPortKeepsItsSevenDays(): void {
		$this->rDb->query("UPDATE `settings` SET `cluster_api_port` = 9000, `cluster_transport` = 'https_required', `cluster_legacy_urls` = ?", (string) json_encode(['http://10.0.0.9:9000/cluster/v1/' => $this->rNow + 3600], JSON_UNESCAPED_SLASHES));
		$this->node(2, 1, 25463);
		$this->assertTrue(ClusterEndpoint::recordApiPortChange(9000, 9001, $this->live(), $this->rMain));
		$this->rDb->query('UPDATE `settings` SET `cluster_api_port` = 9001');
		$this->assertTrue(ClusterEndpoint::recordMainChange($this->rMain, ['https_broadcast_port' => 8443] + $this->rMain, $this->live()));
		$this->assertSame([9000], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
		$this->assertSame(['https://panel.example.com:25463/cluster/v1/', 'http://10.0.0.9:9000/cluster/v1/'], array_keys(ClusterEndpoint::legacyUrls($this->settings())));
		$this->assertSame(3, $this->ver());

		$this->node(2, 3, 8443);
		$this->assertTrue(ClusterEndpoint::release($this->live()));
		$this->assertSame([9000], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
		$this->assertSame(['http://10.0.0.9:9000/cluster/v1/'], array_keys(ClusterEndpoint::legacyUrls($this->settings())));
		$this->assertSame(['ports' => [25463], 'kept' => [9000], 'kept_urls' => ['http://10.0.0.9:9000/cluster/v1/'], 'policy_ver' => 3], $this->audit()[2]['detail']);
		$this->node(2, 4, 8443);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'nothing else goes');

		// Back to auto (a settings save raises the version).
		$this->rDb->query("UPDATE `settings` SET `cluster_transport` = 'auto', `cluster_policy_ver` = `cluster_policy_ver` + 1");
		$this->node(2, 5, 9001);
		$this->assertTrue(ClusterEndpoint::release($this->live()));
		$this->assertSame(['', ''], [$this->settings()['cluster_legacy_ports'], (string) $this->settings()['cluster_legacy_urls']]);
	}

	/**
	 * The port a node reached MAIN on is nginx's: Public/cluster passes
	 * SERVER_PORT, which fastcgi_params set to $server_port, never the node's
	 * source port (REMOTE_PORT), which would never name a kept port.
	 */
	public function testThePortComesFromNginx(): void {
		$rSrc = MAIN_HOME;
		$this->assertStringContainsString("'port' => (int) (\$_SERVER['SERVER_PORT'] ?? 0),", (string) file_get_contents($rSrc . 'Public/cluster/index.php'));
		$this->assertMatchesRegularExpression('/^fastcgi_param\s+SERVER_PORT\s+\$server_port;$/m', (string) file_get_contents($rSrc . 'bin/nginx/conf/fastcgi_params'));
		$this->assertStringContainsString("    include fastcgi_params;\n", ClusterNginxConfig::locations(), 'the cluster API location passes it');
	}

	/**
	 * An old URL on a port MAIN serves anyway (an old address on the current
	 * port) is released only when no node arrives on that port: the port
	 * cannot tell which address a node dialled.
	 */
	public function testAnOldAddressOnTheCurrentPortStaysWhileNodesUseThePort(): void {
		$this->node(2, 1, 25461);
		$this->assertTrue(ClusterEndpoint::recordMainChange($this->rMain, ['server_ip' => '10.0.0.2'] + $this->rMain, $this->live()));
		$this->node(2, 2, 25461);
		$this->assertFalse(ClusterEndpoint::release($this->live()));
		$this->assertSame(['http://10.0.0.1:25461/cluster/v1/'], array_keys(ClusterEndpoint::legacyUrls($this->settings())));
	}

	/**
	 * An agent that says nothing (policy_ver 0, today's agent) keeps its 7
	 * days: the port goes when they are up, as before.
	 */
	public function testAnAgentThatSaysNothingKeepsTheSevenDays(): void {
		$this->movePort();
		$this->node(2, 0, 8080);
		$this->node(3, 2, 8080);
		$this->assertFalse(ClusterEndpoint::release($this->live()));
		ClusterClock::fix(($this->rNow + ClusterEndpoint::GRACE - 1) * 1000);
		$this->node(2, 0, 8080);
		$this->assertFalse(ClusterEndpoint::release($this->live()));
		$this->assertFalse(ClusterEndpoint::prune($this->settings()), 'not expired yet');
		$this->assertSame([25461], array_keys(ClusterEndpoint::legacyPorts($this->settings())));

		ClusterClock::fix(($this->rNow + ClusterEndpoint::GRACE + 1) * 1000);
		$this->assertTrue(ClusterEndpoint::prune($this->settings()));
		$this->assertSame('', $this->settings()['cluster_legacy_ports']);
		$this->assertSame(['cluster.endpoint_change', 'cluster.endpoint_expired'], array_column($this->audit(), 'event'));
	}

	/** No node left that may use MAIN's URLs (revoked, or back in mode 0 and not heard): nothing to wait for. */
	public function testWithoutANodeTheOldPortGoesAtOnce(): void {
		$this->movePort();
		$this->node(2, 0, null, null, 'revoked');
		$this->assertTrue(ClusterEndpoint::release($this->live()));
		$this->assertSame('', $this->settings()['cluster_legacy_ports']);
	}

	/** A node read that fails releases nothing. */
	public function testAFailedNodeReadReleasesNothing(): void {
		$this->movePort();
		$this->node(2, 2, 8080);
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rRefuse = '/FROM `cluster_nodes`/';
		DatabaseFactory::set($rLog);
		$this->assertFalse(ClusterEndpoint::release($this->live()));
		$this->rDb->exec('DROP TABLE `cluster_nodes`');
		DatabaseFactory::set($this->rDb);
		$this->assertFalse(ClusterEndpoint::release($this->live()), 'a read that throws');
		$this->assertSame([25461], array_keys(ClusterEndpoint::legacyPorts($this->settings())));
	}

	/**
	 * A change stored between the release's read and its write keeps what it
	 * kept: the write goes only over the lists as they were read.
	 */
	public function testAChangeStoredMeanwhileIsNotLost(): void {
		$this->movePort();
		$this->node(2, 2, 8080);
		$rLog = new QueryLogDb($this->rDb);
		$rLog->rBefore = function (string $rQuery): void {
			if (str_starts_with($rQuery, 'UPDATE `settings`') && str_contains($rQuery, 'WHERE')) {
				$this->rDb->query('UPDATE `settings` SET `cluster_legacy_ports` = ?, `cluster_policy_ver` = `cluster_policy_ver` + 1', (string) json_encode([8080 => $this->rNow + 60, 25461 => $this->rNow + ClusterEndpoint::GRACE]));
			}
		};
		DatabaseFactory::set($rLog);
		$this->assertFalse(ClusterEndpoint::release($this->live()));
		$this->assertSame([8080, 25461], array_keys(ClusterEndpoint::legacyPorts($this->settings())), 'the port kept meanwhile stays');
		$this->assertSame(3, $this->ver(), 'bumped once, by the change');
		$this->assertSame(['cluster.endpoint_change'], array_column($this->audit(), 'event'));
	}

	/**
	 * What a hello or heartbeat records of the node: the policy version it
	 * says it uses (0 when it says nothing or something else) and the port
	 * nginx took the request on, each only when it changed, the port only
	 * once migration 046 added its column.
	 */
	public function testTheNodeSaysWhichPolicyItUses(): void {
		$rNode = ['server_id' => 2, 'policy_ver' => 0, 'main_port' => null];
		$this->assertSame(['policy_ver' => 4, 'main_port' => 8080], ClusterEndpoint::nodeUses($rNode, ['policy_ver' => 4], 8080));
		$this->assertSame([], ClusterEndpoint::nodeUses(['policy_ver' => 4, 'main_port' => 8080] + $rNode, ['policy_ver' => 4], 8080), 'nothing changed: nothing to write');
		$this->assertSame(['policy_ver' => 0], ClusterEndpoint::nodeUses(['policy_ver' => 4, 'main_port' => 8080] + $rNode, [], 8080), 'an agent that stops saying it (a downgrade) is unknown again');
		$this->assertSame(['policy_ver' => 4294967295], ClusterEndpoint::nodeUses($rNode, ['policy_ver' => 4294967295], 0), 'the largest the column holds');
		foreach (['4', 4.0, -1, 4294967296, true, null, [4]] as $rBad) {
			$this->assertSame([], ClusterEndpoint::nodeUses($rNode, ['policy_ver' => $rBad], 0), var_export($rBad, true));
		}
		$this->assertSame([], ClusterEndpoint::nodeUses($rNode, [], 0), 'no port from nginx: not observed');
		$this->assertSame([], ClusterEndpoint::nodeUses($rNode, [], 65536));
		$this->assertSame([], ClusterEndpoint::nodeUses(['server_id' => 2, 'policy_ver' => 0], [], 8080), 'before migration 046: no main_port column');
	}

	/** Migration 046 adds the port a node last reached MAIN on; database.sql has it for fresh installs. */
	public function testTheSchema(): void {
		$rSrc = MAIN_HOME;
		$rUp = (string) file_get_contents($rSrc . 'migrations/database/up/046_add_cluster_node_main_port.sql');
		$this->assertStringContainsString("ALTER TABLE `cluster_nodes` ADD COLUMN IF NOT EXISTS `main_port` smallint(5) unsigned DEFAULT NULL AFTER `policy_ver`;", $rUp);
		$this->assertStringContainsString('ALTER TABLE `cluster_nodes` DROP COLUMN IF EXISTS `main_port`;', (string) file_get_contents($rSrc . 'migrations/database/down/046_add_cluster_node_main_port.sql'));
		$this->assertStringContainsString("  `policy_ver` int(10) unsigned NOT NULL DEFAULT '0',\n  `main_port` smallint(5) unsigned DEFAULT NULL,\n", (string) file_get_contents($rSrc . 'bin/install/database.sql'));
	}
}
