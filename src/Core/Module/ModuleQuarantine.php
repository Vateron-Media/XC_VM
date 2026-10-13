<?php

namespace XcVm\Core\Module;

/**
 * Keeps a module whose code dies with an uncatchable fatal error out of every
 * later process.
 *
 * try/catch cannot stop a fatal raised while PHP compiles or decodes a file: a
 * parse error, an ionCube file without its licence, an expired licence. One
 * such module used to take the whole panel and every console.php command down.
 * A shutdown function now reads the fatal, and when the file (or, for the
 * loader's errors, the message) points inside a module's directory, it records
 * that directory here together with a fingerprint of its files. ModuleLoader
 * skips a recorded module, so only the process that hit the fatal is lost.
 *
 * The record lifts itself when the module's files change (an update, a
 * rollback, a .lic written next to the encoded files): the fingerprint no
 * longer matches and the module is tried again.
 *
 * Runtime limits (memory, execution time) and uncaught exceptions are not
 * defects of the module's files and never quarantine it.
 *
 * Stored as `.quarantine.json` in the modules directory, which the loader's
 * `<dir>/module.json` glob does not see.
 *
 * @package XC_VM_Core_Module
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
final class ModuleQuarantine {
	public const FILE = '.quarantine.json';

	private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

	/** @var array<string, true> Modules directories a shutdown check is registered for. */
	private static array $watched = [];

	/** Record the module behind this process's fatal error, if there is one, when it ends. */
	public static function watch(string $modulesDir): void {
		$modulesDir = rtrim($modulesDir, '/');
		if (isset(self::$watched[$modulesDir])) {
			return;
		}
		self::$watched[$modulesDir] = true;
		register_shutdown_function(static function () use ($modulesDir): void {
			$error = error_get_last();
			if ($error !== null) {
				self::recordFatal($modulesDir, $error);
			}
		});
	}

	/**
	 * Quarantine the module a fatal error came from. Returns the module's
	 * directory name, or null when the error is not one that quarantines.
	 *
	 * @param array{type: int, message: string, file: string, line: int} $error As error_get_last() returns it.
	 */
	public static function recordFatal(string $modulesDir, array $error): ?string {
		$message = (string) $error['message'];
		if (($error['type'] & self::FATAL) === 0
			|| str_starts_with($message, 'Uncaught ')
			|| str_contains($message, 'Allowed memory size')
			|| str_contains($message, 'Maximum execution time')
		) {
			return null;
		}

		// The ionCube loader reports "in Unknown on line 0" and names the file in
		// the message (wrapped in <b> under FPM).
		$prefix  = rtrim($modulesDir, '/') . '/';
		$haystack = (string) $error['file'] . "\n" . strip_tags($message);
		if (!preg_match('#' . preg_quote($prefix, '#') . '([^/\s]+)/#', $haystack, $m) || !is_dir($prefix . $m[1])) {
			return null;
		}

		self::record($modulesDir, $m[1], trim(strip_tags($message)));
		return $m[1];
	}

	/** Quarantine a module directory for the files it holds now. */
	public static function record(string $modulesDir, string $dir, string $reason): void {
		$entries        = self::read($modulesDir);
		$entries[$dir]  = [
			'fingerprint' => self::fingerprint(rtrim($modulesDir, '/') . '/' . $dir),
			'reason'      => mb_substr($reason, 0, 500),
			'at'          => time(),
		];
		error_log("ModuleQuarantine: module '{$dir}' quarantined after a fatal error: {$reason}");
		self::write($modulesDir, $entries);
	}

	/**
	 * Why a module directory must not load, or null when it may. A record whose
	 * files have changed since is dropped.
	 */
	public static function reason(string $modulesDir, string $dir): ?string {
		$entries = self::read($modulesDir);
		if (!isset($entries[$dir])) {
			return null;
		}
		if ($entries[$dir]['fingerprint'] === self::fingerprint(rtrim($modulesDir, '/') . '/' . $dir)) {
			return $entries[$dir]['reason'];
		}
		unset($entries[$dir]);
		error_log("ModuleQuarantine: module '{$dir}' changed on disk — lifting its quarantine");
		self::write($modulesDir, $entries);
		return null;
	}

	/**
	 * Every file under the directory, by path, size and mtime.
	 *
	 * ponytail: walks the whole module tree, so it runs only for a module that
	 * is already quarantined (one is_file() otherwise).
	 */
	private static function fingerprint(string $path): string {
		if (!is_dir($path)) {
			return '';
		}
		$lines = [];
		$files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
		foreach ($files as $file) {
			$lines[] = substr($file->getPathname(), strlen($path)) . ':' . $file->getSize() . ':' . $file->getMTime();
		}
		sort($lines, SORT_STRING);
		return hash('sha256', implode("\n", $lines));
	}

	/** @return array<string, array{fingerprint: string, reason: string, at: int}> */
	private static function read(string $modulesDir): array {
		$file = rtrim($modulesDir, '/') . '/' . self::FILE;
		if (!is_file($file) || is_link($file)) {
			return [];
		}
		$data = json_decode((string) @file_get_contents($file), true);
		return is_array($data) ? array_filter($data, static fn($e): bool => is_array($e) && isset($e['fingerprint'], $e['reason'])) : [];
	}

	/** @param array<string, array{fingerprint: string, reason: string, at: int}> $entries */
	private static function write(string $modulesDir, array $entries): void {
		$file = rtrim($modulesDir, '/') . '/' . self::FILE;
		if ($entries === []) {
			@unlink($file);
			return;
		}
		// Root (console.php crons) writes into a directory the panel user owns, so
		// nothing here may follow a link that user planted: a fresh file created
		// exclusively, handed to the directory's owner with lchown(), then renamed
		// over the target (rename replaces a link, it never writes through it).
		$tmp  = $file . '.' . bin2hex(random_bytes(6)) . '.tmp';
		$mask = umask(0022);
		$fh   = @fopen($tmp, 'x');
		umask($mask);
		if ($fh === false) {
			error_log("ModuleQuarantine: cannot write {$file}");
			return;
		}
		fwrite($fh, (string) json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
		fclose($fh);
		$owner = @fileowner(dirname($file));
		if ($owner !== false && function_exists('posix_geteuid') && posix_geteuid() === 0) {
			@lchown($tmp, $owner);
		}
		if (!@rename($tmp, $file)) {
			@unlink($tmp);
			error_log("ModuleQuarantine: cannot write {$file}");
		}
	}
}
