<?php

namespace XcVm\Core\Error;

/**
 * Error-response logic extracted from the procedural generateError()/generate404()
 * functions so it can be unit-tested without terminating the process.
 *
 * codes(), renderDebug() and render404() are pure. respondError()/respond404()
 * decide the outcome and return an ErrorResponseException value carrier. emit()
 * is the ONE side-effecting method (echo / http_response_code / exit) and is
 * called by the thin global function shims in Core/Error/ErrorHandler.php — the
 * 139 legacy generateError() call sites keep terminating in production.
 *
 * @package XC_VM_Core_Error
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ErrorResponder {
	/**
	 * When true, emit() throws the ErrorResponseException instead of calling
	 * exit(). Set ONLY by the test bootstrap — never in production.
	 */
	public static bool $throwInsteadOfExit = false;

	/**
	 * Error code => English description, shown in debug mode.
	 *
	 * @return array<string,string>
	 */
	public static function codes(): array {
		return [
			'API_IP_NOT_ALLOWED'       => 'IP is not allowed to access the API.',
			'ARCHIVE_DOESNT_EXIST'     => 'Archive files are missing for this stream ID.',
			'ASN_BLOCKED'              => 'ASN has been blocked.',
			'ATTRIBUTION_REMOVED'      => 'Panel copyright/attribution notice is missing. Restore it to continue.',
			'BANNED'                   => 'Line has been banned.',
			'BLOCKED_USER_AGENT'       => 'User-agent has been blocked.',
			'DEVICE_NOT_ALLOWED'       => 'MAG & Enigma devices are not allowed to access this.',
			'DISABLED'                 => 'Line has been disabled.',
			'DOWNLOAD_LIMIT_REACHED'   => 'Reached the simultaneous download limit.',
			'E2_DEVICE_LOCK_FAILED'    => 'Device lock checks failed.',
			'E2_DISABLED'              => 'Device has been disabled.',
			'E2_NO_TOKEN'              => 'No token has been specified.',
			'E2_TOKEN_DOESNT_MATCH'    => "Token doesn't match records.",
			'E2_WATCHDOG_TIMEOUT'      => 'Time limit reached.',
			'EMPTY_USER_AGENT'         => 'Empty user-agents are disallowed.',
			'EPG_DISABLED'             => 'EPG has been disabled.',
			'EPG_FILE_MISSING'         => 'Cached EPG files are missing.',
			'EXPIRED'                  => 'Line has expired.',
			'FORCED_COUNTRY_INVALID'   => 'Country does not match forced country.',
			'GENERATE_PLAYLIST_FAILED' => 'Playlist failed to generate.',
			'HLS_DISABLED'             => 'HLS has been disabled.',
			'HOSTING_DETECT'           => 'Hosting server has been detected.',
			'INVALID_API_PASSWORD'     => 'API password is invalid.',
			'INVALID_CREDENTIALS'      => 'Username or password is invalid.',
			'INVALID_HOST'             => 'Domain name not recognised.',
			'INVALID_STREAM_ID'        => "Stream ID doesn't exist.",
			'INVALID_TYPE_TOKEN'       => "Tokens can't be used for this stream type.",
			'IP_BLOCKED'               => 'IP has been blocked.',
			'IP_MISMATCH'              => "Current IP doesn't match initial connection IP.",
			'ISP_BLOCKED'              => 'ISP has been blocked.',
			'LB_TOKEN_INVALID'         => 'AES Token cannot be decrypted.',
			'LEGACY_EPG_DISABLED'      => 'Legacy epg.php access has been disabled.',
			'LEGACY_GET_DISABLED'      => 'Legacy get.php access has been disabled.',
			'LEGACY_PANEL_API_DISABLED' => 'Legacy panel_api.php access has been disabled.',
			'LINE_CREATE_FAIL'         => 'Line failed to insert into database.',
			'NO_CREDENTIALS'           => 'No credentials have been specified.',
			'NO_TIMESTAMP'             => 'No archive timestamp has been specified.',
			'NO_TOKEN_SPECIFIED'       => 'No AES encrypted token has been specified.',
			'NOT_ENIGMA_DEVICE'        => "Line isn't an enigma device.",
			'NOT_IN_ALLOWED_COUNTRY'   => 'Not in allowed country list.',
			'NOT_IN_ALLOWED_IPS'       => 'Not in allowed IP list.',
			'NOT_IN_ALLOWED_UAS'       => 'Not in allowed user-agent list.',
			'NOT_IN_BOUQUET'           => "Line doesn't have access to this stream ID.",
			'PLAYER_API_DISABLED'      => 'Player API has been disabled.',
			'PROXY_DETECT'             => 'Proxy has been detected.',
			'PROXY_NO_API_ACCESS'      => "Can't access API's via proxy.",
			'RESTREAM_DETECT'          => 'Restreaming has been detected.',
			'STALKER_CHANNEL_MISMATCH' => "Stream ID doesn't match stalker token.",
			'STALKER_DECRYPT_FAILED'   => 'Failed to decrypt stalker token.',
			'STALKER_INVALID_KEY'      => 'Invalid stalker key.',
			'STALKER_IP_MISMATCH'      => "IP doesn't match stalker token.",
			'STALKER_KEY_EXPIRED'      => 'Stalker token has expired.',
			'STREAM_OFFLINE'           => 'Stream is currently offline.',
			'THUMBNAIL_DOESNT_EXIST'   => "Thumbnail file doesn't exist.",
			'THUMBNAILS_NOT_ENABLED'   => 'Thumbnail not enabled for this stream.',
			'TOKEN_ERROR'              => 'AES token has incomplete data.',
			'TOKEN_EXPIRED'            => 'AES token has expired.',
			'TS_DISABLED'              => 'MPEG-TS has been disabled.',
			'USER_ALREADY_CONNECTED'   => 'Line already connected on a different IP.',
			'USER_DISALLOW_EXT'        => 'Extension is not in allowed list.',
			'VOD_DOESNT_EXIST'         => "VOD file doesn't exist.",
			'WAIT_TIME_EXPIRED'        => 'Stream start has timed out, failed to start.',
			'CACHE_INCOMPLETE'         => 'Cache is being generated...',
			'SUBTITLE_DOESNT_EXIST'    => "Subtitle file doesn't exist.",
			'NO_SERVERS_AVAILABLE'     => 'No servers are currently available for this stream.',
			'PROXY_ACCESS_DENIED'      => 'You cannot access this stream directly while proxy is enabled.',
		];
	}

	/**
	 * Debug-mode error page (styled, shows the error code and description).
	 *
	 * @param string $code        Error code (raw, as passed to generateError()).
	 * @param string $description Human-readable description (may be empty).
	 */
	public static function renderDebug(string $code, string $description): string {
		$rStyle = '*{-webkit-box-sizing:border-box;box-sizing:border-box}body{padding:0;margin:0}#notfound{position:relative;height:100vh}#notfound .notfound{position:absolute;left:50%;top:50%;-webkit-transform:translate(-50%,-50%);-ms-transform:translate(-50%,-50%);transform:translate(-50%,-50%)}.notfound{max-width:520px;width:100%;line-height:1.4;text-align:center}.notfound .notfound-404{position:relative;height:200px;margin:0 auto 20px;z-index:-1}.notfound .notfound-404 h1{font-family:Montserrat,sans-serif;font-size:236px;font-weight:200;margin:0;color:#211b19;text-transform:uppercase;position:absolute;left:50%;top:50%;-webkit-transform:translate(-50%,-50%);-ms-transform:translate(-50%,-50%);transform:translate(-50%,-50%)}.notfound .notfound-404 h2{font-family:Montserrat,sans-serif;font-size:28px;font-weight:400;text-transform:uppercase;color:#211b19;background:#fff;padding:10px 5px;margin:auto;display:inline-block;position:absolute;bottom:0;left:0;right:0}.notfound p{font-family:Montserrat,sans-serif;font-size:14px;font-weight:300;text-transform:uppercase}@media only screen and (max-width:767px){.notfound .notfound-404 h1{font-size:148px}}@media only screen and (max-width:480px){.notfound .notfound-404{height:148px;margin:0 auto 10px}.notfound .notfound-404 h1{font-size:86px}.notfound .notfound-404 h2{font-size:16px}}';

		return '<html><head><title>XC_VM - Debug Mode</title><link href="https://fonts.googleapis.com/css?family=Montserrat:200,400,700" rel="stylesheet"><style>' . $rStyle . '</style></head><body><div id="notfound"><div class="notfound"><div class="notfound-404"><h1>XC_VM</h1><h2>' . $code . '</h2><br/></div><p>' . $description . '</p></div></div></body></html>';
	}

	/**
	 * Standard "404 Not Found" page (mimics nginx, with the MSIE/Chrome padding).
	 */
	public static function render404(): string {
		return '<html>' . "\r\n" . '<head><title>404 Not Found</title></head>' . "\r\n" . '<body>' . "\r\n" . '<center><h1>404 Not Found</h1></center>' . "\r\n" . '<hr><center>nginx</center>' . "\r\n" . '</body>' . "\r\n" . '</html>' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->' . "\r\n" . '<!-- a padding to disable MSIE and Chrome friendly error page -->';
	}

	/**
	 * Resolve a generateError() call into an outcome, preserving the exact
	 * branching of the legacy procedural function.
	 *
	 * @param string               $code     Error code key.
	 * @param array<string,mixed>  $settings Panel settings (reads debug_show_errors).
	 * @param bool                 $kill     Whether the caller wants to terminate.
	 * @param int|null             $httpCode Explicit HTTP status (falsy — null or 0 — falls to 404).
	 */
	public static function respondError(string $code, array $settings, bool $kill = true, ?int $httpCode = null): ErrorResponseException {
		$debug = isset($settings['debug_show_errors']) && $settings['debug_show_errors'];

		if ($debug) {
			$description = self::codes()[$code] ?? '';
			// Debug branch: always echo the styled page, terminate only when
			// $kill is set, and then with production's status (404, or the
			// given code): a player that read a 200 page as its playlist stalled
			// instead of reconnecting.
			return new ErrorResponseException($code, $kill ? ($httpCode ?: 404) : null, self::renderDebug($code, $description), false, $kill);
		}

		// Production, no termination requested: emit nothing.
		if (!$kill) {
			return new ErrorResponseException($code, null, '', false, false);
		}

		// Production + terminate: a bare 404 page, or just the given status code.
		// Matches the legacy `!$rCode` check — a falsy code (null or 0) means 404.
		if (!$httpCode) {
			return self::respond404(true, $code);
		}

		return new ErrorResponseException($code, $httpCode, '', false, true);
	}

	/**
	 * Resolve a generate404() call into an outcome.
	 *
	 * @param bool   $kill Whether the caller wants to terminate.
	 * @param string $code Originating error code, for the exception context.
	 */
	public static function respond404(bool $kill = true, string $code = ''): ErrorResponseException {
		return new ErrorResponseException($code, 404, self::render404(), true, $kill);
	}

	/**
	 * Apply an outcome: set the HTTP status, echo the body, then terminate.
	 *
	 * The ONE side-effecting method. In test mode a terminating outcome throws the
	 * exception instead of calling exit(). The status goes first: once the body
	 * is out (no output buffer) the headers are sent and it could not be set.
	 */
	public static function emit(ErrorResponseException $outcome): void {
		if ($outcome->httpCode !== null && !headers_sent()) {
			http_response_code($outcome->httpCode);
		}

		if ($outcome->body !== '') {
			echo $outcome->body;
		}

		if ($outcome->shouldExit) {
			if (self::$throwInsteadOfExit) {
				throw $outcome;
			}

			exit();
		}
	}
}
