<?php

/**
 * FfmpegPaths — value-object holding FFmpeg/FFprobe binary paths.
 *
 * Resolves paths once based on the configured ffmpeg version from settings.
 */
class FfmpegPaths {
	private static $cpu = null;
	private static $gpu = null;
	private static $probe = null;
	private static $resolved = false;

	/**
	 * Resolve paths from an ffmpeg_cpu version string.
	 *
	 * Called once during bootstrap; subsequent calls are no-ops.
	 *
	 * @param string $version  e.g. '8.0', '7.1', '4.0'
	 */
	public static function resolve($version) {
		if (self::$resolved) {
			return;
		}
		switch ($version) {
			case '8.0':
				self::$cpu   = FFMPEG_BIN_80;
				self::$probe = FFPROBE_BIN_80;
				self::$gpu   = FFMPEG_BIN_80;
				break;
			case '7.1':
				self::$cpu   = FFMPEG_BIN_71;
				self::$probe = FFPROBE_BIN_71;
				self::$gpu   = FFMPEG_BIN_71;
				break;
			default:
				self::$cpu   = FFMPEG_BIN_40;
				self::$probe = FFPROBE_BIN_40;
				self::$gpu   = FFMPEG_BIN_40;
				break;
		}
		self::$resolved = true;
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
