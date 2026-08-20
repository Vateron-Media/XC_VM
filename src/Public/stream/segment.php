<?php

use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Util\Encryption;

/**
 * HLS segment delivery endpoint
 *
 * @package XC_VM_Web_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

header('Access-Control-Allow-Origin: *');
header('X-Content-Type-Options: nosniff');
set_time_limit(0);

$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
$rServers = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'servers'));
if (!defined('SERVER_ID')) define('SERVER_ID', intval(ConfigReader::get('server_id')));

if (empty($rSettings['live_streaming_pass'])) {
	generate404();
}

if (!empty($rSettings['send_server_header'])) {
	header('Server: ' . $rSettings['send_server_header']);
}

if ($rSettings['send_protection_headers']) {
	header('X-XSS-Protection: 0');
	header('X-Content-Type-Options: nosniff');
}

if ($rSettings['send_altsvc_header']) {
	header('Alt-Svc: h3-29=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-T051=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q050=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q046=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q043=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,quic=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000; v="46,43"');
}

if (!empty($rSettings['send_unique_header_domain']) || filter_var(HOST, FILTER_VALIDATE_IP)) {
} else {
	$rSettings['send_unique_header_domain'] = '.' . HOST;
}

$rVideoCodec = 'h264';
$rIsHMAC = null;

if (isset($_GET['token'])) {
	$rOffset = 0;
	$rTokenArray = explode('/', Encryption::decrypt($_GET['token'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA));

	if (6 > count($rTokenArray)) {
	} else {
		if ($rTokenArray[0] == 'TS') {
			$rServerID = $rTokenArray[8];
		} else {
			$rServerID = $rTokenArray[6];
		}

		if ($rServerID == SERVER_ID) {
			if ($rTokenArray[0] == 'TS') {
				$rType = 'ARCHIVE';
				list(, $rUsername, $rPassword, $rUserIP, $rDuration, $rStartDate, $rSegmentData, $rUUID) = $rTokenArray;
				list($rStreamID, $rSegmentID, $rOffset) = explode('_', $rSegmentData);
				$rStreamID = intval($rStreamID);
				$rSegment = ARCHIVE_PATH . $rStreamID . '/' . $rSegmentID;

				if (!file_exists($rSegment)) {
					generate404();
				}
			} else {
				$rType = 'LIVE';

				if (substr($rTokenArray[0], 0, 5) == 'HMAC#') {
					$rIsHMAC = intval(explode('#', $rTokenArray[0])[1]);
					$rIdentifier = $rTokenArray[1];
				} else {
					list($rUsername, $rPassword) = $rTokenArray;
				}

				$rUserIP = $rTokenArray[2];
				$rStreamID = intval($rTokenArray[3]);
				$rSegmentID = basename($rTokenArray[4]);
				$rUUID = $rTokenArray[5];
				$rVideoCodec = ($rTokenArray[7] ?: 'h264');
				$rOnDemand = ($rTokenArray[8] ?: 0);

				// Phase B (ADR 0003): daemon in-RAM HLS segment — name is
				// "<id>_d<seq>.ts", no on-disk file. Auth lives in the token; run
				// the same uuid + IP checks, then proxy the segment from the
				// daemon's RAM via an internal X-Accel location (2-path-segment
				// target so the server-level rewrites don't hijack it).
				if (preg_match('/^' . intval($rStreamID) . '_d(\d+)\.ts$/', $rSegmentID, $rDSeg)) {
					if (!file_exists(CONS_TMP_PATH . $rUUID)) {
						generate404();
					}
					$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rUserIP), 0, -1)) == implode('.', array_slice(explode('.', getuserip()), 0, -1)) : $rUserIP == getuserip());
					if (!($rIPMatch || !$rSettings['restrict_same_ip'])) {
						generate404();
					}
					header('Access-Control-Allow-Origin: *');
					header('Content-Type: video/mp2t');
					// Pass the viewer uuid + video codec so the daemon can apply a
					// pending "send message" overlay to this segment (else no-op).
					header('X-Accel-Redirect: /xc_fanout_hls/' . intval($rStreamID) . '_' . $rDSeg[1] . '?c=' . rawurlencode($rUUID) . '&vc=' . rawurlencode($rVideoCodec));
					exit();
				}

				// Client HLS is daemon-only (ADR 0003, Phase E). A LIVE segment that
				// is not a daemon token ("<id>_d<seq>.ts", handled above) is no longer
				// served from the on-disk tmpfs HLS — those files stay only for
				// timeshift/thumbnail/.analyse/MonitorCommand, never for clients.
				generate404();
			}

			if (!file_exists(CONS_TMP_PATH . $rUUID)) {
				generate404();
			}

			$rFilesize = filesize($rSegment);
			$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rUserIP), 0, -1)) == implode('.', array_slice(explode('.', getuserip()), 0, -1)) : $rUserIP == getuserip());

			if (!$rIPMatch && $rSettings['restrict_same_ip']) {
				generate404();
			}

			header('Access-Control-Allow-Origin: *');
			$rExtension = pathinfo($rSegment, PATHINFO_EXTENSION);
			if ($rExtension === 'm4s' || $rExtension === 'mp4') {
				header('Content-Type: video/iso.segment');
			} else {
				header('Content-Type: video/mp2t');
			}

			// ARCHIVE (timeshift catch-up) segments are on-disk files, served here.
			// Live HLS is daemon-only now (handled above, else 404), so only archive
			// requests reach this point. Offset-read a partial first segment, else readfile.
			if (0 < $rOffset) {
				header('Content-Length: ' . ($rFilesize - $rOffset));
				$rFP = @fopen($rSegment, 'rb');

				if ($rFP) {
					fseek($rFP, $rOffset);

					while (!feof($rFP)) {
						echo stream_get_line($rFP, $rSettings['read_buffer_size']);
					}
					fclose($rFP);
				}
			} else {
				header('Content-Length: ' . $rFilesize);
				readfile($rSegment);
			}

			exit();
		}

		if ($rServers[$rServerID]['random_ip'] && 0 < count($rServers[$rServerID]['domains']['urls'])) {
			$rURL = $rServers[$rServerID]['domains']['protocol'] . '://' . $rServers[$rServerID]['domains']['urls'][array_rand($rServers[$rServerID]['domains']['urls'])] . ':' . $rServers[$rServerID]['domains']['port'];
		} else {
			$rURL = rtrim($rServers[$rServerID]['site_url'], '/');
		}

		header('Location: ' . $rURL . '/hls/' . $_GET['token']);

		exit();
	}
}

generate404();
function getuserip() {
	return $_SERVER['REMOTE_ADDR'];
}
