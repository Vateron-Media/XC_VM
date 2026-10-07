<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronJobs\ServersCronJob;
use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\AgentPaths;
use XcVm\Core\Cluster\ClusterDiagnosis;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Cluster\NodeRole;
use XcVm\Core\Config\OpensslExtra;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Process\ProcessManager;
use XcVm\Domain\Cluster\ClusterAdmin;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Diagnose why a proxy/LB node has gone silent to the main server.
 *
 * The panel marks a node offline purely from a stale heartbeat
 * (server_online = enabled && status==1 && now - last_check_ago <= threshold),
 * which never tells you WHY it stopped reporting. This command has two modes,
 * auto-selected from where it runs (config.ini `server_id` → is_main):
 *
 *   • ON THE MAIN — `server:diagnose <server_id>`: remote-probe the target node
 *     (ICMP / TCP / HTTP /api) and read its panel-side heartbeat + signal queue.
 *
 *   • ON THE LB/NODE — `server:diagnose` (id optional): LOCAL self-diagnosis —
 *     the causes usually live here. Checks the node's own view from the panel DB,
 *     whether it can reach the MAIN, whether the node firewalled the main's IP in
 *     its own iptables (the classic "silent for no reason"), and whether its
 *     service / nginx / watchdog heartbeat daemon / babysitter cron are up.
 *     (The heartbeat is written by the `watchdog` DAEMON every few seconds — it
 *     exits when its DB connection drops; `cron:servers` in the xc_vm crontab
 *     relaunches it. A MySQL blip on the main therefore drops ALL nodes at once.)
 *
 * A node in the cluster API (ADR 0004) is judged by its cluster state as well:
 *
 *   • ON THE MAIN — the node's `cluster_nodes` row: state and health, mode and
 *     flows, when its agent was last heard, its token and the fence window
 *     that follows it (token expiry + `lb_partition_tolerance_h`, then
 *     `lb_fence_drain_min`), the clock offset its heartbeats carry, and its
 *     command queue's lag.
 *   • ON THE NODE — its agent's own report (`GET /v1/status` on the agent's
 *     socket): whether the agent answers, the licence fence, the last
 *     heartbeat MAIN answered, the clock against MAIN's, token and lease
 *     windows, each event lane's backlog, and the lease verdict the node's PHP
 *     acts on (NodeLease). With the TELEMETRY flow on the legacy heartbeat is
 *     not the authority and is not judged; in mode 2 the node has no database
 *     of MAIN's, so the checks that need one are skipped.
 *
 * Read-only: only ping/curl/fsockopen, `SELECT`s, `sudo -n iptables -nL`, and
 * the agent's status endpoint.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ServerDiagnoseCommand implements CommandInterface {
	use DatabaseAware;

	/** Skew (seconds) beyond which the node clock is flagged. */
	private const CLOCK_SKEW_LIMIT = 30;

	/** A signal older than this (seconds) is treated as an unconsumed backlog. */
	private const SIGNAL_STUCK_AFTER = 120;

	public function getName(): string {
		return 'server:diagnose';
	}

	public function getDescription(): string {
		return 'Diagnose why a proxy/LB node is silent to the main (heartbeat, reachability, iptables, service, cluster state)';
	}

	public function execute(array $rArgs): int {
		// A node in mode 2 reads its servers from its replica; one whose replica
		// is not there yet cannot read them at all. The agent still answers.
		try {
			$rServers = ServerRepository::getAll(true);
		} catch (\Throwable) {
			$rServers = null;
		}

		$rMe = ($rServers !== null && defined('SERVER_ID') && isset($rServers[SERVER_ID])) ? $rServers[SERVER_ID] : null;

		// Where are we? The main row has is_main=1; anything else is a node. With
		// no servers to go by, a node's agent file or a build without MAIN's
		// cluster endpoint says it.
		$rIsNode = $rMe !== null ? empty($rMe['is_main']) : ($rServers === null && (NodeFlows::declared()['mode'] >= 1 || !NodeRole::mainBuild()));
		if ($rIsNode) {
			return $this->diagnoseLocal($rServers ?? [], $rMe);
		}
		if ($rServers === null) {
			echo "Cannot read the servers (no database, no replica).\n";
			return 1;
		}

		// On the main (or is_main undeterminable): probe a target by id.
		$rTargetID = intval($rArgs[0] ?? 0);
		if ($rTargetID <= 0) {
			echo "Usage: php console.php server:diagnose <server_id>   (run WITHOUT an id on the LB itself for local self-diagnosis)\n";
			return 1;
		}
		if (!isset($rServers[$rTargetID])) {
			echo "Server #{$rTargetID} not found.\n";
			return 1;
		}
		if (!empty($rServers[$rTargetID]['is_main']) || $rTargetID == SERVER_ID) {
			echo "Server #{$rTargetID} is the MAIN server — nothing to diagnose (it is always 'online' to itself).\n";
			return 0;
		}
		return $this->diagnoseFromMain($rServers, $rTargetID, $this->findMain($rServers));
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  Mode A — remote probe from the MAIN
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @param array<int, array<string, mixed>> $rServers
	 * @param array<string, mixed>|null $rMain
	 */
	private function diagnoseFromMain(array $rServers, int $rServerID, ?array $rMain): int {
		self::db();

		$rServer = $rServers[$rServerID];
		$rIP   = (string) $rServer['server_ip'];
		$rPort = intval($rServer['http_broadcast_port']);
		$rNow  = time();

		echo "Diagnosing node #{$rServerID} FROM the main — " . ($rServer['server_name'] ?? '(no name)') . " @ {$rIP}:{$rPort}\n";
		echo str_repeat('-', 64) . "\n";
		$rProblems = [];

		// The cluster API's view first: with the TELEMETRY flow on, the node's
		// agent is what keeps it online, not the legacy heartbeat.
		$rSettings = SettingsManager::getAll();
		$rNode = $this->clusterNode($rServers, $rServerID, $rSettings);
		$rTelemetry = $rNode !== null && (int) $rNode['mode'] >= 1 && ((int) $rNode['flows'] & NodeFlows::TELEMETRY) === NodeFlows::TELEMETRY;

		if (!$rTelemetry) {
			$this->heartbeatSection($rServer, $rNow, $rProblems);
		} else {
			$this->enabledSection($rServer, $rProblems);
		}

		$rIcmp = $this->icmpPing($rIP);
		$this->line('ICMP ping', $rIcmp ? 'reply' : 'no reply', $rIcmp);
		$rTcp = $this->tcpOpen($rIP, $rPort);
		$this->line("TCP :{$rPort}", $rTcp === true ? 'open' : (string) $rTcp, $rTcp === true);
		list($rCode, $rCurlErr) = $this->httpApi((string) $rServer['api_url_ip']);
		$rHttpOk = ($rCode >= 200 && $rCode < 500);
		$this->line('HTTP /api', $rCode ? "HTTP {$rCode}" : ('curl: ' . ($rCurlErr ?: 'no response')), $rHttpOk);

		$rBeatStale = !$rTelemetry && (($rNow - intval($rServer['last_check_ago'])) > (((int) $rServer['server_type'] === 1) ? 180 : 90));
		if (!$rIcmp && $rTcp !== true) {
			$rProblems[] = "Host unreachable (no ICMP, port {$rPort} closed): the node is powered off, network-partitioned, or fully firewalled from the main.";
		} elseif ($rIcmp && $rTcp !== true) {
			$rProblems[] = "Host pings but port {$rPort} is DROPPED: most likely the node's iptables blocked the main's IP (RootSignals flood/block — public IPs are dropped silently), or nginx/the xc_vm service is down. Run `server:diagnose` ON the node for the exact cause.";
		} elseif (!$rHttpOk) {
			$rProblems[] = "Port {$rPort} is open but /api did not answer (" . ($rCode ? "HTTP {$rCode}" : $rCurlErr) . "): nginx is up but PHP/the app is not responding — check php-fpm on the node.";
		} elseif ($rHttpOk && $rBeatStale) {
			$rProblems[] = "The node answers /api yet its heartbeat is stale: its watchdog daemon (the heartbeat writer) is not running — it exits when its DB connection to the main drops — or it cannot WRITE to the panel DB. Run `server:diagnose` ON the node.";
		}

		if ($rNode !== null) {
			echo "-- Cluster API --\n";
			$this->checks(ClusterDiagnosis::node($rNode, $this->commandQueue($rServerID), (int) round(microtime(true) * 1000), $rSettings), $rProblems);
		} elseif (!empty($rSettings['cluster_api_enabled']) && ClusterAdmin::isLoadBalancer($rServer)) {
			$this->line('Cluster node', 'not enrolled (legacy node)', true);
		}

		if (!$rTelemetry) {
			$this->clockSection($rServer, $rProblems);
		}
		$this->signalSection($rServerID, $rNow, $rProblems);
		$this->opensslExtraSection($rServer, $rMain, $rProblems);
		return $this->summary($rProblems);
	}

	/**
	 * The node's `cluster_nodes` row as the Cluster Nodes page reads it (health
	 * and the freshest last_seen_at), or null when it is not enrolled or the
	 * cluster tables are not there yet.
	 *
	 * @param array<int, array<string, mixed>> $rServers
	 * @param array<string, mixed> $rSettings
	 * @return array<string, mixed>|null
	 */
	private function clusterNode(array $rServers, int $rServerID, array $rSettings): ?array {
		if (empty($rSettings['cluster_api_enabled'])) {
			return null;
		}
		try {
			foreach (ClusterAdmin::nodes($rServers, ClusterSettings::int('cluster_offline_after_sec', $rSettings['cluster_offline_after_sec'] ?? null)) as $rNode) {
				if ((int) $rNode['server_id'] === $rServerID) {
					$rDb = self::db();
					if ($rDb->query('SELECT `clock_offset_ms`, `useq_p0`, `useq_p1` FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID) && $rDb->num_rows() > 0) {
						$rNode += $rDb->get_row();
					}
					return $rNode;
				}
			}
		} catch (\Throwable) {
			// No cluster tables yet (before cluster:init's migrations).
		}
		return null;
	}

	/**
	 * The node's commands not yet acked: how many, and when the oldest was queued.
	 *
	 * @return array{count: int, oldest: ?int}
	 */
	private function commandQueue(int $rServerID): array {
		$rDb = self::db();
		if (!$rDb->query("SELECT COUNT(*) AS `c`, MIN(`created_at`) AS `oldest` FROM `cluster_commands` WHERE `server_id` = ? AND `state` IN ('queued', 'delivered') AND `exp` > ?;", $rServerID, time())) {
			return ['count' => 0, 'oldest' => null];
		}
		$rRow = $rDb->get_row() ?: [];
		return ['count' => (int) ($rRow['c'] ?? 0), 'oldest' => isset($rRow['oldest']) ? (int) $rRow['oldest'] : null];
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  Mode B — local self-diagnosis ON the LB/node
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @param array<int, array<string, mixed>> $rServers The server map; empty when it cannot be read.
	 * @param array<string, mixed>|null $rMe This node's row; null when it cannot be read.
	 */
	private function diagnoseLocal(array $rServers, ?array $rMe): int {
		$rNow      = time();
		$rDeclared = NodeFlows::declared();
		$rMode     = (int) $rDeclared['mode'];
		$rTelemetry = $rMode >= 1 && ((int) $rDeclared['flows'] & NodeFlows::TELEMETRY) === NodeFlows::TELEMETRY;

		echo "Self-diagnosis on node #" . (defined('SERVER_ID') ? SERVER_ID : '?') . " — " . ($rMe['server_name'] ?? '(no name)') . " (type " . ($rMe['server_type'] ?? '?') . ", cluster mode {$rMode})\n";
		echo str_repeat('-', 64) . "\n";
		$rProblems = [];

		// 1. How the main currently sees me (my own row). With TELEMETRY on the
		// agent's heartbeat is the authority (section 9 below), not this row.
		if ($rMe !== null && !$rTelemetry) {
			$this->heartbeatSection($rMe, $rNow, $rProblems);
		} elseif ($rMe !== null) {
			$this->enabledSection($rMe, $rProblems);
		}

		// 2. Can I reach the MAIN? In mode 2 the node reads no database of MAIN's.
		if ($rMode >= 2) {
			$this->line('DB → main', 'not used (mode 2: servers from the replica)', true);
		} elseif ($rMe !== null) {
			$this->line('DB → main', 'reachable (this query ran)', true);
		}
		$rMain = $rServers === [] ? null : $this->findMain($rServers);
		if ($rMain === null) {
			$this->line('Main server', 'NOT found in the servers', false);
			$rProblems[] = $rServers === []
				? 'This node cannot read the servers at all (no database of MAIN\'s, and no replica yet): it does not know where MAIN is. The agent below still reports what it holds.'
				: 'No is_main server row found — the panel DB has no main record; this node cannot know where to report.';
		} else {
			$rMainIP   = (string) $rMain['server_ip'];
			$rMainPort = intval($rMain['http_broadcast_port']);
			$rP = $this->icmpPing($rMainIP);
			$this->line('Ping main', $rP ? "reply ({$rMainIP})" : "no reply ({$rMainIP})", $rP);
			$rT = $this->tcpOpen($rMainIP, $rMainPort);
			$this->line("Main :{$rMainPort}", $rT === true ? 'open' : (string) $rT, $rT === true);
			if (!$rP && $rT !== true) {
				$rProblems[] = "Cannot reach the main ({$rMainIP}): network/route/firewall between this node and the main is down — " . ($rMode >= 1 ? 'the agent cannot reach MAIN\'s cluster API either.' : 'the DB works but the HTTP callback path does not.');
			}

			// 3. Did THIS node firewall the main's IP? (the silent classic)
			$rBlocked = $this->iptablesBlocks($rMainIP);
			$rMarker  = file_exists(FLOOD_TMP_PATH . 'block_' . $rMainIP);
			$rSelfBlock = ($rBlocked === true) || $rMarker;
			$this->line('Main in iptables', $rBlocked === true ? 'DROP present' : ($rBlocked === false ? 'not blocked' : (string) $rBlocked) . ($rMarker ? ' (+flood marker)' : ''), !$rSelfBlock);
			if ($rSelfBlock) {
				$rSet = filter_var($rMainIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'xcvm_block6' : 'xcvm_block4';
				$rProblems[] = "This node has DROPPED the main's IP {$rMainIP} in its own firewall (flood/block false-positive). Unblock it in MAIN's Blocked IPs (each node applies MAIN's list within minutes), or by hand: `sudo ipset del {$rSet} {$rMainIP}` (a node without ipset: `sudo iptables -D INPUT -s {$rMainIP} -j DROP`) `&& sudo rm -f " . FLOOD_TMP_PATH . "block_{$rMainIP}`.";
			}
		}

		// 4. My service / nginx.
		$rSvc = $this->svcActive('xc_vm');
		$this->line('Service xc_vm', $rSvc ? 'active' : 'NOT active', $rSvc);
		if (!$rSvc) {
			$rProblems[] = 'The xc_vm systemd service is not active — `sudo systemctl restart xc_vm` (it launches nginx + startup).';
		}
		if ($rMe !== null) {
			$rMyPort = intval($rMe['http_broadcast_port']);
			$rNginx  = $this->tcpOpen('127.0.0.1', $rMyPort) === true;
			$this->line("nginx :{$rMyPort}", $rNginx ? 'listening' : 'NOT listening (localhost)', $rNginx);
			if (!$rNginx) {
				$rProblems[] = "nginx is not listening on {$rMyPort} locally — `sudo " . BIN_PATH . "nginx/sbin/nginx -t` then restart the service.";
			}
		}
		// 5. The heartbeat writer: the watchdog daemon self-respawns every few
		// seconds via `(sleep 1; console.php 'watchdog') &`, so a single pgrep
		// can land in the ~1s window where only the sleeping subshell exists.
		// Match the quoted subshell form ('?) AND re-sample across a full
		// respawn cycle before declaring the chain dead.
		$rWatchdogPattern = "XC_VM\\[Watchdog\\]|console\\.php '?watchdog";
		$rWatchdog = $this->processRunning($rWatchdogPattern);
		for ($rTry = 0; $rTry < 3 && !$rWatchdog; $rTry++) {
			sleep(1);
			$rWatchdog = $this->processRunning($rWatchdogPattern);
		}
		$this->line('watchdog daemon', $rWatchdog ? 'running' : 'NOT running', $rWatchdog);
		if (!$rWatchdog) {
			$rProblems[] = 'The watchdog daemon — the process that writes this node\'s heartbeat — is not running (killed/OOM, or an older build that died when the MySQL connection dropped; current builds wait the outage out). cron:servers relaunches it within a minute; start it now: `sudo -u xc_vm ' . PHP_BIN . ' ' . MAIN_HOME . 'console.php watchdog`.';
		}

		// 6. The babysitter cron: relaunches the watchdog if it died.
		$rCronOk = $this->crontabHas('cron:servers');
		$this->line('cron:servers', $rCronOk ? 'in xc_vm crontab' : 'MISSING from xc_vm crontab', $rCronOk);
		if (!$rCronOk) {
			$rProblems[] = 'cron:servers is missing from the xc_vm user\'s crontab — nothing relaunches the watchdog (heartbeat writer) when it dies. Regenerate it: `rm -f ' . TMP_PATH . 'crontab` then run any console command or restart the service.';
		}

		// 6b. The cron daemon itself: no cron → the xc_vm crontab never fires.
		$rCronSvc = $this->svcActive('cron') || $this->svcActive('crond') || $this->svcActive('cronie');
		$this->line('cron service', $rCronSvc ? 'active' : 'NOT active', $rCronSvc);
		if (!$rCronSvc) {
			$rProblems[] = 'The system cron service is not running — the xc_vm crontab (including cron:servers) never fires, so a dead watchdog stays dead. `sudo systemctl start cron`.';
		}

		// 6c. A hung cron:servers holds its lock and blocks every relaunch until
		// the first run after its ten-minute limit ends it
		// (ProcessManager::exitIfCronLockHeld).
		$rLock = ProcessManager::cronLockPath(ServersCronJob::class);
		if (file_exists($rLock)) {
			$rLockPID = intval(trim((string) @file_get_contents($rLock)));
			$rLockAge = time() - filemtime($rLock);
			$rHung    = $rLockPID > 0 && file_exists('/proc/' . $rLockPID) && $rLockAge > 120;
			$this->line('servers cron lock', "held by PID {$rLockPID} for {$rLockAge}s", !$rHung);
			if ($rHung) {
				$rProblems[] = "A cron:servers instance (PID {$rLockPID}) has been holding its cron lock for {$rLockAge}s — it is likely hung (since the same DB/network blip that killed the watchdog) and blocks every babysitter run until it ends. The first cron:servers run after it has lasted 10 minutes ends it and relaunches the watchdog; to recover sooner: `sudo kill -9 {$rLockPID}`.";
			}
		}

		// 7. Clock (the legacy row's offset; the agent's own measure is below).
		if ($rMe !== null && !$rTelemetry) {
			$this->clockSection($rMe, $rProblems);
		}

		// 8. Do tokens minted on the main open here?
		if ($rMe !== null) {
			$this->opensslExtraSection($rMe, $rMain, $rProblems);
		}

		// 9. The cluster API: the agent's own report and the lease verdict.
		if ($rMode >= 1 || is_file(AgentPaths::file(AgentPaths::STATE))) {
			echo "-- Cluster API --\n";
			try {
				$rSettings = SettingsManager::getAll();
			} catch (\Throwable) {
				$rSettings = [];
			}
			$this->checks(ClusterDiagnosis::agent(AgentClient::status(), (int) round(microtime(true) * 1000), $rSettings), $rProblems);
			$rVerdict = NodeLease::verdict($rSettings);
			$rServing = $rVerdict['state'] === NodeLease::SERVING;
			$this->line('Lease verdict', $rVerdict['state'] . ($rVerdict['why'] !== '' ? ' (' . $rVerdict['why'] . ')' : ''), $rServing);
			if (!$rServing) {
				$rProblems[] = $rVerdict['state'] === NodeLease::FENCED
					? 'This node is fenced: its lease ran out and the drain is over, so it serves no viewer. It needs a new token (and lease) from a licensed, reachable MAIN.'
					: 'This node is draining: its lease ran out, so no new viewer starts here and the running ones stop at ' . gmdate('Y-m-d H:i', $rVerdict['drain_until']) . ' UTC (MAIN\'s clock).';
			}
		}

		return $this->summary($rProblems);
	}

	// ── Shared sections ──────────────────────────────────────────────────────
	private function heartbeatSection(array $rServer, int $rNow, array &$rProblems): void {
		$rEnabled = !empty($rServer['enabled']);
		$this->line('Enabled', $rEnabled ? 'yes' : 'NO (disabled in panel)', $rEnabled);
		if (!$rEnabled) {
			$rProblems[] = 'The server is disabled in the panel.';
		}
		$rStatusOk = ((int) $rServer['status'] === 1);
		$this->line('Status', $rServer['status'] . ($rStatusOk ? ' (online)' : ' (not 1/online)'), $rStatusOk);
		if (!$rStatusOk) {
			$rProblems[] = "Status is {$rServer['status']} (not 1) — a previous install/provision errored (status 4 = error).";
		}
		$rThreshold = ((int) $rServer['server_type'] === 1) ? 180 : 90;
		$rStale     = $rNow - intval($rServer['last_check_ago']);
		$rBeatOk    = $rStale <= $rThreshold;
		$this->line('Heartbeat', "last check-in {$rStale}s ago (limit {$rThreshold}s)", $rBeatOk);
		if (!$rBeatOk) {
			$rProblems[] = "Heartbeat is stale ({$rStale}s > {$rThreshold}s): the node stopped reporting — the checks below narrow down why.";
		}
	}

	/** The panel's enable switch alone, for a node whose heartbeat is the cluster API's. */
	private function enabledSection(array $rServer, array &$rProblems): void {
		$rEnabled = !empty($rServer['enabled']);
		$this->line('Enabled', $rEnabled ? 'yes' : 'NO (disabled in panel)', $rEnabled);
		if (!$rEnabled) {
			$rProblems[] = 'The server is disabled in the panel.';
		}
		$this->line('Heartbeat', 'judged by the cluster API (TELEMETRY flow on)', true);
	}

	/**
	 * Print ClusterDiagnosis checks, and take their problems.
	 *
	 * @param list<array{label: string, value: string, ok: bool, problem: ?string}> $rChecks
	 * @param list<string> $rProblems
	 */
	private function checks(array $rChecks, array &$rProblems): void {
		foreach ($rChecks as $rCheck) {
			$this->line($rCheck['label'], $rCheck['value'], $rCheck['ok']);
			if ($rCheck['problem'] !== null) {
				$rProblems[] = $rCheck['problem'];
			}
		}
	}

	private function clockSection(array $rServer, array &$rProblems): void {
		$rOffset = intval($rServer['time_offset']);
		$rOk = abs($rOffset) <= self::CLOCK_SKEW_LIMIT;
		$this->line('Clock offset', "{$rOffset}s vs panel", $rOk);
		if (!$rOk) {
			$rProblems[] = "Clock skew {$rOffset}s: heartbeat freshness compares timestamps, so a large offset can flap the node online/offline — sync NTP.";
		}
	}

	private function signalSection(int $rServerID, int $rNow, array &$rProblems): void {
		$db = self::db();
		$db->query('SELECT COUNT(*) AS `c`, MIN(`time`) AS `oldest` FROM `signals` WHERE `server_id` = ?;', $rServerID);
		$rRows    = $db->get_rows() ?: [];
		$rSig     = $rRows[0] ?? [];
		$rBacklog = intval($rSig['c'] ?? 0);
		$rOldest  = $rBacklog > 0 ? ($rNow - intval($rSig['oldest'])) : 0;
		$rOk      = ($rBacklog === 0) || ($rOldest < self::SIGNAL_STUCK_AFTER);
		$this->line('Signal queue', $rBacklog > 0 ? "{$rBacklog} pending (oldest {$rOldest}s)" : 'empty', $rOk);
		if (!$rOk) {
			$rProblems[] = "{$rBacklog} unconsumed signals (oldest {$rOldest}s): the node is not draining its signal queue — its RootSignals/callback loop is stuck.";
		}
	}

	/**
	 * Compare the OPENSSL_EXTRA fingerprints the node and the main publish in
	 * server_hardware (cron:servers). Proxies publish none and are skipped.
	 */
	private function opensslExtraSection(array $rServer, ?array $rMain, array &$rProblems): void {
		if ($rMain === null || (int) ($rServer['server_type'] ?? 0) === 1) {
			return;
		}
		$rNodePrint = OpensslExtra::reportedFingerprint($rServer);
		$rMainPrint = OpensslExtra::reportedFingerprint($rMain);
		if ($rNodePrint === null || $rMainPrint === null) {
			$this->line('OPENSSL_EXTRA', 'unknown (' . ($rNodePrint === null ? 'node' : 'main') . ' not updated)', true);
			return;
		}
		$rOk = hash_equals($rMainPrint, $rNodePrint);
		$this->line('OPENSSL_EXTRA', $rOk ? 'matches main' : 'MISMATCH with main', $rOk);
		if (!$rOk) {
			$rProblems[] = 'OPENSSL_EXTRA mismatch: tokens minted on MAIN are rejected by this node (playback redirected from the main fails). Repair it from the main: `php console.php server:sync-openssl-extra ' . intval($rServer['id'] ?? 0) . '`.';
		}
	}

	private function summary(array $rProblems): int {
		echo str_repeat('-', 64) . "\n";
		if ($rProblems === []) {
			echo "[OK] No obvious cause — reachable, reporting, clock-synced.\n";
			echo "     If the panel still shows it offline, force a poll: php console.php cron:servers\n";
			return 0;
		}
		echo "Probable cause(s):\n";
		foreach ($rProblems as $rI => $rP) {
			echo '  ' . ($rI + 1) . ". {$rP}\n";
		}
		return 2;
	}

	// ── Probes / helpers ─────────────────────────────────────────────────────
	private function findMain(array $rServers): ?array {
		foreach ($rServers as $rRow) {
			if (!empty($rRow['is_main'])) {
				return $rRow;
			}
		}
		return null;
	}

	private function icmpPing(string $rIP): bool {
		$rOut = [];
		$rCode = 1;
		exec('ping -c1 -W2 ' . escapeshellarg($rIP) . ' 2>/dev/null', $rOut, $rCode);
		return $rCode === 0;
	}

	/** @return true|string true if the port accepts, else a short reason. */
	private function tcpOpen(string $rIP, int $rPort) {
		$rErrno = 0;
		$rErrstr = '';
		$rFp = @fsockopen($rIP, $rPort, $rErrno, $rErrstr, 3);
		if ($rFp) {
			fclose($rFp);
			return true;
		}
		return $rErrstr !== '' ? $rErrstr : 'closed/timeout';
	}

	/** @return array{0:int,1:string} [http_code, curl_error]; code 0 = no response. */
	private function httpApi(string $rURL): array {
		$ch = curl_init($rURL);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT        => 6,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
		]);
		curl_exec($ch);
		$rCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$rErr  = curl_error($ch);
		curl_close($ch);
		return [$rCode, $rErr];
	}

	private function svcActive(string $rName): bool {
		$rOut = [];
		$rCode = 1;
		exec('systemctl is-active ' . escapeshellarg($rName) . ' 2>/dev/null', $rOut, $rCode);
		return trim(implode('', $rOut)) === 'active';
	}

	/** @return true|false|string true=blocked, false=not, string=could not check. */
	private function iptablesBlocks(string $rIP) {
		$rOut = [];
		$rCode = 1;
		exec('sudo -n iptables -nL INPUT 2>/dev/null', $rOut, $rCode);
		if ($rCode !== 0) {
			return 'cannot check (need sudo iptables)';
		}
		foreach ($rOut as $rLine) {
			if (strpos($rLine, 'DROP') !== false && strpos($rLine, $rIP) !== false) {
				return true;
			}
		}
		// The blocks RootSignalsCronJob::syncSets() keeps in an ipset set, one rule matching each.
		$rSetOut = [];
		$rSetCode = 1;
		exec('sudo -n ipset test ' . (filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) ? 'xcvm_block6' : 'xcvm_block4') . ' ' . escapeshellarg($rIP) . ' 2>/dev/null', $rSetOut, $rSetCode);
		return $rSetCode === 0;
	}

	private function crontabHas(string $rNeedle): bool {
		// Panel crons live in the xc_vm USER's crontab (LegacyInitializer::generateCron),
		// not root's. `-u xc_vm` needs root; fall back to the caller's own crontab.
		$rOut = [];
		$rCode = 1;
		exec('crontab -u xc_vm -l 2>/dev/null', $rOut, $rCode);
		if ($rCode !== 0) {
			exec('crontab -l 2>/dev/null', $rOut, $rCode);
		}
		if ($rCode !== 0) {
			return true; // cannot read crontab → do not raise a false alarm
		}
		return strpos(implode("\n", $rOut), $rNeedle) !== false;
	}

	/** Whether a process whose command line matches the ERE $rPattern is running. */
	private function processRunning(string $rPattern): bool {
		$rOut = [];
		$rCode = 1;
		exec('pgrep -f ' . escapeshellarg($rPattern) . ' 2>/dev/null', $rOut, $rCode);
		return $rCode === 0 && count($rOut) > 0;
	}

	/** One aligned status line: "[OK]/[WARN] <label> <value>". */
	private function line(string $rLabel, string $rValue, bool $rOk): void {
		echo sprintf("%-6s %-16s %s\n", $rOk ? '[OK]' : '[WARN]', $rLabel, $rValue);
	}
}
