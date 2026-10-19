<?php

namespace XcVm\Tests\Support;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\ClusterRefusedException;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Core\Cluster\Crypto\Seal;
use XcVm\Core\Cluster\Crypto\SessionKeys;

/**
 * `xcvm_core`'s cluster half, in PHP, for the MAIN API tests. It speaks the
 * extension's wire formats (token doc, SEAL to the agent's per-epoch key,
 * panel signatures) through ClusterReference, so the test agent can open and
 * use what it issues exactly as the Go agent will. The epoch record is plain
 * JSON here; the real one is sealed to the machine.
 */
class FakeClusterCrypto extends ClusterCrypto {
	/**
	 * Command types the extension classes R (restrictive), signable without a
	 * licence. Informational, as CommandBus::RESTRICTIVE: sign() classes by
	 * the extension's registry (commandRegistry()), and CommandBusRegistryTest
	 * holds this list to it.
	 */
	public const RESTRICTIVE_COMMANDS = ['conn.drop', 'conn.drop_line', 'conn.kill_worker', 'conn.close', 'stream.stop', 'vod.stop', 'token.rotate_now', 'node.quarantine', 'node.fence', 'resync', 'config.changed', 'node.purge'];

	/** @var array<string, mixed>|null the extension's command registry (cluster_commands.json) */
	private static ?array $rRegistry = null;

	public string $rSeed;

	public string $rPrk;

	public string $rB;

	/** The panel box key (X25519) that pre-token bodies are sealed to. */
	public string $rBoxSk;

	public bool $rLicensed = true;

	/** @var array<string, int> node uuid => generation floor */
	public array $rFloor = [];

	/** Set to a reason code to make session() refuse. */
	public ?string $rRefuse = null;

	public bool $rInitialised = false;

	/** Set to a reason code to make tokenIssue() refuse (LICENCE, …). */
	public ?string $rRefuseIssue = null;

	/** Set to a reason code (CLOCK, REVOKED, …) to make sign() refuse whatever is not restrictive. */
	public ?string $rRefuseSign = null;

	/** Set to a reason code to make leaseIssue() refuse (LICENCE, CLOCK, …). */
	public ?string $rRefuseLease = null;

	/** The extension's own bounds on a lease (ADR-002, "Lease"). */
	public const MAX_TOLERANCE_H = 24;

	public const MAX_LEASE_SEC = 26 * 3600;

	public function __construct() {
		$this->rSeed = str_repeat("\x42", 32);
		$this->rPrk = str_repeat("\x07", 32);
		$this->rB = str_repeat("\x09", 16);
		$this->rBoxSk = str_repeat("\x0b", 32);
	}

	/**
	 * The extension's `root_error` (ADR-005, ensureRoot): NOT_INITIALISED (no
	 * root.enc: init() creates one) or ROOT_UNREADABLE (init() refuses). Null:
	 * the root is there, as every other test has it.
	 */
	public ?string $rRootError = null;

	/** Does this extension pack XCVT v2 (`panel_pk`, `cluster_pack` version 2)? */
	public bool $rV2 = true;

	public function info(): array {
		$rPub = ClusterReference::panelPub($this->rSeed);
		if ($this->rRootError !== null) {
			return ['api' => 1, 'ext_version' => 'fake', 'licensed' => $this->rLicensed, 'kid' => '', 'clock_ok' => true, 'initialised' => false, 'root_error' => $this->rRootError];
		}
		return [
			'api' => 1, 'ext_version' => 'fake', 'licensed' => $this->rLicensed, 'kid' => bin2hex(substr($this->rB, 0, 4)),
			'clock_ok' => true, 'initialised' => true, 'panel_sign_pub' => $rPub,
			'panel_box_pub' => sodium_crypto_scalarmult_base($this->rBoxSk), 'panel_fp' => hash('sha256', $rPub, true),
		];
	}

	public function supportsV2(): bool {
		return $this->rV2;
	}

	public function init(): array {
		if ($this->rRootError === 'ROOT_UNREADABLE') {
			throw new ClusterRefusedException('ROOT_UNREADABLE', 'cluster_init');
		}
		$this->rRootError = null;
		$rInfo = $this->info();
		$rCreated = !$this->rInitialised;
		$this->rInitialised = true;
		return ['created' => $rCreated, 'panel_sign_pub' => $rInfo['panel_sign_pub'], 'panel_box_pub' => $rInfo['panel_box_pub'], 'panel_fp' => $rInfo['panel_fp']];
	}

	/** DR bundles as the extension's contract, minus the KDF: a fixed passphrase rule, ROOT_EXISTS, a no-op on the same root. */
	public function exportKeys(string $rPassphrase): string {
		if (strlen($rPassphrase) < 20) {
			throw new ClusterRefusedException('ARG:passphrase', 'cluster_export_keys');
		}
		return 'FAKEDR1' . hash('sha256', $rPassphrase, true) . $this->rSeed;
	}

	public function importKeys(string $rBundle, string $rPassphrase): array {
		if (strlen($rBundle) !== 71 || !str_starts_with($rBundle, 'FAKEDR1') || !hash_equals(substr($rBundle, 7, 32), hash('sha256', $rPassphrase, true))) {
			throw new ClusterRefusedException('CRYPTO', 'cluster_import_keys');
		}
		$rSeed = substr($rBundle, 39);
		if ($this->rInitialised && $rSeed !== $this->rSeed) {
			throw new ClusterRefusedException('ROOT_EXISTS', 'cluster_import_keys');
		}
		$rCreated = !$this->rInitialised;
		$this->rSeed = $rSeed;
		$this->rInitialised = true;
		$rInfo = $this->info();
		return ['created' => $rCreated, 'panel_sign_pub' => $rInfo['panel_sign_pub'], 'panel_box_pub' => $rInfo['panel_box_pub'], 'panel_fp' => $rInfo['panel_fp'], 'nodes' => 2, 'exported_at' => 1800000000];
	}

	public function tokenIssue(array $rParams): array {
		if ($this->rRefuseIssue !== null) {
			throw new ClusterRefusedException($this->rRefuseIssue, 'cluster_token_issue');
		}
		$rNow = \XcVm\Domain\Cluster\ClusterClock::now();
		$rRotation = (int) $rParams['rotation_min'];
		$rGrace = ClusterSettings::graceMin($rRotation);
		$rNbf = $rNow - 120;
		$rExp = $rNow + ($rRotation + $rGrace) * 60;
		$rRefreshAt = $rNow + intdiv($rRotation * 60, 2);
		$rExtSk = random_bytes(32);
		$rZ = sodium_crypto_scalarmult($rExtSk, (string) $rParams['agent_eph_pub']);
		$rMsg = ClusterReference::tokenMsg((string) $rParams['node_uuid'], (int) $rParams['server_id'], (int) $rParams['gen'], (string) $rParams['node_sign_pub'], (int) $rParams['epoch'], $rNbf, $rExp, $rZ);
		$rT = hash_hmac('sha256', $rMsg, ClusterReference::ck($this->rPrk, $this->rB), true);
		$rDoc = (string) json_encode([
			'v' => 1, 'typ' => 'xcvm-token', 'node_uuid' => $rParams['node_uuid'], 'server_id' => (int) $rParams['server_id'],
			'gen' => (int) $rParams['gen'], 'epoch' => (int) $rParams['epoch'], 'iat' => $rNow, 'nbf' => $rNbf, 'exp' => $rExp,
			'kid' => bin2hex(substr($this->rB, 0, 4)), 'rotation_min' => $rRotation, 'grace_min' => $rGrace,
			'refresh_at' => $rRefreshAt, 'token' => bin2hex($rT),
		], JSON_UNESCAPED_SLASHES);
		$rBody = Enc::u32(strlen($rDoc)) . $rDoc . ClusterReference::panelSign($this->rSeed, 'tok', $rDoc);
		$rRecord = (string) json_encode([
			'node_uuid' => $rParams['node_uuid'], 'server_id' => (int) $rParams['server_id'], 'gen' => (int) $rParams['gen'],
			'node_sign_pub' => bin2hex((string) $rParams['node_sign_pub']), 'epoch' => (int) $rParams['epoch'],
			'nbf' => $rNbf, 'exp' => $rExp, 't' => bin2hex($rT),
		]);
		return [
			'epoch_record' => $rRecord,
			'token_sealed' => Seal::seal((string) $rParams['agent_eph_pub'], 'token', (string) $rParams['node_uuid'], $rBody),
			'epoch' => (int) $rParams['epoch'], 'iat' => $rNow, 'nbf' => $rNbf, 'exp' => $rExp, 'refresh_at' => $rRefreshAt,
			'rotation_min' => $rRotation, 'grace_min' => $rGrace, 'kid' => bin2hex(substr($this->rB, 0, 4)),
		];
	}

	/**
	 * `cluster_pack`: a stand-in pin blob naming its target and the panel key
	 * (the real one is XCVT, which only that install opens), its version in
	 * byte 4 as the real one's. Version 1 is licence-gated; version 2 (ADR-005)
	 * needs only the root; any other is refused (`ARG:version`).
	 */
	public function pack(string $rTargetInstallID, int $rVersion = 1): string {
		if ($rVersion !== 1 && $rVersion !== 2) {
			throw new ClusterRefusedException('ARG:version', 'cluster_pack');
		}
		if ($this->rRootError !== null) {
			throw new ClusterRefusedException($this->rRootError, 'cluster_pack');
		}
		if ($rVersion === 1 && !$this->rLicensed) {
			throw new ClusterRefusedException('LICENCE', 'cluster_pack');
		}
		return 'XCVT' . chr($rVersion) . str_repeat("\x01", 65) . json_encode(['target' => $rTargetInstallID, 'panel_sign_pub' => bin2hex($this->info()['panel_sign_pub'])]);
	}

	/**
	 * As the extension: `exp = min(token_exp + tolerance_h · 3600, iat + 26 h)`
	 * with the tolerance clamped to 0-24 h, the document's keys in its order,
	 * signed under tag `lea`, and nothing at all without a licence.
	 *
	 * @param array{node_uuid: string, server_id: int, gen: int, token_exp: int, tolerance_h: int} $rParams
	 * @return array{payload: string, sig: string, exp: int}
	 */
	public function leaseIssue(array $rParams): array {
		if ($this->rRefuseLease !== null) {
			throw new ClusterRefusedException($this->rRefuseLease, 'cluster_lease_issue');
		}
		if (!$this->rLicensed) {
			throw new ClusterRefusedException('LICENCE', 'cluster_lease_issue');
		}
		$rNow = \XcVm\Domain\Cluster\ClusterClock::now();
		$rTolerance = max(0, min(self::MAX_TOLERANCE_H, (int) $rParams['tolerance_h']));
		$rExp = min((int) $rParams['token_exp'] + $rTolerance * 3600, $rNow + self::MAX_LEASE_SEC);
		$rDoc = (string) json_encode([
			'v' => 1, 'typ' => 'xcvm-lease', 'node_uuid' => $rParams['node_uuid'], 'server_id' => (int) $rParams['server_id'],
			'gen' => (int) $rParams['gen'], 'iat' => $rNow, 'exp' => $rExp, 'kid' => bin2hex(substr($this->rB, 0, 4)),
		], JSON_UNESCAPED_SLASHES);
		return ['payload' => $rDoc, 'sig' => ClusterReference::panelSign($this->rSeed, 'lea', $rDoc), 'exp' => $rExp];
	}

	public function session(string $rEpochRecord, string $rNodeUuid, bool $rHard = false): SessionKeys {
		$rR = json_decode($rEpochRecord, true);
		if (!is_array($rR) || $rR['node_uuid'] !== $rNodeUuid) {
			throw new ClusterRefusedException('RECORD', 'cluster_session');
		}
		if ($this->rRefuse !== null) {
			throw new ClusterRefusedException($this->rRefuse, 'cluster_session');
		}
		if ($rR['gen'] < ($this->rFloor[$rNodeUuid] ?? 0)) {
			throw new ClusterRefusedException('REVOKED', 'cluster_session');
		}
		$rNow = \XcVm\Domain\Cluster\ClusterClock::now();
		if ($rNow >= $rR['exp'] || $rNow < $rR['nbf']) {
			throw new ClusterRefusedException('EXPIRED', 'cluster_session');
		}
		if ($rHard && !$this->rLicensed) {
			// CLUSTER_SESSION_HARD (lb_revocation_mode=hard): no session without a licence.
			throw new ClusterRefusedException('LICENCE', 'cluster_session');
		}
		$rK = ClusterReference::sessionKeys((string) hex2bin($rR['t']));
		return new SessionKeys(
			$rNodeUuid,
			(int) $rR['server_id'],
			(int) $rR['gen'],
			(string) hex2bin($rR['node_sign_pub']),
			(int) $rR['epoch'],
			(int) $rR['nbf'],
			(int) $rR['exp'],
			bin2hex(substr($this->rB, 0, 4)),
			$this->rLicensed,
			$rK['mac_up'],
			$rK['mac_down'],
			$rK['enc_up'],
			$rK['enc_down']
		);
	}

	public function nodeGen(string $rNodeUuid, int $rGen, int $rMinEpoch = 0): array {
		$this->rFloor[$rNodeUuid] = max($this->rFloor[$rNodeUuid] ?? 0, $rGen);
		return ['gen' => $this->rFloor[$rNodeUuid], 'min_epoch' => $rMinEpoch];
	}

	/**
	 * The extension's command registry, which xcvm_core generates from the
	 * table cluster_sign classes by (tests/Support/cluster_commands.json).
	 *
	 * @return array<string, mixed>
	 */
	public static function commandRegistry(): array {
		return self::$rRegistry ??= (array) json_decode((string) file_get_contents(__DIR__ . '/cluster_commands.json'), true);
	}

	/**
	 * A `cmd` record's class as the extension's sign::classify decides it:
	 * "R" or "G", or the extension's refusal (RECORD:type for a type it does
	 * not know, RECORD:args for an argument a restrictive type does not take,
	 * RECORD:action for an action type without its top-level action,
	 * RECORD:key for an envelope key it does not know, RECORD:exp without an
	 * integer exp).
	 */
	public static function commandClass(string $rPayload): string {
		$rReg = self::commandRegistry();
		$rDoc = json_decode($rPayload);
		if (!$rDoc instanceof \stdClass) {
			throw new ClusterRefusedException('RECORD:json_object', 'cluster_sign');
		}
		$rCmd = get_object_vars($rDoc);
		$rType = $rCmd['type'] ?? null;
		if (!is_string($rType)) {
			throw new ClusterRefusedException('RECORD:type', 'cluster_sign');
		}
		// The extension also bounds exp to its clock's next max_ttl_sec; the
		// tests sign documents dated at fixed times, so only its presence is
		// checked here.
		if (!is_int($rCmd['exp'] ?? null)) {
			throw new ClusterRefusedException('RECORD:exp', 'cluster_sign');
		}
		if (array_key_exists('args', $rCmd) && !$rCmd['args'] instanceof \stdClass) {
			throw new ClusterRefusedException('RECORD:args', 'cluster_sign');
		}
		$rEntry = $rReg['types'][$rType] ?? null;
		$rKeys = $rReg['envelope'];
		if (!empty($rEntry['action'])) {
			$rKeys[] = $rReg['action_key'];
		}
		if (array_diff(array_keys($rCmd), $rKeys) !== []) {
			throw new ClusterRefusedException('RECORD:key', 'cluster_sign');
		}
		if (!is_array($rEntry)) {
			throw new ClusterRefusedException('RECORD:type', 'cluster_sign');
		}
		$rAllowed = $rEntry['args'] ?? null;
		if (!empty($rEntry['action'])) {
			$rAction = $rCmd[$rReg['action_key']] ?? null;
			if (!is_string($rAction)) {
				throw new ClusterRefusedException('RECORD:action', 'cluster_sign');
			}
			$rAllowed = $rEntry['restrictive_actions'][$rAction] ?? null;
		}
		if (!is_array($rAllowed)) {
			return 'G';
		}
		if (isset($rCmd['args']) && array_diff(array_keys(get_object_vars($rCmd['args'])), $rAllowed) !== []) {
			throw new ClusterRefusedException('RECORD:args', 'cluster_sign');
		}
		// node.purge: restrictive only with jobs, each of the registry's job types and keys.
		if (isset($rEntry['job_types'])) {
			$rJobs = isset($rCmd['args']) ? ($rCmd['args']->jobs ?? null) : null;
			if (!is_array($rJobs) || $rJobs === []) {
				throw new ClusterRefusedException('RECORD:args', 'cluster_sign');
			}
			foreach ($rJobs as $rJob) {
				if (!$rJob instanceof \stdClass || array_diff(array_keys(get_object_vars($rJob)), $rEntry['job_keys']) !== [] || !in_array($rJob->type ?? null, $rEntry['job_types'], true)) {
					throw new ClusterRefusedException('RECORD:args', 'cluster_sign');
				}
			}
		}
		return 'R';
	}

	public function recordClass(string $rTag, string $rPayload): string {
		if ($rTag === 'cmd') {
			return self::commandClass($rPayload);
		}
		if ($rTag === 'blk') {
			return empty(json_decode($rPayload, true)['remove'] ?? null) ? 'R' : 'G';
		}
		return in_array($rTag, \XcVm\Core\Cluster\Crypto\PanelSig::RESTRICTIVE_TAGS, true) ? 'R' : 'G';
	}

	public function sign(string $rTag, string $rPayload): string {
		// As the extension: a blocklist delta restricts while it removes
		// nothing, and a command is classed by the extension's registry: a
		// type, argument or envelope it does not know is refused, licensed or
		// not.
		$rRestrictive = $this->recordClass($rTag, $rPayload) === 'R';
		if ($this->rRefuseSign !== null && !$rRestrictive) {
			throw new ClusterRefusedException($this->rRefuseSign, 'cluster_sign');
		}
		if (!$this->rLicensed && !$rRestrictive) {
			throw new ClusterRefusedException('LICENCE', 'cluster_sign');
		}
		return ClusterReference::panelSign($this->rSeed, $rTag, $rPayload);
	}

	public function openSealed(string $rPurpose, string $rSealed, string $rContext = ''): string {
		if (!in_array($rPurpose, ['enrol', 'enrol_code', 'rekey'], true)) {
			throw new ClusterRefusedException('PURPOSE', 'cluster_open_sealed');
		}
		$rPlain = Seal::open($this->rBoxSk, $rPurpose, $rContext, $rSealed);
		if ($rPlain === null) {
			throw new ClusterRefusedException('SEAL', 'cluster_open_sealed');
		}
		return $rPlain;
	}

	/** Machine sealing, standing in for the extension's install_id-bound key. */
	public function sealLocal(string $rPurpose, string $rData, string $rContext = ''): string {
		$rNonce = random_bytes(12);
		$rTag = '';
		$rCipher = (string) openssl_encrypt($rData, 'aes-256-gcm', hash('sha256', 'fake-machine' . $this->rSeed, true), OPENSSL_RAW_DATA, $rNonce, $rTag, 'php:' . $rPurpose . "\0" . $rContext, 16);
		return $rNonce . $rCipher . $rTag;
	}

	public function openLocal(string $rPurpose, string $rBlob, string $rContext = ''): string {
		$rPlain = strlen($rBlob) < 28 ? false : openssl_decrypt(substr($rBlob, 12, -16), 'aes-256-gcm', hash('sha256', 'fake-machine' . $this->rSeed, true), OPENSSL_RAW_DATA, substr($rBlob, 0, 12), substr($rBlob, -16), 'php:' . $rPurpose . "\0" . $rContext);
		if ($rPlain === false) {
			throw new ClusterRefusedException('SEAL', 'cluster_open_local');
		}
		return $rPlain;
	}
}
