<?php

namespace XcVm\Core\Util;

use XcVm\Core\Process\ProcessManager;
use XcVm\Core\Process\ProcessRunner;

/**
 * StreamUtils — stream utils
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamUtils {
	/**
	 * Ensure a cookie string carries path and domain attributes.
	 *
	 * @param string $rCookie Raw cookie string.
	 * @return string Cookie string with `path=/` and `domain=` appended if missing.
	 */
	public static function fixCookie(string $rCookie) {
		$rPath = false;
		$rDomain = false;
		$rSplit = explode(';', $rCookie);
		foreach ($rSplit as $rPiece) {
			list($rKey, $rValue) = explode('=', $rPiece, 2);
			if (strtolower($rKey) == 'path') {
				$rPath = true;
			} else {
				if (strtolower($rKey) == 'domain') {
					$rDomain = true;
				}
			}
		}
		if (!substr($rCookie, -1) == ';') {
			$rCookie .= ';';
		}
		if (!$rPath) {
			$rCookie .= 'path=/;';
		}
		if (!$rDomain) {
			$rCookie .= 'domain=;';
		}
		return $rCookie;
	}

	/**
	 * A stream's "HTTP Proxy" value as a proxy URL. The form asks for ip:port,
	 * but ffmpeg's -http_proxy ignores a value without http:// (it fetches
	 * directly), and yt-dlp wants a scheme too.
	 *
	 * @param string $rProxy ip:port, or a URL with its scheme.
	 * @return string
	 */
	public static function proxyURL(string $rProxy): string {
		$rProxy = trim($rProxy);
		if ($rProxy === '' || str_contains($rProxy, '://')) {
			return $rProxy;
		}
		return 'http://' . $rProxy;
	}

	/**
	 * Build the ffmpeg argument list for a stream, filtered by category/protocol.
	 *
	 * @param array       $rArguments Configured argument definitions.
	 * @param string|null $rProtocol  Stream protocol to match against.
	 * @param mixed       $rType      Argument category to include.
	 * @return string[] Formatted command-line arguments.
	 */
	public static function getArguments(array $rArguments, ?string $rProtocol, mixed $rType) {
		$rReturn = [];
		foreach ($rArguments as $rArgument) {
			if ($rArgument['argument_cat'] == $rType && (is_null($rArgument['argument_wprotocol']) || stristr($rProtocol, $rArgument['argument_wprotocol']) || is_null($rProtocol))) {
				if ($rArgument['argument_key'] == 'cookie') {
					$rArgument['value'] = self::fixCookie($rArgument['value']);
				} elseif ($rArgument['argument_key'] == 'proxy') {
					$rArgument['value'] = self::proxyURL((string) $rArgument['value']);
				}
				if ($rArgument['argument_type'] == 'text') {
					$rReturn[] = sprintf($rArgument['argument_cmd'], self::quoteForTemplate((string) $rArgument['argument_cmd'], (string) $rArgument['value']));
				} else {
					$rReturn[] = $rArgument['argument_cmd'];
				}
			}
		}
		return $rReturn;
	}

	/**
	 * Quote a text argument's value for the place its %s takes in the command
	 * template, so the shell that runs the line hands the program the value
	 * exactly as stored, as one argument: inside '...', inside "...", or
	 * wrapped in "..." where the template leaves it unquoted. A template
	 * without %s (%d) formats the number itself.
	 *
	 * @param string $rTemplate argument_cmd, e.g. `-user_agent "%s"`.
	 * @param string $rValue    The stored value.
	 * @return string
	 */
	private static function quoteForTemplate(string $rTemplate, string $rValue): string {
		$rPosition = strpos($rTemplate, '%s');
		if ($rPosition === false) {
			return $rValue;
		}
		$rQuote = '';
		for ($i = 0; $i < $rPosition; $i++) {
			if ($rQuote === '') {
				$rQuote = in_array($rTemplate[$i], ['"', "'"]) ? $rTemplate[$i] : '';
			} elseif ($rTemplate[$i] === $rQuote) {
				$rQuote = '';
			}
		}
		if ($rQuote === "'") {
			return str_replace("'", "'\\''", $rValue);
		}
		// Double quotes also where the template has none: parseTranscode() looks
		// for -filter_complex "..." in the text, and a value escaped for them
		// cannot hold one.
		$rValue = addcslashes($rValue, '"$`\\');
		return $rQuote === '"' ? $rValue : '"' . $rValue . '"';
	}

	/**
	 * Normalize transcode arguments for ffmpeg.
	 *
	 * Merges multiple `-filter_complex` clauses into one, drops control keys
	 * (gpu/software_decoding) and orders so input (`-i`) comes first.
	 *
	 * @param array $rArgs Raw transcode argument map.
	 * @return string[] Ordered, trimmed ffmpeg arguments.
	 */
	public static function parseTranscode(array $rArgs) {
		$rFitlerComplex = [];
		foreach ($rArgs as $rKey => $rArgument) {
			if (!(in_array($rKey, ['gpu', 'software_decoding', '16']))) {
				if (isset($rArgument['cmd'])) {
					$rArgs[$rKey] = $rArgument = $rArgument['cmd'];
				}
				// A clause runs to its closing quote: a quote escaped for the shell (\") is part of it.
				if (preg_match('/-filter_complex "((?:[^"\\\\\n]|\\\\[^\n])*+)"/', $rArgument, $rMatches)) {
					$rArgs[$rKey] = trim(str_replace($rMatches[0], '', $rArgs[$rKey]));
					$rFitlerComplex[] = $rMatches[1];
				}
			}
		}
		if ($rFitlerComplex !== []) {
			$rArgs[] = '-filter_complex "' . implode(',', $rFitlerComplex) . '"';
		}
		$rNewArgs = [];
		foreach ($rArgs as $rKey => $rArg) {
			if ($rKey != 'gpu' && $rKey != 'software_decoding') {
				if (is_numeric($rKey)) {
					$rNewArgs[] = $rArg;
				} else {
					$rNewArgs[] = $rKey . ' ' . $rArg;
				}
			}
		}
		$rNewArgs = array_filter($rNewArgs);
		uasort($rNewArgs, [self::class, 'customOrder']);
		return array_map('trim', array_values(array_filter($rNewArgs)));
	}

	/**
	 * Comparator that sorts `-i` input arguments before others.
	 *
	 * @param string $a First argument.
	 * @param string $b Second argument.
	 * @return int -1 if $a is an input arg, 1 otherwise.
	 */
	public static function customOrder(string $a, string $b) {
		if (substr($a, 0, 3) == '-i ') {
			return -1;
		}
		return 1;
	}

	/**
	 * Normalize a stream source URL for ffmpeg.
	 *
	 * Applies rtmp options and resolves known streaming-platform pages to a
	 * direct media URL via yt-dlp. The resolved googlevideo URL is bound to the
	 * IP that asked for it, so yt-dlp goes through the stream's own proxy, the
	 * one ffmpeg fetches through.
	 *
	 * @param string $rURL   Source URL.
	 * @param string $rProxy The stream's "HTTP Proxy" value ('' for none).
	 * @return string Normalized/resolved URL.
	 */
	public static function parseStreamURL(string $rURL, string $rProxy = '') {
		$rProtocol = strtolower(substr($rURL, 0, 4));
		if ($rProtocol == 'rtmp') {
			if (stristr($rURL, '$OPT')) {
				$rPattern = 'rtmp://$OPT:rtmp-raw=';
				$rURL = trim(substr($rURL, stripos($rURL, $rPattern) + strlen($rPattern)));
			}
			$rURL .= ' live=1 timeout=10';
		} else {
			if (self::needsResolver($rURL)) {
				// ponytail: without a JS runtime YouTube's default clients give a live
				// stream only as separate video/audio, which -f best cannot pick;
				// android_vr still serves one muxed HLS playlist. Drop it once nodes have deno.
				$rArgv = [YOUTUBE_BIN, '--extractor-args', 'youtube:player_client=default,android_vr', '-q', '--get-url', '--skip-download', '-f', 'best'];
				if ($rProxy !== '') {
					array_push($rArgv, '--proxy', self::proxyURL($rProxy));
				}
				[, $rURLs] = ProcessRunner::capture([...$rArgv, '--', $rURL]);
				list($rURL) = explode("\n", trim($rURLs));
			}
		}
		return $rURL;
	}

	/** Video platforms whose page URLs parseStreamURL() resolves through yt-dlp. */
	public const RESOLVED_PLATFORMS = ['livestream.com', 'ustream.tv', 'twitch.tv', 'vimeo.com', 'facebook.com', 'dailymotion.com', 'cnn.com', 'edition.cnn.com', 'youtube.com', 'youtu.be'];

	/**
	 * Whether a source URL is a platform page parseStreamURL() has to resolve
	 * (through yt-dlp) into a playable — and short-lived — media URL.
	 *
	 * @param string $rURL Source URL.
	 * @return bool
	 */
	public static function needsResolver(string $rURL) {
		if (strtolower(substr($rURL, 0, 4)) !== 'http') {
			return false;
		}
		$rHost = str_ireplace('www.', '', (string) parse_url($rURL, PHP_URL_HOST));
		return in_array($rHost, self::RESOLVED_PLATFORMS, true);
	}

	/**
	 * Heuristically detect whether a URL is an XC_VM stream endpoint.
	 *
	 * @param string $rURL URL to inspect.
	 * @return bool True if the path matches a known XC_VM stream pattern.
	 */
	public static function detectXC_VM(string $rURL) {
		$rPath = parse_url($rURL)['path'];
		$rPathSize = count(explode('/', $rPath));
		$rRegex = ['/\\/auth\\/(.*)$/m' => 3, '/\\/play\\/(.*)$/m' => 3, '/\\/play\\/(.*)\\/(.*)$/m' => 4, '/\\/live\\/(.*)\\/(\\d+)$/m' => 4, '/\\/live\\/(.*)\\/(\\d+)\\.(.*)$/m' => 4, '/\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m' => 4, '/\\/(.*)\\/(.*)\\/(\\d+)$/m' => 4, '/\\/live\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m' => 5, '/\\/live\\/(.*)\\/(.*)\\/(\\d+)$/m' => 5];
		foreach ($rRegex as $rQuery => $rCount) {
			if ($rPathSize == $rCount) {
				preg_match($rQuery, $rPath, $rMatches);
				if (0 < count($rMatches)) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Extract `.ts` segments (or the current segment id) from an m3u8 playlist.
	 *
	 * @param string $rPlaylist        Path to the m3u8 file.
	 * @param int    $rPrebuffer       Seconds of trailing segments to return; -1 for all; 0 for current id.
	 * @param int    $rSegmentDuration Assumed segment duration in seconds.
	 * @return array|string|null Segment list, current segment id, or null if missing.
	 */
	public static function getPlaylistSegments(string $rPlaylist, int $rPrebuffer = 0, int $rSegmentDuration = 10) {
		if (file_exists($rPlaylist)) {
			$rSource = file_get_contents($rPlaylist);
			if (preg_match_all('/(.*?).ts/', $rSource, $rMatches)) {
				if (0 < $rPrebuffer) {
					$rTotalSegments = intval($rPrebuffer / (($rSegmentDuration ?: 1)));
					return array_slice($rMatches[0], -1 * $rTotalSegments);
				}
				if ($rPrebuffer == -1) {
					return $rMatches[0];
				}
				preg_match('/_(.*)\\./', array_pop($rMatches[0]), $rCurrentSegment);
				return $rCurrentSegment[1];
			}
		}
		return null;
	}

	/**
	 * Rewrite an m3u8 so its segments are served through the admin endpoint.
	 *
	 * @param string $rM3U8     Path to the source m3u8.
	 * @param string $rPassword Admin password (used when no UI token).
	 * @param int    $rStreamID Stream id (used when no UI token).
	 * @param string $rUIToken  UI token; preferred auth when present.
	 * @return string|false Rewritten playlist text, or false if unavailable.
	 */
	public static function generateAdminHLS(string $rM3U8, string $rPassword, int $rStreamID, string $rUIToken) {
		if (file_exists($rM3U8)) {
			$rSource = file_get_contents($rM3U8);
			if (preg_match_all('/(.*?)\\.ts/', $rSource, $rMatches)) {
				foreach ($rMatches[0] as $rMatch) {
					if ($rUIToken) {
						$rSource = str_replace($rMatch, '/admin/live?extension=m3u8&segment=' . $rMatch . '&uitoken=' . $rUIToken, $rSource);
					} else {
						$rSource = str_replace($rMatch, '/admin/live?password=' . $rPassword . '&extension=m3u8&segment=' . $rMatch . '&stream=' . $rStreamID, $rSource);
					}
				}
				return $rSource;
			}
		}
		return false;
	}

	/**
	 * Whether a stream is healthy: its process runs and its playlist exists.
	 *
	 * @param string $rPlaylist Playlist path.
	 * @param int    $rPID      Process id to check.
	 * @param int    $rStreamID Stream whose producer must be running.
	 * @return bool True if running and the playlist file exists.
	 */
	public static function isValidStream(string $rPlaylist, int $rPID, int $rStreamID) {
		return ProcessManager::isStreamRunning($rPID, $rStreamID) && file_exists($rPlaylist);
	}

	/**
	 * Find the byte offset of the first keyframe in an MPEG-TS segment.
	 *
	 * @param string $rSegment Path to the .ts segment.
	 * @return int Byte offset of the keyframe (0 if not found).
	 */
	public static function findKeyframe(string $rSegment) {
		$rPacketSize = 188;
		$rKeyframe = $rPosition = 0;
		$rFoundStart = false;
		$rBuffer = '';
		if (file_exists($rSegment)) {
			$rFP = fopen($rSegment, 'rb');
			if ($rFP) {
				while (!feof($rFP)) {
					if (!$rFoundStart) {
						$rFirstPacket = fread($rFP, $rPacketSize);
						$rSecondPacket = fread($rFP, $rPacketSize);
						$i = 0;
						while ($i < strlen($rFirstPacket)) {
							list(, $rFirstHeader) = unpack('N', substr($rFirstPacket, $i, 4));
							list(, $rSecondHeader) = unpack('N', substr($rSecondPacket, $i, 4));
							$rSync = ($rFirstHeader >> 24 & 255) == 71 && ($rSecondHeader >> 24 & 255) == 71;
							if (!$rSync) {
								$i++;
							} else {
								$rFoundStart = true;
								$rPosition = $i;
								fseek($rFP, $i);
								break;
							}
						}
					}
					$rBuffer .= fread($rFP, $rPacketSize * 64 - strlen($rBuffer));
					if (!empty($rBuffer)) {
						foreach (str_split($rBuffer, $rPacketSize) as $rPacket) {
							list(, $rHeader) = unpack('N', substr($rPacket, 0, 4));
							$rSync = $rHeader >> 24 & 255;
							if ($rSync == 71) {
								if (substr($rPacket, 6, 4) == '?' . '' . "\r" . '' . '' . '' . "\x01") {
									$rKeyframe = $rPosition;
								} else {
									$rAdaptationField = $rHeader >> 4 & 3;
									if (($rAdaptationField & 2) === 2) {
										if (0 < $rKeyframe && unpack('C', $rPacket[4])[1] == 7 && substr($rPacket, 4, 2) == "\x07" . 'P') {
											break;
										}
									}
								}
							}
							$rPosition += strlen($rPacket);
						}
					}
					$rBuffer = '';
				}
				fclose($rFP);
			}
		}
		return $rKeyframe;
	}

	/**
	 * Estimate the average bitrate (kbps) of a movie file or live playlist.
	 *
	 * @param string      $rType          'movie' or 'live'.
	 * @param string      $rPath          File/playlist path.
	 * @param string|null $rForceDuration Duration "H:M:S" for movies (size-based estimate).
	 * @return int|false Average bitrate, or false if unavailable.
	 */
	public static function getStreamBitrate(string $rType, string $rPath, ?string $rForceDuration = null) {
		clearstatcache();
		if (file_exists($rPath)) {
			$rBitrate = 0;
			switch ($rType) {
				case 'movie':
					if (!is_null($rForceDuration)) {
						sscanf($rForceDuration, '%d:%d:%d', $rHours, $rMinutes, $rSeconds);
						$rTime = (isset($rSeconds) ? $rHours * 3600 + $rMinutes * 60 + $rSeconds : $rHours * 60 + $rMinutes);
						$rBitrate = round((filesize($rPath) * 0.008) / (($rTime ?: 1)));
					}
					break;
				case 'live':
					$rFP = fopen($rPath, 'r');
					$rBitrates = [];
					while (!feof($rFP)) {
						$rLine = trim(fgets($rFP));
						if (stristr($rLine, 'EXTINF')) {
							list($rTrash, $rSeconds) = explode(':', $rLine);
							$rSeconds = rtrim($rSeconds, ',');
							if ($rSeconds > 0) {
								$rSegmentFile = trim(fgets($rFP));
								if (file_exists(dirname($rPath) . '/' . $rSegmentFile)) {
									$rSize = filesize(dirname($rPath) . '/' . $rSegmentFile) * 0.008;
									$rBitrates[] = $rSize / (($rSeconds ?: 1));
								} else {
									fclose($rFP);
									return false;
								}
							}
						}
					}
					fclose($rFP);
					$rBitrate = (0 < count($rBitrates) ? round(array_sum($rBitrates) / count($rBitrates)) : 0);
					break;
			}
			return (0 < $rBitrate ? $rBitrate : false);
		}
		return false;
	}

	/**
	 * Strip path separators from a URL-encoded, user-supplied segment filename
	 * so it cannot traverse out of its directory. URL-decodes first, then
	 * removes every `/` and `\`.
	 *
	 * @param string $rRawSegment Raw (URL-encoded) segment from the request.
	 * @return string
	 */
	public static function sanitizeSegmentName(string $rRawSegment) {
		return str_replace(['\\', '/'], '', urldecode($rRawSegment));
	}

	/**
	 * MIME type for a VOD container extension, or `application/octet-stream`
	 * when unknown.
	 *
	 * @param string $rContainer Container extension (e.g. "mp4").
	 * @return string
	 */
	public static function containerMimeType(string $rContainer) {
		$rMap = [
			'mp4' => 'video/mp4',
			'mkv' => 'video/x-matroska',
			'avi' => 'video/x-msvideo',
			'3gp' => 'video/3gpp',
			'flv' => 'video/x-flv',
			'wmv' => 'video/x-ms-wmv',
			'mov' => 'video/quicktime',
			'ts'  => 'video/mp2t',
		];

		return $rMap[$rContainer] ?? 'application/octet-stream';
	}

	/**
	 * Parse the timeshift `start` parameter into a unix timestamp. Accepts a raw
	 * timestamp, `YYYYMMDD-H`, or `Y-m-d:H-i`.
	 *
	 * @return int
	 */
	public static function timeshiftStartTimestamp(string|int $rStartDate) {
		if (is_numeric($rStartDate)) {
			return (int) $rStartDate;
		}

		if (substr_count($rStartDate, '-') == 1) {
			list($rDate, $rHour) = explode('-', $rStartDate);

			return (int) mktime((int) $rHour, 0, 0, (int) substr($rDate, 4, 2), (int) substr($rDate, 6, 2), (int) substr($rDate, 0, 4));
		}

		list($rDate, $rTime) = explode(':', $rStartDate);
		list($rYear, $rMonth, $rDay) = explode('-', $rDate);
		list($rHour, $rMinutes) = explode('-', $rTime);

		return (int) mktime((int) $rHour, (int) $rMinutes, 0, (int) $rMonth, (int) $rDay, (int) $rYear);
	}

	/**
	 * Retry budget (seconds) the live loopback waits for the next HLS segment:
	 * the larger of twice the segment duration or the configured wait (0 → 20).
	 *
	 * @param int $rSegTimeSeconds        Segment duration in seconds.
	 * @param int $rConfiguredWaitSeconds Configured `segment_wait_time`.
	 * @return int
	 */
	public static function segmentRetryBudget(int $rSegTimeSeconds, int $rConfiguredWaitSeconds) {
		return max($rSegTimeSeconds * 2, $rConfiguredWaitSeconds ?: 20);
	}
}
