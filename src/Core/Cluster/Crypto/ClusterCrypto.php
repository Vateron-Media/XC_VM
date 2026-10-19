<?php

namespace XcVm\Core\Cluster\Crypto;

/**
 * The panel's handle on `xcvm_core`'s cluster API (ADR-002, API version 1).
 * Everything that needs the cluster root goes through here: token and lease
 * issue, session keys, panel signatures, the revocation floor, the pin blob
 * for a new LB, machine sealing. PHP never holds PRK, CK_B or `z`.
 *
 * Obtain it from ClusterCryptoFactory, which refuses when the extension is
 * missing or out of range. Every refusal throws ClusterRefusedException with
 * the extension's reason code. BOX, SEAL, the canonical MAC and signature
 * verification are plain PHP (sodium + OpenSSL) in the sibling classes.
 */
class ClusterCrypto {
	/** @return array<string, mixed> api, ext_version, licensed, kid, clock_ok, initialised, panel keys. */
	public function info(): array {
		return (array) \XC_VM::cluster_info();
	}

	/** Create MAIN's cluster root (idempotent). @return array{created: bool, panel_sign_pub: string, panel_box_pub: string, panel_fp: string} */
	public function init(): array {
		return $this->call('cluster_init');
	}

	/**
	 * Mint an epoch token for a node.
	 *
	 * @param array{node_uuid: string, server_id: int, gen: int, epoch: int, node_sign_pub: string, agent_eph_pub: string, rotation_min: int} $rParams
	 * @return array<string, mixed> epoch_record, token_sealed, epoch, iat, nbf, exp, refresh_at, rotation_min, grace_min, kid
	 */
	public function tokenIssue(array $rParams): array {
		return $this->call('cluster_token_issue', $rParams);
	}

	/** The session of a stored epoch record, for the node the request authenticated as. */
	public function session(string $rEpochRecord, string $rNodeUuid, bool $rHard = false): SessionKeys {
		return SessionKeys::fromArray($this->call('cluster_session', $rEpochRecord, $rNodeUuid, $rHard));
	}

	/** Raise a node's revocation floor. @return array{gen: int, min_epoch: int} The floor in force. */
	public function nodeGen(string $rNodeUuid, int $rGen, int $rMinEpoch = 0): array {
		return $this->call('cluster_node_gen', $rNodeUuid, $rGen, $rMinEpoch);
	}

	/** "R" or "G", as the extension classifies the record. */
	public function recordClass(string $rTag, string $rPayload): string {
		return $this->call('cluster_record_class', $rTag, $rPayload);
	}

	/** The panel's 64-byte signature over a record (granting records need a licence). */
	public function sign(string $rTag, string $rPayload): string {
		PanelSig::input($rTag, ''); // the closed registry, checked before crossing into the extension
		return $this->call('cluster_sign', $rTag, $rPayload);
	}

	/** @param array{node_uuid: string, server_id: int, gen: int, token_exp: int, tolerance_h: int} $rParams @return array{payload: string, sig: string, exp: int} */
	public function leaseIssue(array $rParams): array {
		return $this->call('cluster_lease_issue', $rParams);
	}

	/**
	 * The XCVT pin blob carrying the panel keys to the LB with this install_id:
	 * version 1, licence-gated, or 2 (the extension's ADR-005), signed with the
	 * cluster root, no licence. An extension before ADR-005 takes no version,
	 * so 1 is asked as before.
	 */
	public function pack(string $rTargetInstallID, int $rVersion = 1): string {
		return $rVersion === 1 ? $this->call('cluster_pack', $rTargetInstallID) : $this->call('cluster_pack', $rTargetInstallID, $rVersion);
	}

	/** Does this extension pack XCVT v2 (ADR-005: `panel_pk`, `config_pack` with a key, `cluster_pack` version 2)? */
	public function supportsV2(): bool {
		return method_exists('XC_VM', 'panel_pk');
	}

	/** Seal to this machine; purposes are namespaced `php:` by the extension. */
	public function sealLocal(string $rPurpose, string $rData, string $rContext = ''): string {
		return $this->call('cluster_seal_local', $rPurpose, $rData, $rContext);
	}

	public function openLocal(string $rPurpose, string $rBlob, string $rContext = ''): string {
		return $this->call('cluster_open_local', $rPurpose, $rBlob, $rContext);
	}

	/** Open a pre-token SEAL body addressed to the panel box key (`enrol`, `enrol_code`, `rekey`). */
	public function openSealed(string $rPurpose, string $rSealed, string $rContext = ''): string {
		return $this->call('cluster_open_sealed', $rPurpose, $rSealed, $rContext);
	}

	/**
	 * A disaster-recovery bundle of MAIN's cluster root, its revocation floors
	 * and clock high-water, under Argon2id (1 GiB) of the passphrase plus a
	 * pepper only an extension holds. CLI only; the extension refuses a weak
	 * passphrase (`ARG:passphrase`).
	 */
	public function exportKeys(string $rPassphrase): string {
		return $this->call('cluster_export_keys', $rPassphrase);
	}

	/**
	 * A replacement MAIN takes the bundle's root (sealed to this machine) and
	 * merges its floors and clock. Never replaces a different root
	 * (`ROOT_EXISTS`); the same one again is a no-op.
	 *
	 * @return array{created: bool, panel_sign_pub: string, panel_box_pub: string, panel_fp: string, nodes: int, exported_at: int}
	 */
	public function importKeys(string $rBundle, string $rPassphrase): array {
		return $this->call('cluster_import_keys', $rBundle, $rPassphrase);
	}

	/**
	 * @param mixed ...$rArgs
	 * @return mixed
	 */
	protected function call(string $rMethod, ...$rArgs) {
		$rResult = \XC_VM::$rMethod(...$rArgs);
		if ($rResult === false) {
			throw new ClusterRefusedException((string) (\XC_VM::cluster_last_error() ?? 'UNKNOWN'), $rMethod);
		}
		return $rResult;
	}
}
