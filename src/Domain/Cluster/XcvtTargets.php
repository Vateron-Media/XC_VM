<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * What MAIN knows of each load balancer for XCVT v2 (xcvm_core's ADR-005,
 * Decision 9): the node's install_id, its panel X25519 key (what a v2 config
 * is sealed to), and the v2 pin MAIN delivered (its fingerprint and version).
 *
 * The record is `cluster_meta` `xcvt.<server_id>` = {install_id, panel_pk,
 * pin_fp, pin_ver, at, v2, probe, probe_at}; `cluster_nodes` cannot hold it,
 * a node outside the cluster API has no row there. `panel_pk` is sealed to
 * this machine (`cluster_seal_local`, context `xcvt:<server_id>:<install_id>`),
 * so a DB writer cannot swap the key MAIN seals to, nor move it to another
 * node or install. Every key in it is one the node showed: over a verified SSH
 * session (the install, `server:xcvt-pin`), or in root's session-bound ack of
 * `pin_core` (CorePins).
 *
 * - `v2` is sticky: once MAIN delivered a v2 pin the node is not sent a v1
 *   config again without a fresh probe (NodeConfigPack).
 * - `probe` is why the last probe found no key (`ext_old`, `no_pk`,
 *   `ssh_unreachable`), for `server:xcvt-report`.
 *
 * ready() is the predicate NodeConfigPack packs v2 on. It may be stale (a
 * re-pin, a cleared install_id, a new root): it fails closed, the node
 * enforces. ensureRoot() is the one owner of `cluster_init` on this path.
 *
 * @package XC_VM_Domain_Cluster
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class XcvtTargets {
	use DatabaseAware;

	public const META = 'xcvt.';

	/** The `cluster_seal_local` purpose of a recorded panel key. */
	public const PURPOSE = 'xcvt';

	/** Why a node is not covered (server:xcvt-report): its extension predates ADR-005, it showed no key, it is not v2-pinned, its pin is another root's, or SSH did not reach it. */
	public const REASONS = ['ext_old', 'no_pk', 'no_pin', 'stale_fp', 'ssh_unreachable'];

	/** A host key the admin confirmed (`--expect-hostkey`), per server: `sshkey.<server_id>`. */
	private const HOSTKEY = 'sshkey.';

	private static ?ClusterCrypto $rCrypto = null;

	/** Tests: the crypto that seals and opens recorded keys; null restores the extension's. */
	public static function useCrypto(?ClusterCrypto $rCrypto): void {
		self::$rCrypto = $rCrypto;
	}

	/** A panel key as `panel_pk()` prints it: 64 lowercase hex characters. */
	public static function validKey(mixed $rKey): bool {
		return is_string($rKey) && preg_match('/^[0-9a-f]{64}\z/', $rKey) === 1;
	}

	/**
	 * Record what the node showed. A key is kept only sealed; one that cannot
	 * be sealed (no extension) is not recorded at all. $rV2 null: sticky (set
	 * by a version-2 pin, else kept); false only after the node was seen
	 * unpinned. False when nothing could be written.
	 */
	public static function record(int $rServerID, string $rInstallId, ?string $rPanelPk, ?string $rPinFp, int $rPinVer, string $rActor, ?ClusterCrypto $rCrypto = null, ?bool $rV2 = null, ?string $rProbe = null): bool {
		if (!CorePins::validInstallId($rInstallId) || ($rPanelPk !== null && !self::validKey($rPanelPk))) {
			return false;
		}
		$rSealed = null;
		if ($rPanelPk !== null) {
			$rCrypto ??= self::crypto();
			try {
				$rSealed = $rCrypto === null ? null : base64_encode($rCrypto->sealLocal(self::PURPOSE, $rPanelPk, self::context($rServerID, $rInstallId)));
			} catch (\Throwable) {
				$rSealed = null;
			}
			if ($rSealed === null) {
				return false;
			}
		}
		$rWas = self::raw($rServerID);
		$rDoc = [
			'install_id' => $rInstallId,
			'panel_pk' => $rSealed,
			'pin_fp' => $rPinFp,
			'pin_ver' => $rPinVer,
			'at' => ClusterClock::now(),
			'v2' => $rV2 ?? ($rPinVer === 2 || !empty($rWas['v2'])),
			'probe' => $rProbe,
			'probe_at' => $rProbe === null ? null : ClusterClock::now(),
		];
		if (!self::write($rServerID, $rDoc)) {
			return false;
		}
		if ($rPanelPk !== null) {
			ClusterAudit::log('node.xcvt_key', $rServerID, ['install_id' => $rInstallId, 'panel_pk' => $rPanelPk, 'pin_fp' => $rPinFp, 'pin_ver' => $rPinVer], $rActor);
		}
		return true;
	}

	/**
	 * The last probe found no key, or did not reach the node: kept beside what
	 * MAIN knows, for server:xcvt-report.
	 */
	public static function noteProbe(int $rServerID, string $rWhy): void {
		$rDoc = self::raw($rServerID) ?? ['install_id' => null, 'panel_pk' => null, 'pin_fp' => null, 'pin_ver' => 0, 'at' => ClusterClock::now(), 'v2' => false];
		$rDoc['probe'] = in_array($rWhy, self::REASONS, true) ? $rWhy : 'no_pin';
		$rDoc['probe_at'] = ClusterClock::now();
		self::write($rServerID, $rDoc);
	}

	/**
	 * Forget the node's install_id and key (a `CRYPTO` ack: not this install's
	 * blob, or install_config failed): the pin version and the sticky v2 stay,
	 * so nothing is packed for it until it is probed again.
	 */
	public static function clear(int $rServerID): void {
		$rDoc = self::raw($rServerID);
		if ($rDoc === null) {
			return;
		}
		$rDoc['install_id'] = null;
		$rDoc['panel_pk'] = null;
		self::write($rServerID, $rDoc);
	}

	/**
	 * The record, with the key opened (null when absent or when it does not
	 * open for this server and install: a substituted or moved value).
	 *
	 * @return array{install_id: ?string, panel_pk: ?string, pin_fp: ?string, pin_ver: int, at: int, v2: bool, probe: ?string, probe_at: ?int}|null
	 */
	public static function get(int $rServerID, ?ClusterCrypto $rCrypto = null): ?array {
		$rDoc = self::raw($rServerID);
		if ($rDoc === null) {
			return null;
		}
		$rID = CorePins::validInstallId($rDoc['install_id'] ?? null) ? (string) $rDoc['install_id'] : null;
		$rKey = null;
		$rSealed = is_string($rDoc['panel_pk'] ?? null) ? base64_decode($rDoc['panel_pk'], true) : false;
		if ($rID !== null && is_string($rSealed) && $rSealed !== '') {
			$rCrypto ??= self::crypto();
			try {
				$rOpened = $rCrypto?->openLocal(self::PURPOSE, $rSealed, self::context($rServerID, $rID));
				$rKey = self::validKey($rOpened) ? $rOpened : null;
			} catch (\Throwable) {
				$rKey = null;
			}
		}
		return [
			'install_id' => $rID,
			'panel_pk' => $rKey,
			'pin_fp' => is_string($rDoc['pin_fp'] ?? null) ? $rDoc['pin_fp'] : null,
			'pin_ver' => (int) ($rDoc['pin_ver'] ?? 0),
			'at' => (int) ($rDoc['at'] ?? 0),
			'v2' => !empty($rDoc['v2']),
			'probe' => is_string($rDoc['probe'] ?? null) ? $rDoc['probe'] : null,
			'probe_at' => isset($rDoc['probe_at']) ? (int) $rDoc['probe_at'] : null,
		];
	}

	/**
	 * Is the node a v2 target (Decision 9)? Its install_id valid, its key 32
	 * bytes of hex that open for it, a version-2 pin, and that pin this
	 * panel's key. A key alone never counts.
	 */
	public static function ready(int $rServerID, ?ClusterCrypto $rCrypto = null): bool {
		return self::reason($rServerID, $rCrypto) === null;
	}

	/** Why the node is not ready() (one of REASONS), or null when it is. */
	public static function reason(int $rServerID, ?ClusterCrypto $rCrypto = null): ?string {
		$rRec = self::get($rServerID, $rCrypto);
		if ($rRec === null) {
			return 'no_pin';
		}
		$rFp = CorePins::panelFp();
		if ($rRec['install_id'] !== null && $rRec['panel_pk'] !== null && $rRec['pin_ver'] === 2 && $rFp !== null && $rRec['pin_fp'] !== null && hash_equals($rFp, $rRec['pin_fp'])) {
			return null;
		}
		if ($rRec['probe'] !== null) {
			return $rRec['probe'];
		}
		if ($rRec['panel_pk'] === null) {
			return 'no_pk';
		}
		return $rRec['pin_ver'] === 2 ? 'stale_fp' : 'no_pin';
	}

	/**
	 * MAIN's cluster root, for a v2 pin or config (Decision 9), and the one
	 * guard in front of every `cluster_init` (initRoot()). Null when it is
	 * there and is the root this panel recorded (an unrecorded one is recorded
	 * when every pin on record is of it); created (through ClusterMeta::init,
	 * which records it) only when the extension has none and no fingerprint of
	 * one is recorded anywhere, with $rNotice set to the backup prompt for the
	 * caller to show. Otherwise why not: a root that does not open, one that
	 * is not the recorded one, or none where one was recorded (restore it with
	 * cluster:import-keys). Runs at an install and in server:xcvt-pin; a v2
	 * pack (CorePins, NodeConfigPack) only loads the root.
	 */
	public static function ensureRoot(ClusterCrypto $rCrypto, ?string &$rNotice = null): ?string {
		$rNotice = null;
		$rInfo = $rCrypto->info();
		$rKnown = ClusterMeta::get('panel_fp');
		if (!empty($rInfo['initialised'])) {
			$rFp = bin2hex((string) ($rInfo['panel_fp'] ?? ''));
			if ($rKnown !== null && $rKnown !== '') {
				return hash_equals($rKnown, $rFp) ? null : "MAIN's cluster root (" . $rFp . ') is not the one this panel recorded (' . $rKnown . '): a swapped root.enc, or XCVM_CONFIG_DIR pointing elsewhere. Restore the recorded root (cluster:import-keys)';
			}
			$rMine = hash('sha256', (string) ($rInfo['panel_sign_pub'] ?? ''));
			if (array_diff(self::recordedFps(), [$rMine]) !== []) {
				return "MAIN's cluster root (" . $rFp . ') was never recorded, and nodes were pinned to another: restore that root (cluster:import-keys)';
			}
			ClusterMeta::init($rCrypto);
			return null;
		}
		$rError = (string) ($rInfo['root_error'] ?? '');
		if ($rError !== 'NOT_INITIALISED') {
			return "MAIN's cluster root does not open (" . ($rError === '' ? 'unknown' : $rError) . '): it is never replaced. Restore it (cluster:import-keys)';
		}
		if (self::fingerprintRecorded()) {
			return 'MAIN has no cluster root, but one was recorded here: restore it with cluster:import-keys rather than creating a new one (cluster:init --force makes a new root on purpose; every pinned load balancer then needs server:xcvt-pin)';
		}
		try {
			$rRoot = ClusterMeta::init($rCrypto);
		} catch (\Throwable $rE) {
			return "MAIN's cluster root could not be created: " . $rE->getMessage();
		}
		$rNotice = "MAIN's cluster root was created (" . $rRoot['panel_fp'] . "): it now protects every load balancer's config. Back it up now: console.php cluster:export-keys";
		return null;
	}

	/**
	 * What cluster:init and enabling the cluster API in Settings do: the root
	 * through ensureRoot(), then ClusterMeta::init (idempotent on that root:
	 * records its keys, restarts the silence clock). Null when done, else
	 * ensureRoot's refusal. Throws when the extension refuses.
	 *
	 * @param string|null $rNotice set to the backup prompt when a root was created
	 * @return array{0: ?string, 1: bool} why not (null: done), and whether a root was created
	 */
	public static function initRoot(ClusterCrypto $rCrypto, ?string &$rNotice = null): array {
		$rWhy = self::ensureRoot($rCrypto, $rNotice);
		if ($rWhy !== null) {
			return [$rWhy, false];
		}
		ClusterMeta::init($rCrypto);
		return [null, $rNotice !== null];
	}

	/**
	 * Is the host key a load balancer presents now one the admin confirmed?
	 * ServerInstallCommand and server:xcvt-pin record a key given with
	 * `--expect-hostkey`; a key stored on first use is not one.
	 */
	public static function hostKeyVerified(int $rServerID, string $rHostKey): bool {
		$rKnown = self::meta(self::HOSTKEY . $rServerID);
		return $rKnown !== null && $rHostKey !== '' && hash_equals($rKnown, $rHostKey);
	}

	/**
	 * After a host-key check passed: $rConfirmed when the admin gave the key
	 * (`--expect-hostkey`). An unconfirmed key that is not the confirmed one
	 * (first use, a forgotten key) drops the mark.
	 */
	public static function noteHostKey(int $rServerID, string $rHostKey, bool $rConfirmed): void {
		try {
			if ($rConfirmed) {
				ClusterMeta::set(self::HOSTKEY . $rServerID, $rHostKey);
			} elseif (!self::hostKeyVerified($rServerID, $rHostKey)) {
				ClusterMeta::delete(self::HOSTKEY . $rServerID);
			}
		} catch (\Throwable) {
			// No cluster_meta: nothing is confirmed, server:xcvt-pin asks for the key.
		}
	}

	/** The sealing context of a node's key: its server and install, so a value moved to another fails to open. */
	private static function context(int $rServerID, string $rInstallId): string {
		return 'xcvt:' . $rServerID . ':' . $rInstallId;
	}

	/** Is any fingerprint of a cluster root recorded: the panel's, a node pin, an XCVT record, an enrolled node? */
	private static function fingerprintRecorded(): bool {
		if (self::recordedFps() !== [] || ClusterMeta::get('panel_fp') !== null) {
			return true;
		}
		try {
			self::db()->query('SELECT 1 FROM `cluster_nodes` LIMIT 1;');
			return self::db()->num_rows() > 0;
		} catch (\Throwable) {
			return false;
		}
	}

	/**
	 * The SHA-256 of every panel signing key on record: the panel's own, each
	 * node pin, each XCVT pin. A pin, not an attempt: CorePins writes `fp` ""
	 * while it waits, an XCVT record without a pin has `pin_fp` null.
	 *
	 * @return list<string>
	 */
	private static function recordedFps(): array {
		$rFps = [];
		$rPub = base64_decode((string) ClusterMeta::get('panel_sign_pub'), true);
		if (is_string($rPub) && $rPub !== '') {
			$rFps[] = hash('sha256', $rPub);
		}
		self::db()->query("SELECT `name`, `value` FROM `cluster_meta` WHERE `name` LIKE 'core\\_pin:%' OR `name` LIKE 'xcvt.%';");
		foreach (self::db()->get_rows() ?: [] as $rRow) {
			$rDoc = json_decode((string) $rRow['value'], true);
			$rFp = is_array($rDoc) ? ($rDoc[str_starts_with((string) $rRow['name'], self::META) ? 'pin_fp' : 'fp'] ?? null) : null;
			if (is_string($rFp) && $rFp !== '') {
				$rFps[] = $rFp;
			}
		}
		return array_values(array_unique($rFps));
	}

	private static function crypto(): ?ClusterCrypto {
		if (self::$rCrypto !== null) {
			return self::$rCrypto;
		}
		try {
			return ClusterCryptoFactory::create();
		} catch (\Throwable) {
			return null;
		}
	}

	/** @return array<string, mixed>|null */
	private static function raw(int $rServerID): ?array {
		$rDoc = json_decode((string) self::meta(self::META . $rServerID), true);
		return is_array($rDoc) ? $rDoc : null;
	}

	/** ClusterMeta::get, null without the table (it is in every panel's schema since migration 029). */
	private static function meta(string $rName): ?string {
		try {
			return ClusterMeta::get($rName);
		} catch (\Throwable) {
			return null;
		}
	}

	/** @param array<string, mixed> $rDoc */
	private static function write(int $rServerID, array $rDoc): bool {
		try {
			ClusterMeta::set(self::META . $rServerID, (string) json_encode($rDoc, JSON_UNESCAPED_SLASHES));
			return true;
		} catch (\Throwable) {
			return false;
		}
	}
}
