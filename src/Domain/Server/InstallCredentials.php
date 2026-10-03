<?php

namespace XcVm\Domain\Server;

/**
 * Install Credentials
 *
 * How the SSH root credentials reach `server:install`. They used to travel on
 * the command line (visible in `ps` and /proc/<pid>/cmdline for the whole
 * install) and were kept in a plaintext `bin/install/<id>.json` for reinstalls.
 * Now the panel writes them to a 0600 `bin/install/<id>.cred`, passes only its
 * path (`--cred-file`), and the command reads and deletes it before it
 * connects. `<id>.json` keeps the non-secret parameters only.
 *
 * The node's SSH host key is checked too: an optional expected SHA-1
 * fingerprint from the admin, else the one stored from the node's first install
 * (`servers.ssh_hostkey_sha1`), else trust on first use, as before. An admin
 * who reinstalls a rebuilt node may have the stored one forgotten for that
 * install (`--forget-hostkey`): the key the node presents is then stored.
 *
 * @package XC_VM_Domain_Server
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

final class InstallCredentials {
	public static function dir(): string {
		return BIN_PATH . 'install/';
	}

	/** Write `<dir><id>.cred` (0600, never world- or group-readable) and return its path. */
	public static function write(int $rServerID, string $rUsername, string $rPassword, ?string $rDir = null): string {
		$rDir ??= self::dir();
		if (!is_dir($rDir)) {
			@mkdir($rDir, 0750, true);
		}
		$rPath = $rDir . $rServerID . '.cred';
		@unlink($rPath);
		$rOld = umask(0077);
		try {
			file_put_contents($rPath, json_encode(['u' => $rUsername, 'p' => $rPassword]), LOCK_EX);
		} finally {
			umask($rOld);
		}
		@chmod($rPath, 0600);
		return $rPath;
	}

	/**
	 * Read and delete a credential file written by write(). Only `<id>.cred`
	 * files directly in the install directory are accepted, so the option
	 * cannot be pointed at another file.
	 *
	 * @return array{username: string, password: string}|null
	 */
	public static function consume(string $rPath, ?string $rDir = null): ?array {
		$rDir = realpath($rDir ?? self::dir());
		$rReal = realpath($rPath);
		if ($rDir === false || $rReal === false || dirname($rReal) !== $rDir || !preg_match('/^\d+\.cred$/', basename($rReal))) {
			return null;
		}
		$rData = json_decode((string) @file_get_contents($rReal), true);
		@unlink($rReal);
		if (!is_array($rData) || !is_string($rData['u'] ?? null) || !is_string($rData['p'] ?? null)) {
			return null;
		}
		return ['username' => $rData['u'], 'password' => $rData['p']];
	}

	/**
	 * The background `server:install` command line for the panel to run. The
	 * credentials go to the cred file; argv carries "-" in their place.
	 *
	 * @param list<string> $rTail Remaining positional arguments, already shell-safe.
	 */
	public static function command(int $rType, int $rServerID, int $rPort, string $rUsername, string $rPassword, array $rTail = [], string $rExpectedHostKey = '', bool $rForgetHostKey = false): string {
		$rCred = self::write($rServerID, $rUsername, $rPassword);
		$rCommand = PHP_BIN . ' ' . MAIN_HOME . 'console.php server:install ' . $rType . ' ' . $rServerID . ' ' . $rPort . ' - -';
		foreach ($rTail as $rArg) {
			$rCommand .= ' ' . $rArg;
		}
		$rCommand .= ' --cred-file=' . escapeshellarg($rCred);
		$rHostKey = self::normalizeHostKey($rExpectedHostKey);
		if ($rHostKey !== null) {
			$rCommand .= ' --expect-hostkey=' . $rHostKey;
		}
		if ($rForgetHostKey) {
			// The admin says the node was rebuilt: the key stored at its first install is not asked for.
			$rCommand .= ' --forget-hostkey=1';
		}
		return $rCommand . ' > "' . self::dir() . $rServerID . '.install" 2>/dev/null &';
	}

	/**
	 * Drop `root_password` from `<id>.json` files written before the password
	 * moved out of them. Returns how many files were rewritten.
	 */
	public static function scrubLegacyMetadata(?string $rDir = null): int {
		$rCount = 0;
		foreach (glob(($rDir ?? self::dir()) . '*.json') ?: [] as $rFile) {
			if (!preg_match('/^\d+\.json$/', basename($rFile))) {
				continue;
			}
			$rData = json_decode((string) @file_get_contents($rFile), true);
			if (is_array($rData) && array_key_exists('root_password', $rData)) {
				unset($rData['root_password']);
				file_put_contents($rFile, json_encode($rData), LOCK_EX);
				$rCount++;
			}
		}
		return $rCount;
	}

	/**
	 * Split `--name=value` options out of server:install's arguments.
	 *
	 * @param list<string> $rArgs
	 * @return array{0: list<string>, 1: array<string, string>} [positional, options]
	 */
	public static function splitOptions(array $rArgs): array {
		$rPositional = [];
		$rOptions = [];
		foreach ($rArgs as $rArg) {
			if (is_string($rArg) && preg_match('/^--([a-z-]+)=(.*)$/s', $rArg, $rMatch)) {
				$rOptions[$rMatch[1]] = $rMatch[2];
			} else {
				$rPositional[] = $rArg;
			}
		}
		return [$rPositional, $rOptions];
	}

	/**
	 * A SHA-1 host-key fingerprint as 40 lowercase hex digits. Accepts plain or
	 * colon-separated hex (libssh2, PuTTY) and `ssh-keygen -l -E sha1` output
	 * (`SHA1:<base64>`); anything else is null.
	 */
	public static function normalizeHostKey(string $rInput): ?string {
		$rInput = trim($rInput);
		if ($rInput === '') {
			return null;
		}
		if (preg_match('/SHA1:([A-Za-z0-9+\/]{27}=?)/', $rInput, $rMatch)) {
			$rRaw = base64_decode(rtrim($rMatch[1], '=') . '=', true);
			return ($rRaw !== false && strlen($rRaw) === 20) ? bin2hex($rRaw) : null;
		}
		$rHex = strtolower(str_replace(':', '', $rInput));
		return preg_match('/^[0-9a-f]{40}$/', $rHex) ? $rHex : null;
	}

	/**
	 * Decide whether the presented host key may be used.
	 *
	 * @return string|null null to proceed; otherwise the refusal to report.
	 */
	public static function checkHostKey(string $rPresented, ?string $rExpected, ?string $rStored): ?string {
		$rPresented = self::normalizeHostKey($rPresented);
		if ($rPresented === null) {
			return 'the server presented no usable SSH host key';
		}
		if ($rExpected !== null && $rExpected !== '') {
			return hash_equals($rExpected, $rPresented) ? null : "SSH host key mismatch: expected {$rExpected}, got {$rPresented}";
		}
		if ($rStored !== null && $rStored !== '' && !hash_equals($rStored, $rPresented)) {
			return "SSH host key changed since this node was installed (stored {$rStored}, got {$rPresented}). If the node was rebuilt, enter its new fingerprint as the expected host key, or switch on \"Forget the saved SSH host key\", and retry";
		}
		return null;
	}
}
