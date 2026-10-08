<?php

namespace XcVm\Streaming\Auth;

use XcVm\Core\Cluster\AgentClient;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\NodeFlows;
use XcVm\Core\Cluster\NodeLease;

/**
 * An RTMP viewer on a load balancer, checked by MAIN through the node's agent
 * (`rtmp_auth`, ADR 0004), and while the agent cannot reach MAIN. An HTTP
 * viewer brings a token the node reads itself; an RTMP viewer brings only its
 * line's credentials, which MAIN alone can check. So the node keeps MAIN's
 * last yes for the same credentials, stream, address and restream flag for
 * TTL seconds, and takes it only when the agent could not reach MAIN: never
 * when MAIN refused, nor when the agent did not answer. It is not taken under
 * `lb_offline_admission = deny`, on a node whose lease refuses new sessions
 * or that is not active, or past the line's expiry. Any other answer from MAIN
 * forgets it. Such a viewer is recorded without MAIN's mint, and its
 * admission is the agent's offline policy's, as an HTTP viewer's is.
 *
 * One file per key in TMP_PATH/rtmp_offline/: an HMAC of what the viewer
 * named, under a key of the node's own, so no credential is written.
 */
final class RtmpOffline {
	/**
	 * How long MAIN's yes stands in: a line revoked while MAIN is away plays
	 * on that long at most. ponytail: fixed; a setting if operators need it.
	 */
	public const TTL = 600;

	/** The agent's answer when it could not reach MAIN (XC_VM_Fanout socket.go); 409 is MAIN's own refusal. */
	public const MAIN_UNREACHED = 502;

	private const NO_ANSWER = ['ok' => false, 'reason' => 'NO_ANSWER'];

	private static ?string $rDir = null;

	/** Tests: another directory; null restores TMP_PATH's. */
	public static function useDir(?string $rDir): void {
		self::$rDir = $rDir;
	}

	/**
	 * MAIN's answer for an RTMP viewer on this node, MAIN's last yes when the
	 * agent could not reach MAIN, or a refusal.
	 *
	 * @param array<string, mixed> $rSettings
	 * @param array<string, string> $rCreds
	 * @return array<string, mixed>
	 */
	public static function ask(array $rSettings, int $rStreamID, string $rIP, array $rCreds, bool $rRestream, string $rUUID, ?int $rNow = null): array {
		$rOut = AgentClient::request('POST', '/v1/main/rtmp_auth', ['stream_id' => $rStreamID, 'ip' => $rIP, 'restream' => $rRestream, 'uuid' => $rUUID] + $rCreds, 6.0);
		$rKey = [$rStreamID, $rIP, $rRestream, $rCreds];
		if ($rOut !== null && $rOut[0] === 200 && is_array($rOut[1])) {
			if (($rOut[1]['ok'] ?? false) === true) {
				self::keep($rKey, $rOut[1], $rNow);
			} else {
				self::forget($rKey);
			}
			return $rOut[1];
		}
		if ($rOut !== null && $rOut[0] === self::MAIN_UNREACHED) {
			return self::answer($rSettings, $rKey, $rNow) ?? self::NO_ANSWER;
		}
		// MAIN refused this node, or the agent did not answer.
		self::forget($rKey);
		return self::NO_ANSWER;
	}

	/** Drop what is past TTL (cron:cleanup); how many went. */
	public static function prune(?int $rNow = null): int {
		$rDir = self::dir(false);
		$rGone = 0;
		foreach ($rDir === null ? [] : (glob($rDir . '*.json') ?: []) as $rFile) {
			if (($rNow ?? time()) - (int) @filemtime($rFile) > self::TTL && @unlink($rFile)) {
				$rGone++;
			}
		}
		return $rGone;
	}

	/**
	 * @param array{0: int, 1: string, 2: bool, 3: array<string, string>} $rKey
	 * @param array<string, mixed> $rAnswer
	 */
	private static function keep(array $rKey, array $rAnswer, ?int $rNow): void {
		$rFile = self::file($rKey);
		if ($rFile !== null && is_array($rAnswer['user'] ?? null)) {
			@file_put_contents($rFile, (string) json_encode(['at' => $rNow ?? time(), 'user' => $rAnswer['user'], 'country_code' => (string) ($rAnswer['country_code'] ?? '')]), LOCK_EX);
		}
	}

	/** @param array{0: int, 1: string, 2: bool, 3: array<string, string>} $rKey */
	private static function forget(array $rKey): void {
		$rFile = self::file($rKey);
		if ($rFile !== null && is_file($rFile)) {
			@unlink($rFile);
		}
	}

	/**
	 * @param array<string, mixed> $rSettings
	 * @param array{0: int, 1: string, 2: bool, 3: array<string, string>} $rKey
	 * @return array{ok: true, user: array<string, mixed>, country_code: string}|null
	 */
	private static function answer(array $rSettings, array $rKey, ?int $rNow): ?array {
		if (ClusterSettings::enum('lb_offline_admission', $rSettings['lb_offline_admission'] ?? null) === 'deny'
			|| NodeFlows::declared()['state'] !== 'active'
			|| NodeLease::refusesNewSessions($rSettings)
		) {
			return null;
		}
		$rNow ??= time();
		$rFile = self::file($rKey);
		$rKept = $rFile === null ? null : json_decode((string) @file_get_contents($rFile), true);
		if (!is_array($rKept) || !is_array($rKept['user'] ?? null) || $rNow - (int) ($rKept['at'] ?? 0) > self::TTL) {
			return null;
		}
		$rExpires = $rKept['user']['exp_date'] ?? null;
		if ($rExpires !== null && (int) $rExpires <= $rNow) {
			return null;
		}
		return ['ok' => true, 'user' => $rKept['user'], 'country_code' => (string) ($rKept['country_code'] ?? '')];
	}

	/** @param array{0: int, 1: string, 2: bool, 3: array<string, string>} $rKey */
	private static function file(array $rKey): ?string {
		$rDir = self::dir(true);
		$rSecret = $rDir === null ? null : self::secret($rDir);
		if ($rSecret === null) {
			return null;
		}
		ksort($rKey[3]);
		// serialize(): every string length-prefixed, bytes as they are; json_encode()
		// failed on invalid UTF-8, and all such credentials shared one key.
		return $rDir . hash_hmac('sha256', serialize($rKey), $rSecret) . '.json';
	}

	/** The node's own key, made by the first writer (0600). */
	private static function secret(string $rDir): ?string {
		$rFile = $rDir . '.key';
		if (!is_file($rFile) && ($rHandle = @fopen($rFile, 'x')) !== false) {
			@chmod($rFile, 0600);
			fwrite($rHandle, random_bytes(32));
			fclose($rHandle);
		}
		$rSecret = @file_get_contents($rFile);
		return is_string($rSecret) && strlen($rSecret) === 32 ? $rSecret : null;
	}

	private static function dir(bool $rMake): ?string {
		$rDir = self::$rDir ?? (defined('TMP_PATH') ? TMP_PATH . 'rtmp_offline/' : null);
		if ($rDir === null || (!is_dir($rDir) && (!$rMake || (!@mkdir($rDir, 0700, true) && !is_dir($rDir))))) {
			return null;
		}
		return $rDir;
	}
}
