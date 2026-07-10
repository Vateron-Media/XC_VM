<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Domain\Server\ServerRepository;

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
 *     service / nginx / reporting cron are actually up.
 *
 * Read-only: only ping/curl/fsockopen, `SELECT`s, and `sudo -n iptables -nL`.
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ServerDiagnoseCommand implements CommandInterface {

	/** Skew (seconds) beyond which the node clock is flagged. */
	private const CLOCK_SKEW_LIMIT = 30;

	/** A signal older than this (seconds) is treated as an unconsumed backlog. */
	private const SIGNAL_STUCK_AFTER = 120;

	public function getName(): string {
		return 'server:diagnose';
	}

	public function getDescription(): string {
		return 'Diagnose why a proxy/LB node is silent to the main (heartbeat, reachability, iptables, service)';
	}

	public function execute(array $rArgs): int {
		$rServers = ServerRepository::getAll(true);

		$rMe = (defined('SERVER_ID') && isset($rServers[SERVER_ID])) ? $rServers[SERVER_ID] : null;

		// Where are we? The main row has is_main=1; anything else is a node.
		if ($rMe !== null && empty($rMe['is_main'])) {
			return $this->diagnoseLocal($rServers, $rMe);
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
		return $this->diagnoseFromMain($rServers[$rTargetID], $rTargetID);
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  Mode A — remote probe from the MAIN
	// ─────────────────────────────────────────────────────────────────────────
	private function diagnoseFromMain(array $rServer, int $rServerID): int {
		global $db;

		$rIP   = (string) $rServer['server_ip'];
		$rPort = intval($rServer['http_broadcast_port']);
		$rNow  = time();

		echo "Diagnosing node #{$rServerID} FROM the main — " . ($rServer['server_name'] ?? '(no name)') . " @ {$rIP}:{$rPort}\n";
		echo str_repeat('-', 64) . "\n";
		$rProblems = array();

		$this->heartbeatSection($rServer, $rNow, $rProblems);

		$rIcmp = $this->icmpPing($rIP);
		$this->line('ICMP ping', $rIcmp ? 'reply' : 'no reply', $rIcmp);
		$rTcp = $this->tcpOpen($rIP, $rPort);
		$this->line("TCP :{$rPort}", $rTcp === true ? 'open' : (string) $rTcp, $rTcp === true);
		list($rCode, $rCurlErr) = $this->httpApi((string) $rServer['api_url_ip']);
		$rHttpOk = ($rCode >= 200 && $rCode < 500);
		$this->line('HTTP /api', $rCode ? "HTTP {$rCode}" : ('curl: ' . ($rCurlErr ?: 'no response')), $rHttpOk);

		$rBeatStale = (($rNow - intval($rServer['last_check_ago'])) > (((int) $rServer['server_type'] === 1) ? 180 : 90));
		if (!$rIcmp && $rTcp !== true) {
			$rProblems[] = "Host unreachable (no ICMP, port {$rPort} closed): the node is powered off, network-partitioned, or fully firewalled from the main.";
		} elseif ($rIcmp && $rTcp !== true) {
			$rProblems[] = "Host pings but port {$rPort} is DROPPED: most likely the node's iptables blocked the main's IP (RootSignals flood/block — public IPs are dropped silently), or nginx/the xc_vm service is down. Run `server:diagnose` ON the node for the exact cause.";
		} elseif ($rTcp === true && !$rHttpOk) {
			$rProblems[] = "Port {$rPort} is open but /api did not answer (" . ($rCode ? "HTTP {$rCode}" : $rCurlErr) . "): nginx is up but PHP/the app is not responding — check php-fpm on the node.";
		} elseif ($rHttpOk && $rBeatStale) {
			$rProblems[] = "The node answers /api yet its heartbeat is stale: it reaches the main but its own cron:servers is not running or cannot WRITE to the panel DB.";
		}

		$this->clockSection($rServer, $rProblems);
		$this->signalSection($db, $rServerID, $rNow, $rProblems);
		return $this->summary($rProblems);
	}

	// ─────────────────────────────────────────────────────────────────────────
	//  Mode B — local self-diagnosis ON the LB/node
	// ─────────────────────────────────────────────────────────────────────────
	private function diagnoseLocal(array $rMe, array $rMeRow): int {
		// Note: $rMe is the full server map; $rMeRow is this node's row.
		global $db;
		$rServers = $rMe;
		$rMe      = $rMeRow;
		$rNow     = time();

		echo "Self-diagnosis on node #" . SERVER_ID . " — " . ($rMe['server_name'] ?? '(no name)') . " (type " . ($rMe['server_type'] ?? '?') . ")\n";
		echo str_repeat('-', 64) . "\n";
		$rProblems = array();

		// 1. How the main currently sees me (my own row in the shared DB).
		$this->heartbeatSection($rMe, $rNow, $rProblems);

		// 2. Can I reach the MAIN? (I just used the DB, so DB→main already works.)
		$this->line('DB → main', 'reachable (this query ran)', true);
		$rMain = $this->findMain($rServers);
		if ($rMain === null) {
			$this->line('Main server', 'NOT found in servers table', false);
			$rProblems[] = 'No is_main server row found — the panel DB has no main record; this node cannot know where to report.';
		} else {
			$rMainIP   = (string) $rMain['server_ip'];
			$rMainPort = intval($rMain['http_broadcast_port']);
			$rP = $this->icmpPing($rMainIP);
			$this->line('Ping main', $rP ? "reply ({$rMainIP})" : "no reply ({$rMainIP})", $rP);
			$rT = $this->tcpOpen($rMainIP, $rMainPort);
			$this->line("Main :{$rMainPort}", $rT === true ? 'open' : (string) $rT, $rT === true);
			if (!$rP && $rT !== true) {
				$rProblems[] = "Cannot reach the main ({$rMainIP}): network/route/firewall between this node and the main is down — the DB works but the HTTP callback path does not.";
			}

			// 3. Did THIS node firewall the main's IP? (the silent classic)
			$rBlocked = $this->iptablesBlocks($rMainIP);
			$rMarker  = file_exists(FLOOD_TMP_PATH . 'block_' . $rMainIP);
			$rSelfBlock = ($rBlocked === true) || $rMarker;
			$this->line('Main in iptables', $rBlocked === true ? 'DROP present' : ($rBlocked === false ? 'not blocked' : (string) $rBlocked) . ($rMarker ? ' (+flood marker)' : ''), !$rSelfBlock);
			if ($rSelfBlock) {
				$rProblems[] = "This node has DROPPED the main's IP {$rMainIP} in its own iptables (flood/block false-positive). Unblock: `sudo iptables -D INPUT -s {$rMainIP} -j DROP && sudo rm -f " . FLOOD_TMP_PATH . "block_{$rMainIP}`.";
			}
		}

		// 4. My service / nginx.
		$rSvc = $this->svcActive('xc_vm');
		$this->line('Service xc_vm', $rSvc ? 'active' : 'NOT active', $rSvc);
		if (!$rSvc) {
			$rProblems[] = 'The xc_vm systemd service is not active — `sudo systemctl restart xc_vm` (it launches nginx + startup).';
		}
		$rMyPort = intval($rMe['http_broadcast_port']);
		$rNginx  = $this->tcpOpen('127.0.0.1', $rMyPort) === true;
		$this->line("nginx :{$rMyPort}", $rNginx ? 'listening' : 'NOT listening (localhost)', $rNginx);
		if (!$rNginx) {
			$rProblems[] = "nginx is not listening on {$rMyPort} locally — `sudo " . BIN_PATH . "nginx/sbin/nginx -t` then restart the service.";
		}

		// 5. Reporting cron present? (updates the heartbeat the main watches)
		$rCronOk = $this->crontabHas('cron:servers');
		$this->line('cron:servers', $rCronOk ? 'in crontab' : 'MISSING from crontab', $rCronOk);
		if (!$rCronOk) {
			$rProblems[] = 'cron:servers is not in this node\'s crontab — the heartbeat never refreshes, so the main always sees it offline. Re-run `console.php startup`.';
		}

		// 6. Clock.
		$this->clockSection($rMe, $rProblems);

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

	private function clockSection(array $rServer, array &$rProblems): void {
		$rOffset = intval($rServer['time_offset']);
		$rOk = abs($rOffset) <= self::CLOCK_SKEW_LIMIT;
		$this->line('Clock offset', "{$rOffset}s vs panel", $rOk);
		if (!$rOk) {
			$rProblems[] = "Clock skew {$rOffset}s: heartbeat freshness compares timestamps, so a large offset can flap the node online/offline — sync NTP.";
		}
	}

	private function signalSection($db, int $rServerID, int $rNow, array &$rProblems): void {
		$db->query('SELECT COUNT(*) AS `c`, MIN(`time`) AS `oldest` FROM `signals` WHERE `server_id` = ?;', $rServerID);
		$rRows    = $db->get_rows() ?: array();
		$rSig     = $rRows[0] ?? array();
		$rBacklog = intval($rSig['c'] ?? 0);
		$rOldest  = $rBacklog > 0 ? ($rNow - intval($rSig['oldest'])) : 0;
		$rOk      = ($rBacklog === 0) || ($rOldest < self::SIGNAL_STUCK_AFTER);
		$this->line('Signal queue', $rBacklog > 0 ? "{$rBacklog} pending (oldest {$rOldest}s)" : 'empty', $rOk);
		if (!$rOk) {
			$rProblems[] = "{$rBacklog} unconsumed signals (oldest {$rOldest}s): the node is not draining its signal queue — its RootSignals/callback loop is stuck.";
		}
	}

	private function summary(array $rProblems): int {
		echo str_repeat('-', 64) . "\n";
		if (empty($rProblems)) {
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
		$rOut = array();
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
		curl_setopt_array($ch, array(
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 3,
			CURLOPT_TIMEOUT        => 6,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_SSL_VERIFYHOST => 0,
		));
		curl_exec($ch);
		$rCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		$rErr  = curl_error($ch);
		curl_close($ch);
		return array($rCode, $rErr);
	}

	private function svcActive(string $rName): bool {
		$rOut = array();
		$rCode = 1;
		exec('systemctl is-active ' . escapeshellarg($rName) . ' 2>/dev/null', $rOut, $rCode);
		return trim(implode('', $rOut)) === 'active';
	}

	/** @return true|false|string true=blocked, false=not, string=could not check. */
	private function iptablesBlocks(string $rIP) {
		$rOut = array();
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
		return false;
	}

	private function crontabHas(string $rNeedle): bool {
		$rOut = array();
		$rCode = 1;
		exec('crontab -l 2>/dev/null', $rOut, $rCode);
		if ($rCode !== 0) {
			return true; // cannot read crontab → do not raise a false alarm
		}
		return strpos(implode("\n", $rOut), $rNeedle) !== false;
	}

	/** One aligned status line: "[OK]/[WARN] <label> <value>". */
	private function line(string $rLabel, string $rValue, bool $rOk): void {
		echo sprintf("%-6s %-16s %s\n", $rOk ? '[OK]' : '[WARN]', $rLabel, $rValue);
	}
}
