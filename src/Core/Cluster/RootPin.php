<?php

namespace XcVm\Core\Cluster;

use XcVm\Core\Cluster\Crypto\Canonical;
use XcVm\Core\Cluster\Crypto\PanelSig;

/**
 * The node's root-owned trust anchor for root commands (plan, section 7,
 * "Root handoff"), and the hand-off between the agent's side (xc_vm) and
 * cluster:root (root).
 *
 * ```text
 * /etc/xc_vm/cluster/            root:root, not group/other writable
 *   main_sign.pub                the panel signing key, hex
 *   node                         this node's uuid
 *   root.seq                     the highest command seq root has run
 * config/cluster/root-inbox/     xc_vm: <seq>.json in (command + sig), <seq>.done out
 * ```
 *
 * The agent's own copy of the panel key lives in files xc_vm can write, so a
 * compromised panel process could plant a key; root trusts only the copy in
 * /etc, written over SSH at install (or by `cluster:pin-root` with the
 * fingerprint read on MAIN). Everything root reads from the inbox is data:
 * a command runs only when its signature verifies under the pinned key.
 */
final class RootPin {
	public const DIR = '/etc/xc_vm/cluster/';

	/** Largest command file root reads from the inbox. */
	public const MAX_COMMAND = 65536;

	private static ?string $rDir = null;

	private static ?string $rInbox = null;

	/** Tests: other directories; null restores the defaults. */
	public static function useDirs(?string $rDir, ?string $rInbox): void {
		self::$rDir = $rDir;
		self::$rInbox = $rInbox;
	}

	public static function dir(): string {
		return self::$rDir ?? self::DIR;
	}

	public static function inbox(): string {
		return self::$rInbox ?? AgentPaths::file(AgentPaths::DIR . 'root-inbox/');
	}

	/**
	 * The pin, when it is in place and safe to trust: the directory owned by
	 * root (unless tests moved it) and writable by nobody else.
	 *
	 * @return array{pub: string, node: string}|null
	 */
	public static function read(): ?array {
		$rDir = self::dir();
		// stat() is cached per literal path string (not per resolved file), so a
		// permission change made via a differently-formed path (or simply a
		// repeated call within this same long-running process, e.g. the drain
		// loop in ClusterRootCommand) would otherwise go unnoticed for the rest
		// of the process — unacceptable for a trust check.
		clearstatcache(true, $rDir);
		$rStat = @stat($rDir);
		if ($rStat === false || is_link(rtrim($rDir, '/')) || ($rStat['mode'] & 0022) !== 0 || (self::$rDir === null && $rStat['uid'] !== 0)) {
			return null;
		}
		$rPub = @hex2bin(trim((string) @file_get_contents($rDir . 'main_sign.pub')));
		$rNode = trim((string) @file_get_contents($rDir . 'node'));
		if ($rPub === false || strlen($rPub) !== 32 || !Canonical::validUuid($rNode)) {
			return null;
		}
		return ['pub' => $rPub, 'node' => $rNode];
	}

	/** Write the pin (root; the install flow does the same over SSH). */
	public static function write(string $rPub, string $rNode): bool {
		$rDir = self::dir();
		if (!is_dir($rDir) && !@mkdir($rDir, 0755, true)) {
			return false;
		}
		@chmod($rDir, 0755);
		@unlink($rDir . 'root.seq'); // a new pin starts root's seq afresh (a re-enrolled node)
		return @file_put_contents($rDir . 'main_sign.pub', bin2hex($rPub) . "\n") !== false
			&& @file_put_contents($rDir . 'node', $rNode . "\n") !== false
			&& @chmod($rDir . 'main_sign.pub', 0644) && @chmod($rDir . 'node', 0644);
	}

	/**
	 * `node.root rotate_sign_key {new_pub, pin_blob?}` (ADR 0004, Phase 9;
	 * plan, section 7): re-pin MAIN's panel signing key without SSH. The
	 * command itself was verified under the key pinned now (cluster:root), so
	 * only the MAIN that holds this node's trust can hand it on — to a
	 * replacement MAIN after `cluster:init`, whose fingerprint the operator
	 * read there. root.seq starts afresh, as for any new pin: the new key's
	 * commands are a new stream.
	 *
	 * The agent's own copy of the key is not touched: it no longer matches, so
	 * the node reports root_ready false and MAIN sends it no root command until
	 * its agent holds the new key too (an enrolment by code, or cluster:reenrol).
	 *
	 * `pin_blob` is the extension's own pin (`cluster_pack` for this node's
	 * install_id, from the new MAIN), replaced through `cluster_pin($blob,
	 * true)` when the extension has it; refused before anything changes when
	 * it does not open.
	 *
	 * @param array<string, mixed> $rData
	 * @throws \RuntimeException refused (the ack carries the message)
	 */
	public static function rotate(array $rData): string {
		$rPub = is_string($rData['new_pub'] ?? null) && preg_match('/^[0-9a-f]{64}$/', $rData['new_pub']) ? (string) hex2bin($rData['new_pub']) : null;
		$rPin = self::read();
		if ($rPub === null || $rPin === null) {
			throw new \RuntimeException('rotate_sign_key: ' . ($rPub === null ? 'new_pub is not a 32-byte hex key' : 'no pin in place'));
		}
		if (hash_equals($rPin['pub'], $rPub)) {
			return 'rotate_sign_key: already pinned (' . self::fingerprint($rPub) . ')';
		}
		if (isset($rData['pin_blob'])) {
			$rBlob = is_string($rData['pin_blob']) ? base64_decode($rData['pin_blob'], true) : false;
			if ($rBlob === false || !method_exists('XC_VM', 'cluster_pin')) {
				throw new \RuntimeException('rotate_sign_key: the extension pin cannot be replaced here');
			}
			$rPinned = call_user_func(['XC_VM', 'cluster_pin'], $rBlob, true);
			if (!is_array($rPinned) || !hash_equals((string) ($rPinned['panel_sign_pub'] ?? ''), $rPub)) {
				throw new \RuntimeException('rotate_sign_key: the extension refused the pin blob, or it names another key');
			}
		}
		if (!self::write($rPub, $rPin['node'])) {
			throw new \RuntimeException('rotate_sign_key: cannot write ' . self::dir());
		}
		return 'Panel signing key re-pinned: ' . self::fingerprint($rPub) . ' (was ' . self::fingerprint($rPin['pub']) . ')';
	}

	/** A panel key's fingerprint as the operator compares it: SHA-256, hex. */
	public static function fingerprint(string $rPub): string {
		return hash('sha256', $rPub);
	}

	/** Does the agent's panel key match root's pin? (the agent reports this) */
	public static function matches(string $rAgentPub, string $rNode): bool {
		$rPin = self::read();
		return $rPin !== null && hash_equals($rPin['pub'], $rAgentPub) && $rPin['node'] === $rNode;
	}

	/**
	 * Check a command against the pin: panel signature (tag `cmd`), a
	 * `node.root` for this node, a known action (the envelope's `action`, as
	 * the extension classes it; none among the arguments), not stale, and
	 * above root's own high-water.
	 *
	 * @param array{pub: string, node: string} $rPin
	 * @return array<string, mixed>|string the command, or why it is refused
	 */
	public static function verify(array $rPin, string $rDoc, string $rSig, int $rNow, int $rHighWater): array|string {
		if (!PanelSig::verify($rPin['pub'], 'cmd', $rDoc, $rSig)) {
			return 'bad signature';
		}
		$rCmd = json_decode($rDoc, true);
		if (!is_array($rCmd) || ($rCmd['type'] ?? null) !== 'node.root' || ($rCmd['node_uuid'] ?? null) !== $rPin['node']) {
			return 'not a root command for this node';
		}
		if ((int) ($rCmd['exp'] ?? 0) + 300 < $rNow) {
			return 'expired';
		}
		if ((int) ($rCmd['seq'] ?? 0) <= $rHighWater) {
			return 'seq not above ' . $rHighWater;
		}
		if (!is_array($rCmd['args'] ?? null) || array_key_exists('action', $rCmd['args'])
			|| !NodeActions::valid(['action' => $rCmd['action'] ?? null] + $rCmd['args'])) {
			return 'unknown root action';
		}
		return $rCmd;
	}

	public static function highWater(): int {
		return (int) trim((string) @file_get_contents(self::dir() . 'root.seq'));
	}

	public static function raiseHighWater(int $rSeq): bool {
		return @file_put_contents(self::dir() . 'root.seq', (string) $rSeq, LOCK_EX) !== false;
	}

	/**
	 * Write a result where xc_vm can read it, without following anything
	 * planted at that path: whatever is there is removed, and the file is
	 * created exclusively.
	 */
	public static function writeDone(string $rPath, string $rJson): bool {
		if (is_link($rPath) || file_exists($rPath)) {
			@unlink($rPath);
		}
		$rHandle = @fopen($rPath, 'x');
		if ($rHandle === false) {
			return false;
		}
		fwrite($rHandle, $rJson);
		fclose($rHandle);
		@chmod($rPath, 0644);
		return true;
	}
}
