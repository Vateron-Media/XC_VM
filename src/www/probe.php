<?php

register_shutdown_function('shutdown');
include './stream/init.php';

if (!isset(CoreUtilities::$rRequest['data'])) {
} else {
	$rIP = CoreUtilities::getUserIP();
	$rPath = base64_decode(CoreUtilities::$rRequest['data']);
	$rPathSize = count(explode('/', $rPath));
	$rUserInfo = $rStreamID = null;

	if ($rPathSize == 3) {
		if ($rStreamID) {
		} else {
			$rQuery = '/\\/auth\\/(.*)$/m';
			preg_match($rQuery, $rPath, $rMatches);

			if (count($rMatches) != 2) {
			} else {
				$rData = json_decode(CoreUtilities::decryptData($rMatches[1], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);
				$rStreamID = intval($rData['stream_id']);
				$rUserInfo = CoreUtilities::getUserInfo(null, $rData['username'], $rData['password'], true);
			}
		}

		if ($rStreamID) {
		} else {
			$rQuery = '/\\/play\\/(.*)$/m';
			preg_match($rQuery, $rPath, $rMatches);

			if (count($rMatches) != 2) {
			} else {
				$rData = explode('/', CoreUtilities::decryptData($rMatches[1], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA));

				if ($rData[0] != 'live') {
				} else {
					$rStreamID = intval($rData[3]);
					$rUserInfo = CoreUtilities::getUserInfo(null, $rData[1], $rData[2], true);
				}
			}
		}
	} else {
		if ($rPathSize == 4) {
			if ($rStreamID) {
			} else {
				$rQuery = '/\\/play\\/(.*)\\/(.*)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) != 3) {
				} else {
					$rData = explode('/', CoreUtilities::decryptData($rMatches[1], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA));

					if ($rData[0] != 'live') {
					} else {
						$rStreamID = intval($rData[3]);
						$rUserInfo = CoreUtilities::getUserInfo(null, $rData[1], $rData[2], true);
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
					$rUserInfo = CoreUtilities::getUserInfo(null, $rMatches[1], null, true);
				}
			}

			if ($rStreamID) {
			} else {
				$rQuery = '/\\/live\\/(.*)\\/(\\d+)\\.(.*)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) != 4) {
				} else {
					$rStreamID = intval($rMatches[2]);
					$rUserInfo = CoreUtilities::getUserInfo(null, $rMatches[1], null, true);
				}
			}

			if ($rStreamID) {
			} else {
				$rQuery = '/\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) != 5) {
				} else {
					$rStreamID = intval($rMatches[3]);
					$rUserInfo = CoreUtilities::getUserInfo(null, $rMatches[1], $rMatches[2], true);
				}
			}

			if ($rStreamID) {
			} else {
				$rQuery = '/\\/(.*)\\/(.*)\\/(\\d+)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) != 4) {
				} else {
					$rStreamID = intval($rMatches[3]);
					$rUserInfo = CoreUtilities::getUserInfo(null, $rMatches[1], $rMatches[2], true);
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
						$rUserInfo = CoreUtilities::getUserInfo(null, $rMatches[1], $rMatches[2], true);
					}
				}

				if ($rStreamID) {
				} else {
					$rQuery = '/\\/live\\/(.*)\\/(.*)\\/(\\d+)$/m';
					preg_match($rQuery, $rPath, $rMatches);

					if (count($rMatches) != 4) {
					} else {
						$rStreamID = intval($rMatches[3]);
						$rUserInfo = CoreUtilities::getUserInfo(null, $rMatches[1], $rMatches[2], true);
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

		$rChannelInfo = CoreUtilities::redirectStream($rStreamID, 'ts', $rUserInfo, null, '', 'live');

		if (isset($rChannelInfo['redirect_id']) && $rChannelInfo['redirect_id'] != SERVER_ID) {
			$rServerID = $rChannelInfo['redirect_id'];
		} else {
			$rServerID = SERVER_ID;
		}

		if (!(0 < $rChannelInfo['monitor_pid'] && 0 < $rChannelInfo['pid'] && CoreUtilities::$rServers[$rServerID]['last_status'] == 1)) {
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
	if (!is_object(CoreUtilities::$db)) {
	} else {
		CoreUtilities::$db->close_mysql();
	}
}
