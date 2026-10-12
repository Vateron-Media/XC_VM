<?php

namespace XcVm\Streaming\Codec;

/**
 * FfmpegPaths — value-object holding FFmpeg/FFprobe binary paths.
 *
 * Resolves paths once based on the configured ffmpeg version from settings.
 *
 * @package XC_VM_Streaming_Codec
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class FfmpegPaths {
	private static $cpu;

	private static $gpu;

	private static $probe;

	private static $resolved = false;

	/**
	 * Resolve the CPU/GPU/probe binary paths from the configured version strings.
	 *
	 * Paths are built dynamically from BIN_PATH/ffmpeg_bin/<version>/ so any build
	 * dropped into that folder is usable without touching this class — no version
	 * switch to maintain. This runs on the bootstrap hot path, so it stays cheap:
	 * a format check plus an is_file() stat, never a shell probe (that lives in
	 * {@see FfmpegBinaries}). A version the node does not have takes the newest
	 * build of its major it has (8.0 for 8.1 until the node fetched 8.1, or the
	 * reverse), else the legacy 4.0 build; the GPU binary falls back to the
	 * resolved CPU binary when ffmpeg_gpu is empty or points at a missing build.
	 *
	 * Called once during bootstrap; subsequent calls are no-ops.
	 *
	 * @param string      $cpuVersion e.g. '8.1', '7.1', '4.0'
	 * @param string|null $gpuVersion GPU ffmpeg version, or null to reuse the CPU build
	 */
	public static function resolve(string $cpuVersion, ?string $gpuVersion = null) {
		if (self::$resolved) {
			return;
		}

		self::$cpu   = self::binary($cpuVersion, 'ffmpeg') ?? self::newest('ffmpeg') ?? FFMPEG_BIN_40;
		self::$probe = self::binary($cpuVersion, 'ffprobe') ?? self::newest('ffprobe') ?? FFPROBE_BIN_40;

		$rGpu = ($gpuVersion !== null && $gpuVersion !== '') ? self::binary($gpuVersion, 'ffmpeg') : null;
		self::$gpu = $rGpu ?? self::$cpu;

		self::$resolved = true;
	}

	/** A build id: `<major>.<minor>` (ours) or `<major>.<minor>-<vendor>` (e.g. 4.0-xui). */
	private const BUILD_ID = '/^(\d+\.\d+)(?:-[a-z0-9]+)?\z/';

	/** Whether $rId names a build folder, as settings may store it (no path, no trailing newline). */
	public static function isBuildId(string $rId): bool {
		return (bool) preg_match(self::BUILD_ID, $rId);
	}

	/**
	 * Build a binary path for a build folder, or, when it is absent, that of a
	 * folder of the same major: the same minor first (4.0-xui for 4.0), then the
	 * newest. Null when the id is malformed or the node has no build of that major.
	 *
	 * @param string $version Build id (e.g. '8.1', '4.0-xui')
	 * @param string $name    'ffmpeg' or 'ffprobe'
	 */
	private static function binary(string $version, string $name): ?string {
		if (!preg_match(self::BUILD_ID, $version, $rMatch)) {
			return null;
		}
		$rPath = BIN_PATH . 'ffmpeg_bin/' . $version . '/' . $name;
		if (is_file($rPath)) {
			return $rPath;
		}
		return self::newest($name, strtok($rMatch[1], '.') . '.*', $rMatch[1]);
	}

	/**
	 * $name from the newest build folder matching $rGlob, those of minor $rMinor
	 * first; folders that are no build id (backups) are passed over. Null when
	 * there is none.
	 */
	private static function newest(string $name, string $rGlob = '*', string $rMinor = ''): ?string {
		$rNum = static fn(string $rP): string => preg_match(self::BUILD_ID, basename(dirname($rP)), $rM) ? $rM[1] : '';
		$rFound = array_filter(glob(BIN_PATH . 'ffmpeg_bin/' . $rGlob . '/' . $name) ?: [], static fn(string $rP): bool => $rNum($rP) !== '');
		usort($rFound, static fn(string $a, string $b): int => (($rNum($b) === $rMinor) <=> ($rNum($a) === $rMinor)) ?: version_compare($rNum($b), $rNum($a)));
		return $rFound[0] ?? null;
	}

	/**
	 * The legacy ffmpeg (the `dts_legacy_ffmpeg` path for AC3/EAC3): XUI's 4.0
	 * where the node has it, else the newest 4.x, else the configured build.
	 */
	public static function legacy(): string {
		return self::binary('4.0', 'ffmpeg') ?? self::$cpu ?? FFMPEG_BIN_40;
	}

	/**
	 * Does $rBin take `-nofix_dts`? Only XUI's 4.0 does (its own switch): the
	 * folder 4.0-xui, or 4.0 itself before it moved, with no BUILD_INFO. Any other
	 * 4.0-<vendor> is someone's stock build, which would refuse the switch. Read
	 * through links, so a link answers for the build behind it.
	 */
	public static function fixDts(string $rBin): bool {
		$rDir = realpath(dirname($rBin));
		return $rDir !== false && preg_match('/^4\.0(?:-xui)?\z/', basename($rDir)) === 1 && !is_file($rDir . '/BUILD_INFO');
	}

	/** @return string Path to CPU-optimized ffmpeg binary */
	public static function cpu() {
		return self::$cpu;
	}

	/** @return string Path to GPU-capable ffmpeg binary */
	public static function gpu() {
		return self::$gpu;
	}

	/** @return string Path to ffprobe binary */
	public static function probe() {
		return self::$probe;
	}
}
