<?php

register_shutdown_function('shutdown');
include './stream/init.php';

if (isset(StreamingUtilities::$rRequest['data'])) {
	$rIP = StreamingUtilities::getUserIP();
	$rPath = base64_decode(StreamingUtilities::$rRequest['data']);
	$rPathSize = count(explode('/', $rPath));
	$rUserInfo = $rStreamID = null;

	if ($rPathSize == 3) {
		if (!$rStreamID) {
			$rQuery = '/\\/auth\\/(.*)$/m';
			preg_match($rQuery, $rPath, $rMatches);

			if (count($rMatches) == 2) {
				$rData = json_decode(StreamingUtilities::decryptData($rMatches[1], StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);
				$rStreamID = intval($rData['stream_id']);
				$rUserInfo = StreamingUtilities::getUserInfo(null, $rData['username'], $rData['password'], true);
			}
		}

		if (!$rStreamID) {
			$rQuery = '/\\/play\\/(.*)$/m';
			preg_match($rQuery, $rPath, $rMatches);

			if (count($rMatches) == 2) {
				$rData = explode('/', StreamingUtilities::decryptData($rMatches[1], StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA));

				if ($rData[0] == 'live') {
					$rStreamID = intval($rData[3]);
					$rUserInfo = StreamingUtilities::getUserInfo(null, $rData[1], $rData[2], true);
				}
			}
		}
	} else {
		if ($rPathSize == 4) {
			if (!$rStreamID) {
				$rQuery = '/\\/play\\/(.*)\\/(.*)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) == 3) {
					$rData = explode('/', StreamingUtilities::decryptData($rMatches[1], StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA));

					if ($rData[0] == 'live') {
						$rStreamID = intval($rData[3]);
						$rUserInfo = StreamingUtilities::getUserInfo(null, $rData[1], $rData[2], true);
					}
				}
			}

			if ($rStreamID) {
			} else {
				$rQuery = '/\\/live\\/(.*)\\/(\\d+)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) != 3) {
				} else {
					$rStreamID = intval($rMatches[2]);
					$rUserInfo = StreamingUtilities::getUserInfo(null, $rMatches[1], null, true);
				}
			}

			if ($rStreamID) {
			} else {
				$rQuery = '/\\/live\\/(.*)\\/(\\d+)\\.(.*)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) != 4) {
				} else {
					$rStreamID = intval($rMatches[2]);
					$rUserInfo = StreamingUtilities::getUserInfo(null, $rMatches[1], null, true);
				}
			}

			if ($rStreamID) {
			} else {
				$rQuery = '/\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) != 5) {
				} else {
					$rStreamID = intval($rMatches[3]);
					$rUserInfo = StreamingUtilities::getUserInfo(null, $rMatches[1], $rMatches[2], true);
				}
			}

			if ($rStreamID) {
			} else {
				$rQuery = '/\\/(.*)\\/(.*)\\/(\\d+)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) != 4) {
				} else {
					$rStreamID = intval($rMatches[3]);
					$rUserInfo = StreamingUtilities::getUserInfo(null, $rMatches[1], $rMatches[2], true);
				}
			}
		} else {
			if ($rPathSize != 5) {
			} else {
				if ($rStreamID) {
				} else {
					$rQuery = '/\\/live\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m';
					preg_match($rQuery, $rPath, $rMatches);

					if (count($rMatches) != 5) {
					} else {
						$rStreamID = intval($rMatches[3]);
						$rUserInfo = StreamingUtilities::getUserInfo(null, $rMatches[1], $rMatches[2], true);
					}
				}

				if ($rStreamID) {
				} else {
					$rQuery = '/\\/live\\/(.*)\\/(.*)\\/(\\d+)$/m';
					preg_match($rQuery, $rPath, $rMatches);

					if (count($rMatches) != 4) {
					} else {
						$rStreamID = intval($rMatches[3]);
						$rUserInfo = StreamingUtilities::getUserInfo(null, $rMatches[1], $rMatches[2], true);
					}
				}
			}
		}
	}

	if (!($rStreamID && $rUserInfo)) {
	} else {
		if (is_null($rUserInfo['exp_date']) || $rUserInfo['exp_date'] > time()) {
		} else {
			generate404();
		}

		if ($rUserInfo['admin_enabled'] != 0) {
		} else {
			generate404();
		}

		if ($rUserInfo['enabled'] != 0) {
		} else {
			generate404();
		}

		if ($rUserInfo['is_restreamer']) {
		} else {
			generate404();
		}

		$rChannelInfo = StreamingUtilities::redirectStream($rStreamID, 'ts', $rUserInfo, null, '', 'live');

		if (isset($rChannelInfo['redirect_id']) && $rChannelInfo['redirect_id'] != SERVER_ID) {
			$rServerID = $rChannelInfo['redirect_id'];
		} else {
			$rServerID = SERVER_ID;
		}

		if (!(0 < $rChannelInfo['monitor_pid'] && 0 < $rChannelInfo['pid'] && StreamingUtilities::$rServers[$rServerID]['last_status'] == 1)) {
		} else {
			if (file_exists(STREAMS_PATH . $rStreamID . '_.stream_info')) {
				$rInfo = file_get_contents(STREAMS_PATH . $rStreamID . '_.stream_info');
			} else {
				$rInfo = $rChannelInfo['stream_info'];
			}

			$rInfo = json_decode($rInfo, true);
			echo json_encode(array('codecs' => $rInfo['codecs'], 'container' => $rInfo['container'], 'bitrate' => $rInfo['bitrate']));

			exit();
		}
	}
}

generate404();
function shutdown() {
	if (!is_object(StreamingUtilities::$db)) {
	} else {
		StreamingUtilities::$db->close_mysql();
	}
}
