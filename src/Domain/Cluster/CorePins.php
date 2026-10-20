<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Cluster\NodeActions;
use XcVm\Core\Cluster\NodeCorePin;
use XcVm\Core\Cluster\NodeCredentials;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * MAIN's side of pinning its panel key in each node's `xcvm_core`
 * (`core.pin`, the extension's ADR-002 "Pin"; ADR 0004, Phase 9): until a
 * node is pinned, its extension cannot judge a lease (`NOT_PINNED`), so its
 * fence and `license_valid()` fall back to the agent's file.
 *
 * A pin is `cluster_pack($install_id)`: an XCVT blob only that install opens,
 * for an hour, licence-gated. So MAIN records each node's install_id
 * (`cluster_nodes.install_id`, migration 053): the SSH install reads it, and a
 * node's root reports it.
 *
 * - **At an SSH install** LbInstallFlow::provisionCluster records the
 *   install_id, packs and pins over its own verified session, and records the
 *   pin here (recorded()).
 * - **Every other node** — enrolled by code, enrolled before this release, or
 *   whose MAIN's root changed — is pinned over the cluster API once it takes
 *   root commands (COMMANDS on, root's pin in place): cron:cluster (offer())
 *   or the Cluster Nodes page (request()) sends `node.root pin_core` with the
 *   pin when the install_id is known; otherwise without one, and root answers
 *   with its install_id, which the first ack of that answer (acked()) records
 *   before it sends the pin. Root pins only to the key its own pin trusts
 *   (NodeCorePin); the ack reports the pinned key's SHA-256, which is
 *   recorded when it is this panel's.
 *
 * Pin state lives in `cluster_meta` (`core_pin:<server_id>` = {gen, fp,
 * pinned_at, tried_at}): a re-enrolled node (a new generation) and a new panel
 * root (another fp) both read as not pinned and are offered again. A node that
 * failed is offered again after RETRY_SEC.
 *
 * @package XC_VM_Domain_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class CorePins {
	use DatabaseAware;

	/** How long a node that did not end pinned waits before it is offered again. */
	public const RETRY_SEC = 3600;

	/** Nodes offered per cron:cluster pass. */
	public const PER_PASS = 20;

	/** How often a pinned node that is not an XCVT v2 target yet is asked for its panel key (ADR-005, Decision 10). */
	public const BACKFILL_SEC = 86400;

	private const META = 'core_pin:';

	/**
	 * The SHA-256 (hex) of this panel's signing key, as MAIN recorded it at
	 * cluster:init (ClusterMeta), or null before.
	 */
	public static function panelFp(): ?string {
		$rPub = base64_decode((string) ClusterMeta::get('panel_sign_pub'), true);
		return is_string($rPub) && strlen($rPub) === 32 ? hash('sha256', $rPub) : null;
	}

	/**
	 * A node's pin as MAIN knows it.
	 *
	 * @return array{gen: int, fp: string, pinned_at: int|null, tried_at: int|null}|null
	 */
	public static function state(int $rServerID): ?array {
		$rDoc = json_decode((string) ClusterMeta::get(self::META . $rServerID), true);
		if (!is_array($rDoc)) {
			return null;
		}
		return [
			'gen' => (int) ($rDoc['gen'] ?? 0),
			'fp' => (string) ($rDoc['fp'] ?? ''),
			'pinned_at' => isset($rDoc['pinned_at']) ? (int) $rDoc['pinned_at'] : null,
			'tried_at' => isset($rDoc['tried_at']) ? (int) $rDoc['tried_at'] : null,
		];
	}

	/**
	 * Is this node (its current generation) pinned to this panel's key?
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 */
	public static function current(array $rNode, ?string $rFp = null): bool {
		$rFp ??= self::panelFp();
		$rState = self::state((int) $rNode['server_id']);
		return $rFp !== null && $rState !== null && $rState['pinned_at'] !== null && $rState['gen'] === (int) $rNode['gen'] && hash_equals($rFp, $rState['fp']);
	}

	/** The node was seen with no pin (an install over SSH, `cluster_pinned`): whatever MAIN recorded of one goes. */
	public static function unpinned(int $rServerID): void {
		try {
			ClusterMeta::delete(self::META . $rServerID);
		} catch (\Throwable) {
			// No cluster_meta: nothing was recorded.
		}
	}

	/** Record a pin the node reported for its current generation. */
	public static function recorded(int $rServerID, string $rFp, string $rActor = 'node'): bool {
		$rNode = NodeRegistry::byServer($rServerID);
		$rPanel = self::panelFp();
		if ($rNode === null || $rPanel === null || !hash_equals($rPanel, $rFp)) {
			return false;
		}
		self::write($rServerID, ['gen' => (int) $rNode['gen'], 'fp' => $rFp, 'pinned_at' => ClusterClock::now(), 'tried_at' => self::state($rServerID)['tried_at'] ?? null]);
		ClusterAudit::log('node.core_pinned', $rServerID, ['fp' => substr($rFp, 0, 16)], $rActor);
		return true;
	}

	/** An install_id as the extension makes them (8–64 of `[0-9A-Za-z-]`). */
	public static function validInstallId(mixed $rID): bool {
		return is_string($rID) && preg_match('/^[0-9A-Za-z-]{8,64}\z/', $rID) === 1;
	}

	/** The node's recorded install_id, or null (unknown, or a table before migration 053). */
	public static function installId(int $rServerID): ?string {
		try {
			self::db()->query('SELECT `install_id` FROM `cluster_nodes` WHERE `server_id` = ?;', $rServerID);
			$rID = self::db()->num_rows() > 0 ? self::db()->get_row()['install_id'] : null;
		} catch (\Throwable) {
			return null;
		}
		return self::validInstallId($rID) ? (string) $rID : null;
	}

	/** Record the node's install_id (SSH install, or root's report). */
	public static function rememberInstallId(int $rServerID, string $rID): bool {
		if (!self::validInstallId($rID)) {
			return false;
		}
		if (self::installId($rServerID) !== $rID) {
			NodeRegistry::update($rServerID, ['install_id' => $rID]);
		}
		// Another install than MAIN's XCVT record is of: that record's key is not this install's.
		$rXcvt = XcvtTargets::get($rServerID);
		if ($rXcvt !== null && $rXcvt['install_id'] !== null && $rXcvt['install_id'] !== $rID) {
			XcvtTargets::clear($rServerID);
		}
		return true;
	}

	/**
	 * cron:cluster: offer the pin to nodes that take root commands and are not
	 * pinned to this panel's key, at most PER_PASS, each at most once per
	 * RETRY_SEC. Returns how many were sent a command.
	 *
	 * @param (callable(int, ?string): bool)|null $rSend tests: queue `pin_core` (with the blob, or without)
	 * @param (callable(string, int): string)|null $rPack tests: `cluster_pack` (install_id, version)
	 */
	public static function offer(?callable $rSend = null, ?int $rNow = null, ?callable $rPack = null): int {
		$rFp = self::panelFp();
		if ($rFp === null) {
			return 0;
		}
		$rNow ??= ClusterClock::now();
		self::db()->query("SELECT * FROM `cluster_nodes` WHERE `state` = 'active' ORDER BY `server_id` ASC;");
		$rSent = 0;
		foreach (self::db()->get_rows() ?: [] as $rNode) {
			if ($rSent >= self::PER_PASS) {
				break;
			}
			$rServerID = (int) $rNode['server_id'];
			if (!CommandBus::acceptsRoot($rNode)) {
				continue;
			}
			$rState = self::state($rServerID);
			if (self::current($rNode, $rFp)) {
				// Pinned, but not yet a v2 target (ADR-005 backfill): asked again, at
				// most once a day, for root's answer carries the node's panel key.
				if ((($rState['tried_at'] ?? null) !== null && $rNow - $rState['tried_at'] < self::BACKFILL_SEC) || XcvtTargets::ready($rServerID)) {
					continue;
				}
			} elseif ($rState !== null && $rState['gen'] === (int) $rNode['gen'] && $rState['tried_at'] !== null && $rNow - $rState['tried_at'] < self::RETRY_SEC) {
				continue;
			}
			self::sendDue($rNode, 'cron', $rNow, $rSend, $rPack);
			$rSent++;
		}
		return $rSent;
	}

	/**
	 * Send now (the Cluster Nodes page's action), whatever the retry wait:
	 * only to a node that takes root commands. Null when queued, else the
	 * message key of why not.
	 *
	 * @param (callable(int, ?string): bool)|null $rSend
	 * @param (callable(string, int): string)|null $rPack
	 */
	public static function request(int $rServerID, string $rActor = 'admin', ?callable $rSend = null, ?callable $rPack = null): ?string {
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null || $rNode['state'] !== 'active') {
			return 'cluster_not_enrolled';
		}
		if (!CommandBus::acceptsRoot($rNode)) {
			return 'cluster_pin_core_no_root';
		}
		if (self::panelFp() === null) {
			return 'cluster_pin_core_no_root_key';
		}
		return self::sendDue($rNode, $rActor, ClusterClock::now(), $rSend, $rPack) ? null : 'cluster_pin_core_not_queued';
	}

	/**
	 * Ask a node that takes root commands for its install_id and panel key
	 * now (`pin_core` step 1; the extension's ADR-005): what makes it a v2
	 * target once acked (acked()). True when queued.
	 *
	 * @param (callable(int, ?string): bool)|null $rSend
	 */
	public static function askKey(int $rServerID, string $rActor = 'system', ?callable $rSend = null): bool {
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null || $rNode['state'] !== 'active' || !CommandBus::acceptsRoot($rNode)) {
			return false;
		}
		$rSend ??= static fn(int $rSid, ?string $rBlob): bool => NodeActions::pinCore($rSid, $rBlob);
		try {
			$rQueued = (bool) $rSend($rServerID, null);
		} catch (\Throwable) {
			$rQueued = false;
		}
		ClusterAudit::log('node.core_pin', $rServerID, ['step' => 'install_id', 'queued' => $rQueued], $rActor);
		return $rQueued;
	}

	/**
	 * A `node.root` command's first ack (ClusterApi). For `pin_core`: an
	 * answer with the node's install_id records it and sends the pin; an
	 * answer with the pinned key's SHA-256 is recorded when it is this
	 * panel's. True when the node ended pinned to this panel's key.
	 *
	 * The XCVT v2 record (XcvtTargets, the extension's ADR-005) is kept from
	 * these acks: the panel key root shows in step 1, the v2 pin it acks in
	 * step 2. A pinned node with no v2 record yet is sent the v2 pin. A failed
	 * `install_config` probes the node again (Decision 13), but for a replay
	 * (`RECORD:replay`), which is packed afresh and sent once more
	 * (DbCredentials::replayed).
	 *
	 * @param (callable(string, int): string)|null $rPack tests: `cluster_pack` (install_id, version)
	 * @param (callable(int, ?string): bool)|null $rSend tests: queue `pin_core`
	 */
	public static function acked(int $rServerID, string $rCmdID, bool $rOk, string $rResult, ?callable $rPack = null, ?callable $rSend = null): bool {
		self::db()->query('SELECT `type`, `payload` FROM `cluster_commands` WHERE `server_id` = ? AND `cmd_id` = ?;', $rServerID, $rCmdID);
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_raw_row() : null;
		$rDoc = $rRow !== null && $rRow['type'] === 'node.root' ? json_decode((string) $rRow['payload'], true) : null;
		if (is_array($rDoc) && ($rDoc['action'] ?? null) === NodeCredentials::INSTALL && !$rOk) {
			// A replay is a stamp clash on MAIN, not a stale key: packed afresh, once.
			if (str_contains($rResult, 'RECORD:replay')) {
				DbCredentials::replayed($rServerID);
			} else {
				self::reprobe($rServerID, $rResult, $rSend);
			}
			return false;
		}
		if (!is_array($rDoc) || ($rDoc['action'] ?? null) !== NodeCorePin::ACTION) {
			return false;
		}
		$rStep = array_key_exists('blob', (array) ($rDoc['args'] ?? [])) ? 'pin' : 'install_id';
		if (!$rOk) {
			ClusterAudit::log('node.core_pin_failed', $rServerID, ['step' => $rStep, 'result' => mb_substr($rResult, 0, 300)], 'node');
			if ($rStep === 'pin' && str_contains($rResult, 'CRYPTO')) {
				// Not this install's blob (the machine was replaced, or the blob
				// outlived its hour): ask the node for its install_id again, and
				// forget the key MAIN held for it with it.
				NodeRegistry::update($rServerID, ['install_id' => null]);
				XcvtTargets::clear($rServerID);
			}
			return false;
		}
		$rCore = NodeCorePin::outcome($rResult);
		$rFp = self::panelFp();
		if ($rCore === null || $rFp === null) {
			return false; // root had not finished ({"queued": true}); cron offers it again
		}
		if (self::validInstallId($rCore['install_id'] ?? null)) {
			self::rememberInstallId($rServerID, (string) $rCore['install_id']);
		}
		$rPinned = is_string($rCore['pinned'] ?? null) && hash_equals($rFp, $rCore['pinned']);
		$rKey = self::keepXcvt($rServerID, $rStep, $rCore, $rPinned, $rStep === 'pin' && self::blobVersion($rDoc) === 2);
		if ($rPinned) {
			$rDone = self::recorded($rServerID, (string) $rCore['pinned']);
			$rNode = NodeRegistry::byServer($rServerID);
			if ($rDone && $rStep === 'install_id' && $rKey && $rNode !== null && !XcvtTargets::ready($rServerID)) {
				// Pinned, but not with v2 by this MAIN: the v2 pin, for the key root just showed.
				self::sendDue($rNode, 'system', ClusterClock::now(), $rSend, $rPack, false);
			}
			return $rDone;
		}
		if ($rStep === 'pin') {
			ClusterAudit::log('node.core_pin_failed', $rServerID, ['step' => 'pin', 'result' => 'pinned another key'], 'node');
			return false;
		}
		$rNode = NodeRegistry::byServer($rServerID);
		if ($rNode === null || self::installId($rServerID) === null) {
			ClusterAudit::log('node.core_pin_failed', $rServerID, ['step' => 'install_id', 'result' => 'no install_id'], 'node');
			return false;
		}
		self::sendDue($rNode, 'system', ClusterClock::now(), $rSend, $rPack, false);
		return false;
	}

	/**
	 * Send the step that is due: the pin when MAIN knows the node's install_id
	 * and its panel key, else the question for both (step 1; root's answer
	 * carries the key, ADR-005 Decision 10). `$rAskKey` false right after that
	 * answer: the pin then goes out with what root showed, v1 for an extension
	 * that showed no key, so a node never loops on step 1. Records the attempt
	 * and audits it. True when a command was queued.
	 *
	 * The pin is version 2 (ADR-005: no licence, a v2 target once acked) when
	 * root showed MAIN this install's panel key, else version 1 (an extension
	 * before ADR-005). A node pinned now stays recorded as pinned meanwhile.
	 *
	 * @param array<string, mixed> $rNode cluster_nodes row
	 * @param (callable(int, ?string): bool)|null $rSend
	 * @param (callable(string, int): string)|null $rPack
	 */
	private static function sendDue(array $rNode, string $rActor, int $rNow, ?callable $rSend, ?callable $rPack, bool $rAskKey = true): bool {
		$rServerID = (int) $rNode['server_id'];
		$rSend ??= static fn(int $rSid, ?string $rBlob): bool => NodeActions::pinCore($rSid, $rBlob);
		$rPack ??= static fn(string $rInstallID, int $rVersion): string => ClusterCryptoFactory::create()->pack($rInstallID, $rVersion);
		$rID = self::installId($rServerID);
		$rXcvt = XcvtTargets::get($rServerID);
		$rKnown = $rID !== null && $rXcvt !== null && $rXcvt['install_id'] === $rID && $rXcvt['panel_pk'] !== null;
		$rAsk = $rID === null || (!$rKnown && $rAskKey);
		$rState = self::current($rNode) ? self::state($rServerID) : null;
		self::write($rServerID, ['gen' => (int) $rNode['gen'], 'fp' => $rState['fp'] ?? '', 'pinned_at' => $rState['pinned_at'] ?? null, 'tried_at' => $rNow]);
		try {
			$rQueued = (bool) $rSend($rServerID, $rAsk ? null : $rPack((string) $rID, $rKnown ? 2 : 1));
		} catch (\Throwable $rE) {
			ClusterAudit::log('node.core_pin_failed', $rServerID, ['step' => 'pack', 'result' => mb_substr($rE->getMessage(), 0, 300)], 'system');
			return false;
		}
		ClusterAudit::log('node.core_pin', $rServerID, ['step' => $rAsk ? 'install_id' : 'pin', 'queued' => $rQueued], $rActor);
		return $rQueued;
	}

	/**
	 * Keep what a `pin_core` ack says for XCVT v2 (XcvtTargets): step 1's
	 * panel key, for the install_id MAIN has (the same pin version when root
	 * still shows the key and pin MAIN recorded), or why it showed none (no
	 * `panel_pk` in the answer: an extension before ADR-005; null: no
	 * panel.sk); step 2's v2 pin, for the key step 1 showed. True when the
	 * node showed a key in step 1.
	 *
	 * @param array<string, mixed> $rCore NodeCorePin::outcome()
	 */
	private static function keepXcvt(int $rServerID, string $rStep, array $rCore, bool $rPinned, bool $rV2Pin): bool {
		$rID = self::installId($rServerID);
		if ($rID === null) {
			return false;
		}
		$rWas = XcvtTargets::get($rServerID);
		$rWasKey = $rWas !== null && $rWas['install_id'] === $rID ? $rWas['panel_pk'] : null;
		if ($rStep === 'pin') {
			if ($rV2Pin && $rPinned && $rWasKey !== null) {
				XcvtTargets::record($rServerID, $rID, $rWasKey, (string) $rCore['pinned'], 2, 'node');
			}
			return false;
		}
		if (!array_key_exists('panel_pk', $rCore) || !XcvtTargets::validKey($rCore['panel_pk'])) {
			XcvtTargets::noteProbe($rServerID, array_key_exists('panel_pk', $rCore) ? 'no_pk' : 'ext_old');
			return false;
		}
		$rKey = (string) $rCore['panel_pk'];
		$rStill = $rPinned && $rWas !== null && $rWasKey === $rKey && $rWas['pin_ver'] === 2 && $rWas['pin_fp'] === $rCore['pinned'];
		XcvtTargets::record($rServerID, $rID, $rKey, $rStill ? $rWas['pin_fp'] : null, $rStill ? 2 : 0, 'node');
		return true;
	}

	/**
	 * The XCVT version of a `pin_core` command's blob (byte 4 after "XCVT"), 0 when there is none.
	 *
	 * @param array<string, mixed> $rDoc
	 */
	private static function blobVersion(array $rDoc): int {
		$rArgs = (array) ($rDoc['args'] ?? []);
		$rBlob = base64_decode((string) ($rArgs['blob'] ?? ''), true);
		return is_string($rBlob) && strlen($rBlob) > 4 && str_starts_with($rBlob, 'XCVT') ? ord($rBlob[4]) : 0;
	}

	/**
	 * A config MAIN sent did not install (ADR-005, Decision 13: the node's
	 * panel.sk regenerated, a re-pin, a replay): forget the key MAIN holds
	 * for it and ask root again (`pin_core` step 1), never recording a key the
	 * node did not show.
	 *
	 * @param (callable(int, ?string): bool)|null $rSend
	 */
	private static function reprobe(int $rServerID, string $rResult, ?callable $rSend): void {
		XcvtTargets::clear($rServerID);
		$rSend ??= static fn(int $rSid, ?string $rBlob): bool => NodeActions::pinCore($rSid, $rBlob);
		try {
			$rQueued = (bool) $rSend($rServerID, null);
		} catch (\Throwable) {
			$rQueued = false;
		}
		ClusterAudit::log('node.xcvt_reprobe', $rServerID, ['result' => mb_substr($rResult, 0, 300), 'queued' => $rQueued], 'system');
	}

	/** @param array{gen: int, fp: string, pinned_at: int|null, tried_at: int|null} $rState */
	private static function write(int $rServerID, array $rState): void {
		ClusterMeta::set(self::META . $rServerID, (string) json_encode($rState));
	}
}
