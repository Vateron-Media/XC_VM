<?php

namespace XcVm\Domain\Cluster;

use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\ClusterCrypto;

/**
 * Enrolment on MAIN.
 *
 * SSH path (plan, section 6): the install flow generates the node's keys on
 * the LB, reads the public halves and a fresh per-epoch key back over the
 * verified SSH session, and calls issueFirst(). MAIN mints epoch 1 and returns
 * everything the LB needs, which the install flow copies over SSH. The node
 * then proves possession with `enrol_complete` within 30 minutes, when an
 * unused first token dies.
 */
final class EnrolmentService {
	/**
	 * @param array<string, mixed> $rSettings
	 * @param array<string, mixed> $rMain The main server's `servers` row.
	 * `token_sealed` and the lease's `payload`/`sig` are raw bytes, as the
	 * extension returns them: a caller writing them anywhere encodes them first
	 * (the install data does, through `LeaseService::wire()`), and `json_encode`
	 * of this array as it stands answers false.
	 *
	 * @return array{node_uuid: string, gen: int, epoch: int, token_sealed: string, exp: int, refresh_at: int, lease: array{payload: string, sig: string, exp: int}|null, cluster: array<string, mixed>}
	 */
	public static function issueFirst(ClusterCrypto $rCrypto, int $rServerID, string $rNodeUuid, string $rSignPub, string $rBoxPub, string $rAgentEphPub, array $rSettings, array $rMain): array {
		if (!self::validUuid($rNodeUuid) || strlen($rSignPub) !== 32 || strlen($rBoxPub) !== 32 || strlen($rAgentEphPub) !== 32) {
			throw new \InvalidArgumentException('enrolment keys');
		}
		[$rGen, $rIssued, $rMode] = self::begin($rCrypto, $rServerID, $rNodeUuid, $rSignPub, $rBoxPub, $rAgentEphPub, $rSettings);
		ClusterAudit::log('node.enrol_start', $rServerID, ['node' => $rNodeUuid, 'gen' => $rGen, 'mode' => $rMode], 'install');
		return [
			'node_uuid' => $rNodeUuid,
			'gen' => $rGen,
			'epoch' => 1,
			'token_sealed' => (string) $rIssued['token_sealed'],
			'exp' => (int) $rIssued['exp'],
			'refresh_at' => (int) $rIssued['refresh_at'],
			'lease' => $rIssued['lease'] ?? null,
			'cluster' => self::clusterJson($rCrypto, $rServerID, $rNodeUuid, $rSettings, $rMain),
		];
	}

	/**
	 * Start an enrolment, as both paths do (install, and an approved code):
	 * the node's mode from `lb_new_node_mode` (mode 2, `api`, with the flows
	 * mode 2 needs), a new generation with these keys, and epoch 1's token
	 * sealed to the agent's ephemeral key.
	 *
	 * The caller writes the `node.enrol_start` audit event: the install path
	 * right after this, the code path only once the approval is signed and
	 * stored (a refused signature leaves no enrol_start behind).
	 *
	 * @param array<string, mixed> $rSettings
	 * @return array{0: int, 1: array<string, mixed>, 2: int} [gen, TokenService::issue()'s answer, mode]
	 */
	public static function begin(ClusterCrypto $rCrypto, int $rServerID, string $rNodeUuid, string $rSignPub, string $rBoxPub, string $rAgentEphPub, array $rSettings): array {
		$rMode = ClusterSettings::enum('lb_new_node_mode', $rSettings['lb_new_node_mode'] ?? null) === 'api' ? 2 : 1;
		// A node born in mode 2 has no DB grant and no credentials to fall back
		// on: every flow but the data plane is on from its first hello, as the
		// mode gate asks of a promoted one (ClusterAdmin::MODE2_FLOWS).
		$rGen = NodeRegistry::startEnrolment($rServerID, $rNodeUuid, $rSignPub, $rBoxPub, $rMode, $rCrypto, $rMode === 2 ? ClusterAdmin::MODE2_FLOWS : 0)['gen'];
		$rIssued = TokenService::issue($rCrypto, (array) NodeRegistry::byServer($rServerID), 1, $rAgentEphPub);
		return [$rGen, $rIssued, $rMode];
	}

	/**
	 * `cluster.json` for a node: MAIN URLs and policy, panel public keys and
	 * the node's identity. Panel-signed (tag `cfg`) by the caller when written.
	 *
	 * @return array<string, mixed>
	 */
	public static function clusterJson(ClusterCrypto $rCrypto, int $rServerID, string $rNodeUuid, array $rSettings, array $rMain): array {
		$rInfo = $rCrypto->info();
		return [
			'v' => 1,
			'typ' => 'xcvm-cluster',
			'node_uuid' => $rNodeUuid,
			'server_id' => $rServerID,
			'panel_sign_pub' => base64_encode((string) ($rInfo['panel_sign_pub'] ?? '')),
			'panel_box_pub' => base64_encode((string) ($rInfo['panel_box_pub'] ?? '')),
			'panel_fp' => bin2hex((string) ($rInfo['panel_fp'] ?? '')),
			'proto' => ClusterApi::PROTO_RANGE,
			'policy' => ClusterPolicy::current($rSettings, $rMain),
			'iat' => ClusterClock::now(),
		];
	}

	/** SAS: six base32 groups of SHA-256(node_uuid ‖ ed25519_pub ‖ x25519_pub). */
	public static function sas(string $rNodeUuid, string $rSignPub, string $rBoxPub): string {
		$rHash = hash('sha256', $rNodeUuid . $rSignPub . $rBoxPub, true);
		// 15 bytes are 24 characters exactly.
		return implode('-', str_split(Base32::encode(substr($rHash, 0, 15)), 4));
	}

	public static function validUuid(string $rUuid): bool {
		return Canonical::validUuid($rUuid);
	}
}
