<?php

namespace XcVm\Streaming\Codec;

/**
 * FfmpegBinaries — discovery + capability probe for the bundled FFmpeg builds.
 *
 * Scans BIN_PATH/ffmpeg_bin/<version>/ for real version directories (named
 * `<major>.<minor>`, so `*.backup.*` folders are ignored) that ship an
 * executable `ffmpeg`, and records per build: the version banner, whether it can
 * encode on the GPU, and the list of hardware encoders it exposes.
 *
 * Probing shells out to each binary (`-hwaccels`, `-encoders`), which is slow, so
 * the result is cached to a JSON file keyed by a fingerprint of the directory set
 * and their mtimes — a binary swap (e.g. by BinariesCommand) changes the mtime and
 * transparently invalidates the cache. This class is UI-only (settings page); the
 * streaming hot path resolves paths cheaply via {@see FfmpegPaths} without scanning.
 *
 * @package XC_VM_Streaming_Codec
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FfmpegBinaries {

	/** Encoder-name substrings that mark a hardware (GPU/accelerated) encoder. */
	private const HW_ENCODER_MARKERS = ['_nvenc', 'nvenc_', '_qsv', '_vaapi', '_amf', '_videotoolbox', '_mf'];

	/** @var array<string, array{version: string, gpu: bool, encoders: string[], banner: string}>|null */
	private static ?array $cache = null;

	/** Base directory holding the per-version ffmpeg build folders. */
	private static function baseDir(): string {
		return BIN_PATH . 'ffmpeg_bin/';
	}

	/** Absolute path to the capabilities cache file. */
	private static function cacheFile(): string {
		return (defined('TMP_PATH') ? TMP_PATH : sys_get_temp_dir() . '/') . 'ffmpeg_capabilities.json';
	}

	/**
	 * All discovered builds, newest version first.
	 *
	 * @return array<string, array{version: string, gpu: bool, encoders: string[], banner: string}>
	 *         Keyed by version string (the folder name, e.g. '8.0').
	 */
	public static function available(): array {
		if (self::$cache !== null) {
			return self::$cache;
		}

		$rFingerprint = self::fingerprint();
		$rCached = self::readCache();
		if ($rCached !== null && ($rCached['fingerprint'] ?? null) === $rFingerprint && isset($rCached['versions']) && is_array($rCached['versions'])) {
			return self::$cache = $rCached['versions'];
		}

		$rVersions = self::scan();
		self::writeCache($rFingerprint, $rVersions);
		return self::$cache = $rVersions;
	}

	/**
	 * Only the builds that can encode on the GPU, newest first.
	 *
	 * @return array<string, array{version: string, gpu: bool, encoders: string[], banner: string}>
	 */
	public static function gpuCapable(): array {
		return array_filter(self::available(), static fn (array $rInfo): bool => $rInfo['gpu']);
	}

	/** Whether a given version folder exists with an executable ffmpeg. */
	public static function has(string $rVersion): bool {
		return isset(self::available()[$rVersion]);
	}

	/**
	 * Cheap fingerprint of the build folders (names + mtimes) — no shelling out.
	 * A binary swap bumps the folder mtime and changes this, invalidating the cache.
	 */
	private static function fingerprint(): string {
		$rParts = array();
		foreach (self::versionDirs() as $rVersion => $rDir) {
			$rParts[] = $rVersion . ':' . (@filemtime($rDir . 'ffmpeg') ?: 0);
		}
		sort($rParts);
		return md5(implode('|', $rParts));
	}

	/**
	 * Version folders (name matches `<int>.<int>`) that ship an executable ffmpeg.
	 *
	 * @return array<string, string> version => absolute directory path (trailing slash)
	 */
	private static function versionDirs(): array {
		$rBase = self::baseDir();
		$rOut = array();
		foreach (glob($rBase . '*', GLOB_ONLYDIR) ?: array() as $rDir) {
			$rVersion = basename($rDir);
			if (!preg_match('/^\d+\.\d+$/', $rVersion)) {
				continue; // skip backups / non-version folders
			}
			$rBinary = $rDir . '/ffmpeg';
			if (is_file($rBinary)) {
				$rOut[$rVersion] = $rDir . '/';
			}
		}
		uksort($rOut, static fn (string $a, string $b): int => version_compare($b, $a));
		return $rOut;
	}

	/**
	 * Probe every build's capabilities by shelling out. Slow — callers cache it.
	 *
	 * @return array<string, array{version: string, gpu: bool, encoders: string[], banner: string}>
	 */
	private static function scan(): array {
		$rOut = array();
		foreach (self::versionDirs() as $rVersion => $rDir) {
			$rBinary = $rDir . 'ffmpeg';
			$rEncoders = self::hwEncoders($rBinary);
			$rOut[$rVersion] = array(
				'version'  => $rVersion,
				'gpu'      => count($rEncoders) > 0,
				'encoders' => $rEncoders,
				'banner'   => self::banner($rBinary),
			);
		}
		return $rOut;
	}

	/** First line of `ffmpeg -version` (the human-readable build banner). */
	private static function banner(string $rBinary): string {
		$rOut = (string) @shell_exec('timeout 5 ' . escapeshellarg($rBinary) . ' -hide_banner -version 2>/dev/null');
		$rLine = trim(strtok($rOut, "\n") ?: '');
		return $rLine !== '' ? $rLine : 'ffmpeg';
	}

	/**
	 * Hardware encoder names exposed by a build (nvenc/qsv/vaapi/amf/…).
	 *
	 * @return string[]
	 */
	private static function hwEncoders(string $rBinary): array {
		$rOut = (string) @shell_exec('timeout 5 ' . escapeshellarg($rBinary) . ' -hide_banner -encoders 2>/dev/null');
		$rEncoders = array();
		foreach (explode("\n", $rOut) as $rRow) {
			// ` V....D h264_nvenc  NVIDIA NVENC H.264 encoder`
			if (!preg_match('/^\s*[VASFXBD.]{6}\s+(\S+)/', $rRow, $rMatch)) {
				continue;
			}
			$rName = $rMatch[1];
			foreach (self::HW_ENCODER_MARKERS as $rMarker) {
				if (strpos($rName, $rMarker) !== false) {
					$rEncoders[] = $rName;
					break;
				}
			}
		}
		sort($rEncoders);
		return array_values(array_unique($rEncoders));
	}

	/** @return array{fingerprint?: string, versions?: array}|null */
	private static function readCache(): ?array {
		$rFile = self::cacheFile();
		if (!is_file($rFile)) {
			return null;
		}
		$rDecoded = json_decode((string) @file_get_contents($rFile), true);
		return is_array($rDecoded) ? $rDecoded : null;
	}

	/** Best-effort atomic cache write; failure is non-fatal (rescans next time). */
	private static function writeCache(string $rFingerprint, array $rVersions): void {
		$rJson = json_encode(array('fingerprint' => $rFingerprint, 'versions' => $rVersions), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		if ($rJson === false) {
			return;
		}
		$rFile = self::cacheFile();
		$rTmp = $rFile . '.tmp';
		if (@file_put_contents($rTmp, $rJson, LOCK_EX) !== false) {
			@rename($rTmp, $rFile);
		}
	}
}
