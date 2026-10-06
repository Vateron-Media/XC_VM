<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\Crypto\ClusterCrypto;
use XcVm\Core\Cluster\Crypto\Enc;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Break-glass enrolment by code (plan, section 6): a NAT'd LB, lost keys, or
 * no SSH from MAIN.
 *
 * ```text
 * code   = base32(u8 1 ‖ u32 sid ‖ u8 len ‖ main_url ‖ SHA-256(panel_sign_pub)[0:16] ‖ secret[16])
 * K_req  = HKDF-SHA256(ikm = secret, salt = u32 sid, info = "xcvm/enrol-code/v1/req")
 * K_res  = HKDF-SHA256(ikm = secret, salt = u32 sid, info = "xcvm/enrol-code/v1/res")
 * lookup = SHA-256(secret)
 * ```
 *
 * MAIN keeps K_req and K_res sealed to this machine (`cluster_seal_local`)
 * under a row MAC, never the secret. The node pins the panel key by the
 * code's hash, sends `enrol_code` (MAC'd with K_req, node-signed, body SEALed
 * to the panel box key) and waits. A sniffed code yields only a pending
 * request: the admin approves by typing the SAS the node shows, which binds
 * its keys. Only then is epoch 1 minted, and `enrol_code_status` hands it
 * over, panel-signed (`pre`) and MAC'd with K_res.
 *
 * One live code per server, single use, 30 minutes, 5 wrong-SAS attempts.
 */
final class EnrolCodeService {
	use DatabaseAware;

	public const TTL = 1800;
	public const MAX_ATTEMPTS = 5;
	private const VERSION = 1;

	/**
	 * A new code for a server. It supersedes the server's unused codes and any
	 * request still waiting for approval.
	 *
	 * @return array{code: string, exp: int, main_url: string}
	 */
	public static function generate(ClusterCrypto $rCrypto, int $rServerID, string $rMainUrl, ?int $rUserID = null): array {
		if ($rServerID < 1 || !preg_match('#^https?://[^/?\#\s]+\z#', $rMainUrl) || strlen($rMainUrl) > 255) {
			throw new \InvalidArgumentException('enrolment code: server id or MAIN URL');
		}
		$rSecret = random_bytes(16);
		$rLookup = hash('sha256', $rSecret, true);
		$rNow = ClusterClock::now();
		$rExp = $rNow + self::TTL;
		[$rReq, $rRes] = self::keys($rSecret, $rServerID);
		$rSealed = $rCrypto->sealLocal('enrol_code', $rReq . $rRes, bin2hex($rLookup));
		self::db()->query('DELETE FROM `cluster_enrol_codes` WHERE `server_id` = ? AND `used_at` IS NULL;', $rServerID);
		self::db()->query("DELETE FROM `cluster_enrol_requests` WHERE `server_id` = ? AND `state` = 'pending_approval';", $rServerID);
		self::db()->query(
			'INSERT INTO `cluster_enrol_codes` (`server_id`, `lookup`, `keys_sealed`, `row_mac`, `attempts`, `created_by`, `created_at`, `exp`) VALUES (?, ?, ?, ?, ?, ?, ?, ?);',
			$rServerID,
			$rLookup,
			$rSealed,
			self::rowMac($rRes, $rServerID, $rLookup, $rExp),
			0,
			$rUserID,
			$rNow,
			$rExp
		);
		$rFp = substr(hash('sha256', (string) ($rCrypto->info()['panel_sign_pub'] ?? ''), true), 0, 16);
		ClusterAudit::log('node.enrol_code', $rServerID, ['exp' => $rExp, 'main_url' => $rMainUrl], $rUserID === null ? 'cli' : 'admin:' . $rUserID);
		return ['code' => self::encode($rServerID, $rMainUrl, $rFp, $rSecret), 'exp' => $rExp, 'main_url' => $rMainUrl];
	}

	/** @return array{0: string, 1: string} K_req, K_res */
	public static function keys(string $rSecret, int $rServerID): array {
		return [
			hash_hkdf('sha256', $rSecret, 32, 'xcvm/enrol-code/v1/req', Enc::u32($rServerID)),
			hash_hkdf('sha256', $rSecret, 32, 'xcvm/enrol-code/v1/res', Enc::u32($rServerID)),
		];
	}

	public static function encode(int $rServerID, string $rMainUrl, string $rFp, string $rSecret): string {
		$rBytes = chr(self::VERSION) . Enc::u32($rServerID) . chr(strlen($rMainUrl)) . $rMainUrl . $rFp . $rSecret;
		return implode('-', str_split(Base32::encode($rBytes), 4));
	}

	/** @return array{server_id: int, main_url: string, fp: string, secret: string}|null */
	public static function decode(string $rCode): ?array {
		$rBytes = Base32::decode(strtoupper((string) preg_replace('/[\s-]/', '', $rCode)));
		if ($rBytes === null || strlen($rBytes) < 6 || ord($rBytes[0]) !== self::VERSION) {
			return null;
		}
		$rLen = ord($rBytes[5]);
		if (strlen($rBytes) !== 6 + $rLen + 32) {
			return null;
		}
		return [
			'server_id' => unpack('N', substr($rBytes, 1, 4))[1],
			'main_url' => substr($rBytes, 6, $rLen),
			'fp' => substr($rBytes, 6 + $rLen, 16),
			'secret' => substr($rBytes, 6 + $rLen + 16, 16),
		];
	}

	/**
	 * A code row with its keys, when it belongs to the server and its row MAC
	 * holds. `live` also requires it unexpired.
	 *
	 * @return array{row: array<string, mixed>, req: string, res: string}|null
	 */
	public static function code(ClusterCrypto $rCrypto, int $rServerID, ?int $rCodeID = null, bool $rLive = true): ?array {
		if ($rCodeID === null) {
			self::db()->query('SELECT * FROM `cluster_enrol_codes` WHERE `server_id` = ? ORDER BY `id` DESC LIMIT 1;', $rServerID);
		} else {
			self::db()->query('SELECT * FROM `cluster_enrol_codes` WHERE `server_id` = ? AND `id` = ?;', $rServerID, $rCodeID);
		}
		$rRow = self::db()->num_rows() > 0 ? self::db()->get_raw_row() : null;
		if ($rRow === null || ($rLive && (int) $rRow['exp'] <= ClusterClock::now())) {
			return null;
		}
		try {
			$rKeys = $rCrypto->openLocal('enrol_code', (string) $rRow['keys_sealed'], bin2hex((string) $rRow['lookup']));
		} catch (\Throwable) {
			return null;
		}
		if (strlen($rKeys) !== 64) {
			return null;
		}
		$rRes = substr($rKeys, 32);
		if (!hash_equals(self::rowMac($rRes, $rServerID, (string) $rRow['lookup'], (int) $rRow['exp']), (string) $rRow['row_mac'])) {
			return null;
		}
		return ['row' => $rRow, 'req' => substr($rKeys, 0, 32), 'res' => $rRes];
	}

	/** @return array<string, mixed>|null The server's enrolment request. */
	public static function request(int $rServerID): ?array {
		self::db()->query('SELECT * FROM `cluster_enrol_requests` WHERE `server_id` = ?;', $rServerID);
		return self::db()->num_rows() > 0 ? self::db()->get_raw_row() : null;
	}

	/**
	 * Record a node's request under a code. A retry with the same keys is
	 * accepted as it stands; other keys under a used code are a conflict (an
	 * alert, never a silent replacement).
	 *
	 * @param array{row: array<string, mixed>} $rCode
	 * @param array{node_uuid: string, sign_pub: string, box_pub: string, eph_pub: string, instance_id: ?string} $rNode
	 * @return string|null null when recorded, else the refusal reason.
	 */
	public static function submit(array $rCode, int $rServerID, array $rNode, string $rIP = ''): ?string {
		$rCodeID = (int) $rCode['row']['id'];
		$rExisting = self::request($rServerID);
		if ($rExisting !== null && (int) $rExisting['code_id'] === $rCodeID) {
			$rSame = $rExisting['node_uuid'] === $rNode['node_uuid'] && hash_equals((string) $rExisting['node_sign_pub'], $rNode['sign_pub'])
				&& hash_equals((string) $rExisting['node_box_pub'], $rNode['box_pub']) && hash_equals((string) $rExisting['agent_eph_pub'], $rNode['eph_pub']);
			if ($rSame) {
				return null;
			}
			ClusterAudit::log('node.enrol_conflict', $rServerID, ['node' => $rNode['node_uuid'], 'pending' => $rExisting['node_uuid']], 'node', $rIP ?: null);
			return 'ENROL_CONFLICT';
		}
		if (!empty($rCode['row']['used_at'])) {
			return 'CODE_INVALID';
		}
		$rOther = NodeRegistry::byUuid($rNode['node_uuid']);
		if ($rOther !== null && (int) $rOther['server_id'] !== $rServerID) {
			return 'BAD_REQUEST';
		}
		self::db()->query('DELETE FROM `cluster_enrol_requests` WHERE `server_id` = ?;', $rServerID);
		self::db()->query(
			'INSERT INTO `cluster_enrol_requests` (`server_id`, `code_id`, `node_uuid`, `node_sign_pub`, `node_box_pub`, `agent_eph_pub`, `attest`, `state`, `created_at`) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
			$rServerID,
			$rCodeID,
			$rNode['node_uuid'],
			$rNode['sign_pub'],
			$rNode['box_pub'],
			$rNode['eph_pub'],
			$rNode['instance_id'],
			'pending_approval',
			ClusterClock::now()
		);
		self::db()->query('UPDATE `cluster_enrol_codes` SET `used_at` = ? WHERE `id` = ?;', ClusterClock::now(), $rCodeID);
		ClusterAudit::log('node.enrol_request', $rServerID, ['node' => $rNode['node_uuid'], 'code' => $rCodeID], 'node', $rIP ?: null);
		return null;
	}

	/**
	 * The admin's decision on a pending request: the SAS they read on the node.
	 * A wrong SAS counts against the code; the fifth rejects the request.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain The main server's `servers` row.
	 * @return string `approved`, `wrong_sas`, `rejected` or `none` (nothing pending).
	 */
	public static function approve(ClusterCrypto $rCrypto, int $rServerID, string $rSas, array $rSettings, array $rMain, ?int $rUserID = null): string {
		$rReq = self::request($rServerID);
		if ($rReq === null || $rReq['state'] !== 'pending_approval') {
			return 'none';
		}
		$rActor = $rUserID === null ? 'cli' : 'admin:' . $rUserID;
		$rExpected = EnrolmentService::sas((string) $rReq['node_uuid'], (string) $rReq['node_sign_pub'], (string) $rReq['node_box_pub']);
		$rTyped = strtoupper((string) preg_replace('/[\s-]/', '', $rSas));
		if (!hash_equals(str_replace('-', '', $rExpected), $rTyped)) {
			self::db()->query('UPDATE `cluster_enrol_codes` SET `attempts` = `attempts` + 1 WHERE `id` = ?;', (int) $rReq['code_id']);
			self::db()->query('SELECT `attempts` FROM `cluster_enrol_codes` WHERE `id` = ?;', (int) $rReq['code_id']);
			$rAttempts = self::db()->num_rows() > 0 ? (int) self::db()->get_row()['attempts'] : self::MAX_ATTEMPTS;
			ClusterAudit::log('node.enrol_wrong_sas', $rServerID, ['node' => $rReq['node_uuid'], 'attempts' => $rAttempts], $rActor);
			if ($rAttempts >= self::MAX_ATTEMPTS) {
				self::decide($rServerID, 'rejected', null, $rUserID);
				return 'rejected';
			}
			return 'wrong_sas';
		}
		$rUuid = (string) $rReq['node_uuid'];
		// A node MAIN keeps free of its credentials (mode 2, or a revoked grant)
		// enrols again in mode 2 whatever lb_new_node_mode says, as over SSH
		// (LbInstallFlow::installsInApiMode): in mode 1 it would have no database.
		// Asked before the enrolment, which replaces the node's row.
		if (DbCredentials::credentialFree($rServerID)) {
			$rSettings['lb_new_node_mode'] = 'api';
		}
		[$rGen, $rIssued, $rMode] = EnrolmentService::begin($rCrypto, $rServerID, $rUuid, (string) $rReq['node_sign_pub'], (string) $rReq['node_box_pub'], (string) $rReq['agent_eph_pub'], $rSettings);
		$rDoc = (string) json_encode([
			'v' => 1, 'typ' => 'xcvm-enrol-approved', 'node_uuid' => $rUuid, 'server_id' => $rServerID, 'gen' => $rGen,
			'epoch' => 1, 'token_sealed' => base64_encode((string) $rIssued['token_sealed']),
			'exp' => (int) $rIssued['exp'], 'refresh_at' => (int) $rIssued['refresh_at'],
			'cluster' => EnrolmentService::clusterJson($rCrypto, $rServerID, $rUuid, $rSettings, $rMain),
		] + LeaseService::wire($rIssued['lease'] ?? null), JSON_UNESCAPED_SLASHES);
		$rSig = $rCrypto->sign('pre', $rDoc);
		self::decide($rServerID, 'approved', (string) json_encode(['doc' => $rDoc, 'sig' => Enc::b64url($rSig)]), $rUserID);
		ClusterAudit::log('node.enrol_start', $rServerID, ['node' => $rUuid, 'gen' => $rGen, 'mode' => $rMode, 'via' => 'code'], $rActor);
		return 'approved';
	}

	public static function reject(int $rServerID, ?int $rUserID = null): bool {
		$rReq = self::request($rServerID);
		if ($rReq === null || $rReq['state'] !== 'pending_approval') {
			return false;
		}
		self::decide($rServerID, 'rejected', null, $rUserID);
		ClusterAudit::log('node.enrol_reject', $rServerID, ['node' => $rReq['node_uuid']], $rUserID === null ? 'cli' : 'admin:' . $rUserID);
		return true;
	}

	/** Drop expired codes nobody used, decided requests older than a day, and used codes no request needs. */
	public static function prune(): void {
		$rNow = ClusterClock::now();
		self::db()->query('DELETE FROM `cluster_enrol_codes` WHERE `exp` <= ? AND `used_at` IS NULL;', $rNow);
		self::db()->query("DELETE FROM `cluster_enrol_requests` WHERE `state` <> 'pending_approval' AND `decided_at` < ?;", $rNow - 86400);
		self::db()->query('DELETE FROM `cluster_enrol_codes` WHERE `used_at` IS NOT NULL AND `exp` < ? AND `id` NOT IN (SELECT `code_id` FROM `cluster_enrol_requests` WHERE `code_id` IS NOT NULL);', $rNow - 86400);
	}

	private static function decide(int $rServerID, string $rState, ?string $rReply, ?int $rUserID): void {
		self::db()->query(
			'UPDATE `cluster_enrol_requests` SET `state` = ?, `reply` = ?, `decided_at` = ?, `decided_by` = ? WHERE `server_id` = ?;',
			$rState,
			$rReply,
			ClusterClock::now(),
			$rUserID,
			$rServerID
		);
	}

	private static function rowMac(string $rRes, int $rServerID, string $rLookup, int $rExp): string {
		return hash_hmac('sha256', 'xcvm-enrol-code-row' . Enc::u32($rServerID) . $rLookup . Enc::u64($rExp), $rRes, true);
	}
}
