<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Config\StreamSecret;
use XcVm\Core\Util\Encryption;

/**
 * Per-node viewer-token keys (H1, D7: docs/superpowers/specs/2026-10-01-per-node-viewer-keys-design.md).
 *
 * A node serves a viewer on the strength of the token MAIN minted at the
 * redirect alone, and every token was sealed with the fleet's one secret
 * (`live_streaming_pass`, OPENSSL_EXTRA its context), which every node holds:
 * whoever held one node could mint tokens MAIN and every other node accepted.
 * Node n's key, K_n = HMAC(live_streaming_pass, "xc_vm viewer key v1|" ‖ n),
 * opens on n only.
 *
 * - MAIN derives K_n when it mints (mint()), and the replica's `secrets`
 *   section sends node n its own (`viewer_key`, under the replaced secret too
 *   while StreamSecret accepts that).
 * - The node keeps it in config/viewer_key (0600, adopt()), reports its kid
 *   (`servers.viewer_key_fp`), and Encryption::readToken tries it first (own()).
 * - MAIN mints with K_n only for a node that reports the kid of K_n under the
 *   current secret, or under the replaced one inside its window; any other node
 *   (older code, the section not applied yet) gets the shared secret's token,
 *   as before, which it still reads.
 *
 * The containment comes once the node no longer holds the shared secret
 * (the design's third increment).
 */
final class ViewerKey {
	/** The `secrets` section's entry, and the kid's name. */
	public const NAME = 'viewer_key';

	/** The `servers` column the node reports its key's kid in (NodeStateSink). */
	public const FP = 'viewer_key_fp';

	/**
	 * The `secrets` section's entry a locked-down MAIN sends a node in mode 2
	 * in place of `live_streaming_pass` (the third increment): the SHA-256 of
	 * the value, which checks a legacy password (passMatches) and mints nothing.
	 */
	public const PASS_HASH = 'streaming_pass_hash';

	private const FILE = 'viewer_key';

	private const PASS_HASH_FILE = 'stream_pass_hash';

	/** @var array{current: string, previous: ?string, previous_valid_until: ?int}|false|null */
	private static array|bool|null $rOwn = null;

	private static ?int $rReadAt = null;

	private static ?string $rFile = null;

	/** K_n, as hex: the key node $rServerID's viewer tokens are sealed with under $rSecret. */
	public static function derive(string $rSecret, int $rServerID): string {
		return hash_hmac('sha256', 'xc_vm viewer key v1|' . $rServerID, $rSecret);
	}

	/** The key's id: what the node reports, which names the key without revealing it. */
	public static function kid(string $rKey): string {
		return ReplicaSections::kid(self::NAME, $rKey);
	}

	/**
	 * MAIN: the `secrets` section's `viewer_key` for node $rServerID, in
	 * ReplicaSections::secret's form: K_n under $rSecret, and under the replaced
	 * secret while it is accepted ($rPrevious, StreamSecret::previousEntry).
	 *
	 * @param array{value: string, valid_until: int}|null $rPrevious
	 * @return array{current: string, kid: string, previous: ?string, previous_valid_until: ?int}
	 */
	public static function entry(string $rSecret, int $rServerID, ?array $rPrevious): array {
		$rKey = self::derive($rSecret, $rServerID);
		$rOld = $rPrevious !== null && $rPrevious['value'] !== $rSecret ? $rPrevious : null;
		return [
			'current' => $rKey,
			'kid' => self::kid($rKey),
			'previous' => $rOld === null ? null : self::derive($rOld['value'], $rServerID),
			'previous_valid_until' => $rOld['valid_until'] ?? null,
		];
	}

	/**
	 * MAIN: a viewer token that server $rServerID reads: sealed with its key
	 * when it reports holding it (keyFor()), else the shared secret's, as
	 * mintToken() makes it.
	 *
	 * @param array<int, array<string, mixed>> $rServers ServerRepository::getAll()
	 * @param array<string, mixed> $rSettings live_streaming_pass, secure_stream_tokens
	 */
	public static function mint(string $rData, array $rServers, ?int $rServerID, array $rSettings): string {
		// A node sending the viewer to itself (an off-air redirect): its own key.
		if ($rServerID !== null && defined('SERVER_ID') && $rServerID === (int) SERVER_ID && self::own() !== []) {
			return self::mintOwn($rData, $rSettings);
		}
		$rSecret = (string) ($rSettings['live_streaming_pass'] ?? '');
		$rKey = $rServerID === null ? null : self::keyFor($rServers[$rServerID][self::FP] ?? null, $rSecret, $rServerID);
		return $rKey !== null
			? Encryption::seal($rData, $rKey, OPENSSL_EXTRA)
			: Encryption::mintToken($rData, $rSecret, OPENSSL_EXTRA, !empty($rSettings['secure_stream_tokens']));
	}

	/**
	 * A token this node mints for itself to read back (HLS segment and key
	 * links, timeshift HLS): sealed with its own key once it has one, else the
	 * shared secret's, as mintToken() makes it ($rSecure, or the
	 * secure_stream_tokens setting).
	 *
	 * @param array<string, mixed> $rSettings live_streaming_pass, secure_stream_tokens
	 */
	public static function mintOwn(string $rData, array $rSettings, ?bool $rSecure = null): string {
		$rOwn = self::own()[0] ?? null;
		return $rOwn !== null
			? Encryption::seal($rData, $rOwn, OPENSSL_EXTRA)
			: Encryption::mintToken($rData, $rSettings['live_streaming_pass'] ?? '', OPENSSL_EXTRA, $rSecure ?? !empty($rSettings['secure_stream_tokens']));
	}

	/**
	 * MAIN: the key a node that reports $rFp holds: K_n under $rSecret, or
	 * under the secret it replaced (the node has not applied the rotation yet,
	 * within its window or, offline through it, after). Null without a report
	 * or for any other kid, a key from two rotations back among them.
	 */
	public static function keyFor(mixed $rFp, string $rSecret, int $rServerID, ?int $rNow = null): ?string {
		if (!is_string($rFp) || $rFp === '' || $rSecret === '') {
			return null;
		}
		// The replaced secret past its window too: a node that missed the whole
		// window (offline through the rotation) still holds that key, accepts it
		// until it applies the new one, and reads nothing else.
		foreach ([$rSecret, StreamSecret::replacedValue()] as $rFrom) {
			if ($rFrom !== null && $rFrom !== '') {
				$rKey = self::derive($rFrom, $rServerID);
				if (hash_equals(self::kid($rKey), $rFp)) {
					return $rKey;
				}
			}
		}
		return null;
	}

	/**
	 * Node: its own keys, the current one first, then the one it replaced
	 * while its window is open. Empty on MAIN and on a node without one.
	 *
	 * @return list<string>
	 */
	public static function own(?int $rNow = null): array {
		return array_column(self::held($rNow), 'value');
	}

	/**
	 * own() with the end of each key's window (null: none), for the segment
	 * gateway's policy (GatewayPolicy), which reads them without PHP.
	 *
	 * @return list<array{value: string, until: ?int}>
	 */
	public static function held(?int $rNow = null): array {
		$rOwn = self::read();
		if ($rOwn === null) {
			return [];
		}
		$rKeys = [['value' => $rOwn['current'], 'until' => null]];
		if ($rOwn['previous'] !== null && ($rNow ?? time()) <= (int) $rOwn['previous_valid_until']) {
			$rKeys[] = ['value' => $rOwn['previous'], 'until' => (int) $rOwn['previous_valid_until']];
		}
		return $rKeys;
	}

	/**
	 * Node: keep the `viewer_key` entry MAIN sent (ReplicaSections::secret's
	 * form). True when written, null when it is what the node holds, false
	 * when it could not be written.
	 *
	 * @param array{current: string, kid: string, previous: ?string, previous_valid_until: ?int} $rEntry
	 */
	public static function adopt(array $rEntry): ?bool {
		$rKeep = ['current' => $rEntry['current'], 'previous' => $rEntry['previous'], 'previous_valid_until' => $rEntry['previous_valid_until']];
		if (self::read() === $rKeep) {
			return null;
		}
		if (!self::write(self::file(), (string) json_encode($rKeep))) {
			return false;
		}
		self::$rOwn = $rKeep;
		self::$rReadAt = @filemtime(self::file()) ?: null;
		return true;
	}

	/**
	 * Node: keep the stream secret's hash MAIN sent in place of its value, or
	 * forget it once MAIN sends the value again (null). True when written or
	 * removed, null when nothing changed, false when it could not be written.
	 */
	public static function adoptPassHash(?string $rHash): ?bool {
		$rPath = self::passHashFile();
		if ($rHash === null) {
			return is_file($rPath) ? @unlink($rPath) : null;
		}
		if (self::passHash() === $rHash) {
			return null;
		}
		return self::write($rPath, $rHash);
	}

	/** Node: the stream secret's hash it holds instead of the value, or null. */
	public static function passHash(): ?string {
		$rHash = trim((string) @file_get_contents(self::passHashFile()));
		return preg_match('/^[0-9a-f]{64}\z/', $rHash) ? $rHash : null;
	}

	/**
	 * A legacy password (a relay's or RTMP's `password=`) against the stream
	 * secret: its value where the node holds it ($rKnown), else the hash MAIN
	 * sent in its place.
	 */
	public static function passMatches(mixed $rKnown, mixed $rGiven): bool {
		if (!is_string($rGiven) || $rGiven === '') {
			return false;
		}
		if (is_scalar($rKnown) && (string) $rKnown !== '') {
			return hash_equals((string) $rKnown, $rGiven);
		}
		$rHash = self::passHash();
		return $rHash !== null && hash_equals($rHash, hash('sha256', $rGiven));
	}

	/** A file in the config directory, whole (temp file + rename), 0600, owned like the directory. */
	private static function write(string $rPath, string $rData): bool {
		$rTmp = $rPath . '.' . getmypid() . '.tmp';
		@unlink($rTmp);
		if (!@touch($rTmp)) {
			return false;
		}
		@chmod($rTmp, 0600);
		// The directory's owner, so php-fpm reads it (root's crons apply too).
		$rDir = dirname($rPath);
		if (($rOwner = @fileowner($rDir)) !== false) {
			@chown($rTmp, $rOwner);
		}
		if (($rGroup = @filegroup($rDir)) !== false) {
			@chgrp($rTmp, $rGroup);
		}
		if (@file_put_contents($rTmp, $rData) !== strlen($rData) || !@rename($rTmp, $rPath)) {
			@unlink($rTmp);
			return false;
		}
		return true;
	}

	private static function passHashFile(): string {
		return dirname(self::file()) . '/' . self::PASS_HASH_FILE;
	}

	/** The file the node's keys live in. */
	public static function file(): string {
		return self::$rFile ?? ReplicaApply::configDir() . self::FILE;
	}

	/** Tests: another file, and forget what was read; null restores the default. */
	public static function useFile(?string $rPath): void {
		self::$rFile = $rPath;
		self::$rOwn = null;
		self::$rReadAt = null;
	}

	/**
	 * The file's entry, re-read when it changed since (an apply writes it, and
	 * php-fpm reads it): one stat per call.
	 *
	 * @return array{current: string, previous: ?string, previous_valid_until: ?int}|null
	 */
	private static function read(): ?array {
		$rMtime = @filemtime(self::file());
		$rMtime = $rMtime === false ? null : $rMtime;
		if (self::$rOwn === null || self::$rReadAt !== $rMtime) {
			self::$rReadAt = $rMtime;
			$rDoc = $rMtime === null ? null : json_decode((string) @file_get_contents(self::file()), true);
			$rPrevious = is_array($rDoc) ? ($rDoc['previous'] ?? null) : null;
			self::$rOwn = is_array($rDoc) && is_string($rDoc['current'] ?? null) && $rDoc['current'] !== '' && ($rPrevious === null || is_string($rPrevious))
				? ['current' => $rDoc['current'], 'previous' => $rPrevious, 'previous_valid_until' => isset($rDoc['previous_valid_until']) ? (int) $rDoc['previous_valid_until'] : null]
				: false;
		}
		return self::$rOwn === false ? null : self::$rOwn;
	}
}
