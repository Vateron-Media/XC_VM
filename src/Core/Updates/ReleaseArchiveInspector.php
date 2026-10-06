<?php

namespace XcVm\Core\Updates;

use XcVm\Core\Process\ProcessRunner;

/**
 * ReleaseArchiveInspector — peeks inside a downloaded release .tar.gz without
 * extracting the whole thing.
 *
 * Used by the version rollback flow to read the TARGET version's
 * migrations/*.sql file list straight out of the already-downloaded release
 * asset (the same archive that gets swapped in), so MigrationRunner::rollback()
 * can diff it against what is currently recorded as applied — no separate
 * network call (e.g. a GitHub contents-API request) is needed.
 *
 * Extraction mirrors ModuleManager::extractTarArchive()'s PharData→`tar` CLI
 * fallback (src/Core/Module/ModuleManager.php) — kept as a small self-contained
 * copy here rather than a shared abstraction, since there are only these two
 * call sites.
 *
 * @package XC_VM_Core_Updates
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ReleaseArchiveInspector {
	/**
	 * List the basenames of files directly under $subpath inside a .tar.gz.
	 *
	 * @param string $archivePath Absolute path to the .tar.gz.
	 * @param string $subpath     Top-level directory inside the archive (e.g. 'migrations').
	 * @return string[] Basenames found under $subpath (empty if the archive has none).
	 * @throws \RuntimeException If the archive cannot be read at all.
	 */
	public static function listSubpathFiles(string $archivePath, string $subpath): array {
		if (!is_file($archivePath)) {
			throw new \RuntimeException('Archive not found: ' . $archivePath);
		}

		$tmpDir = sys_get_temp_dir() . '/xcvm_archive_' . bin2hex(random_bytes(6));
		mkdir($tmpDir, 0700, true);

		try {
			self::extractSubpath($archivePath, $subpath, $tmpDir);

			$files = glob($tmpDir . '/' . $subpath . '/*') ?: [];
			return array_map('basename', $files);
		} finally {
			self::rrmdir($tmpDir);
		}
	}

	/**
	 * Best-effort extraction of $subpath via PharData or the `tar` CLI. A
	 * $subpath that simply isn't present in the archive is NOT an error (e.g.
	 * a very old release predating the migrations/ folder) — the destination
	 * directory is just absent afterward, and listSubpathFiles() returns [].
	 * A genuinely unreadable/corrupt archive still throws.
	 *
	 * A release built with `tar -C <dir> .` names its members './<path>', a plain
	 * tar '<path>/', and PharData answers true for a spelling it extracts nothing
	 * for: each is tried until one leaves files.
	 */
	private static function extractSubpath(string $archivePath, string $subpath, string $destination): void {
		if (class_exists('PharData')) {
			try {
				$rPhar = new \PharData($archivePath);
			} catch (\Throwable $e) {
				$rPhar = null; // the CLI says whether it is readable
			}
			if ($rPhar !== null) {
				foreach (['./' . $subpath . '/', $subpath . '/', $subpath] as $rMember) {
					try {
						$rPhar->extractTo($destination, $rMember, true);
					} catch (\Throwable $e) {
						continue;
					}
					if (glob($destination . '/' . $subpath . '/*')) {
						return;
					}
				}
				return;
			}
		}

		if (self::hasBinary('tar')) {
			// Validate the archive is readable at all via a cheap listing —
			// this is what should fail loudly, not a missing member.
			exec('tar -tzf ' . escapeshellarg($archivePath) . ' >/dev/null 2>&1', $out, $code);
			if ($code !== 0) {
				throw new \RuntimeException('Cannot read archive: ' . basename($archivePath) . ' (tar -t failed).');
			}
			// Best-effort extraction of the one member; ignore its own exit
			// code — a missing member (or spelling) is expected to leave nothing extracted.
			ProcessRunner::run(['tar', '-xf', $archivePath, '-C', $destination, './' . $subpath, $subpath], true);
			return;
		}

		throw new \RuntimeException('Cannot read archive: PharData is unavailable and the `tar` command is missing.');
	}

	/** True if $bin resolves on PATH. */
	private static function hasBinary(string $bin): bool {
		$out = @shell_exec('command -v ' . escapeshellarg($bin) . ' 2>/dev/null');
		return is_string($out) && trim($out) !== '';
	}

	/** Recursively remove a scratch directory. */
	private static function rrmdir(string $dir): void {
		if (!is_dir($dir)) {
			return;
		}
		foreach (scandir($dir) ?: [] as $entry) {
			if ($entry === '.' || $entry === '..') {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir($path) ? self::rrmdir($path) : unlink($path);
		}
		rmdir($dir);
	}
}
