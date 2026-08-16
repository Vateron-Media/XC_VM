<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Http\CurlClient;
use XcVm\Core\Util\StreamUtils;
use XcVm\Streaming\Fanout\FanoutClient;

/**
 * StreamProcess — stream process
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamProcess {
	use \XcVm\Infrastructure\Database\DatabaseAware;
	/**
	 * Write stream action log to file
	 *
	 * Migrated from CoreUtilities::streamLog()
	 *
	 * @param int $rStreamID
	 * @param int $rServerID
	 * @param string $rAction
	 * @param string $rSource
	 */
	public static function streamLog($rStreamID, $rServerID, $rAction, $rSource = '') {
		if (SettingsManager::getAll()['save_restart_logs'] != 0) {
			$rData = array('server_id' => $rServerID, 'stream_id' => $rStreamID, 'action' => $rAction, 'source' => $rSource, 'time' => time());
			file_put_contents(LOGS_TMP_PATH . 'stream_log.log', base64_encode(json_encode($rData)) . "\n", FILE_APPEND);
		}
	}

	/**
	 * Clear cached runtime data for the given stream sources.
	 *
	 * @param array $rSources Source identifiers.
	 * @return void
	 */
	public static function deleteCache($rSources) {
		if (empty($rSources)) {
			return;
		}
		foreach ($rSources as $rSource) {
			if (file_exists(CACHE_TMP_PATH . md5($rSource))) {
				unlink(CACHE_TMP_PATH . md5($rSource));
			}
		}
	}

	/**
	 * Queue a channel to be started (optionally on a specific server).
	 *
	 * @param int      $rStreamID Stream id.
	 * @param int|null $rServerID Target server id, or null for the default.
	 * @return mixed Queue result.
	 */
	public static function queueChannel($rStreamID, $rServerID = null) {
		$db = self::db();
		if (!$rServerID) {
			$rServerID = SERVER_ID;
		}
		$db->query('SELECT `id` FROM `queue` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
		if ($db->num_rows() == 0) {
			$db->query("INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES('channel', ?, ?, ?);", $rStreamID, $rServerID, time());
		}
	}

	/**
	 * Create the runtime channel entry for a stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @return mixed Creation result.
	 */
	public static function createChannel($rStreamID) {
		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php created ' . intval($rStreamID) . ' >/dev/null 2>/dev/null &');
		return true;
	}

	/**
	 * Start the monitor process for a stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @param int $rRestart  Restart flag/counter.
	 * @return mixed Start result.
	 */
	public static function startMonitor($rStreamID, $rRestart = 0) {
		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php monitor ' . intval($rStreamID) . ' ' . intval($rRestart) . ' >/dev/null 2>/dev/null &');
		return true;
	}


	/**
	 * Start thumbnail generation for a stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @return mixed Start result.
	 */
	public static function startThumbnail($rStreamID) {
		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php thumbnail ' . intval($rStreamID) . ' >/dev/null 2>/dev/null &');
		return true;
	}

	/**
	 * Insert a cache-invalidation signal for the main server once — skips the
	 * insert when an identical pending signal already exists. Shared by
	 * updateStream / updateStreams.
	 *
	 * @param array $rCustomData Signal payload (type + id/ids).
	 * @return void
	 */
	private static function insertCacheSignalOnce(array $rCustomData) {
		$db = self::db();
		$rMainID = ConnectionTracker::getMainID();
		$rJson = json_encode($rCustomData);
		$db->query('SELECT COUNT(*) AS `count` FROM `signals` WHERE `server_id` = ? AND `cache` = 1 AND `custom_data` = ?;', $rMainID, $rJson);
		if (($db->get_row()['count'] ?? 0) == 0) {
			$db->query('INSERT INTO `signals`(`server_id`, `cache`, `time`, `custom_data`) VALUES(?, 1, ?, ?);', $rMainID, time(), $rJson);
		}
	}

	/**
	 * Push a stream's configuration update to its server.
	 *
	 * @param int  $rStreamID Stream id.
	 * @param bool $rForce    Force the update even if unchanged.
	 * @return mixed Update result.
	 */
	public static function updateStream($rStreamID, $rForce = false) {
		if (!SettingsManager::getAll()['enable_cache']) {
			return false;
		}
		self::insertCacheSignalOnce(array('type' => 'update_stream', 'id' => $rStreamID));
		return true;
	}

	/**
	 * Push configuration updates for multiple streams.
	 *
	 * @param int[] $rStreamIDs Stream ids.
	 * @return void
	 */
	public static function updateStreams($rStreamIDs) {
		if (!SettingsManager::getAll()['enable_cache']) {
			return;
		}
		self::insertCacheSignalOnce(array('type' => 'update_streams', 'id' => $rStreamIDs));
	}

	/**
	 * Build the `-filter_complex` logo/overlay input from transcode attributes.
	 *
	 * Attribute 16 carries the logo (val/pos), 17 enables deinterlace (yadif) and
	 * 9 an optional scale. Attribute 16 is consumed (unset in place) so it is not
	 * also emitted as a plain ffmpeg flag. Shared by createChannelItem, startMovie
	 * and startStream, which previously inlined this block verbatim.
	 *
	 * @param array $rTranscodeAttributes Transcode attributes (attr 16 unset in place).
	 * @param bool  $rLoopback            Loopback streams never overlay a logo.
	 * @return string `-i <logo> -filter_complex "..."`, or '' when no logo applies.
	 */
	private static function buildLogoFilterOptions(array &$rTranscodeAttributes, $rLoopback) {
		if (!isset($rTranscodeAttributes[16]) || $rLoopback) {
			return '';
		}
		$rAttr = $rTranscodeAttributes;
		$rLogoPath = $rAttr[16]['val'];
		$rPos = (isset($rAttr[16]['pos']) && $rAttr[16]['pos'] !== '10:10') ? $rAttr[16]['pos'] : '10:main_h-overlay_h-10';

		$rChain = array();
		$rBase = '[0:v]';
		$rVideoFilters = array();
		if (isset($rAttr[17])) {
			$rVideoFilters[] = 'yadif';
		}
		if (isset($rAttr[9]['val']) && strlen($rAttr[9]['val']) > 0) {
			$rVideoFilters[] = 'scale=' . $rAttr[9]['val'];
		}

		if (!empty($rVideoFilters)) {
			$rChain[] = $rBase . implode(',', $rVideoFilters) . '[bg]';
			$rBase = '[bg]';
		}

		$rChain[] = '[1:v]scale=250:-1[logo]';
		$rChain[] = $rBase . '[logo]overlay=' . $rPos;

		unset($rTranscodeAttributes[16]);
		return '-i ' . escapeshellarg($rLogoPath) . ' -filter_complex "' . implode('; ', $rChain) . '"';
	}

	/**
	 * Detect a CUVID hardware decoder for the source when GPU transcoding is on.
	 *
	 * Returns `-c:v <codec>_cuvid` for a known GPU-decodable codec, otherwise ''.
	 * Shared by createChannelItem and startMovie.
	 *
	 * @param string $rGpuOptions GPU command from the profile ('' = CPU path).
	 * @param string $rSourcePath Source URL/path to probe.
	 * @return string CUVID input-codec flag, or ''.
	 */
	private static function resolveGpuInputCodec($rGpuOptions, $rSourcePath) {
		if (empty($rGpuOptions)) {
			return '';
		}
		$rFFProbeOutput = \XcVm\Streaming\Codec\FFprobeRunner::probeStream($rSourcePath);
		if (in_array($rFFProbeOutput['codecs']['video']['codec_name'], array('h264', 'hevc', 'mjpeg', 'mpeg1', 'mpeg2', 'mpeg4', 'vc1', 'vp8', 'vp9'))) {
			return '-c:v ' . $rFFProbeOutput['codecs']['video']['codec_name'] . '_cuvid';
		}
		return '';
	}

	/**
	 * Default audio and video codecs to stream copy when the profile left them unset.
	 * Shared by createChannelItem, startMovie and startStream.
	 *
	 * @param array $rTranscodeAttributes Transcode attributes (modified in place).
	 */
	private static function applyDefaultCopyCodecs(array &$rTranscodeAttributes) {
		if (!array_key_exists('-acodec', $rTranscodeAttributes)) {
			$rTranscodeAttributes['-acodec'] = 'copy';
		}
		if (!array_key_exists('-vcodec', $rTranscodeAttributes)) {
			$rTranscodeAttributes['-vcodec'] = 'copy';
		}
	}

	/**
	 * Build the ffmpeg subtitle import + metadata options for a VOD movie.
	 *
	 * Imports every configured subtitle as an extra input and maps each one into
	 * the output. Inputs are 0 = main source and 1..N = subtitles, so metadata
	 * targets `-map <i+1>`.
	 *
	 * Previously the metadata loop was nested inside the import loop and reused
	 * the same `$i`, which made the import loop run only once (first subtitle) yet
	 * still emit `-map` for every file — so multi-subtitle movies imported one
	 * track but mapped non-existent inputs. The two loops are now siblings.
	 *
	 * @param string $rSubtitlesJson `movie_subtitles` JSON from the stream row.
	 * @param array  $rServers       Server registry (for remote subtitle fetch).
	 * @return array{0:string,1:string} [$rSubtitlesImport, $rSubtitlesMetadata].
	 */
	private static function buildSubtitleImport($rSubtitlesJson, $rServers) {
		$rSubtitles = json_decode($rSubtitlesJson, true);
		$rSubtitlesImport = '';
		$rSubtitlesMetadata = '';
		if (!empty($rSubtitles) && !empty($rSubtitles['files']) && is_array($rSubtitles['files'])) {
			$rCount = count($rSubtitles['files']);
			for ($i = 0; $i < $rCount; $i++) {
				$rSubtitleFile = escapeshellarg($rSubtitles['files'][$i]);
				$rInputCharset = escapeshellarg($rSubtitles['charset'][$i]);
				if ($rSubtitles['location'] == SERVER_ID) {
					$rSubtitlesImport .= '-sub_charenc ' . $rInputCharset . ' -i ' . $rSubtitleFile . ' ';
				} else {
					$rSubtitlesImport .= '-sub_charenc ' . $rInputCharset . ' -i "' . $rServers[$rSubtitles['location']]['api_url'] . '&action=getFile&filename=' . urlencode($rSubtitleFile) . '" ';
				}
			}
			for ($i = 0; $i < $rCount; $i++) {
				$rSubtitlesMetadata .= '-map ' . ($i + 1) . ' -metadata:s:s:' . $i . ' title=' . escapeshellcmd($rSubtitles['names'][$i]) . ' -metadata:s:s:' . $i . ' language=' . escapeshellcmd($rSubtitles['names'][$i]) . ' ';
			}
		}
		return array($rSubtitlesImport, $rSubtitlesMetadata);
	}

	/**
	 * Resolve the ffmpeg `-map` selection for a VOD transcode.
	 *
	 * A custom map (when set) wins; otherwise strip subtitles on request, else
	 * copy everything. Extracted from startMovie.
	 *
	 * @param string|null $rCustomMap       Admin custom map, or empty for the default.
	 * @param mixed       $rRemoveSubtitles Truthy (== 1) to drop subtitle streams.
	 * @return string The `-map ...` fragment.
	 */
	private static function resolveOutputMap($rCustomMap, $rRemoveSubtitles) {
		if (!empty($rCustomMap)) {
			return escapeshellcmd($rCustomMap) . ' -copy_unknown ';
		}
		if ($rRemoveSubtitles == 1) {
			return '-map 0:a -map 0:v';
		}
		return '-map 0 -copy_unknown ';
	}

	/**
	 * Pick the subtitle codec for a VOD target container. Extracted from startMovie.
	 *
	 * @param string $rContainer Target container (mp4/mkv/…).
	 * @return string ffmpeg subtitle codec: mov_text (mp4), srt (mkv), else copy.
	 */
	private static function subtitleCodecForContainer($rContainer) {
		if ($rContainer == 'mp4') {
			return 'mov_text';
		}
		if ($rContainer == 'mkv') {
			return 'srt';
		}
		return 'copy';
	}

	/**
	 * Whether a path recorded against another server ALSO resolves on this
	 * server's own filesystem — true for shared storage (SAN/NFS/bind-mount)
	 * that is mounted at an identical path on every node (Main + every LB).
	 *
	 * `stream_source` only ever records the server id the path was BROWSED
	 * from at import time (almost always Main, since only Main runs the admin
	 * UI) — it says nothing about whether the underlying storage is actually
	 * server-local or a shared mount. Callers used to treat "not the owning
	 * server id" as "must fetch over HTTP", which forces an LB to download the
	 * whole file via ffmpeg even when the exact same path is already mounted
	 * on it. Checking the real path first lets shared-mount files stay on the
	 * local/symlink path on every node instead of just the recorded owner.
	 *
	 * @param string $rPath Absolute filesystem path recorded in stream_source.
	 * @return bool
	 */
	private static function isLocallyMountedPath($rPath) {
		if (!is_string($rPath) || $rPath === '') {
			return false;
		}
		$rSharedPrefixes = SettingsManager::get('shared_mount_prefixes', array());
		foreach ($rSharedPrefixes as $rPrefix) {
			if ($rPrefix !== '' && strncmp($rPath, $rPrefix, strlen($rPrefix)) === 0) {
				return file_exists($rPath);
			}
		}
		return false;
	}

	/**
	 * Resolve a created-channel source string into [serverId, sourcePath].
	 *
	 * A plain string is a local path on this server. An `s:<serverId>:<path>`
	 * string references a file on another server; when that server is known —
	 * and the path is not also reachable locally via a shared mount, see
	 * isLocallyMountedPath() — it is rewritten to its getFile API URL,
	 * otherwise the raw path is kept. Extracted from createChannelItem. The
	 * `explode(':', …, 3)` limit keeps colons in the path intact.
	 *
	 * @param string $rSource  Source string (`path` or `s:<serverId>:<path>`).
	 * @param mixed  $rServers Server registry (array keyed by server id).
	 * @return array{0:int,1:string} [serverId, sourcePath]
	 */
	private static function resolveChannelSource($rSource, $rServers) {
		if (substr($rSource, 0, 2) == 's:') {
			$rSplit = explode(':', $rSource, 3);
			$rServerID = intval($rSplit[1]);
			$rSourcePath = $rSplit[2];
			if ($rServerID != SERVER_ID && !self::isLocallyMountedPath($rSplit[2])) {
				if (is_array($rServers) && isset($rServers[$rServerID])) {
					$rSourcePath = $rServers[$rServerID]['api_url'] . '&action=getFile&filename=' . urlencode($rSplit[2]);
				} else {
					$rSourcePath = $rSplit[2];
				}
			} else {
				// Either already local, or a shared-mount path that resolves
				// here too, report it as local so the symlink gate upstream
				// (createChannelItem's `$rServerID == SERVER_ID` check) fires.
				$rSourcePath = $rSplit[2];
				$rServerID = SERVER_ID;
			}
		} else {
			$rServerID = SERVER_ID;
			$rSourcePath = $rSource;
		}
		return array($rServerID, $rSourcePath);
	}

	/**
	 * Assemble the HLS/mpegts segmenter output arguments for a live stream.
	 *
	 * Pure string builder extracted from startStream. The caller still computes
	 * the conditional $rOptions, $rKeyFrames and $rInitTime and passes them in, so
	 * the control flow is unchanged.
	 *
	 * @param string $rOptions         Leading option placeholders ({MAP} {LLOD}).
	 * @param array  $rSegmentSettings seg_time / seg_list_size / seg_delete_threshold.
	 * @param string $rKeyFrames       Extra hls_flags (e.g. '+split_by_time') or ''.
	 * @param int    $rInitTime        hls_init_time seconds.
	 * @param int    $rStreamID        Stream id (segment/playlist filenames).
	 * @return string The `-f hls …` output fragment.
	 */
	private static function buildHlsMpegtsOutput($rOptions, $rSegmentSettings, $rKeyFrames, $rInitTime, $rStreamID) {
		return $rOptions . ' -individual_header_trailer 0 -f hls -hls_init_time ' . $rInitTime
			. ' -hls_time ' . intval($rSegmentSettings['seg_time'])
			. ' -hls_list_size ' . intval($rSegmentSettings['seg_list_size'])
			. ' -hls_delete_threshold ' . intval($rSegmentSettings['seg_delete_threshold'])
			. ' -hls_flags delete_segments+discont_start+omit_endlist' . $rKeyFrames
			. ' -hls_segment_type mpegts -hls_segment_filename "' . STREAMS_PATH . intval($rStreamID) . '_%d.ts" "'
			. STREAMS_PATH . intval($rStreamID) . '_.m3u8" ';
	}

	/**
	 * Same HLS output as {@see buildHlsMpegtsOutput()} but fanned through the
	 * `tee` muxer so the stream also feeds the xc_fanout daemon (ADR 0003, A2):
	 * slave 1 is the existing on-disk HLS, slave 2 pushes mpegts into the daemon's
	 * ingest socket. `onfail=ignore` on the daemon slave keeps the HLS output
	 * alive if the daemon is down/restarts (verified: ffmpeg "continuing with 1/2
	 * slaves"). Used only when the daemon accepted an ingest registration; a plain
	 * multi-output would abort ffmpeg entirely on a failed daemon output.
	 *
	 * The per-muxer flags move from `-flag value` form into the tee slave's
	 * `:flag=value` form; the leading {MAP}/{LLOD} options stay shared before
	 * `-f tee`.
	 *
	 * @param string $rIngestSock Daemon ingest socket (from FanoutClient::registerIngest()).
	 * @return string The `-f tee …` output fragment.
	 */
	private static function buildHlsTeeOutput($rOptions, $rSegmentSettings, $rKeyFrames, $rInitTime, $rStreamID, $rIngestSock) {
		$rHls = '[f=hls'
			. ':hls_init_time=' . $rInitTime
			. ':hls_time=' . intval($rSegmentSettings['seg_time'])
			. ':hls_list_size=' . intval($rSegmentSettings['seg_list_size'])
			. ':hls_delete_threshold=' . intval($rSegmentSettings['seg_delete_threshold'])
			. ':hls_flags=delete_segments+discont_start+omit_endlist' . $rKeyFrames
			. ':hls_segment_type=mpegts'
			// NB: the flag-form output has `-individual_header_trailer 0`, but that
			// is a `segment`-muxer option the `hls` muxer ignores (silent no-op in
			// flag form; a FATAL "Unknown option" inside a tee slave). Omitted here.
			. ':hls_segment_filename=' . STREAMS_PATH . intval($rStreamID) . '_%d.ts'
			. ']' . STREAMS_PATH . intval($rStreamID) . '_.m3u8';
		$rDaemon = '[f=mpegts:onfail=ignore:mpegts_flags=+initial_discontinuity]unix:' . $rIngestSock;

		return $rOptions . ' -f tee "' . $rHls . '|' . $rDaemon . '"';
	}

	/**
	 * Wrap an FLV output target (local RTMP relay or external push URL) with the
	 * shared `-f flv -flvflags no_duration_filesize` options. Extracted from the
	 * two identical FLV output lines in startStream.
	 *
	 * @param string $rFLVOptions Leading option placeholders ({MAP} {AAC_FILTER}).
	 * @param string $rTarget     The rtmp:// URL or escaped push URL.
	 * @return string The `… -f flv … <target> ` output fragment.
	 */
	private static function buildFlvOutput($rFLVOptions, $rTarget) {
		return $rFLVOptions . ' -f flv -flvflags no_duration_filesize ' . $rTarget . ' ';
	}

	/**
	 * Resolve ffprobe/analysis timing for a live stream start.
	 *
	 * On-demand streams use a small (LLOD) or medium analysis window and the
	 * per-stream probesize; everything else uses the global settings. The read
	 * timeout is derived from the analysis window plus the configured slack.
	 * Extracted from startStream.
	 *
	 * @param mixed $rOnDemand          server_info on_demand flag (== 1 → on-demand).
	 * @param mixed $rProbesizeOndemand Per-stream on-demand probesize (0 → default).
	 * @param bool  $rLLOD              Live-on-demand (shorter analysis window).
	 * @param array $rSettings          Global settings (analyze/probesize/slack).
	 * @return array{0:int,1:int|string,2:int} [probesize, analyzeDuration, timeout]
	 */
	private static function resolveProbeSettings($rOnDemand, $rProbesizeOndemand, $rLLOD, $rSettings) {
		if ($rOnDemand == 1) {
			$rProbesize = intval($rProbesizeOndemand) ?: 1000000;
			$rAnalyseDuration = ($rLLOD ? '500000' : '10000000');
		} else {
			$rAnalyseDuration = abs(intval($rSettings['stream_max_analyze']));
			$rProbesize = abs(intval($rSettings['probesize']));
		}
		$rTimeout = intval($rAnalyseDuration / 1000000) + $rSettings['probe_extra_wait'];
		return array($rProbesize, $rAnalyseDuration, $rTimeout);
	}

	/**
	 * Failover ordering for a stream's source list.
	 *
	 * Unless priority-backup mode is on, and when the last-used source is still
	 * in the list, rotate every source up to and including it to the end so the
	 * NEXT untried source leads (already-tried sources become fallbacks).
	 * Extracted from startStream.
	 *
	 * @param array  $rSources        Ordered source list.
	 * @param mixed  $rPriorityBackup priority_backup setting (== 1 → keep order).
	 * @param mixed  $rCurrentSource  Last-used source, or empty.
	 * @return array The (possibly) reordered source list, re-indexed.
	 */
	private static function rotateSourcesPastCurrent($rSources, $rPriorityBackup, $rCurrentSource) {
		if ($rPriorityBackup == 1 || empty($rCurrentSource)) {
			return $rSources;
		}
		$k = array_search($rCurrentSource, $rSources);
		if ($k === false) {
			return $rSources;
		}
		$i = 0;
		while ($i <= $k) {
			$rTemp = $rSources[$i];
			unset($rSources[$i]);
			array_push($rSources, $rTemp);
			$i++;
		}
		return array_values($rSources);
	}

	/**
	 * Append an extra HTTP header line to a stream's ffmpeg argument list.
	 *
	 * If the list already carries a 'headers' entry, the line is appended to it
	 * (CRLF-separated); otherwise a new 'headers' fetch argument is added. Mirrors
	 * the X-XC_VM-* header injection repeated in startStream.
	 *
	 * @param array  $rArguments  Argument list (each entry an assoc array).
	 * @param string $rHeaderLine e.g. 'X-XC_VM-Detect:1'.
	 * @return array The argument list with the header applied.
	 */
	private static function appendHeaderArgument($rArguments, $rHeaderLine) {
		$rApplied = false;
		foreach (array_keys($rArguments) as $rID) {
			if ($rArguments[$rID]['argument_key'] == 'headers') {
				$rArguments[$rID]['value'] .= "\r\n" . $rHeaderLine;
				$rApplied = true;
			}
		}
		if (!$rApplied) {
			$rArguments[] = array('value' => $rHeaderLine, 'argument_key' => 'headers', 'argument_cat' => 'fetch', 'argument_wprotocol' => 'http', 'argument_type' => 'text', 'argument_cmd' => "-headers '%s" . "\r\n" . "'");
		}
		return $rArguments;
	}

	/**
	 * Derive the codec metadata persisted for a started live stream from its
	 * ffprobe output: player compatibility, audio/video codec names and the
	 * resolution snapped to the nearest standard height. Extracted from startStream.
	 *
	 * @param mixed $rFFProbeOutput ffprobe result (array with a 'codecs' entry) or anything else.
	 * @param mixed $rAllowHevc     player_allow_hevc setting, passed to the compatibility check.
	 * @return array{0:int,1:?string,2:?string,3:mixed} [compatible, audioCodec, videoCodec, resolution]
	 */
	private static function resolveStreamCodecMeta($rFFProbeOutput, $rAllowHevc) {
		$rCompatible = 0;
		$rAudioCodec = $rVideoCodec = $rResolution = null;

		if (is_array($rFFProbeOutput) && isset($rFFProbeOutput['codecs']) && is_array($rFFProbeOutput['codecs'])) {
			$rCompatible = intval(DiagnosticsService::checkCompatibility($rFFProbeOutput, $rAllowHevc));
			$rAudioCodec = isset($rFFProbeOutput['codecs']['audio']['codec_name']) ? $rFFProbeOutput['codecs']['audio']['codec_name'] : null;
			$rVideoCodec = isset($rFFProbeOutput['codecs']['video']['codec_name']) ? $rFFProbeOutput['codecs']['video']['codec_name'] : null;
			$rResolution = isset($rFFProbeOutput['codecs']['video']['height']) ? $rFFProbeOutput['codecs']['video']['height'] : null;

			if ($rResolution) {
				$rResolution = StreamSorter::getNearest(array(240, 360, 480, 576, 720, 1080, 1440, 2160), $rResolution);
			}
		}

		return array($rCompatible, $rAudioCodec, $rVideoCodec, $rResolution);
	}

	/**
	 * The AAC ADTS-to-ASC bitstream filter, required when a copied AAC audio
	 * stream is muxed into a non-FLV container. Extracted from startStream.
	 *
	 * @param mixed $rContainer  ffprobe container name.
	 * @param mixed $rAudioCodec ffprobe audio codec name.
	 * @param mixed $rACodec     resolved output -acodec ('' when absent).
	 * @return string '-bsf:a aac_adtstoasc' when applicable, otherwise ''.
	 */
	private static function aacBitstreamFilter($rContainer, $rAudioCodec, $rACodec) {
		return (!stristr($rContainer, 'flv') && $rAudioCodec === 'aac' && $rACodec === 'copy') ? '-bsf:a aac_adtstoasc' : '';
	}

	/**
	 * Whether the stream's arguments request skipping ffprobe (skip_ffprobe == 1).
	 * Extracted from startStream.
	 *
	 * @param array $rArguments Stream arguments (each an assoc array).
	 * @return bool
	 */
	private static function hasSkipFFProbe($rArguments) {
		foreach ($rArguments as $rArg) {
			if ($rArg['argument_key'] == 'skip_ffprobe' && $rArg['value'] == 1) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The assumed ffprobe result used when ffprobe is skipped: a plain h264/aac
	 * mpegts stream. Extracted from startStream.
	 *
	 * @return array
	 */
	private static function skipFFProbeOutput() {
		return array(
			'codecs' => array(
				'video' => array('codec_name' => 'h264', 'codec_type' => 'video', 'height' => 1080),
				'audio' => array('codec_name' => 'aac', 'codec_type' => 'audio')
			),
			'container' => 'mpegts'
		);
	}

	/**
	 * Resume segment number for a delayed HLS stream, parsed from the existing
	 * delay playlist lines. Reads the last (or previous) line's "_<n>.ts" index
	 * and returns n+1; 0 when no index is found. Extracted from startStream.
	 *
	 * @param array $rLines    Playlist lines (>= 2, newest last).
	 * @param mixed $rStreamID Stream id; its "<id>_" marks the stream's own segment line.
	 * @return int Next segment number, or 0.
	 */
	private static function resolveDelaySegmentStart($rLines, $rStreamID) {
		$rLast = $rLines[count($rLines) - 1];
		$rPrev = $rLines[count($rLines) - 2];
		$rTarget = stristr($rLast, $rStreamID . '_') ? $rLast : $rPrev;
		if (preg_match('/_(.*?)\.ts/', $rTarget, $rMatches)) {
			return intval($rMatches[1]) + 1;
		}
		return 0;
	}

	/**
	 * Delay sleep seconds for a stream: delay_minutes*60, reduced by ~10s per
	 * already-produced segment (never below 0). Extracted from startStream.
	 *
	 * @param mixed $rDelayMinutes Configured delay in minutes.
	 * @param int   $rSegmentStart Resume segment number (0 = fresh).
	 * @return int Seconds to sleep.
	 */
	private static function resolveDelaySleepTime($rDelayMinutes, $rSegmentStart) {
		$rSleepTime = $rDelayMinutes * 60;
		if ($rSegmentStart > 0) {
			$rSleepTime -= ($rSegmentStart - 1) * 10;
			if ($rSleepTime <= 0) {
				$rSleepTime = 0;
			}
		}
		return $rSleepTime;
	}

	/**
	 * Generate and persist a fresh AES-128-CBC key + IV for a stream's HLS
	 * encryption (the _.key / _.iv sidecar files). Identical setup used by every
	 * launcher (startStream / startLoopback / startLLOD).
	 *
	 * @param int $rStreamID Stream id.
	 * @return void
	 */
	private static function writeStreamKeyIv($rStreamID) {
		$rKey = openssl_random_pseudo_bytes(16);
		file_put_contents(STREAMS_PATH . $rStreamID . '_.key', $rKey);
		$rIVSize = openssl_cipher_iv_length('AES-128-CBC');
		$rIV = openssl_random_pseudo_bytes($rIVSize);
		file_put_contents(STREAMS_PATH . $rStreamID . '_.iv', $rIV);
	}

	/**
	 * Clear a stream's leftover segments and stale PID file before a (re)launch.
	 * Shared preamble of the loopback / LLOD launchers.
	 *
	 * @param int $rStreamID Stream id.
	 * @return void
	 */
	private static function clearStreamPidSegments($rStreamID) {
		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*.ts');
		if (file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
			unlink(STREAMS_PATH . $rStreamID . '_.pid');
		}
	}

	/**
	 * Read a stream's PID from its sidecar file if present, else fall back to
	 * the named streams_servers column. Used when stopping a stream to locate
	 * the feed and monitor processes.
	 *
	 * @param int    $rStreamID Stream id.
	 * @param string $rColumn   Column to fall back to ('pid' or 'monitor_pid').
	 * @param string $rSuffix   Sidecar suffix ('_.pid' or '_.monitor').
	 * @return int PID, or 0 if none.
	 */
	private static function pidFromFileOrColumn($rStreamID, $rColumn, $rSuffix) {
		if (file_exists(STREAMS_PATH . $rStreamID . $rSuffix)) {
			return intval(file_get_contents(STREAMS_PATH . $rStreamID . $rSuffix));
		}
		$db = self::db();
		$db->query('SELECT `' . $rColumn . '` FROM `streams_servers` WHERE `server_id` = ? AND `stream_id` = ? LIMIT 1;', SERVER_ID, $rStreamID);
		$rStreamServer = $db->get_row();
		return intval($rStreamServer[$rColumn] ?? 0);
	}

	/**
	 * Reset a stream's per-server runtime row to the stopped state — clear pid,
	 * source, codecs, status and analysis flags. Shared by the stop paths.
	 *
	 * @param int  $rStreamID    Stream id.
	 * @param bool $rWithMonitor Also clear monitor_pid (full stop vs. movie stop).
	 * @return void
	 */
	private static function resetStreamServerRow($rStreamID, $rWithMonitor = false) {
		$rMonitor = $rWithMonitor ? ',`monitor_pid` = NULL' : '';
		self::db()->query('UPDATE `streams_servers` SET `bitrate` = NULL,`current_source` = NULL,`to_analyze` = 0,`pid` = NULL,`stream_started` = NULL,`stream_info` = NULL,`audio_codec` = NULL,`video_codec` = NULL,`resolution` = NULL,`compatible` = 0,`stream_status` = 0' . $rMonitor . ' WHERE `stream_id` = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
	}

	/**
	 * Assemble the live ffmpeg command string from prepared state. PURE: no
	 * probe/DB/shell/file I/O — the single source of truth for the live command,
	 * fed from $data instead of loop-local variables. The delay-playlist I/O and
	 * the segment-start/sleep computation stay in startStream and arrive via
	 * $data['segmentStart']/['delayActive'].
	 *
	 * @param array $data Prepared assembly inputs (see startStream call site).
	 * @return string The full shell command (ffmpeg + outputs + redirects + pid).
	 */
	private static function buildLive(array $data): string {
		$rStream = $data['stream'];
		$rSettings = $data['settings'];
		$rServers = $data['servers'];
		$rStreamID = $data['streamID'];
		$rStreamSource = $data['streamSource'];
		$rFetchOptions = $data['fetchOptions'];
		$rFFProbeOutput = $data['ffprobe'];
		$rProtocol = $data['protocol'];
		$rSource = $data['source'];
		$rSegmentSettings = $data['segmentSettings'];
		$rExternalPush = $data['externalPush'];
		$rProbesize = $data['probesize'];
		$rAnalyseDuration = $data['analyseDuration'];
		$rLLOD = $data['llod'];
		$rLoopback = $data['loopback'];
		$rSegmentStart = $data['segmentStart'];
		$rDelayActive = $data['delayActive'];
		$rFFMPEG_CPU = $data['ffmpegCpu'];
		$rFFMPEG_GPU = $data['ffmpegGpu'];

		$externalPushJson = $rStream['stream_info']['external_push'] ?? '[]';
		$rExternalPush = json_decode($externalPushJson, true);
		// ffmpeg writes progress reports to this local file; StreamsCronJob tails it.
		// (PHP-FPM cannot read ffmpeg's open-ended chunked progress POST, so the HTTP
		// /progress endpoint only ever ran at stream end -> speed was stuck at "1x".)
		$rProgressFile = STREAMS_PATH . intval($rStreamID) . '_.progress';
		// LLOD input resilience: an on-demand HTTP source that drops the
		// connection makes ffmpeg exit ("Stream ends prematurely"), which the
		// watchdog then restarts — turning a brief upstream hiccup into a
		// multi-second re-probe gap and client re-buffering. Reconnecting keeps
		// ffmpeg alive across drops instead of dying. Guarded to HTTP(S): these
		// options are http-protocol-only and are a fatal "Option not found"
		// error on udp/rtmp/file inputs.
		$rLLODReconnect = ($rLLOD && !$rLoopback && is_string($rSource) && preg_match('#^https?://#i', $rSource))
			? '-reconnect 1 -reconnect_streamed 1 -reconnect_delay_max 5 '
			: '';
		$rLLODInputFlags = ($rLLOD && !$rLoopback ? $rLLODReconnect . '-fflags +discardcorrupt ' : '');

		// Command-template defaults: only the non-custom_ffmpeg branch below
		// assigns these, yet the {MAP}/{GEN_PTS}/{READ_NATIVE} substitution and
		// the delay sleep read them unconditionally. Default them so the
		// custom_ffmpeg path (which skips the branch) stays defined.
		$rMap = '';
		$rGenPTS = '';
		$rReadNative = '';
		$rSleepTime = 0;
		if (empty($rStream['stream_info']['custom_ffmpeg'])) {
			if ($rLoopback) {
				$rOptions = '{FETCH_OPTIONS}';
			} else {
				$rOptions = '{GPU} {FETCH_OPTIONS}';
			}

			if ($rStream['stream_info']['stream_all'] == 1) {
				$rMap = '-map 0 -copy_unknown ';
			} else {
				if (!empty($rStream['stream_info']['custom_map'])) {
					$rMap = escapeshellcmd($rStream['stream_info']['custom_map']) . ' -copy_unknown ';
				} else {
					if ($rStream['stream_info']['type_key'] == 'radio_streams') {
						$rMap = '-map 0:a? ';
					} else {
						$rMap = '';
					}
				}
			}

			if (($rStream['stream_info']['gen_timestamps'] == 1 || empty($rProtocol)) && $rStream['stream_info']['type_key'] != 'created_live') {
				$rGenPTS = '-fflags +genpts -async 1';
			} else {
				if (is_array($rFFProbeOutput) && isset($rFFProbeOutput['codecs']['audio']['codec_name']) && in_array($rFFProbeOutput['codecs']['audio']['codec_name'], array('ac3', 'eac3')) && $rSettings['dts_legacy_ffmpeg']) {
					$rFFMPEG_CPU = FFMPEG_BIN_40;
				}

				$rNoFix = ($rFFMPEG_CPU == FFMPEG_BIN_40 ? '-nofix_dts' : '');
				$rGenPTS = $rNoFix . ' -start_at_zero -copyts -vsync 0 -correct_ts_overflow 0 -avoid_negative_ts disabled -max_interleave_delta 0';
			}

			$container = (isset($rFFProbeOutput) && is_array($rFFProbeOutput)) ? ($rFFProbeOutput['container'] ?? null) : null;
			if (empty($rStream['server_info']['parent_id']) && (($rStream['stream_info']['read_native'] == 1) || ($container && stristr($container, 'hls') && $rSettings['read_native_hls']) || empty($rProtocol) || ($container && stristr($container, 'mp4')) || ($container && stristr($container, 'matroska')))) {
				$rReadNative = '-re';
			} else {
				$rReadNative = '';
			}

			if (!$rStream['server_info']['parent_id'] && $rStream['stream_info']['enable_transcode'] == 1 && $rStream['stream_info']['type_key'] != 'created_live') {
				if ($rStream['stream_info']['transcode_profile_id'] == -1) {
					$rStream['stream_info']['transcode_attributes'] = array_merge(StreamUtils::getArguments($rStream['stream_arguments'], $rProtocol, 'transcode'), json_decode((string) $rStream['stream_info']['transcode_attributes'], true) ?: array());
				} else {
					$rStream['stream_info']['transcode_attributes'] = json_decode((string) $rStream['stream_info']['profile_options'], true) ?: array();
				}
			} else {
				$rStream['stream_info']['transcode_attributes'] = array();
			}

			$rFFMPEG = ((isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rFFMPEG_GPU : $rFFMPEG_CPU)) . ' -y -nostdin -hide_banner -loglevel ' . (($rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err -thread_queue_size 1024 ' . $rOptions . ' {GEN_PTS} {READ_NATIVE} ' . $rLLODInputFlags . '-probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' -progress "' . $rProgressFile . '" {CONCAT} -i {STREAM_SOURCE} {LOGO} -max_muxing_queue_size 1024 ';

			self::applyDefaultCopyCodecs($rStream['stream_info']['transcode_attributes']);

			if (!array_key_exists('-scodec', $rStream['stream_info']['transcode_attributes'])) {
				$rStream['stream_info']['transcode_attributes']['-sn'] = '';
			}
		} else {
			$rStream['stream_info']['transcode_attributes'] = array();
			$rFFMPEG = ((stripos($rStream['stream_info']['custom_ffmpeg'], 'nvenc') !== false ? $rFFMPEG_GPU : $rFFMPEG_CPU)) . ' -y -nostdin -hide_banner -loglevel ' . (($rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -progress "' . $rProgressFile . '" ' . $rStream['stream_info']['custom_ffmpeg'];
		}

		$rLLODOptions = ($rLLOD && !$rLoopback ? '-tune zerolatency -fflags nobuffer -flags low_delay -strict experimental -threads 0' : '');
		$rOutputs = array();

		if ($rLoopback) {
			$rOptions = '{MAP}';
			$rFLVOptions = '{MAP}';
			$rMap = '-map 0 -copy_unknown ';
		} else {
			$rOptions = '{MAP} {LLOD}';
			$rFLVOptions = '{MAP} {AAC_FILTER}';
		}

		$rKeyFrames = ($rSettings['ignore_keyframes'] ? '+split_by_time' : '');
		// Fast start: shorten the first segment so players can begin sooner.
		// Capped at seg_time so a small seg_time never produces a longer first segment.
		$rInitTime = min(2, intval($rSegmentSettings['seg_time']));
		// When the xc_fanout daemon accepted an ingest registration (reachable),
		// tee the HLS output to it too (ADR 0003, A2). Standard live only — not
		// loopback/delay. If the daemon was unreachable ($data['ingestSock'] is
		// null) the original on-disk-only HLS output runs, unchanged.
		if (!$rLoopback && !$rDelayActive && !empty($data['ingestSock'])) {
			// The tee muxer needs an EXPLICIT -map — plain single outputs use
			// ffmpeg's automatic stream selection, but tee does not ("Output file
			// does not contain any stream" otherwise). Reuse the stream's own map,
			// or -map 0 -copy_unknown when it relies on automatic selection.
			$rTeeMap = ($rMap !== '' ? $rMap : '-map 0 -copy_unknown ');
			$rOutputs['mpegts'][] = self::buildHlsTeeOutput($rTeeMap . '{LLOD}', $rSegmentSettings, $rKeyFrames, $rInitTime, $rStreamID, $data['ingestSock']);
		} else {
			$rOutputs['mpegts'][] = self::buildHlsMpegtsOutput($rOptions, $rSegmentSettings, $rKeyFrames, $rInitTime, $rStreamID);
		}

		if ($rStream['stream_info']['rtmp_output'] == 1) {
			$rOutputs['flv'][] = self::buildFlvOutput($rFLVOptions, 'rtmp://127.0.0.1:' . intval($rServers[$rStream['server_info']['server_id']]['rtmp_port']) . '/live/' . intval($rStreamID) . '?password=' . urlencode($rSettings['live_streaming_pass']));
		}

		if (!empty($rExternalPush[SERVER_ID])) {
			foreach ($rExternalPush[SERVER_ID] as $rPushURL) {
				$rOutputs['flv'][] = self::buildFlvOutput($rFLVOptions, escapeshellarg($rPushURL));
			}
		}

		$rLogoOptions = self::buildLogoFilterOptions($rStream['stream_info']['transcode_attributes'], $rLoopback);

		$rGPUOptions = (isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rStream['stream_info']['transcode_attributes']['gpu']['cmd'] : '');
		$rInputCodec = '';

		$supportedCodecs = ['h264', 'hevc', 'mjpeg', 'mpeg1', 'mpeg2', 'mpeg4', 'vc1', 'vp8', 'vp9'];
		$videoCodec = null;
		if (isset($rFFProbeOutput) && is_array($rFFProbeOutput)) {
			$videoCodec = $rFFProbeOutput['codecs']['video']['codec_name'] ?? null;
		}

		if (!empty($rGPUOptions) && in_array($videoCodec, $supportedCodecs)) {
			$rInputCodec = '-c:v ' . $rFFProbeOutput['codecs']['video']['codec_name'] . '_cuvid';
		}


		if (!$rDelayActive) {
			foreach ($rOutputs as $rOutputCommands) {
				foreach ($rOutputCommands as $rOutputCommand) {
					if (isset($rStream['stream_info']['transcode_attributes']['gpu'])) {
						$rFFMPEG .= '-gpu ' . intval($rStream['stream_info']['transcode_attributes']['gpu']['device']) . ' ';
					}

					$rFFMPEG .= implode(' ', StreamUtils::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
					$rFFMPEG .= $rOutputCommand;
				}
			}
		} else {
			$rFFMPEG .= implode(' ', StreamUtils::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
			$rFFMPEG .= '{MAP} -individual_header_trailer 0 -f hls -hls_time ' . intval($rSegmentSettings['seg_time']) . ' -hls_list_size ' . intval($rStream['stream_info']['delay_minutes']) * 6 . ' -hls_delete_threshold 4 -start_number ' . $rSegmentStart . ' -hls_flags delete_segments+discont_start+omit_endlist -hls_segment_type mpegts -hls_segment_filename "' . DELAY_PATH . intval($rStreamID) . '_%d.ts" "' . DELAY_PATH . intval($rStreamID) . '_.m3u8" ';
		}

		$rFFMPEG .= ' >/dev/null 2>>' . STREAMS_PATH . intval($rStreamID) . '.errors & echo $! > ' . STREAMS_PATH . intval($rStreamID) . '_.pid';

		$ffprobeContainer = (isset($rFFProbeOutput['container']) && is_string($rFFProbeOutput['container'])) ? $rFFProbeOutput['container'] : '';

		$audioCodec = (isset($rFFProbeOutput['codecs']['audio']['codec_name']) && is_array($rFFProbeOutput['codecs']['audio'])) ? $rFFProbeOutput['codecs']['audio']['codec_name'] : '';

		$rFFMPEG = str_replace(
			['{FETCH_OPTIONS}', '{GEN_PTS}', '{STREAM_SOURCE}', '{MAP}', '{READ_NATIVE}', '{CONCAT}', '{AAC_FILTER}', '{GPU}', '{INPUT_CODEC}', '{LOGO}', '{LLOD}'],
			[
				empty($rStream['stream_info']['custom_ffmpeg']) ? $rFetchOptions : '',
				empty($rStream['stream_info']['custom_ffmpeg']) ? $rGenPTS : '',
				escapeshellarg($rStreamSource),
				empty($rStream['stream_info']['custom_ffmpeg']) ? $rMap : '',
				empty($rStream['stream_info']['custom_ffmpeg']) ? $rReadNative : '',
				($rStream['stream_info']['type_key'] == 'created_live' && empty($rStream['server_info']['parent_id']) ? '-safe 0 -f concat' : ''),
				self::aacBitstreamFilter($ffprobeContainer, $audioCodec, $rStream['stream_info']['transcode_attributes']['-acodec'] ?? ''),
				$rGPUOptions,
				$rInputCodec,
				$rLogoOptions,
				$rLLODOptions
			],
			$rFFMPEG
		);

		return $rFFMPEG;
	}

	public static function createChannelItem($rStreamID, $rSource) {
		global $rSettings, $rServers, $rFFMPEG_CPU, $rFFMPEG_GPU;
		$db = self::db();
		$rStream = array();
		$rLoopback = false;
		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t1.type = 3 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);
		if ($db->num_rows() > 0) {
			$rStream['stream_info'] = $db->get_row();
			$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			if ($db->num_rows() > 0) {
				$rStream['server_info'] = $db->get_row();
				$rMD5 = md5($rSource);
				list($rServerID, $rSourcePath) = self::resolveChannelSource($rSource, $rServers);

				if ($rServerID == SERVER_ID && intval($rStream['stream_info']['movie_symlink']) == 1) {
					$rExtension = pathinfo($rSource)['extension'];
					if (strlen($rExtension) == 0) {
						$rExtension = 'mp4';
					}
					$rCommand = 'ln -sfn ' . escapeshellarg($rSourcePath) . ' "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.' . escapeshellcmd($rExtension) . '" >/dev/null 2>/dev/null & echo $! > "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid"';
				} else {
					$rStream['stream_info']['transcode_attributes'] = json_decode($rStream['stream_info']['profile_options'], true);
					if (!is_array($rStream['stream_info']['transcode_attributes'])) {
						$rStream['stream_info']['transcode_attributes'] = array();
					}

					$rLogoOptions = self::buildLogoFilterOptions($rStream['stream_info']['transcode_attributes'], $rLoopback);

					$rGPUOptions = (isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rStream['stream_info']['transcode_attributes']['gpu']['cmd'] : '');
					$rInputCodec = self::resolveGpuInputCodec($rGPUOptions, $rSourcePath);

					$rCommand = ((isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rFFMPEG_GPU : $rFFMPEG_CPU)) . ' -y -nostdin -hide_banner -loglevel ' . (($rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err -progress "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.progress" {GPU} -fflags +genpts -async 1 -i {STREAM_SOURCE} {LOGO} ';

					self::applyDefaultCopyCodecs($rStream['stream_info']['transcode_attributes']);
					if (isset($rStream['stream_info']['transcode_attributes']['gpu'])) {
						$rCommand .= '-gpu ' . intval($rStream['stream_info']['transcode_attributes']['gpu']['device']) . ' ';
					}
					$rCommand .= implode(' ', StreamUtils::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
					$rCommand .= '-strict -2 -mpegts_flags +initial_discontinuity -f mpegts "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.ts"';
					$rCommand .= ' >/dev/null 2>"' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.errors" & echo $! > "' . CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid"';
					$rCommand = str_replace(array('{GPU}', '{INPUT_CODEC}', '{LOGO}', '{STREAM_SOURCE}'), array($rGPUOptions, $rInputCodec, $rLogoOptions, escapeshellarg($rSourcePath)), $rCommand);
				}

				shell_exec($rCommand);
				return intval(file_get_contents(CREATED_PATH . intval($rStreamID) . '_' . $rMD5 . '.pid'));
			}
			return false;
		}
		return false;
	}

	/**
	 * Stop a running channel stream.
	 *
	 * @param int  $rStreamID Stream id.
	 * @param bool $rStop     Mark the stream as fully stopped (not just restarting).
	 * @return mixed Stop result.
	 */
	public static function stopStream($rStreamID, $rStop = false) {
		$rMonitor = self::pidFromFileOrColumn($rStreamID, 'monitor_pid', '_.monitor');

		if (0 < $rMonitor && \XcVm\Streaming\Health\ProcessChecker::checkPID($rMonitor, array('XC_VM[' . $rStreamID . ']', 'XC_VMProxy[' . $rStreamID . ']')) && is_numeric($rMonitor)) {
			posix_kill($rMonitor, 9);
		}

		$rPID = self::pidFromFileOrColumn($rStreamID, 'pid', '_.pid');

		if (0 < $rPID && \XcVm\Streaming\Health\ProcessChecker::checkPID($rPID, array($rStreamID . '_.m3u8', $rStreamID . '_%d.ts', 'LLOD[' . $rStreamID . ']', 'XC_VMProxy[' . $rStreamID . ']', 'Loopback[' . $rStreamID . ']')) && is_numeric($rPID)) {
			posix_kill($rPID, 9);
		}

		if (file_exists(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID))) {
			unlink(SIGNALS_TMP_PATH . 'queue_' . intval($rStreamID));
		}

		// Drop any xc_fanout daemon ingest listener for this stream (ADR 0003, A2).
		// No-op when the daemon isn't reachable.
		FanoutClient::unregister(intval($rStreamID));

		self::streamLog($rStreamID, SERVER_ID, 'STREAM_STOP');
		shell_exec('rm -f ' . STREAMS_PATH . intval($rStreamID) . '_*');

		if ($rStop) {
			shell_exec('rm -f ' . DELAY_PATH . intval($rStreamID) . '_*');
			self::resetStreamServerRow($rStreamID, true);
			self::updateStream($rStreamID);
		}
	}

	/**
	 * Stop a running movie (VOD) stream.
	 *
	 * @param int  $rStreamID Stream id.
	 * @param bool $rForce    Force stop.
	 * @return mixed Stop result.
	 */
	public static function stopMovie($rStreamID, $rForce = false) {
		$db = self::db();
		shell_exec("kill -9 `ps -ef | grep '/" . intval($rStreamID) . ".' | grep -v grep | awk '{print \$2}'`;");
		if ($rForce) {
			exec('rm ' . MAIN_HOME . 'content/vod/' . intval($rStreamID) . '.*');
		} else {
			$db->query('INSERT INTO `signals`(`server_id`, `time`, `custom_data`, `cache`) VALUES(?, ?, ?, 1);', SERVER_ID, time(), json_encode(array('type' => 'delete_vod', 'id' => $rStreamID)));
		}
		self::resetStreamServerRow($rStreamID);
		self::updateStream($rStreamID);
	}

	/**
	 * Queue a movie (VOD) to be started.
	 *
	 * @param int      $rStreamID Stream id.
	 * @param int|null $rServerID Target server id, or null for the default.
	 * @return mixed Queue result.
	 */
	public static function queueMovie($rStreamID, $rServerID = null) {
		$db = self::db();
		if (!$rServerID) {
			$rServerID = SERVER_ID;
		}
		$db->query('DELETE FROM `queue` WHERE `stream_id` = ? AND `server_id` = ?;', $rStreamID, $rServerID);
		$db->query("INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES('movie', ?, ?, ?);", $rStreamID, $rServerID, time());
	}

	/**
	 * Queue multiple movies to be started.
	 *
	 * @param int[]    $rStreamIDs Stream ids.
	 * @param int|null $rServerID  Target server id, or null for the default.
	 * @return void
	 */
	public static function queueMovies($rStreamIDs, $rServerID = null) {
		$db = self::db();
		if (!$rServerID) {
			$rServerID = SERVER_ID;
		}
		if (0 < count($rStreamIDs)) {
			$db->query('DELETE FROM `queue` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ') AND `server_id` = ?;', $rServerID);
			$rQuery = '';
			foreach ($rStreamIDs as $rStreamID) {
				if (0 < $rStreamID) {
					$rQuery .= "('movie', " . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . time() . '),';
				}
			}
			if (!empty($rQuery)) {
				$rQuery = rtrim($rQuery, ',');
				$db->query('INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES ' . $rQuery . ';');
			}
		}
	}

	/**
	 * Refresh movie metadata/processing for the given ids.
	 *
	 * @param int[] $rIDs  Stream ids.
	 * @param int   $rType Refresh type.
	 * @return void
	 */
	public static function refreshMovies($rIDs, $rType = 1) {
		$db = self::db();
		if (0 < count($rIDs)) {
			$db->query('DELETE FROM `watch_refresh` WHERE `type` = ? AND `stream_id` IN (' . implode(',', array_map('intval', $rIDs)) . ');', $rType);
			$rQuery = '';
			foreach ($rIDs as $rID) {
				if (0 < $rID) {
					$rQuery .= '(' . intval($rType) . ', ' . intval($rID) . ', 0),';
				}
			}
			if (!empty($rQuery)) {
				$rQuery = rtrim($rQuery, ',');
				$db->query('INSERT INTO `watch_refresh`(`type`, `stream_id`, `status`) VALUES ' . $rQuery . ';');
			}
		}
	}

	/**
	 * Start a movie (VOD) stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @return mixed Start result.
	 */
	public static function startMovie($rStreamID) {
		global $rSettings, $rServers, $rFFMPEG_CPU, $rFFMPEG_GPU;
		$db = self::db();
		$rStream = array();
		$rLoopback = false;
		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 0 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);
		if ($db->num_rows() > 0) {
			$rStream['stream_info'] = $db->get_row();
			$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			if ($db->num_rows() > 0) {
				$rStream['server_info'] = $db->get_row();
				$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
				$rStream['stream_arguments'] = $db->get_rows();

				list($rStreamSource) = json_decode($rStream['stream_info']['stream_source'], true);
				if (substr($rStreamSource, 0, 2) == 's:') {
					$rMovieSource = explode(':', $rStreamSource, 3);
					$rMovieServerID = $rMovieSource[1];
					if ($rMovieServerID != SERVER_ID && !self::isLocallyMountedPath($rMovieSource[2])) {
						$rMoviePath = $rServers[$rMovieServerID]['api_url'] . '&action=getFile&filename=' . urlencode($rMovieSource[2]);
					} else {
						// Recorded owner is a different server, but the path also
						// resolves on THIS server's filesystem (shared mount) use
						// it directly and report ourselves as the owner so the
						// `ln -s` branch below fires instead of the ffmpeg fallback.
						$rMoviePath = $rMovieSource[2];
						$rMovieServerID = SERVER_ID;
					}
					$rProtocol = null;
				} else {
					if (substr($rStreamSource, 0, 1) == '/') {
						$rMovieServerID = SERVER_ID;
						$rMoviePath = $rStreamSource;
						$rProtocol = null;
					} else {
						$rProtocol = substr($rStreamSource, 0, strpos($rStreamSource, '://'));
						$rMoviePath = str_replace(' ', '%20', $rStreamSource);
						$rFetchOptions = implode(' ', StreamUtils::getArguments($rStream['stream_arguments'], $rProtocol, 'fetch'));
					}
				}

				if ((isset($rMovieServerID) && $rMovieServerID == SERVER_ID || file_exists($rMoviePath)) && $rStream['stream_info']['movie_symlink'] == 1) {
					$rFFMPEG = 'ln -sfn ' . escapeshellarg($rMoviePath) . ' ' . VOD_PATH . intval($rStreamID) . '.' . escapeshellcmd(pathinfo($rMoviePath)['extension']) . ' >/dev/null 2>/dev/null & echo $! > ' . VOD_PATH . intval($rStreamID) . '_.pid';
				} else {
					list($rSubtitlesImport, $rSubtitlesMetadata) = self::buildSubtitleImport($rStream['stream_info']['movie_subtitles'], $rServers);

					$rReadNative = ($rStream['stream_info']['read_native'] == 1 ? '-re' : '');
					if ($rStream['stream_info']['enable_transcode'] == 1) {
						if ($rStream['stream_info']['transcode_profile_id'] == -1) {
							$rDecoded = json_decode($rStream['stream_info']['transcode_attributes'], true);
							$rStream['stream_info']['transcode_attributes'] = array_merge(StreamUtils::getArguments($rStream['stream_arguments'], $rProtocol, 'transcode'), (is_array($rDecoded) ? $rDecoded : array()));
						} else {
							$rDecoded = json_decode($rStream['stream_info']['profile_options'], true);
							$rStream['stream_info']['transcode_attributes'] = (is_array($rDecoded) ? $rDecoded : array());
						}
					} else {
						$rStream['stream_info']['transcode_attributes'] = array();
					}

					$rLogoOptions = self::buildLogoFilterOptions($rStream['stream_info']['transcode_attributes'], $rLoopback);
					$rGPUOptions = (isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rStream['stream_info']['transcode_attributes']['gpu']['cmd'] : '');
					$rInputCodec = self::resolveGpuInputCodec($rGPUOptions, $rMoviePath);
					$rFFMPEG = ((isset($rStream['stream_info']['transcode_attributes']['gpu']) ? $rFFMPEG_GPU : $rFFMPEG_CPU)) . ' -y -nostdin -hide_banner -loglevel ' . (($rSettings['ffmpeg_warnings'] ? 'warning' : 'error')) . ' -err_detect ignore_err {GPU} {FETCH_OPTIONS} -fflags +genpts -async 1 {READ_NATIVE} -i {STREAM_SOURCE} {LOGO} ' . $rSubtitlesImport;
					$rMap = self::resolveOutputMap($rStream['stream_info']['custom_map'], $rStream['stream_info']['remove_subtitles']);
					self::applyDefaultCopyCodecs($rStream['stream_info']['transcode_attributes']);
					$rStream['stream_info']['transcode_attributes']['-scodec'] = self::subtitleCodecForContainer($rStream['stream_info']['target_container']);
					$rOutputs = array();
					$rOutputs[$rStream['stream_info']['target_container']] = '-movflags +faststart -dn ' . $rMap . ' -ignore_unknown ' . $rSubtitlesMetadata . ' ' . VOD_PATH . intval($rStreamID) . '.' . escapeshellcmd($rStream['stream_info']['target_container']);
					foreach ($rOutputs as $rOutputCommand) {
						$rFFMPEG .= implode(' ', StreamUtils::parseTranscode($rStream['stream_info']['transcode_attributes'])) . ' ';
						$rFFMPEG .= $rOutputCommand;
					}
					$rFFMPEG .= ' >/dev/null 2>' . VOD_PATH . intval($rStreamID) . '.errors & echo $! > ' . VOD_PATH . intval($rStreamID) . '_.pid';
					$rFFMPEG = str_replace(array('{GPU}', '{INPUT_CODEC}', '{LOGO}', '{FETCH_OPTIONS}', '{STREAM_SOURCE}', '{READ_NATIVE}'), array($rGPUOptions, $rInputCodec, $rLogoOptions, (empty($rFetchOptions) ? '' : $rFetchOptions), escapeshellarg($rMoviePath), (empty($rStream['stream_info']['custom_ffmpeg']) ? $rReadNative : '')), $rFFMPEG);
				}

				shell_exec($rFFMPEG);
				file_put_contents(VOD_PATH . $rStreamID . '_.ffmpeg', $rFFMPEG);
				$rPID = intval(file_get_contents(VOD_PATH . $rStreamID . '_.pid'));
				$db->query('UPDATE `streams_servers` SET `to_analyze` = 1,`stream_started` = ?,`stream_status` = 0,`pid` = ? WHERE `stream_id` = ? AND `server_id` = ?', time(), $rPID, $rStreamID, SERVER_ID);
				self::updateStream($rStreamID);
				return $rPID;
			}
			return false;
		}
		return false;
	}

	/**
	 * Start a loopback stream.
	 *
	 * @param int $rStreamID Stream id.
	 * @return mixed Start result.
	 */
	public static function startLoopback($rStreamID) {
		global $rSettings, $rServers;
		$db = self::db();
		self::clearStreamPidSegments($rStreamID);
		$rStream = array();
		$db->query('SELECT * FROM `streams` WHERE direct_source = 0 AND id = ?', $rStreamID);
		if ($db->num_rows() > 0) {
			$rStream['stream_info'] = $db->get_row();
			$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);
			if ($db->num_rows() > 0) {
				$rStream['server_info'] = $db->get_row();
				if ($rStream['server_info']['parent_id'] != 0) {
					shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php loopback ' . intval($rStreamID) . ' ' . intval($rStream['server_info']['parent_id']) . ' >/dev/null 2>/dev/null & echo $! > ' . STREAMS_PATH . intval($rStreamID) . '_.pid');
					$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
					$rLoopURL = (!is_null($rServers[SERVER_ID]['private_url_ip']) && !is_null($rServers[$rStream['server_info']['parent_id']]['private_url_ip']) ? $rServers[$rStream['server_info']['parent_id']]['private_url_ip'] : $rServers[$rStream['server_info']['parent_id']]['public_url_ip']);
					$rCurrentSource = $rLoopURL . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode($rSettings['live_streaming_pass']) . '&extension=ts';
					self::writeStreamKeyIv($rStreamID);
					$db->query('UPDATE `streams_servers` SET `delay_available_at` = ?,`to_analyze` = 0,`stream_started` = ?,`stream_info` = ?,`stream_status` = 2,`pid` = ?,`progress_info` = ?,`current_source` = ? WHERE `stream_id` = ? AND `server_id` = ?', null, time(), null, $rPID, json_encode(array()), $rCurrentSource, $rStreamID, SERVER_ID);
					self::updateStream($rStreamID);
					return array('main_pid' => $rPID, 'stream_source' => $rLoopURL . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode($rSettings['live_streaming_pass']) . '&extension=ts', 'delay_enabled' => false, 'parent_id' => 0, 'delay_start_at' => null, 'playlist' => STREAMS_PATH . $rStreamID . '_.m3u8', 'transcode' => false, 'offset' => 0);
				}
				return 0;
			}
			return false;
		}
		return false;
	}

	/**
	 * Start a live-on-demand (LLOD) stream.
	 *
	 * @param int         $rStreamID        Stream id.
	 * @param array       $rStreamInfo      Stream metadata.
	 * @param array       $rStreamArguments ffmpeg/stream arguments.
	 * @param string|null $rForceSource     Force a specific source URL.
	 * @return mixed Start result.
	 */
	public static function startLLOD($rStreamID, $rStreamInfo, $rStreamArguments, $rForceSource = null) {
		$db = self::db();
		self::clearStreamPidSegments($rStreamID);
		$rSources = ($rForceSource ? array($rForceSource) : json_decode($rStreamInfo['stream_source'], true));
		$rArgumentMap = array();
		foreach ($rStreamArguments as $rStreamArgument) {
			$rArgumentMap[$rStreamArgument['argument_key']] = array('value' => $rStreamArgument['value'], 'argument_default_value' => $rStreamArgument['argument_default_value']);
		}
		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php llod ' . intval($rStreamID) . ' "' . base64_encode(json_encode($rSources)) . '" "' . base64_encode(json_encode($rArgumentMap)) . '" >/dev/null 2>/dev/null & echo $! > ' . STREAMS_PATH . intval($rStreamID) . '_.pid');
		$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
		self::writeStreamKeyIv($rStreamID);
		$db->query('UPDATE `streams_servers` SET `delay_available_at` = ?,`to_analyze` = 0,`stream_started` = ?,`stream_info` = ?,`stream_status` = 2,`pid` = ?,`progress_info` = ?,`current_source` = ? WHERE `stream_id` = ? AND `server_id` = ?', null, time(), null, $rPID, json_encode(array()), $rSources[0], $rStreamID, SERVER_ID);
		self::updateStream($rStreamID);
		return array('main_pid' => $rPID, 'stream_source' => $rSources[0], 'delay_enabled' => false, 'parent_id' => 0, 'delay_start_at' => null, 'playlist' => STREAMS_PATH . $rStreamID . '_.m3u8', 'transcode' => false, 'offset' => 0);
	}

	/**
	 * Start a live stream (main entry point for channel start-up).
	 *
	 * Selects a source, builds the ffmpeg command and launches the process.
	 *
	 * @param int         $rStreamID    Stream id.
	 * @param bool        $rFromCache   Use cached stream info.
	 * @param string|null $rForceSource Force a specific source URL.
	 * @param bool        $rLLOD        Treat as live-on-demand.
	 * @param int         $rStartPos    Start position/offset.
	 * @return mixed Start result.
	 */
	public static function startStream($rStreamID, $rFromCache = false, $rForceSource = null, $rLLOD = false, $rStartPos = 0) {
		global $rSettings, $rServers, $rFFMPEG_CPU, $rFFMPEG_GPU, $rFFPROBE;
		$db = self::db();
		$rSegmentSettings = array('seg_time' => intval($rSettings['seg_time']), 'seg_list_size' => intval($rSettings['seg_list_size']), 'seg_delete_threshold' => intval($rSettings['seg_delete_threshold']));
		@unlink(STREAMS_PATH . $rStreamID . '_.pid');

		$rStream = array();
		$db->query('SELECT * FROM `streams` t1 INNER JOIN `streams_types` t2 ON t2.type_id = t1.type AND t2.live = 1 LEFT JOIN `profiles` t4 ON t1.transcode_profile_id = t4.profile_id WHERE t1.direct_source = 0 AND t1.id = ?', $rStreamID);

		if ($db->num_rows() > 0) {
			$rStream['stream_info'] = $db->get_row();
			$db->query('SELECT * FROM `streams_servers` WHERE stream_id  = ? AND `server_id` = ?', $rStreamID, SERVER_ID);

			if ($db->num_rows() > 0) {
				$rStream['server_info'] = $db->get_row();
				$db->query('SELECT t1.*, t2.* FROM `streams_options` t1, `streams_arguments` t2 WHERE t1.stream_id = ? AND t1.argument_id = t2.id', $rStreamID);
				$rStream['stream_arguments'] = $db->get_rows();

				list($rProbesize, $rAnalyseDuration, $rTimeout) = self::resolveProbeSettings($rStream['server_info']['on_demand'], $rStream['stream_info']['probesize_ondemand'], $rLLOD, $rSettings);
				$rFFProbee = 'timeout ' . $rTimeout . ' ' . $rFFPROBE . ' {FETCH_OPTIONS} -probesize ' . $rProbesize . ' -analyzeduration ' . $rAnalyseDuration . ' {CONCAT} -i {STREAM_SOURCE} -v quiet -print_format json -show_streams -show_format';
				$rFetchOptions = '';
				$rLoopback = false;
				$rOffset = 0;

				if (!$rStream['server_info']['parent_id']) {
					if ($rStream['stream_info']['type_key'] == 'created_live') {
						$rSources = array(CREATED_PATH . $rStreamID . '_.list');

						if ($rStartPos > 0) {
							$rCCOutput = array();
							$rCCDuration = array();
							$rCCInfo = json_decode($rStream['server_info']['cc_info'], true) ?: array();

							foreach ($rCCInfo as $rItem) {
								$rCCDuration[$rItem['path']] = intval(explode('.', $rItem['seconds'])[0]);
							}
							$rTimer = 0;
							$rValid = true;

							foreach (explode("\n", file_get_contents(CREATED_PATH . $rStreamID . '_.list')) as $rItem) {
								$rItemParts = explode("file '", $rItem);
								if (!isset($rItemParts[1])) {
									continue;
								}
								list($rPath) = explode("'", $rItemParts[1]);

								if ($rPath) {
									if (!empty($rCCDuration[$rPath])) {
										$rDuration = $rCCDuration[$rPath];

										if ($rTimer <= $rStartPos && $rStartPos < $rTimer + $rDuration) {
											$rOffset = $rTimer;
											$rCCOutput[] = $rPath;
										} else {
											if ($rStartPos < $rTimer + $rDuration) {
												$rCCOutput[] = $rPath;
											}
										}

										$rTimer += $rDuration;
									} else {
										$rValid = false;
									}
								}
							}

							if ($rValid) {
								$rSources = array(CREATED_PATH . $rStreamID . '_.tlist');
								$rTList = '';

								foreach ($rCCOutput as $rItem) {
									$rTList .= "file '" . $rItem . "'" . "\n";
								}
								file_put_contents(CREATED_PATH . $rStreamID . '_.tlist', $rTList);
							}
						}
					} else {
						$rSources = json_decode($rStream['stream_info']['stream_source'], true);
					}

					if (count($rSources) > 0) {
						if (!empty($rForceSource)) {
							$rSources = array($rForceSource);
						} else {
							$rSources = self::rotateSourcesPastCurrent($rSources, $rSettings['priority_backup'], $rStream['server_info']['current_source']);
						}
					}
				} else {
					$rLoopback = true;

					if ($rStream['server_info']['on_demand']) {
						$rLLOD = true;
					}

					$rLoopURL = (!is_null($rServers[SERVER_ID]['private_url_ip']) && !is_null($rServers[$rStream['server_info']['parent_id']]['private_url_ip']) ? $rServers[$rStream['server_info']['parent_id']]['private_url_ip'] : $rServers[$rStream['server_info']['parent_id']]['public_url_ip']);
					$rSources = array($rLoopURL . 'admin/live?stream=' . intval($rStreamID) . '&password=' . urlencode($rSettings['live_streaming_pass']) . '&extension=ts');
				}

				if ($rStream['stream_info']['type_key'] == 'created_live' && file_exists(CREATED_PATH . $rStreamID . '_.info')) {
					$db->query('UPDATE `streams_servers` SET `cc_info` = ? WHERE `server_id` = ? AND `stream_id` = ?;', file_get_contents(CREATED_PATH . $rStreamID . '_.info'), SERVER_ID, $rStreamID);
				}

				if (!$rFromCache) {
					self::deleteCache($rSources);
				}

				// Loop-scoped state read again after the loop (final DB update,
				// command substitution). Default it so an empty $rSources list can
				// never surface an undefined variable downstream.
				$rSource = '';
				$rRealSource = '';
				$rStreamSource = '';
				$rProtocol = '';
				$rFFProbeOutput = array();
				foreach ($rSources as $rSource) {
					$rRealSource = $rSource;
					$rStreamSource = StreamUtils::parseStreamURL($rSource);
					echo 'Checking source: ' . $rSource . "\n";
					$rURLInfo = parse_url($rStreamSource);
					$rIsXC_VM = ($rLoopback ? true : StreamUtils::detectXC_VM($rStreamSource));

					if ($rIsXC_VM && !$rLoopback && $rSettings['send_xc_vm_header']) {
						$rStream['stream_arguments'] = self::appendHeaderArgument($rStream['stream_arguments'], 'X-XC_VM-Detect:1');
					}

					$rProbeArguments = $rStream['stream_arguments'];

					if ($rIsXC_VM && $rStream['server_info']['on_demand'] == 1 && $rSettings['request_prebuffer'] == 1) {
						$rStream['stream_arguments'] = self::appendHeaderArgument($rStream['stream_arguments'], 'X-XC_VM-Prebuffer:1');
					}

					$rProbeArguments = self::appendHeaderArgument($rProbeArguments, 'X-XC_VM-Prebuffer:1');

					$rProtocol = strtolower(substr($rStreamSource, 0, strpos($rStreamSource, '://')));
					$rProbeOptions = implode(' ', StreamUtils::getArguments($rProbeArguments, $rProtocol, 'fetch'));
					$rFetchOptions = implode(' ', StreamUtils::getArguments($rStream['stream_arguments'], $rProtocol, 'fetch'));

					$rSkipFFProbe = self::hasSkipFFProbe($rStream['stream_arguments']);

					if ($rSkipFFProbe) {
						$rFFProbeOutput = self::skipFFProbeOutput();
						error_log('[XC_VM] Stream ' . $rStreamID . ': FFProbe skipped');
						echo 'Got stream information via skip_ffprobe (assumed h264/aac)' . "\n";

						if (empty($rSource)) {
							$rSource = is_array($rSources) && count($rSources) > 0 ? $rSources[0] : $rStreamSource;
						}
						break;
					}

					if ($rFromCache && file_exists(CACHE_TMP_PATH . md5($rSource)) && time() - filemtime(CACHE_TMP_PATH . md5($rSource)) <= 300) {
						// Cache key must match the write below and the existence check
						// above (both md5($rSource)); reading md5($rStreamSource) here
						// fetched a different file, so the cache always missed.
						$rFFProbeOutput = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . md5($rSource)));

						if ($rFFProbeOutput && (isset($rFFProbeOutput['streams']) || isset($rFFProbeOutput['codecs']))) {
							echo 'Got stream information via cache' . "\n";

							break;
						}
					} else {
						if ($rFromCache && file_exists(CACHE_TMP_PATH . md5($rSource))) {
							$rFromCache = false;
						}
					}

					if (!($rStream['server_info']['on_demand'] && $rLLOD)) {
						if ($rIsXC_VM && $rSettings['api_probe']) {
							$rProbeURL = $rURLInfo['scheme'] . '://' . $rURLInfo['host'] . (isset($rURLInfo['port']) ? ':' . $rURLInfo['port'] : '') . '/probe/' . base64_encode($rURLInfo['path'] ?? '');
							$rFFProbeOutput = json_decode(CurlClient::getURL($rProbeURL), true);

							if ($rFFProbeOutput && isset($rFFProbeOutput['codecs'])) {
								echo 'Got stream information via API' . "\n";

								break;
							}
						}

						$rProbeCmd = str_replace(array('{FETCH_OPTIONS}', '{CONCAT}', '{STREAM_SOURCE}'), array($rProbeOptions, ($rStream['stream_info']['type_key'] == 'created_live' && !$rStream['server_info']['parent_id'] ? '-safe 0 -f concat' : ''), escapeshellarg($rStreamSource)), $rFFProbee);
						$rFFProbeOutput = json_decode(shell_exec($rProbeCmd), true);

						if ($rFFProbeOutput && isset($rFFProbeOutput['streams'])) {
							echo 'Got stream information via ffprobe' . "\n";

							break;
						}
					}
				}
				if (!($rStream['server_info']['on_demand'] && $rLLOD)) {
					if (!isset($rFFProbeOutput['codecs'])) {
						$rFFProbeOutput = \XcVm\Streaming\Codec\FFprobeRunner::parseFFProbe($rFFProbeOutput);
					}

					if (empty($rFFProbeOutput)) {
						$db->query("UPDATE `streams_servers` SET `progress_info` = '',`to_analyze` = 0,`pid` = -1,`stream_status` = 1 WHERE `server_id` = ? AND `stream_id` = ?", SERVER_ID, $rStreamID);

						return 0;
					}

					if (!$rFromCache) {
						file_put_contents(CACHE_TMP_PATH . md5($rSource), igbinary_serialize($rFFProbeOutput));
					}
				}

					// Delay-mode playlist bookkeeping (segment start + sleep window). Must run
					// before buildLive(), which consumes segmentStart / delayActive.
					$rSleepTime = 0;
				$rSegmentStart = 0;
				$rDelayActive = !(0 >= $rStream['stream_info']['delay_minutes'] || $rStream['server_info']['parent_id']);
				if ($rDelayActive) {
					$m3u8File = DELAY_PATH . $rStreamID . '_.m3u8';
					$oldM3u8File = DELAY_PATH . intval($rStreamID) . '_.m3u8_old';

					if (file_exists($m3u8File)) {
						$rFile = file($m3u8File, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

						if (!is_array($rFile) || count($rFile) < 2) {
							return;
						}

						$rSegmentStart = self::resolveDelaySegmentStart($rFile, $rStreamID);

						if (file_exists($oldM3u8File)) {
							file_put_contents($oldM3u8File, file_get_contents($oldM3u8File) . file_get_contents($m3u8File));
							shell_exec("sed -i '/EXTINF\\|.ts/!d' " . escapeshellarg($oldM3u8File));
						} else {
							copy($m3u8File, $oldM3u8File);
						}
					}
					$rSleepTime = self::resolveDelaySleepTime($rStream['stream_info']['delay_minutes'], $rSegmentStart);
				}

					// Assemble the live ffmpeg command (pure). buildLive() does all the
					// transcode-attribute resolution and {TEMPLATE} substitution internally, so
					// it is fed the raw stream row.
					// Register a daemon ingest for standard live streams (ADR 0003,
					// A2). The daemon starts listening on the returned socket, then
					// buildLive tees the HLS output into it. Null when the daemon is
					// unreachable → buildLive emits the on-disk-only HLS (rollback).
					// Generate the stream's HLS key/iv up-front so, when encrypt_hls
					// is on, we can hand them to the daemon at ingest registration
					// and it encrypts the HLS segments it serves (ADR 0003, Phase B
					// encrypted) — matching the panel's #EXT-X-KEY.
					self::writeStreamKeyIv($rStreamID);
					$rEncKey = $rEncIV = null;
					if (!empty($rSettings['encrypt_hls']) && !$rLoopback && !$rDelayActive) {
						$rEncKey = @bin2hex((string) @file_get_contents(STREAMS_PATH . $rStreamID . '_.key'));
						$rEncIV = @bin2hex((string) @file_get_contents(STREAMS_PATH . $rStreamID . '_.iv'));
					}
					$rIngestSock = (!$rLoopback && !$rDelayActive) ? FanoutClient::registerIngest($rStreamID, $rEncKey, $rEncIV) : null;

					$rFFMPEG = self::buildLive(array(
						'stream' => $rStream, 'settings' => $rSettings, 'servers' => $rServers,
						'streamID' => $rStreamID, 'streamSource' => $rStreamSource,
						'fetchOptions' => $rFetchOptions, 'ffprobe' => $rFFProbeOutput,
						'protocol' => $rProtocol, 'source' => $rSource,
						'segmentSettings' => $rSegmentSettings, 'externalPush' => array(),
						'probesize' => $rProbesize, 'analyseDuration' => $rAnalyseDuration,
						'llod' => $rLLOD, 'loopback' => $rLoopback,
						'segmentStart' => $rSegmentStart, 'delayActive' => $rDelayActive,
						'ffmpegCpu' => $rFFMPEG_CPU, 'ffmpegGpu' => $rFFMPEG_GPU,
						'ingestSock' => $rIngestSock,
					));

				shell_exec($rFFMPEG);
				file_put_contents(STREAMS_PATH . $rStreamID . '_.ffmpeg', $rFFMPEG);

				// Wait briefly for PID file to be written, with retry
				$rPID = 0;
				$rPIDRetries = 0;
				while ($rPIDRetries < 10) {
					usleep(50000); // 50ms
					if (file_exists(STREAMS_PATH . $rStreamID . '_.pid')) {
						$rPID = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.pid'));
						if ($rPID > 0) {
							break;
						}
					}
					$rPIDRetries++;
				}

				if ($rStream['stream_info']['tv_archive_server_id'] == SERVER_ID) {
					shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php archive ' . intval($rStreamID) . ' >/dev/null 2>/dev/null & echo $!');
				}

				if ($rStream['stream_info']['vframes_server_id'] == SERVER_ID) {
					self::startThumbnail($rStreamID);
				}

				$rDelayEnabled = 0 < $rStream['stream_info']['delay_minutes'] && !$rStream['server_info']['parent_id'];
				$rDelayStartAt = ($rDelayEnabled ? time() + $rSleepTime : 0);

				if ($rStream['stream_info']['enable_transcode']) {
					$rFFProbeOutput = array();
				}

				list($rCompatible, $rAudioCodec, $rVideoCodec, $rResolution) = self::resolveStreamCodecMeta($rFFProbeOutput, SettingsManager::getAll()['player_allow_hevc']);

				$rFFProbeOutputSafe = isset($rFFProbeOutput) && is_array($rFFProbeOutput) ? $rFFProbeOutput : [];
				$db->query('UPDATE `streams_servers` SET `delay_available_at` = ?,`to_analyze` = 0,`stream_started` = ?,`stream_info` = ?,`audio_codec` = ?, `video_codec` = ?, `resolution` = ?,`compatible` = ?,`stream_status` = 2,`pid` = ?,`progress_info` = ?,`current_source` = ? WHERE `stream_id` = ? AND `server_id` = ?', $rDelayStartAt, time(), json_encode($rFFProbeOutputSafe), $rAudioCodec, $rVideoCodec, $rResolution, $rCompatible, $rPID, json_encode(array()), $rSource, $rStreamID, SERVER_ID);
				self::updateStream($rStreamID);
				$rPlaylist = (!$rDelayEnabled ? STREAMS_PATH . $rStreamID . '_.m3u8' : DELAY_PATH . $rStreamID . '_.m3u8');

				return array('main_pid' => $rPID, 'stream_source' => $rRealSource, 'delay_enabled' => $rDelayEnabled, 'parent_id' => $rStream['server_info']['parent_id'], 'delay_start_at' => $rDelayStartAt, 'playlist' => $rPlaylist, 'transcode' => $rStream['stream_info']['enable_transcode'], 'offset' => $rOffset);
			} else {
				return false;
			}
		} else {
			return false;
		}
	}
}
