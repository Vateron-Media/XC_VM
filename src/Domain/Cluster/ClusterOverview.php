<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterDiagnosis;
use XcVm\Core\Cluster\ClusterHealth;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\ReplicaSections;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What the Cluster Nodes page shows beside the node list (plan section 11,
 * "Admin UI"): each node's fence window, the licence and certificate banners,
 * the figures MAIN already records (command delivery and ack latency, command
 * queue depth, MAIN's ingest and control-pool saturation, the recent audit),
 * and the fleet-wide "Rotate all tokens now".
 *
 * Only figures MAIN records are shown. Heartbeat latency, ingest lag and the
 * 503 counters the plan also lists are not recorded anywhere yet, so the page
 * says nothing about them rather than a number that means nothing.
 *
 * @package XC_VM_Domain_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ClusterOverview {
	use DatabaseAware;

	/**
	 * The servers that keep every load balancer's legacy `/api` open
	 * (DataPlane::legacyApiRetired(), which each node judges from its signed
	 * node list): MAIN while its data-plane client is off, and every other
	 * server that is not a node active in mode 1 or 2 with its DATAPLANE flow
	 * (a load balancer not enrolled or without the flow, a proxy). None: the
	 * legacy `/api` answers 404 on every node with DATAPLANE from its next
	 * root pass.
	 *
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll()
	 * @param list<array<string, mixed>> $rNodes ClusterAdmin::nodes()
	 * @return list<int> Server ids, as the servers list orders them.
	 */
	public static function legacyApiOpenBy(array $rServers, array $rNodes, int $rMainID, bool $rMainDataPlane): array {
		$rReady = [];
		foreach ($rNodes as $rNode) {
			if (($rNode['state'] ?? null) === 'active' && (int) ($rNode['mode'] ?? 0) >= 1 && ((int) ($rNode['flows'] ?? 0) & NodeRegistry::FLOW_DATAPLANE) !== 0) {
				$rReady[(int) $rNode['server_id']] = true;
			}
		}
		$rOut = [];
		foreach (array_keys($rServers) as $rID) {
			if ((int) $rID === $rMainID ? !$rMainDataPlane : !isset($rReady[(int) $rID])) {
				$rOut[] = (int) $rID;
			}
		}
		return $rOut;
	}

	/**
	 * Viewer record proof (`cluster_conn_binding`, ADR 0004, "The line a node
	 * names"), per active node: whether its records proved MAIN's mint since
	 * its enrolment (`cluster_meta` `conn_proven.<server id>` at its `gen`),
	 * whether enforce would hold it (MAIN withholds its stream secret,
	 * ReplicaBuilder::withholdsStreamPass), and the day's counts
	 * (ConnectionAdmission::bindingCounts). Ready: every node enforce would
	 * hold proves and counted nothing unproven today; `held` says whether
	 * enforce would hold any node at all.
	 *
	 * @param list<array<string, mixed>> $rNodes ClusterAdmin::nodes()
	 * @param array<string, mixed> $rSettings
	 * @param (callable(array<string, mixed>, string): bool)|null $rHeld Tests: whether enforce holds a node; null is ReplicaBuilder::withholdsStreamPass.
	 * @return array{mode: string, ready: bool, held: bool, nodes: list<array{server_id: int, server_name: string, proves: bool, held: bool, counts: array<string, mixed>|null}>}
	 */
	public static function binding(array $rNodes, array $rSettings, ?callable $rHeld = null): array {
		$rLive = (string) ($rSettings['live_streaming_pass'] ?? '');
		$rOut = [];
		$rReady = true;
		$rHeldAny = false;
		foreach ($rNodes as $rNode) {
			if (($rNode['state'] ?? null) !== 'active') {
				continue;
			}
			$rServerID = (int) $rNode['server_id'];
			$rProves = ClusterMeta::get('conn_proven.' . $rServerID) === (string) ($rNode['gen'] ?? '');
			$rIsHeld = $rLive !== '' && ($rHeld !== null ? $rHeld($rNode, $rLive) : ReplicaBuilder::withholdsStreamPass($rNode, $rLive));
			$rCounts = ConnectionAdmission::bindingCounts($rServerID);
			if ($rIsHeld) {
				$rHeldAny = true;
				if (!$rProves || (int) ($rCounts['unproven'] ?? 0) + (int) ($rCounts['admit_unproven'] ?? 0) > 0) {
					$rReady = false;
				}
			}
			$rOut[] = ['server_id' => $rServerID, 'server_name' => (string) ($rNode['server_name'] ?? ''), 'proves' => $rProves, 'held' => $rIsHeld, 'counts' => $rCounts];
		}
		return ['mode' => ($rSettings[ConnectionAdmission::BINDING] ?? null) === 'enforce' ? 'enforce' : 'observe', 'ready' => $rReady, 'held' => $rHeldAny, 'nodes' => $rOut];
	}

	/** Days before MAIN's certificate expires that the page warns, when the nodes dial HTTPS. */
	public const CERT_WARN_DAYS = 14;

	/** The window (s) of acked commands the latency percentiles are drawn from. */
	public const LATENCY_WINDOW_SEC = 3600;

	/** The most acked commands the percentiles read. */
	public const LATENCY_SAMPLES = 2000;

	/** Audit rows shown. */
	public const AUDIT_ROWS = 25;

	/** A control-pool queue last seen this long ago (ms) is over. */
	public const CTL_QUEUE_FRESH_MS = 10000;

	/**
	 * Each node's fence window (ClusterDiagnosis::fenceWindow), by server id.
	 *
	 * @param list<array<string, mixed>> $rNodes ClusterAdmin::nodes()
	 * @param array<string, mixed> $rSettings
	 * @return array<int, array{lease_until: ?int, drain_until: ?int, tolerance_h: int, drain_min: int, fence_on: bool}>
	 */
	public static function fenceWindows(array $rNodes, array $rSettings): array {
		$rOut = [];
		foreach ($rNodes as $rNode) {
			$rOut[(int) $rNode['server_id']] = ClusterDiagnosis::fenceWindow(isset($rNode['token_exp']) ? (int) $rNode['token_exp'] : null, $rSettings);
		}
		return $rOut;
	}

	/**
	 * What the active nodes' agents report of chunk digests that name no
	 * request (NodeDigestN1), and MAIN's own agent (MainDataPlane::digestN1):
	 * the owners named in the last 24 h, and how many active nodes do not
	 * report (an agent from before the report).
	 *
	 * @param list<array<string, mixed>> $rNodes ClusterAdmin::nodes()
	 * @return array{owners: list<int>, silent: int}
	 */
	public static function digestN1(array $rNodes): array {
		$rOwners = [];
		$rSilent = 0;
		foreach ($rNodes as $rNode) {
			if (($rNode['state'] ?? '') !== 'active') {
				continue;
			}
			$rList = NodeDigestN1::owners($rNode);
			if ($rList === null) {
				$rSilent++;
			} else {
				$rOwners = array_merge($rOwners, $rList);
			}
		}
		// MAIN's own data-plane agent reads files too (its report, not a heartbeat).
		$rOwners = array_values(array_unique(array_merge($rOwners, MainDataPlane::digestN1() ?? [])));
		sort($rOwners);
		return ['owners' => $rOwners, 'silent' => $rSilent];
	}

	/**
	 * A node's clock badge (plan, section 4, "Clock"): none within
	 * ClusterDiagnosis::SKEW_WARN_MS, a warning past it, "degraded (clock)"
	 * past SKEW_DEGRADED_MS. It never fences.
	 *
	 * @param mixed $rOffsetMs `cluster_nodes.clock_offset_ms` (node − MAIN)
	 * @return array{tone: string, key: string, vars: array<string, string>}|null
	 */
	public static function clockBadge(mixed $rOffsetMs): ?array {
		$rOffset = is_numeric($rOffsetMs) ? (int) $rOffsetMs : 0;
		if (abs($rOffset) <= ClusterDiagnosis::SKEW_WARN_MS) {
			return null;
		}
		$rDegraded = abs($rOffset) > ClusterDiagnosis::SKEW_DEGRADED_MS;
		return ['tone' => $rDegraded ? 'danger' : 'warning', 'key' => $rDegraded ? 'cluster_clock_degraded' : 'cluster_clock_off', 'vars' => ['{OFFSET}' => sprintf('%+ds', (int) round($rOffset / 1000))]];
	}

	/** How long a connection resync stays on a node's badge (ms). */
	public const RESYNC_SHOWN_MS = 300000;

	/**
	 * A node's connection-digest badge (plan, section 8: "a badge flags digest
	 * mismatches"): MAIN asked it for a snapshot within RESYNC_SHOWN_MS, or its
	 * registry and MAIN's store disagreed at a check in the last minute
	 * (ConnectionDigest). None for a node that sends no digest.
	 *
	 * @param array<string, mixed>|null $rState ConnectionDigest::state()
	 * @return array{tone: string, key: string, vars: array<string, string>}|null
	 */
	public static function divergenceBadge(?array $rState, int $rNowMs): ?array {
		$rAsked = (int) ($rState['asked'] ?? 0);
		if ($rAsked > 0 && $rNowMs - $rAsked < self::RESYNC_SHOWN_MS) {
			return ['tone' => 'warning', 'key' => 'cluster_conn_resynced', 'vars' => ['{AGO}' => ClusterDiagnosis::span(intdiv($rNowMs - $rAsked, 1000))]];
		}
		if ((int) ($rState['miss'] ?? 0) > 0 && $rNowMs - (int) ($rState['at'] ?? 0) < 60000) {
			return ['tone' => 'warning', 'key' => 'cluster_conn_differ', 'vars' => []];
		}
		return null;
	}

	/**
	 * The Servers list's badges for a node beside its state and mode (plan,
	 * section 11, "Servers list badges"), each with its help text's key.
	 *
	 * @param array<string, mixed> $rNode a ClusterAdmin::nodes() row
	 * @return list<array{tone: string, key: string, vars: array<string, string>, help: string}>
	 */
	public static function nodeBadges(array $rNode, ?int $rNowMs = null): array {
		$rNowMs ??= ClusterClock::nowMs();
		$rOut = [];
		if (($rClock = self::clockBadge($rNode['clock_offset_ms'] ?? null)) !== null) {
			$rOut[] = $rClock + ['help' => 'cluster_clock_help'];
		}
		if (($rConn = self::divergenceBadge(ConnectionDigest::state((int) $rNode['server_id']), $rNowMs)) !== null) {
			$rOut[] = $rConn + ['help' => 'cluster_conn_help'];
		}
		// NodeLag: a lane whose oldest event has waited past OUTBOX_LAG_SEC (P0
		// carries state, so it is the graver), and the MAIN URLs that fail.
		foreach (NodeLag::LANES as $rLane) {
			if (($rNode[$rLane . '_lag_since'] ?? null) !== null) {
				$rAge = ClusterDiagnosis::span(max(0, intdiv($rNowMs, 1000) - (int) $rNode[$rLane . '_lag_since']));
				$rOut[] = ['tone' => $rLane === 'p0' ? 'danger' : 'warning', 'key' => 'cluster_lane_lag', 'vars' => ['{LANE}' => strtoupper($rLane), '{AGE}' => $rAge], 'help' => 'cluster_lane_lag_help'];
			}
		}
		if ((string) ($rNode['unreachable_urls'] ?? '') !== '') {
			$rOut[] = ['tone' => 'warning', 'key' => 'cluster_url_unreachable', 'vars' => ['{URLS}' => (string) $rNode['unreachable_urls']], 'help' => 'cluster_url_unreachable_help'];
		}
		return $rOut;
	}

	/**
	 * The Settings Info tab's cluster rows (plan, section 11, "Info tab"), in
	 * the shape of the tab's versions table: [label, value, badge class,
	 * colour]. Null without an extension the panel takes.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return list<array{0: string, 1: string, 2: string, 3: string}>|null
	 */
	public static function infoRows(array $rSettings): ?array {
		$rStatus = ClusterCryptoFactory::status();
		$rInfo = ClusterCryptoFactory::info();
		if (!$rStatus['available'] || $rInfo === null) {
			return null;
		}
		$rTone = static fn(bool $rOk): string => $rOk ? 'bg-success' : 'bg-danger';
		$rNodes = [];
		if (self::db()->query('SELECT `state`, COUNT(*) AS `n` FROM `cluster_nodes` GROUP BY `state` ORDER BY `state`;')) {
			foreach (self::db()->get_rows() ?: [] as $rRow) {
				$rNodes[] = $rRow['n'] . ' ' . $rRow['state'];
			}
		}
		// A node refreshes at its newest epoch's refresh_at; older epochs only expire.
		$rNext = null;
		if (self::db()->query("SELECT MIN(e.`refresh_at`) AS `at` FROM `cluster_node_epochs` e JOIN `cluster_nodes` n ON n.`server_id` = e.`server_id` AND n.`epoch` = e.`epoch` WHERE n.`state` = 'active';")) {
			$rNext = self::db()->get_row()['at'] ?? null;
		}
		$rRotated = ClusterMeta::get(StreamSecretRotation::DONE_META);
		$rSecret = (string) ($rSettings['live_streaming_pass'] ?? '');
		$rOn = !empty($rSettings['cluster_api_enabled']);
		return [
			['Cluster API', $rOn ? 'On' : 'Off', $rOn ? 'bg-success' : 'bg-secondary', ''],
			['Licence gate', !empty($rInfo['licensed']) ? 'Open' : 'Closed: no token is issued', $rTone(!empty($rInfo['licensed'])), ''],
			['Licence kid', (string) ($rInfo['kid'] ?? '') !== '' ? (string) $rInfo['kid'] : '—', 'bg-secondary', ''],
			['Panel key', !empty($rInfo['initialised']) ? bin2hex((string) ($rInfo['panel_fp'] ?? '')) : 'Not initialised: ' . (string) ($rInfo['root_error'] ?? 'unknown'), $rTone(!empty($rInfo['initialised'])), ''],
			['Nodes', $rNodes !== [] ? implode(', ', $rNodes) : 'None', 'bg-info', ''],
			['Next token refresh', $rNext !== null ? gmdate('Y-m-d H:i', (int) $rNext) . ' UTC' : '—', 'bg-secondary', ''],
			['Extension', $rStatus['ext_version'] . ' (API ' . $rStatus['api'] . '; the panel takes ' . $rStatus['range'] . ')', 'bg-secondary', ''],
			['Extension clock', !empty($rInfo['clock_ok']) ? 'OK' : 'Rolled back: cluster calls are refused', $rTone(!empty($rInfo['clock_ok'])), ''],
			['Stream secret', ($rSecret !== '' ? 'kid ' . ReplicaSections::kid('live_streaming_pass', $rSecret) : 'none') . (StreamSecretRotation::inProgress() !== null ? ', rotating now' : ($rRotated !== null ? ', rotated ' . gmdate('Y-m-d H:i', (int) $rRotated) . ' UTC' : ', never rotated')), 'bg-secondary', ''],
		];
	}

	/**
	 * Does the extension still sign granting records (tokens, leases)? What a
	 * node's challenge reports as `licence_ok`, read as the liveness loop reads
	 * it (LivenessService::licensed()). True when $rOn is false (the API is off
	 * or has no extension), or when it cannot tell: no banner rather than a
	 * false alarm.
	 */
	public static function licensed(bool $rOn): bool {
		return !$rOn || LivenessService::licensed(ClusterClock::nowMs()) !== false;
	}

	/**
	 * banners() for the dashboard (plan, section 11, "Dashboard banner"), from
	 * the two columns they read, so the dashboard pays for no node walk.
	 * Nothing while the cluster API is off, or before its table exists.
	 *
	 * @param array<string, mixed> $rMain MAIN's `servers` row.
	 * @param array<string, mixed> $rSettings
	 * @return list<array{type: string, key: string, vars: array<string, string>}>
	 */
	public static function dashboardBanners(array $rMain, array $rSettings, int $rNow): array {
		$rOn = !empty($rSettings['cluster_api_enabled']) && ClusterCryptoFactory::available();
		if (!$rOn || !self::db()->query('SELECT `state`, `token_exp` FROM `cluster_nodes`;')) {
			return [];
		}
		$rNodes = self::db()->get_rows() ?: [];
		return self::banners(self::licensed($rOn), $rMain, $rSettings, $rNodes, $rNow);
	}

	/**
	 * The page's banners. Pure.
	 *
	 * - Licence suspended: MAIN signs no lease (or any granting record), so each
	 *   node serves on the lease it holds. With `lb_lease_fence` on, the last
	 *   active node's window says when the fleet stops at the latest; with it
	 *   off, nothing stops, and the banner says the fence is off.
	 * - MAIN's certificate expiring within CERT_WARN_DAYS while the nodes may
	 *   dial HTTPS (`cluster_transport` not `http`, MAIN's HTTPS on). Under
	 *   `https_required` an expired certificate cuts the fleet off MAIN, so it
	 *   is a danger there and a warning otherwise (the nodes fall back to HTTP).
	 *
	 * @param array<string, mixed> $rMain MAIN's `servers` row.
	 * @param array<string, mixed> $rSettings
	 * @param list<array<string, mixed>> $rNodes ClusterAdmin::nodes()
	 * @return list<array{type: string, key: string, vars: array<string, string>}>
	 */
	public static function banners(bool $rLicensed, array $rMain, array $rSettings, array $rNodes, int $rNow): array {
		if (empty($rSettings['cluster_api_enabled'])) {
			return [];
		}
		$rOut = [];
		if (!$rLicensed) {
			$rStop = null;
			$rFenceOn = false;
			foreach ($rNodes as $rNode) {
				if (($rNode['state'] ?? '') !== 'active') {
					continue;
				}
				$rWindow = ClusterDiagnosis::fenceWindow(isset($rNode['token_exp']) ? (int) $rNode['token_exp'] : null, $rSettings);
				$rFenceOn = $rWindow['fence_on'];
				if ($rWindow['drain_until'] !== null) {
					$rStop = max($rStop ?? 0, $rWindow['drain_until']);
				}
			}
			if ($rStop !== null && $rFenceOn) {
				$rOut[] = ['type' => 'danger', 'key' => 'cluster_banner_licence_stop', 'vars' => ['{TIME}' => gmdate('Y-m-d H:i', $rStop) . ' UTC']];
			} elseif ($rStop !== null) {
				$rOut[] = ['type' => 'danger', 'key' => 'cluster_banner_licence_no_fence', 'vars' => []];
			} else {
				$rOut[] = ['type' => 'danger', 'key' => 'cluster_banner_licence', 'vars' => []];
			}
		}
		$rTransport = ClusterSettings::enum('cluster_transport', $rSettings['cluster_transport'] ?? null);
		$rCert = json_decode((string) ($rMain['certbot_ssl'] ?? ''), true);
		$rExpires = is_array($rCert) ? (int) ($rCert['expiration'] ?? 0) : 0;
		if ($rTransport !== 'http' && !empty($rMain['enable_https']) && $rExpires > 0 && $rExpires - $rNow < self::CERT_WARN_DAYS * 86400) {
			$rOut[] = [
				'type' => $rTransport === 'https_required' || $rExpires <= $rNow ? 'danger' : 'warning',
				'key' => $rExpires <= $rNow ? 'cluster_banner_cert_expired' : 'cluster_banner_cert_expiring',
				'vars' => ['{DATE}' => gmdate('Y-m-d H:i', $rExpires) . ' UTC', '{DAYS}' => (string) max(0, intdiv($rExpires - $rNow, 86400)), '{TRANSPORT}' => $rTransport],
			];
		}
		return $rOut;
	}

	/**
	 * The command channel's figures, from `cluster_commands`: the queue (commands
	 * not yet acked and not expired) per node and in all, its oldest entry, and
	 * the delivery and ack latency percentiles over the commands acked within
	 * LATENCY_WINDOW_SEC. The table keeps whole seconds, so the percentiles are
	 * too.
	 *
	 * @return array{depth: int, oldest: ?int, per_node: array<int, int>, samples: int, deliver_p50: ?int, deliver_p99: ?int, ack_p50: ?int, ack_p99: ?int}
	 */
	public static function commandMetrics(int $rNow): array {
		$rOut = ['depth' => 0, 'oldest' => null, 'per_node' => [], 'samples' => 0, 'deliver_p50' => null, 'deliver_p99' => null, 'ack_p50' => null, 'ack_p99' => null];
		$rDb = self::db();
		if ($rDb->query("SELECT `server_id`, COUNT(*) AS `c`, MIN(`created_at`) AS `oldest` FROM `cluster_commands` WHERE `state` IN ('queued', 'delivered') AND `exp` > ? GROUP BY `server_id`;", $rNow)) {
			foreach ($rDb->get_rows() ?: [] as $rRow) {
				$rOut['per_node'][(int) $rRow['server_id']] = (int) $rRow['c'];
				$rOut['depth'] += (int) $rRow['c'];
				$rOut['oldest'] = $rOut['oldest'] === null ? (int) $rRow['oldest'] : min($rOut['oldest'], (int) $rRow['oldest']);
			}
		}
		if ($rDb->query('SELECT `created_at`, `delivered_at`, `acked_at` FROM `cluster_commands` WHERE `acked_at` IS NOT NULL AND `created_at` >= ? ORDER BY `id` DESC LIMIT ' . self::LATENCY_SAMPLES . ';', $rNow - self::LATENCY_WINDOW_SEC)) {
			$rDeliver = [];
			$rAck = [];
			foreach ($rDb->get_rows() ?: [] as $rRow) {
				$rCreated = (int) $rRow['created_at'];
				$rAck[] = max(0, (int) $rRow['acked_at'] - $rCreated);
				// An ack with no delivery recorded: the ack is the latest it was delivered.
				$rDeliver[] = max(0, (int) ($rRow['delivered_at'] ?? $rRow['acked_at']) - $rCreated);
			}
			$rOut['samples'] = count($rAck);
			$rOut['deliver_p50'] = self::percentile($rDeliver, 50);
			$rOut['deliver_p99'] = self::percentile($rDeliver, 99);
			$rOut['ack_p50'] = self::percentile($rAck, 50);
			$rOut['ack_p99'] = self::percentile($rAck, 99);
		}
		return $rOut;
	}

	/**
	 * The nearest-rank percentile of $rValues; null for none.
	 *
	 * @param list<int> $rValues
	 */
	public static function percentile(array $rValues, int $rP): ?int {
		if ($rValues === []) {
			return null;
		}
		sort($rValues);
		$rRank = (int) ceil($rP / 100 * count($rValues));
		return $rValues[max(0, min(count($rValues) - 1, $rRank - 1))];
	}

	/**
	 * MAIN's own saturation: the ingest permits held per lane against what
	 * each may hold (null without the bus, where nothing is limited), and how
	 * long the cluster_ctl pool's listen queue has lasted, as the liveness loop
	 * last saw it (null when there is none).
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array{ingest: array{p0: int, bulk: int, permits: array{p0: int, bulk: int, total: int}}|null, ctl_queue_ms: ?int}
	 */
	public static function saturation(array $rSettings, int $rNowMs): array {
		$rQueue = ClusterHealth::read()['ctl_queue'];
		$rCtl = $rQueue !== null && $rNowMs - $rQueue['at'] <= self::CTL_QUEUE_FRESH_MS ? max(0, $rQueue['at'] - $rQueue['since']) : null;
		return ['ingest' => ClusterSemaphore::ingestInUse($rSettings['cluster_ingest_concurrency'] ?? null), 'ctl_queue_ms' => $rCtl];
	}

	/**
	 * The latest cluster_audit rows, newest first.
	 *
	 * @return list<array{time: int, server_id: ?int, actor: ?string, event: string, detail: string}>
	 */
	public static function audit(int $rLimit = self::AUDIT_ROWS): array {
		$rDb = self::db();
		if (!$rDb->query('SELECT `time`, `server_id`, `actor`, `event`, `detail` FROM `cluster_audit` ORDER BY `id` DESC LIMIT ' . max(1, min(200, $rLimit)) . ';')) {
			return [];
		}
		$rOut = [];
		foreach ($rDb->get_rows() ?: [] as $rRow) {
			$rOut[] = [
				'time' => (int) $rRow['time'],
				'server_id' => $rRow['server_id'] === null ? null : (int) $rRow['server_id'],
				'actor' => $rRow['actor'] === null ? null : (string) $rRow['actor'],
				'event' => (string) $rRow['event'],
				'detail' => (string) ($rRow['detail'] ?? ''),
			];
		}
		return $rOut;
	}

	/**
	 * "Rotate all tokens now": `token.rotate_now` to every active node, as the
	 * per-node button sends it (ClusterRoute::rotateNow(), deduplicated by its
	 * key, so a second click replaces a rotation still waiting rather than
	 * adding one), each audited.
	 *
	 * @return array{queued: int, no_commands: int, failed: int}
	 */
	public static function rotateAll(?int $rUserID): array {
		$rOut = ['queued' => 0, 'no_commands' => 0, 'failed' => 0];
		$rDb = self::db();
		if (!$rDb->query("SELECT `server_id` FROM `cluster_nodes` WHERE `state` = 'active' ORDER BY `server_id`;")) {
			return $rOut;
		}
		$rActor = $rUserID === null ? 'admin' : 'admin:' . $rUserID;
		foreach (array_map(static fn(array $rRow): int => (int) $rRow['server_id'], $rDb->get_rows() ?: []) as $rServerID) {
			[$rRouted, $rQueued] = ClusterRoute::rotateNow($rServerID);
			if (!$rRouted) {
				$rOut['no_commands']++;
				continue;
			}
			ClusterAudit::log('node.token_rotate', $rServerID, ['queued' => $rQueued, 'all' => true], $rActor);
			$rOut[$rQueued ? 'queued' : 'failed']++;
		}
		return $rOut;
	}
}
