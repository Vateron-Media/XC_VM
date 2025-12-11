<?php

header('Access-Control-Allow-Origin: *');
set_time_limit(0);
require_once 'init.php';
require_once INCLUDES_PATH . 'streaming.php';

$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
$rServers = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'servers'));
$rConfig = parse_ini_file(CONFIG_PATH . 'config.ini');

if (!defined('SERVER_ID')) {
	define('SERVER_ID', intval($rConfig['server_id']));
}

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
	$rTokenArray = explode('/', StreamingUtilities::decryptData($_GET['token'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA));

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

				if (file_exists($rSegment)) {
				} else {
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
				$rSegment = STREAMS_PATH . $rSegmentID;
				$rSegmentData = explode('_', $rSegmentID);


				if (file_exists($rSegment) && $rSegmentData[0] == $rStreamID) {
				} else {
					generate404();
				}
			}

			if (file_exists(CONS_TMP_PATH . $rUUID)) {
			} else {
				generate404();
			}

			$rFilesize = filesize($rSegment);
			$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rUserIP), 0, -1)) == implode('.', array_slice(explode('.', getuserip()), 0, -1)) : $rUserIP == getuserip());

			if ($rIPMatch || !$rSettings['restrict_same_ip']) {
			} else {
				generate404();
			}

			header('Access-Control-Allow-Origin: *');
			$rExtension = pathinfo($rSegment, PATHINFO_EXTENSION);
			if ($rExtension === 'm4s' || $rExtension === 'mp4') {
				header('Content-Type: video/iso.segment');
			} else {
				header('Content-Type: video/mp2t');
			}

			if ($rType == 'LIVE') {
				if ($rOnDemand) {
					$rSettings['encrypt_hls'] = false;
				}

				if (file_exists(SIGNALS_PATH . $rUUID)) {
					$rSignalData = json_decode(file_get_contents(SIGNALS_PATH . $rUUID), true);

					if ($rSignalData['type'] == 'signal') {
						StreamingUtilities::init(false);

						if ($rSettings['encrypt_hls']) {
							$rKey = file_get_contents(STREAMS_PATH . $rStreamID . '_.key');
							$rIV = file_get_contents(STREAMS_PATH . $rStreamID . '_.iv');
							$rData = StreamingUtilities::sendSignal($rSignalData, basename($rSegment), $rVideoCodec, true);
							echo openssl_encrypt($rData, 'aes-128-cbc', $rKey, OPENSSL_RAW_DATA, $rIV);
						} else {
							StreamingUtilities::sendSignal($rSignalData, basename($rSegment), $rVideoCodec);
						}

						unlink(SIGNALS_PATH . $rUUID);

						exit();
					}
				}

				if ($rSettings['encrypt_hls']) {
					$rSegmentData = explode('_', pathinfo($rSegmentID)['filename']);
					$rSegmentExtension = pathinfo($rSegmentID, PATHINFO_EXTENSION);

					if (file_exists(STREAMS_PATH . $rStreamID . '_' . $rSegmentData[1] . '.' . $rSegmentExtension)) {
					} else {
						generate404();
					}

					if (file_exists($rSegment . '.enc_write')) {
						$rChecks = 0;

						if (file_exists(STREAMS_PATH . $rStreamID . '_.dur')) {
							$b73e9a5cd67eae9b = intval(file_get_contents(STREAMS_PATH . $rStreamID . '_.dur')) * 2;
						} else {
							$b73e9a5cd67eae9b = $rSettings['seg_time'] * 2;
						}

						while (file_exists($rSegment . '.enc_write') && !file_exists($rSegment . '.enc') && $rChecks <= $b73e9a5cd67eae9b * 10) {
							usleep(100000);
							$rChecks++;
						}
					} else {
						ignore_user_abort(true);
						touch($rSegment . '.enc_write');
						$rKey = file_get_contents(STREAMS_PATH . $rStreamID . '_.key');
						$rIV = file_get_contents(STREAMS_PATH . $rStreamID . '_.iv');
						$rData = openssl_encrypt(file_get_contents($rSegment), 'aes-128-cbc', $rKey, OPENSSL_RAW_DATA, $rIV);
						file_put_contents($rSegment . '.enc', $rData);
						unset($rData);
						unlink($rSegment . '.enc_write');
						ignore_user_abort(false);
					}

					if (file_exists($rSegment . '.enc')) {
						header('Content-Length: ' . filesize($rSegment . '.enc'));
						readfile($rSegment . '.enc');
					} else {
						generate404();
					}
				} else {
					header('Content-Length: ' . $rFilesize);
					readfile($rSegment);
				}
			} else {
				if (0 < $rOffset) {
					header('Content-Length: ' . ($rFilesize - $rOffset));
					$rFP = @fopen($rSegment, 'rb');

					if (!$rFP) {
					} else {
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
