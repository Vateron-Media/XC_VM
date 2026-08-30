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
	private static $cpu = null;
	private static $gpu = null;
	private static $probe = null;
	private static $resolved = false;

	/**
	 * Resolve the CPU/GPU/probe binary paths from the configured version strings.
	 *
	 * Paths are built dynamically from BIN_PATH/ffmpeg_bin/<version>/ so any build
	 * dropped into that folder is usable without touching this class — no version
	 * switch to maintain. This runs on the bootstrap hot path, so it stays cheap:
	 * a format check plus an is_file() stat, never a shell probe (that lives in
	 * {@see FfmpegBinaries}). An unknown/missing CPU version falls back to the
	 * legacy 4.0 build; the GPU binary falls back to the resolved CPU binary when
	 * ffmpeg_gpu is empty or points at a missing build.
	 *
	 * Called once during bootstrap; subsequent calls are no-ops.
	 *
	 * @param string      $cpuVersion e.g. '8.0', '7.1', '4.0'
	 * @param string|null $gpuVersion GPU ffmpeg version, or null to reuse the CPU build
	 */
	public static function resolve($cpuVersion, $gpuVersion = null) {
		if (self::$resolved) {
			return;
		}

		self::$cpu   = self::binary($cpuVersion, 'ffmpeg') ?? FFMPEG_BIN_40;
		self::$probe = self::binary($cpuVersion, 'ffprobe') ?? FFPROBE_BIN_40;

		$rGpu = ($gpuVersion !== null && $gpuVersion !== '') ? self::binary($gpuVersion, 'ffmpeg') : null;
		self::$gpu = $rGpu ?? self::$cpu;

		self::$resolved = true;
	}

	/**
	 * Build a binary path for a version folder, or null when the version string is
	 * malformed or the binary is absent.
	 *
	 * @param string $version Version folder name (e.g. '8.0')
	 * @param string $name    'ffmpeg' or 'ffprobe'
	 */
	private static function binary($version, $name): ?string {
		if (!is_string($version) || !preg_match('/^\d+\.\d+$/', $version)) {
			return null;
		}
		$rPath = BIN_PATH . 'ffmpeg_bin/' . $version . '/' . $name;
		return is_file($rPath) ? $rPath : null;
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
