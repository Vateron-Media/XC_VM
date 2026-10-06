<?php

namespace XcVm\Domain\Backup;

use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;

/**
 * The recovery bundle: what a database dump leaves out and a new MAIN needs —
 * config.ini (the database credentials), the modules' configuration and,
 * when the cluster API is set up, the cluster's key export — encrypted with a
 * passphrase (Argon2id, then XSalsa20-Poly1305). cron:backups makes one a day
 * when a passphrase is set and copies it to the backup targets beside the
 * dumps; `console.php backup:open-bundle` opens one.
 *
 * The passphrase is kept in config/backup_bundle.pass (xc_vm only), never in
 * the database, so no dump carries it; keep a copy of it off this machine.
 * Files sealed to this machine (config.enc, install_id, the licence) are not
 * in the bundle: they are no use anywhere else.
 *
 * @package XC_VM_Domain_Backup
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class RecoveryBundle {
	public const MAGIC = "XCVMBNDL1\n";

	/** The configuration files it holds, under CONFIG_PATH. */
	public const FILES = ['config.ini', 'modules.php', 'bundled_modules.php'];

	/** A passphrase this long at least (the cluster key export asks as much). */
	public const MIN_LENGTH = 20;

	public static function passphraseFile(): string {
		return CONFIG_PATH . 'backup_bundle.pass';
	}

	public static function passphrase(): ?string {
		$rPass = @file_get_contents(self::passphraseFile());
		return is_string($rPass) && strlen(trim($rPass)) >= self::MIN_LENGTH ? trim($rPass) : null;
	}

	/** Set (or with '', clear) the passphrase. False: too short. */
	public static function setPassphrase(string $rPass): bool {
		$rPass = trim($rPass);
		if ($rPass === '') {
			@unlink(self::passphraseFile());
			return true;
		}
		if (strlen($rPass) < self::MIN_LENGTH) {
			return false;
		}
		$rFile = self::passphraseFile();
		$rTemp = $rFile . '.tmp';
		if (file_put_contents($rTemp, $rPass, LOCK_EX) === false) {
			return false;
		}
		chmod($rTemp, 0600);
		return rename($rTemp, $rFile);
	}

	/**
	 * The bundle's files: the configuration files, and the cluster key export
	 * when the cluster API is set up (it can be refused: the bundle goes without it).
	 *
	 * @return array<string, string> name => content
	 */
	public static function files(string $rPass): array {
		$rFiles = [];
		foreach (self::FILES as $rName) {
			if (is_readable(CONFIG_PATH . $rName)) {
				$rFiles[$rName] = (string) file_get_contents(CONFIG_PATH . $rName);
			}
		}
		try {
			if (class_exists(ClusterCryptoFactory::class) && ClusterCryptoFactory::available()) {
				$rFiles['cluster-keys.export'] = ClusterCryptoFactory::create()->exportKeys($rPass);
			}
		} catch (\Throwable) {
			// No cluster root here, or the export refused: the files still go.
		}
		return $rFiles;
	}

	/** @param array<string, string> $rFiles */
	public static function seal(array $rFiles, string $rPass, int $rNow): string {
		$rSalt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
		$rKey = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $rPass, $rSalt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
		$rNonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$rPlain = (string) json_encode(['made' => $rNow, 'files' => array_map('base64_encode', $rFiles)]);
		$rSealed = self::MAGIC . $rSalt . $rNonce . sodium_crypto_secretbox($rPlain, $rNonce, $rKey);
		sodium_memzero($rKey);
		return $rSealed;
	}

	/**
	 * Open a bundle.
	 *
	 * @return array{made: int, files: array<string, string>}|null null: not a bundle, or the wrong passphrase
	 */
	public static function open(string $rBundle, string $rPass): ?array {
		$rHead = strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES + SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;
		if (!str_starts_with($rBundle, self::MAGIC) || strlen($rBundle) <= $rHead) {
			return null;
		}
		$rSalt = substr($rBundle, strlen(self::MAGIC), SODIUM_CRYPTO_PWHASH_SALTBYTES);
		$rNonce = substr($rBundle, strlen(self::MAGIC) + SODIUM_CRYPTO_PWHASH_SALTBYTES, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
		$rKey = sodium_crypto_pwhash(SODIUM_CRYPTO_SECRETBOX_KEYBYTES, $rPass, $rSalt, SODIUM_CRYPTO_PWHASH_OPSLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_MEMLIMIT_MODERATE, SODIUM_CRYPTO_PWHASH_ALG_ARGON2ID13);
		$rPlain = sodium_crypto_secretbox_open(substr($rBundle, $rHead), $rNonce, $rKey);
		sodium_memzero($rKey);
		$rData = $rPlain === false ? null : json_decode($rPlain, true);
		if (!is_array($rData) || !is_array($rData['files'] ?? null)) {
			return null;
		}
		return ['made' => (int) ($rData['made'] ?? 0), 'files' => array_map(static fn($rValue): string => (string) base64_decode((string) $rValue, true), $rData['files'])];
	}

	/** Write today's bundle to backups/recovery_<date>.bundle; null without a passphrase or when it cannot be written. */
	public static function make(int $rNow): ?string {
		$rPass = self::passphrase();
		if ($rPass === null) {
			return null;
		}
		$rFile = MAIN_HOME . 'backups/recovery_' . date('Y-m-d_H-i-s', $rNow) . '.bundle';
		if (file_put_contents($rFile, self::seal(self::files($rPass), $rPass, $rNow)) === false) {
			return null;
		}
		chmod($rFile, 0600);
		return $rFile;
	}

	/** The newest local bundle's time, or 0. */
	public static function newest(): int {
		$rFiles = glob(MAIN_HOME . 'backups/recovery_*.bundle') ?: [];
		return $rFiles === [] ? 0 : max(array_map('filemtime', $rFiles));
	}
}
