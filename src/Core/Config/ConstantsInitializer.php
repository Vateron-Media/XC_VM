<?php

namespace XcVm\Core\Config;

use RuntimeException;

// Frequently-edited release constants — kept as define()s at the top of the file
// so they are easy to find/edit and the release automation can sed them by name.
// appConfig() reads them back below; init() then skips the already-defined ones.
// Guarded so a pre-definition (e.g. the PHPStan stub) never causes a fatal.
defined('DB_ACCESS_ENABLED') || define('DB_ACCESS_ENABLED', false);
defined('DB_ACCESS_PWD') || define('DB_ACCESS_PWD', '');
defined('DEV_MODE') || define('DEV_MODE', false);
defined('XC_VM_VERSION') || define('XC_VM_VERSION', '2.6.1');
// Per-build watermark stamped into the deploy root by `make main` (see the
// Makefile stamp_release_id target). A source/dev checkout is never stamped, so
// runtime and the licence activation call report 'dev'. Unique per build, so a
// leaked copy can be traced back to the build it came from.
defined('XC_VM_BUILD_ID') || define(
	'XC_VM_BUILD_ID',
	is_file(__DIR__ . '/../../RELEASE_ID')
		? (trim((string) file_get_contents(__DIR__ . '/../../RELEASE_ID')) ?: 'dev')
		: 'dev'
);

/**
 * Single source of truth for the runtime constants that used to live in the
 * procedural prelude files (Paths.php, AppConfig.php, Binaries.php) and in
 * XC_Bootstrap::defineStatusConstants().
 *
 * The value maps (paths(), appConfig(), binaries(), statuses()) are pure and
 * unit-testable with different inputs in the same process — unlike the raw
 * define() constants they feed, which are one-shot per process. init() is the
 * place define() runs for the derived constants (the four frequently-edited
 * release constants above are defined at file scope for editability); every boot
 * path (ConstantsStage, WebApiBootstrap, StreamingRequestBootstrap, the progress
 * endpoint) calls it directly and gets the full constant set regardless of order.
 *
 * @package XC_VM_Core_Config
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ConstantsInitializer {
	/**
	 * Define the path, app-config and binary constants exactly once.
	 *
	 * Ordering (MAIN_HOME -> paths -> binaries) is enforced here in code rather
	 * than by require order: BIN_PATH is established by the path map before the
	 * binary map derives from it.
	 *
	 * @param string|null $mainHome Deploy root with trailing slash. Falls back to
	 *                              the MAIN_HOME constant when omitted.
	 */
	public static function init(?string $mainHome = null): void {
		$mainHome ??= defined('MAIN_HOME') ? MAIN_HOME : null;

		if ($mainHome === null) {
			throw new RuntimeException(
				'ConstantsInitializer::init() requires MAIN_HOME to be defined or passed explicitly.'
			);
		}

		self::defineAll(self::paths($mainHome));

		$appConfig = self::appConfig();
		// OPENSSL_EXTRA is per-install key material: prefer the secret the installer
		// wrote, falling back to the historical literal. Resolved only on the
		// genuine first define (tests / re-init keep the already-set constant).
		if (!defined('OPENSSL_EXTRA')) {
			$appConfig['OPENSSL_EXTRA'] = self::resolveOpensslExtra($mainHome . 'config/openssl_extra', $appConfig['OPENSSL_EXTRA']);
		}
		self::defineAll($appConfig);

		// Derive binaries from the now-defined BIN_PATH (an explicit test override
		// of BIN_PATH wins over the computed value).
		$binPath = defined('BIN_PATH') ? BIN_PATH : $mainHome . 'bin/';
		self::defineAll(self::binaries($binPath));
	}

	/**
	 * Define the STATUS_* constants exactly once.
	 *
	 * Kept separate from init() to preserve the current lazy semantics: status
	 * codes are only needed by the admin/reseller API handlers.
	 */
	public static function initStatus(): void {
		if (defined('STATUS_FAILURE')) {
			return;
		}

		self::defineAll(self::statuses());
	}

	/**
	 * Filesystem path constants, derived from the deploy root.
	 *
	 * @param string $mainHome Deploy root with trailing slash.
	 * @return array<string,string> Constant name => value.
	 */
	public static function paths(string $mainHome): array {
		$content   = $mainHome . 'content/';
		$tmp       = $mainHome . 'tmp/';
		$bin       = $mainHome . 'bin/';
		$storage   = $mainHome . 'storage/';
		$fanoutRun = $bin . 'xc_fanout/sockets/';
		$images    = $storage . 'images/';

		return [
			'CONTENT_PATH'        => $content,
			'TMP_PATH'            => $tmp,
			'CONFIG_PATH'         => $mainHome . 'config/',
			'BIN_PATH'            => $bin,
			'STORAGE_PATH'        => $storage,
			'SIGNALS_PATH'        => $mainHome . 'signals/',
			'FANOUT_RUN_PATH'     => $fanoutRun,
			'FANOUT_CTL_SOCK'     => $fanoutRun . 'control.sock',
			'FANOUT_HTTP_SOCK'    => $fanoutRun . 'http.sock',
			'STREAMS_PATH'        => $content . 'streams/',
			'EPG_PATH'            => $content . 'epg/',
			'VOD_PATH'            => $content . 'vod/',
			'ARCHIVE_PATH'        => $content . 'archive/',
			'CREATED_PATH'        => $content . 'created/',
			'DELAY_PATH'          => $content . 'delayed/',
			'VIDEO_PATH'          => $content . 'video/',
			'PLAYLIST_PATH'       => $content . 'playlists/',
			'CONS_TMP_PATH'       => $tmp . 'opened_cons/',
			'CRONS_TMP_PATH'      => $tmp . 'crons/',
			'CIDR_TMP_PATH'       => $tmp . 'cidr/',
			'CACHE_TMP_PATH'      => $tmp . 'cache/',
			'STREAMS_TMP_PATH'    => $tmp . 'cache/streams/',
			'SERIES_TMP_PATH'     => $tmp . 'cache/series/',
			'LINES_TMP_PATH'      => $tmp . 'cache/lines/',
			'DIVERGENCE_TMP_PATH' => $tmp . 'divergence/',
			'FLOOD_TMP_PATH'      => $tmp . 'flood/',
			'PLAYER_TMP_PATH'     => $tmp . 'player/',
			'MINISTRA_TMP_PATH'   => $tmp . 'ministra/',
			'SIGNALS_TMP_PATH'    => $tmp . 'signals/',
			'LOGS_TMP_PATH'       => $tmp . 'logs/',
			'WATCH_TMP_PATH'      => $tmp . 'watch/',
			'IMAGES_PATH'         => $images,
			'E2_IMAGES_PATH'      => $images . 'enigma2/',
		];
	}

	/**
	 * Application-level constants: versioning, Git repos and feature flags.
	 *
	 * @return array<string,scalar> Constant name => value.
	 */
	public static function appConfig(): array {
		return [
			'DB_ACCESS_ENABLED' => DB_ACCESS_ENABLED,
			'DB_ACCESS_PWD'     => DB_ACCESS_PWD,
			'DEV_MODE'          => DEV_MODE,
			'XC_VM_VERSION'     => XC_VM_VERSION,
			'GIT_OWNER'         => 'Vateron-Media',
			'GIT_REPO_MAIN'     => 'XC_VM',
			'GIT_REPO_DEV'      => 'XC_VM_Dev',
			'GIT_REPO_UPDATE'   => 'XC_VM_Update',
			'GIT_REPO_BIN'      => 'XC_VM_Binaries',
			'GIT_REPO_FANOUT'   => 'XC_VM_Fanout',
			'GIT_REPO_PROXY'    => 'XC_VM_Proxy',
			'GIT_REPO_FFMPEG'   => 'XC_VM_FFMPEG',
			'MONITOR_CALLS'     => 3,
			'OPENSSL_EXTRA'     => 'fNiu3XD448xTDa27xoY4',
		];
	}

	/**
	 * Binary-path constants, derived from the bin directory.
	 *
	 * Only the FFmpeg 4.0 anchor is pinned here; other versions are resolved
	 * dynamically by FfmpegPaths/FfmpegBinaries.
	 *
	 * @param string $binPath Bin directory with trailing slash (BIN_PATH).
	 * @return array<string,string> Constant name => value.
	 */
	public static function binaries(string $binPath): array {
		return [
			'PHP_BIN'        => $binPath . 'php/bin/php',
			'YOUTUBE_BIN'    => $binPath . 'yt-dlp',
			'FFMPEG_FONT'    => $binPath . 'free-sans.ttf',
			'GEOLITE2_BIN'   => $binPath . 'maxmind/GeoLite2-Country.mmdb',
			'GEOLITE2C_BIN'  => $binPath . 'maxmind/GeoLite2-City.mmdb',
			'GEOISP_BIN'     => $binPath . 'maxmind/GeoIP2-ISP.mmdb',
			'FFMPEG_BIN_40'  => $binPath . 'ffmpeg_bin/4.0/ffmpeg',
			'FFPROBE_BIN_40' => $binPath . 'ffmpeg_bin/4.0/ffprobe',
		];
	}

	/**
	 * STATUS_* result codes used across admin/reseller API handlers.
	 *
	 * @return array<string,int> Constant name => value.
	 */
	public static function statuses(): array {
		return [
			'STATUS_FAILURE'             => 0,
			'STATUS_SUCCESS'             => 1,
			'STATUS_SUCCESS_MULTI'       => 2,
			'STATUS_CODE_LENGTH'         => 3,
			'STATUS_NO_SOURCES'          => 4,
			'STATUS_DISABLED'            => 5,
			'STATUS_NOT_ADMIN'           => 6,
			'STATUS_INVALID_EMAIL'       => 7,
			'STATUS_INVALID_PASSWORD'    => 8,
			'STATUS_INVALID_IP'          => 9,
			'STATUS_INVALID_PLAYLIST'    => 10,
			'STATUS_INVALID_NAME'        => 11,
			'STATUS_INVALID_CAPTCHA'     => 12,
			'STATUS_INVALID_CODE'        => 13,
			'STATUS_INVALID_DATE'        => 14,
			'STATUS_INVALID_FILE'        => 15,
			'STATUS_INVALID_GROUP'       => 16,
			'STATUS_INVALID_DATA'        => 17,
			'STATUS_INVALID_DIR'         => 18,
			'STATUS_INVALID_MAC'         => 19,
			'STATUS_EXISTS_CODE'         => 20,
			'STATUS_EXISTS_NAME'         => 21,
			'STATUS_EXISTS_USERNAME'     => 22,
			'STATUS_EXISTS_MAC'          => 23,
			'STATUS_EXISTS_SOURCE'       => 24,
			'STATUS_EXISTS_IP'           => 25,
			'STATUS_EXISTS_DIR'          => 26,
			'STATUS_SUCCESS_REPLACE'     => 27,
			'STATUS_FLUSH'               => 28,
			'STATUS_TOO_MANY_RESULTS'    => 29,
			'STATUS_SPACE_ISSUE'         => 30,
			'STATUS_INVALID_USER'        => 31,
			'STATUS_CERTBOT'             => 32,
			'STATUS_CERTBOT_INVALID'     => 33,
			'STATUS_INVALID_INPUT'       => 34,
			'STATUS_NOT_RESELLER'        => 35,
			'STATUS_NO_TRIALS'           => 36,
			'STATUS_INSUFFICIENT_CREDITS' => 37,
			'STATUS_INVALID_PACKAGE'     => 38,
			'STATUS_INVALID_TYPE'        => 39,
			'STATUS_INVALID_USERNAME'    => 40,
			'STATUS_INVALID_SUBRESELLER' => 41,
			'STATUS_NO_DESCRIPTION'      => 42,
			'STATUS_NO_KEY'              => 43,
			'STATUS_EXISTS_HMAC'         => 44,
			'STATUS_CERTBOT_RUNNING'     => 45,
			'STATUS_RESERVED_CODE'       => 46,
			'STATUS_NO_TITLE'            => 47,
			'STATUS_NO_SOURCE'           => 48,
			'STATUS_PORT_IN_USE'         => 49,
		];
	}

	/**
	 * Resolve the per-install OPENSSL_EXTRA secret from a dedicated file the
	 * installer writes, falling back to the historical literal when absent.
	 *
	 * A standalone file (not config.ini) is used deliberately: it is independent
	 * of the config.ini -> config.enc migration and is never rewritten, so the
	 * value a fresh install generates is read back identically for the life of
	 * the install — OPENSSL_EXTRA must never change, since it feeds key/HMAC
	 * derivation for persisted data (hmac_keys rows, cached image filenames,
	 * proxy URL keys, stream tokens). The literal fallback keeps existing installs
	 * — whose data derives from it — decrypting unchanged. An LB holds its MAIN's
	 * value; its copy is replaced only to match the MAIN (OpensslExtra::install).
	 *
	 * @param string $secretFile Absolute path to the per-install secret file.
	 * @param string $fallback   Historical literal default.
	 */
	private static function resolveOpensslExtra(string $secretFile, string $fallback): string {
		if (is_file($secretFile)) {
			$value = trim((string) @file_get_contents($secretFile));
			if ($value !== '') {
				return $value;
			}
		}

		return $fallback;
	}

	/**
	 * define() every entry that is not already defined.
	 *
	 * @param array<string,scalar> $constants Constant name => value.
	 */
	private static function defineAll(array $constants): void {
		foreach ($constants as $name => $value) {
			if (!defined($name)) {
				define($name, $value);
			}
		}
	}
}
