<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What the admin's Cluster Nodes page shows and does: the node list with
 * liveness, pending code enrolments, and the actions (issue a code, approve
 * by SAS, reject, revoke). Kept out of the controller so it is tested without
 * a web request; the CLI commands are the same operations.
 */
final class ClusterAdmin {
	use DatabaseAware;

	/** The flows the page switches, by action name. */
	public const FLOW_BITS = [
		'telemetry' => NodeRegistry::FLOW_TELEMETRY,
		'commands' => NodeRegistry::FLOW_COMMANDS,
		'logs' => NodeRegistry::FLOW_LOGS,
		'streams' => NodeRegistry::FLOW_STREAMS,
		'content' => NodeRegistry::FLOW_CONTENT,
		'config' => NodeRegistry::FLOW_CONFIG,
		'connections' => NodeRegistry::FLOW_CONNECTIONS,
		'dataplane' => NodeRegistry::FLOW_DATAPLANE,
	];

	/**
	 * What an agent says in hello's `features` once it runs the loopback relay
	 * proxy (XC_VM_Fanout, relayproxy.go). DATAPLANE is switched on only for
	 * such an agent: the flow points the node's encoders at 127.0.0.1:31290,
	 * and with nothing of the agent's there every relay and file read would
	 * fail until it was switched off again.
	 */
	public const FEATURE_RELAY = 'relay';

	/**
	 * Does this node's agent run the relay proxy (its `features` at hello)?
	 *
	 * @param array<string, mixed> $rNode a `cluster_nodes` row
	 */
	public static function relayAdvertised(array $rNode): bool {
		return in_array(self::FEATURE_RELAY, explode(',', (string) ($rNode['features'] ?? '')), true);
	}

	/**
	 * Flows a node must have before it can run without MAIN's database: every
	 * one, the data plane included (Phase 8): a node in mode 2 pulls its relays
	 * and files through its agent, with no stream secret in a URL.
	 */
	public const MODE2_FLOWS = NodeRegistry::FLOW_TELEMETRY | NodeRegistry::FLOW_COMMANDS | NodeRegistry::FLOW_LOGS
		| NodeRegistry::FLOW_STREAMS | NodeRegistry::FLOW_CONTENT | NodeRegistry::FLOW_CONFIG | NodeRegistry::FLOW_CONNECTIONS
		| NodeRegistry::FLOW_DATAPLANE;

	/**
	 * Days a node spends in mode 2 before it may give up MAIN's credentials
	 * (DbCredentials::strip): the step that cannot be undone. They stood
	 * before the move to mode 2 (plan, section 11), as days of no connect to
	 * MAIN, which a node in mode 1 never shows (modeGate()).
	 */
	public const CUTOVER_CLEAN_DAYS = 7;

	/** cluster_meta: when a node entered mode 2, by MAIN's clock (`mode2_at.<server id>`); gone when it leaves. */
	public const MODE2_AT = 'mode2_at.';

	/**
	 * cluster_meta: the node's `gen` when the page moved it to mode 2
	 * (`mode2_gen.<server id>`). A node of that gen still holds the
	 * credentials it had in mode 1; a re-enrolment since (a reinstall in mode
	 * 2) gave it none, which MAIN cannot tell otherwise.
	 */
	public const MODE2_GEN = 'mode2_gen.';

	/** cluster_meta: when MAIN first heard a node in mode 2 say it no longer reads its streams on itself (autoModeDown()). */
	public const STREAMS_LOST_AT = 'streams_lost_at.';

	/**
	 * May this node move to $rMode? Pure, so the gate is tested without a request.
	 *
	 * Going down is always allowed: it is the way back when a node misbehaves.
	 * Going up to 1 needs the config replica, because that is what a node boots
	 * from. Going up to 2 stops the node reaching MAIN's database at all, so it
	 * needs every flow, the data plane included, root's pin in place (`root_ready`:
	 * root actions then reach it only as node.root commands), and a node that
	 * can run that way now: active (a quarantined node takes no command), heard
	 * by MAIN a moment ago, and saying by itself that it boots and reads its
	 * streams from its own copy ($rStreamsLocal). In mode 2 it can neither seed
	 * that store nor read them from MAIN.
	 *
	 * The node's connect audit is not asked. A node in mode 1 reads MAIN's
	 * database by design (its crons, its signals daemon, viewer authentication),
	 * so the zero this gate used to wait seven days for could only come in mode
	 * 2, and no node could be moved. The move takes nothing from the node: its
	 * credentials and MAIN's grant stay, so mode_down undoes it. The days are
	 * asked where there is no way back (DbCredentials::strip, CUTOVER_CLEAN_DAYS).
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row.
	 * @param bool $rHeard Was the node heard within NodeHealth::SUSPECT_AFTER_MS (act())?
	 * @param bool|null $rStreamsLocal NodeAudit::streamsLocal() of its last report; null when it does not say.
	 * @return array{0: bool, 1: string} [allowed, message key]
	 */
	public static function modeGate(array $rNode, int $rMode, bool $rHeard = false, ?bool $rStreamsLocal = null): array {
		if ($rMode < 0 || $rMode > 2) {
			return [false, 'cluster_mode_unknown'];
		}
		if ($rMode <= (int) $rNode['mode']) {
			return [true, 'cluster_mode_done'];
		}
		if (((int) $rNode['flows'] & NodeRegistry::FLOW_CONFIG) !== NodeRegistry::FLOW_CONFIG) {
			return [false, 'cluster_mode_needs_config'];
		}
		if ($rMode < 2) {
			return [true, 'cluster_mode_done'];
		}
		if (((int) $rNode['flows'] & self::MODE2_FLOWS) !== self::MODE2_FLOWS) {
			return [false, 'cluster_mode_needs_flows'];
		}
		if (empty($rNode['root_ready'])) {
			// ADR 0004, Mode 2: without root's pin no root action reaches a node that no longer polls MAIN's signals table.
			return [false, 'cluster_mode_needs_root'];
		}
		if (($rNode['state'] ?? null) !== 'active') {
			return [false, 'cluster_mode_needs_active'];
		}
		if (!$rHeard) {
			// Its last report is only as old as its last heartbeat.
			return [false, 'cluster_mode_not_heard'];
		}
		if ($rStreamsLocal !== true) {
			return [false, 'cluster_mode_needs_streams'];
		}
		return [true, 'cluster_mode_done'];
	}

	/**
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll(true)
	 * @return list<array<string, mixed>> One row per enrolled node, with `server_name`, `health`,
	 *                                    `settings_misses`, `connects` and `streams_local` (NodeAudit; null when not reported),
	 *                                    `relay` (its agent runs the relay proxy), `relay_down_since` and
	 *                                    `relay_error` (NodeRelay; null and '' while it holds its port),
	 *                                    `core_pinned` (CorePins) and `db_revoked_at` (null: never).
	 */
	public static function nodes(array $rServers, int $rOfflineAfterSec): array {
		$rReady = ClusterMeta::readyAtMs(); // its own query: before ours, not between query() and get_rows()
		$rReports = NodeAudit::reports(); // likewise
		$rHeard = HeartbeatService::lastSeen(); // MySQL's copy may be a flush behind
		$rNow = ClusterClock::nowMs();
		try {
			$rPanelFp = CorePins::panelFp();
		} catch (\Throwable) {
			$rPanelFp = null;
		}
		// Every column: `db_revoked_at` arrived with migration 052, the relay's with 054.
		self::db()->query('SELECT * FROM `cluster_nodes` ORDER BY `server_id`;');
		$rRows = self::db()->get_rows();
		$rOut = [];
		foreach ($rRows as $rRow) {
			// The keys and MACs are no business of the page (nor of JSON).
			unset($rRow['node_sign_pub'], $rRow['node_box_pub'], $rRow['attest'], $rRow['row_mac']);
			$rRow['db_revoked_at'] = isset($rRow['db_revoked_at']) ? (int) $rRow['db_revoked_at'] : null;
			$rRow['relay_down_since'] = isset($rRow['relay_down_since']) ? (int) $rRow['relay_down_since'] : null;
			$rRow['relay_error'] = (string) ($rRow['relay_error'] ?? '');
			try {
				$rRow['core_pinned'] = CorePins::current($rRow, $rPanelFp);
			} catch (\Throwable) {
				$rRow['core_pinned'] = false;
			}
			$rLastSeen = HeartbeatService::freshest($rRow['last_seen_at'], $rHeard[(int) $rRow['server_id']] ?? null);
			$rRow['last_seen_at'] = $rLastSeen;
			$rRow['server_name'] = (string) ($rServers[(int) $rRow['server_id']]['server_name'] ?? ('#' . $rRow['server_id']));
			$rRow['health'] = $rRow['state'] === 'active' ? NodeHealth::state($rLastSeen, $rReady, $rNow, $rOfflineAfterSec) : (string) $rRow['state'];
			$rRow['settings_misses'] = $rReports[(int) $rRow['server_id']]['settings_misses'] ?? null;
			$rRow['connects'] = NodeAudit::connectsOf($rReports[(int) $rRow['server_id']] ?? null);
			$rRow['streams_local'] = NodeAudit::streamsLocal($rReports[(int) $rRow['server_id']] ?? null);
			$rRow['relay'] = self::relayAdvertised($rRow);
			$rOut[] = $rRow;
		}
		return $rOut;
	}

	/** @return list<array<string, mixed>> Code enrolments waiting for the admin. */
	public static function pending(array $rServers): array {
		self::db()->query("SELECT `server_id`, `node_uuid`, `attest`, `created_at` FROM `cluster_enrol_requests` WHERE `state` = 'pending_approval' ORDER BY `created_at`;");
		$rOut = [];
		foreach (self::db()->get_rows() as $rRow) {
			$rRow['server_name'] = (string) ($rServers[(int) $rRow['server_id']]['server_name'] ?? ('#' . $rRow['server_id']));
			$rOut[] = $rRow;
		}
		return $rOut;
	}

	/**
	 * Is this `servers` row a load balancer (not MAIN, not a proxy): what a
	 * node can be enrolled, re-enrolled or issued a code for, from this page
	 * or the CLI (cluster:enrol-code, cluster:reenrol, server:enrol).
	 *
	 * @param array<string, mixed>|null $rServer
	 */
	public static function isLoadBalancer(?array $rServer): bool {
		return $rServer !== null && empty($rServer['is_main']) && intval($rServer['server_type'] ?? 0) === 0;
	}

	/**
	 * Load balancers a code may be issued for.
	 *
	 * @return array<int, string> server id => name
	 */
	public static function loadBalancers(array $rServers): array {
		$rOut = [];
		foreach ($rServers as $rID => $rServer) {
			if (self::isLoadBalancer($rServer)) {
				$rOut[(int) $rID] = (string) ($rServer['server_name'] ?? ('#' . $rID));
			}
		}
		return $rOut;
	}

	/**
	 * MAIN's data-plane client on or off from the page: the
	 * `cluster:main-dataplane` the CLI runs, as the panel's own user (xc_vm),
	 * so the key, the files and the audit are the command's. A refusal is
	 * the command's own reason (no agent binary yet, no panel key, ...).
	 *
	 * @return array{type: string, message: string, vars?: array<string, string>}
	 */
	private static function mainDataPlane(bool $rOn, ?int $rUserID): array {
		[$rCode, $rOut] = ProcessRunner::capture([PHP_BIN, MAIN_HOME . 'console.php', 'cluster:main-dataplane', $rOn ? 'on' : 'off']);
		ClusterAudit::log('cluster.main_dataplane_asked', null, ['on' => $rOn, 'exit' => $rCode], $rUserID === null ? 'admin' : 'admin:' . $rUserID);
		if ($rCode === 0) {
			return ['type' => 'success', 'message' => $rOn ? 'cluster_main_dataplane_on_done' : 'cluster_main_dataplane_off_done'];
		}
		$rWhy = preg_match('/^Refused: (.+)$/m', $rOut, $rMatch) === 1 ? $rMatch[1] : trim($rOut);
		return ['type' => 'warning', 'message' => 'cluster_main_dataplane_refused', 'vars' => ['{WHY}' => mb_strimwidth($rWhy !== '' ? $rWhy : 'exit ' . $rCode, 0, 300, '…')]];
	}

	/**
	 * Move a node to $rWanted, as the page's mode buttons do, with what the
	 * step needs around it.
	 *
	 * @param array<string, mixed> $rNode Its cluster_nodes row.
	 * @param array<string, mixed> $rDetail More for the `node.mode` audit entry.
	 */
	private static function moveMode(array $rNode, int $rWanted, string $rActor, array $rDetail = []): void {
		$rServerID = (int) $rNode['server_id'];
		NodeRegistry::update($rServerID, ['mode' => $rWanted]);
		if ($rWanted === 2) {
			ClusterMeta::set(self::MODE2_AT . $rServerID, (string) ClusterClock::now());
			ClusterMeta::set(self::MODE2_GEN . $rServerID, (string) (int) $rNode['gen']);
		} elseif ((int) $rNode['mode'] === 2) {
			ClusterMeta::delete(self::MODE2_AT . $rServerID);
			ClusterMeta::delete(self::MODE2_GEN . $rServerID);
			ClusterMeta::delete(self::STREAMS_LOST_AT . $rServerID);
			// Below mode 2 the node needs its credentials: a strip it was not handed yet no longer goes out.
			DbCredentials::cancelStrip($rServerID);
		}
		// What it said of its streams, it said in the mode it leaves: it says it again.
		NodeAudit::forgetStreamsLocal($rServerID);
		ClusterAudit::log('node.mode', $rServerID, ['mode' => $rWanted, 'was' => (int) $rNode['mode']] + $rDetail, $rActor);
	}

	/**
	 * A node in mode 2 that has said for `cluster_auto_mode_down_min` minutes
	 * that it no longer reads its streams on itself goes back to mode 1, as
	 * the page's Mode down would take it (cron:cluster, every minute; 0, the
	 * default, is off). Only a node that can run there: active, heard within
	 * NodeHealth::SUSPECT_AFTER_MS (the move reaches it), moved to mode 2 by
	 * the page at the gen it has now (MODE2_GEN: it holds the credentials it
	 * had in mode 1), its grant not revoked, and MAIN not locked down.
	 *
	 * @param array<string, mixed> $rSettings
	 * @return list<int> The nodes moved down.
	 */
	public static function autoModeDown(array $rSettings): array {
		$rMinutes = ClusterSettings::int('cluster_auto_mode_down_min', $rSettings['cluster_auto_mode_down_min'] ?? null);
		$rReports = NodeAudit::reports();
		$rNowMs = ClusterClock::nowMs();
		$rMoved = [];
		foreach (NodeRegistry::enrolled() as $rServerID => $rNode) {
			$rSince = ClusterMeta::get(self::STREAMS_LOST_AT . $rServerID);
			if ($rMinutes <= 0 || (int) $rNode['mode'] !== 2 || NodeAudit::streamsLocal($rReports[$rServerID] ?? null) !== false) {
				if ($rSince !== null) {
					ClusterMeta::delete(self::STREAMS_LOST_AT . $rServerID);
				}
				continue;
			}
			if ($rSince === null) {
				ClusterMeta::set(self::STREAMS_LOST_AT . $rServerID, (string) intdiv($rNowMs, 1000));
				continue;
			}
			$rLastSeen = HeartbeatService::freshest($rNode['last_seen_at'] ?? null, HeartbeatService::lastSeen()[$rServerID] ?? null);
			if (intdiv($rNowMs, 1000) - (int) $rSince < $rMinutes * 60
				|| $rNode['state'] !== 'active'
				|| $rLastSeen === null || $rNowMs - $rLastSeen > NodeHealth::SUSPECT_AFTER_MS
				|| ClusterMeta::get(self::MODE2_GEN . $rServerID) !== (string) (int) $rNode['gen']
				|| DbCredentials::revokedAt($rServerID) !== null
				|| DbAllowlist::lockedDown()
			) {
				continue;
			}
			self::moveMode($rNode, 1, 'auto', ['streams_lost_at' => (int) $rSince]);
			$rMoved[] = $rServerID;
		}
		return $rMoved;
	}

	/**
	 * Perform one action from the page.
	 *
	 * @param array<string, mixed> $rInput cluster_action, server_id, sas, url
	 * @param array<int, array<string, mixed>> $rServers
	 * @param array<string, mixed> $rSettings
	 * @return array{type: string, message: string, code?: string, server_id?: int, vars?: array<string, string>}
	 */
	public static function act(ClusterCrypto $rCrypto, array $rInput, array $rServers, int $rMainID, array $rSettings, ?int $rUserID): array {
		$rAction = (string) ($rInput['cluster_action'] ?? '');
		if ($rAction === 'rotate_all') {
			// Fleet-wide, so no server id: every active node, as rotate_now one.
			$rDone = ClusterOverview::rotateAll($rUserID);
			return [
				'type' => $rDone['failed'] > 0 ? 'warning' : ($rDone['queued'] > 0 ? 'success' : 'info'),
				'message' => 'cluster_rotate_all_done',
				'vars' => ['{QUEUED}' => (string) $rDone['queued'], '{SKIPPED}' => (string) $rDone['no_commands'], '{FAILED}' => (string) $rDone['failed']],
			];
		}
		if ($rAction === 'main_dataplane_on' || $rAction === 'main_dataplane_off') {
			return self::mainDataPlane($rAction === 'main_dataplane_on', $rUserID);
		}
		$rServerID = (int) ($rInput['server_id'] ?? 0);
		$rMain = $rServers[$rMainID] ?? [];
		$rLbs = self::loadBalancers($rServers);
		if (!isset($rLbs[$rServerID])) {
			return ['type' => 'danger', 'message' => 'cluster_not_a_load_balancer'];
		}
		try {
			switch ($rAction) {
				case 'code':
					$rUrl = trim((string) ($rInput['url'] ?? ''));
					if ($rUrl === '') {
						$rUrl = (string) preg_replace('#/cluster/v1/$#', '', ClusterPolicy::current($rSettings, $rMain)['main_urls'][0] ?? '');
					}
					try {
						$rOut = EnrolCodeService::generate($rCrypto, $rServerID, rtrim($rUrl, '/'), $rUserID);
					} catch (\InvalidArgumentException) {
						return ['type' => 'danger', 'message' => 'cluster_bad_main_url'];
					}
					return ['type' => 'success', 'message' => 'cluster_code_issued', 'code' => $rOut['code'], 'server_id' => $rServerID];

				case 'approve':
					return match (EnrolCodeService::approve($rCrypto, $rServerID, (string) ($rInput['sas'] ?? ''), $rSettings, $rMain, $rUserID)) {
						'approved' => ['type' => 'success', 'message' => 'cluster_enrol_approved'],
						'wrong_sas' => ['type' => 'warning', 'message' => 'cluster_wrong_sas'],
						'rejected' => ['type' => 'danger', 'message' => 'cluster_enrol_rejected_attempts'],
						default => ['type' => 'info', 'message' => 'cluster_nothing_pending'],
					};

				case 'reject':
					return EnrolCodeService::reject($rServerID, $rUserID)
						? ['type' => 'warning', 'message' => 'cluster_enrol_rejected']
						: ['type' => 'info', 'message' => 'cluster_nothing_pending'];

				case 'cutover_start':
					// The guided cutover (ClusterCutover): every flow, watched, up to mode 1.
					$rNode = NodeRegistry::byServer($rServerID);
					if ($rNode === null || $rNode['state'] !== 'active') {
						return ['type' => 'info', 'message' => 'cluster_not_enrolled'];
					}
					if ((int) $rNode['mode'] === 2) {
						return ['type' => 'info', 'message' => 'cluster_cutover_mode_two'];
					}
					if (!ClusterCutover::start($rServerID, $rUserID)) {
						return ['type' => 'warning', 'message' => 'cluster_cutover_busy'];
					}
					ClusterAudit::log('node.cutover', $rServerID, ['flows' => (int) $rNode['flows']], $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return ['type' => 'success', 'message' => 'cluster_cutover_started'];

				case 'cutover_cancel':
					ClusterCutover::cancel($rServerID);
					ClusterAudit::log('node.cutover_cancel', $rServerID, [], $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return ['type' => 'info', 'message' => 'cluster_cutover_cancel_done'];

				case 'telemetry_on':
				case 'telemetry_off':
				case 'commands_on':
				case 'commands_off':
				case 'logs_on':
				case 'logs_off':
				case 'streams_on':
				case 'streams_off':
				case 'content_on':
				case 'content_off':
				case 'config_on':
				case 'config_off':
				case 'connections_on':
				case 'connections_off':
				case 'dataplane_on':
				case 'dataplane_off':
					$rNode = NodeRegistry::byServer($rServerID);
					if ($rNode === null || !in_array($rNode['state'], ['active', 'quarantined'], true)) {
						return ['type' => 'info', 'message' => 'cluster_not_enrolled'];
					}
					[$rName, $rSwitch] = explode('_', $rAction);
					if ($rSwitch === 'off' && (int) $rNode['mode'] === 2) {
						// Mode 2 runs on every flow (modeGate()): without one the node has
						// only MAIN's database for that work, which it may not reach.
						return ['type' => 'warning', 'message' => 'cluster_flow_mode_two'];
					}
					if ($rAction === 'dataplane_on' && !self::relayAdvertised($rNode)) {
						return ['type' => 'warning', 'message' => 'cluster_dataplane_needs_relay'];
					}
					$rBit = self::FLOW_BITS[$rName];
					$rFlows = $rSwitch === 'on' ? ((int) $rNode['flows'] | $rBit) : ((int) $rNode['flows'] & ~$rBit);
					if (!NodeRegistry::validFlows($rFlows)) {
						// CONNECTIONS needs COMMANDS and STREAMS; DATAPLANE needs STREAMS and CONTENT.
						return ['type' => 'warning', 'message' => 'cluster_flow_needs'];
					}
					NodeRegistry::update($rServerID, ['flows' => $rFlows]);
					ClusterAudit::log('node.flows', $rServerID, ['flows' => $rFlows, 'was' => (int) $rNode['flows']], $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return ['type' => 'success', 'message' => 'cluster_' . $rName . '_' . $rSwitch . '_done'];

				case 'mode_up':
				case 'mode_down':
					$rNode = NodeRegistry::byServer($rServerID);
					if ($rNode === null || !in_array($rNode['state'], ['active', 'quarantined'], true)) {
						return ['type' => 'info', 'message' => 'cluster_not_enrolled'];
					}
					$rWanted = (int) $rNode['mode'] + ($rAction === 'mode_up' ? 1 : -1);
					if ($rWanted < 0 || $rWanted > 2) {
						return ['type' => 'info', 'message' => 'cluster_mode_unknown'];
					}
					// The step is relative, so the form says which mode it was drawn for:
					// a page reloaded after a move (the browser posts it again), or left
					// open while someone else moved the node, moves nothing. The step to
					// mode 2 is never taken without it: only the form that asks first sends it.
					$rShown = $rInput['mode'] ?? null;
					if ($rShown !== null && $rShown !== '' ? (int) $rShown !== (int) $rNode['mode'] : $rWanted === 2) {
						return ['type' => 'info', 'message' => 'cluster_mode_moved'];
					}
					// As nodes() reads it: MySQL's copy may be a flush behind the bus's.
					$rLastSeen = HeartbeatService::freshest($rNode['last_seen_at'] ?? null, HeartbeatService::lastSeen()[$rServerID] ?? null);
					$rHeard = $rLastSeen !== null && ClusterClock::nowMs() - $rLastSeen <= NodeHealth::SUSPECT_AFTER_MS;
					[$rAllowed, $rWhy] = self::modeGate($rNode, $rWanted, $rHeard, NodeAudit::streamsLocal(NodeAudit::reports()[$rServerID] ?? null));
					if (!$rAllowed) {
						return ['type' => 'warning', 'message' => $rWhy];
					}
					self::moveMode($rNode, $rWanted, $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return ['type' => 'success', 'message' => 'cluster_mode_done'];

				case 'rotate_now':
					// The token is the agent's, so this is a command, not a row:
					// the node rotates at its next poll without being stopped.
					[$rRouted, $rQueued] = ClusterRoute::rotateNow($rServerID);
					if (!$rRouted) {
						return ['type' => 'info', 'message' => 'cluster_rotate_no_commands'];
					}
					ClusterAudit::log('node.token_rotate', $rServerID, ['queued' => $rQueued], $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return $rQueued
						? ['type' => 'success', 'message' => 'cluster_rotate_done']
						: ['type' => 'danger', 'message' => 'cluster_rotate_failed'];

				case 'fence':
				case 'unfence':
				case 'quarantine':
				case 'resync':
					// Commands for the node's agent (ADR 0004, Phase 9): a fence,
					// its lifting, a quarantine and a resync all go to a node that
					// takes commands, and none of them stops it.
					$rActor = $rUserID === null ? 'admin' : 'admin:' . $rUserID;
					$rEnded = [];
					[$rRouted, $rQueued] = match ($rAction) {
						'fence' => ClusterRoute::fence($rServerID, 'admin', ClusterSettings::int('lb_fence_drain_min', $rSettings['lb_fence_drain_min'] ?? null)),
						'unfence' => ClusterRoute::unfence($rServerID),
						'quarantine' => ClusterRoute::quarantine($rServerID, 'admin', $rEnded),
						default => ClusterRoute::resync($rServerID),
					};
					if (!$rRouted) {
						return ['type' => 'info', 'message' => 'cluster_rotate_no_commands'];
					}
					// A quarantine names the granting commands it ended; the operator sends again what is still wanted.
					ClusterAudit::log('node.' . $rAction, $rServerID, ['queued' => $rQueued] + $rEnded, $rActor);
					if ($rQueued && !empty($rEnded['ended'])) {
						return ['type' => 'success', 'message' => 'cluster_quarantine_ended', 'vars' => ['{ENDED}' => (string) $rEnded['ended']]];
					}
					return $rQueued
						? ['type' => 'success', 'message' => 'cluster_' . $rAction . '_done']
						: ['type' => 'danger', 'message' => 'cluster_command_failed'];

				case 'trust':
					if (!ClusterRoute::trust($rServerID)) {
						return ['type' => 'info', 'message' => 'cluster_trust_not_quarantined'];
					}
					ClusterAudit::log('node.trust', $rServerID, [], $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return ['type' => 'success', 'message' => 'cluster_trust_done'];

				case 'pin_core':
					// MAIN's panel key into the node's xcvm_core now, rather than at
					// cron:cluster's next offer (CorePins).
					$rWhy = CorePins::request($rServerID, $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return $rWhy === null ? ['type' => 'success', 'message' => 'cluster_pin_core_queued'] : ['type' => 'warning', 'message' => $rWhy];

				case 'strip_credentials':
					// Phase 9's point of no return for a node: it drops MAIN's DB and
					// Redis credentials, and MAIN revokes its grant once it acks
					// (DbCredentials). Only for an active node in mode 2; the page asks
					// for confirmation first.
					$rWhy = DbCredentials::strip($rServerID, $rUserID === null ? 'admin' : 'admin:' . $rUserID);
					return $rWhy === null ? ['type' => 'success', 'message' => 'cluster_strip_queued'] : ['type' => 'warning', 'message' => $rWhy];

				case 'revoke':
					return NodeRegistry::revoke($rServerID, $rCrypto, $rUserID === null ? 'admin' : 'admin:' . $rUserID)
						? ['type' => 'warning', 'message' => 'cluster_node_revoked']
						: ['type' => 'info', 'message' => 'cluster_not_enrolled'];
			}
		} catch (ClusterRefusedException $rE) {
			return ['type' => 'danger', 'message' => $rE->reason() === 'LICENCE' ? 'cluster_licence_required' : 'cluster_refused'];
		}
		return ['type' => 'danger', 'message' => 'cluster_unknown_action'];
	}
}
