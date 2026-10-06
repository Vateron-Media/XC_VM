<?php

use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Util\Encryption;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\User\UserRepository;
use XcVm\Streaming\Delivery\StreamRedirector;

/**
 * Stream probe endpoint
 *
 * @package XC_VM_Web
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

register_shutdown_function('shutdown');

// The path to probe is one plain value: a list of them is no path.
if (isset($rRequest['data']) && !is_array($rRequest['data'])) {
	$rIP = $_SERVER['REMOTE_ADDR'];
	$rPath = base64_decode($rRequest['data']);
	$rPathSize = count(explode('/', $rPath));
	$rUserInfo = $rStreamID = $rUsername = $rPassword = null;

	if ($rPathSize == 3) {
		if (!$rStreamID) {
			$rQuery = '/\\/auth\\/(.*)$/m';
			preg_match($rQuery, $rPath, $rMatches);

			if (count($rMatches) == 2) {
				$rData = json_decode((string) Encryption::readToken($rMatches[1], $rSettings['live_streaming_pass'], OPENSSL_EXTRA, empty($rSettings['secure_stream_tokens'])), true);
				$rStreamID = intval($rData['stream_id']);
				$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rData['username'], $rData['password'], true);
			}
		}

		if (!$rStreamID) {
			$rQuery = '/\\/play\\/(.*)$/m';
			preg_match($rQuery, $rPath, $rMatches);

			if (count($rMatches) == 2) {
				$rData = explode('/', (string) Encryption::readToken($rMatches[1], $rSettings['live_streaming_pass'], OPENSSL_EXTRA, true));

				if ($rData[0] == 'live') {
					$rStreamID = intval($rData[3]);
					$rUsername = $rData[1];
					$rPassword = $rData[2] ?? null;
					$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rData[1], $rData[2], true);
				}
			}
		}
	} else {
		if ($rPathSize == 4) {
			if (!$rStreamID) {
				$rQuery = '/\/play\/(.*)\/(.*)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) == 3) {
					$rData = explode('/', (string) Encryption::readToken($rMatches[1], $rSettings['live_streaming_pass'], OPENSSL_EXTRA, true));

					if ($rData[0] == 'live') {
						$rStreamID = intval($rData[3]);
						$rUsername = $rData[1];
						$rPassword = $rData[2] ?? null;
						$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rData[1], $rData[2], true);
					}
				}
			}

			if (!$rStreamID) {
				$rQuery = '/\\/live\\/(.*)\\/(\\d+)$/m';
				preg_match($rQuery, $rPath, $rMatches);
				if (count($rMatches) == 3) {
					$rStreamID = intval($rMatches[2]);
					$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rMatches[1], null, true);
				}
			}

			if (!$rStreamID) {
				$rQuery = '/\\/live\\/(.*)\\/(\\d+)\\.(.*)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) == 4) {
					$rStreamID = intval($rMatches[2]);
					$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rMatches[1], null, true);
				}
			}

			if (!$rStreamID) {
				$rQuery = '/\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) == 5) {
					$rStreamID = intval($rMatches[3]);
					$rUsername = $rMatches[1];
					$rPassword = $rMatches[2];
					$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rMatches[1], $rMatches[2], true);
				}
			}

			if (!$rStreamID) {
				$rQuery = '/\\/(.*)\\/(.*)\\/(\\d+)$/m';
				preg_match($rQuery, $rPath, $rMatches);

				if (count($rMatches) == 4) {
					$rStreamID = intval($rMatches[3]);
					$rUsername = $rMatches[1];
					$rPassword = $rMatches[2];
					$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rMatches[1], $rMatches[2], true);
				}
			}
		} else {
			if ($rPathSize == 5) {
				if (!$rStreamID) {
					$rQuery = '/\\/live\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m';
					preg_match($rQuery, $rPath, $rMatches);

					if (count($rMatches) == 5) {
						$rStreamID = intval($rMatches[3]);
						$rUsername = $rMatches[1];
						$rPassword = $rMatches[2];
						$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rMatches[1], $rMatches[2], true);
					}
				}

				if (!$rStreamID) {
					$rQuery = '/\\/live\\/(.*)\\/(.*)\\/(\\d+)$/m';
					preg_match($rQuery, $rPath, $rMatches);

					if (count($rMatches) == 4) {
						$rStreamID = intval($rMatches[3]);
						$rUsername = $rMatches[1];
						$rPassword = $rMatches[2];
						$rUserInfo = UserRepository::getStreamingUserInfo($rSettings, $rCached, $rBouquets, null, $rMatches[1], $rMatches[2], true);
					}
				}
			}
		}
	}

	// A name and password that match no line count against the address, where
	// auth.php counts them too (it does not under ignore_invalid_users).
	if (!$rUserInfo && $rUsername !== null && !($rSettings['ignore_invalid_users'] && $rSettings['enable_cache'])) {
		BruteforceGuard::checkBruteforce($rIP, null, $rUsername, false, $rPassword);
	}

	if ($rStreamID && $rUserInfo) {
		if (is_null($rUserInfo['exp_date']) || $rUserInfo['exp_date'] > time()) {
		} else {
			generate404();
		}

		if ($rUserInfo['admin_enabled'] == 0) {
			generate404();
		}

		if ($rUserInfo['enabled'] == 0) {
			generate404();
		}

		if (!$rUserInfo['is_restreamer']) {
			generate404();
		}

		// Only a stream the line's bouquets include: the rule auth.php applies
		// before it plays one.
		if (!array_intersect((array) ($rUserInfo['bouquet'] ?? []), BouquetService::getMapEntry($rStreamID))) {
			generate404();
		}

		$rChannelInfo = StreamRedirector::redirectStream($rCached, $rSettings, $rServers, $rStreamID, 'ts', $rUserInfo, null, '', 'live');

		if (isset($rChannelInfo['redirect_id']) && $rChannelInfo['redirect_id'] != SERVER_ID) {
			$rServerID = $rChannelInfo['redirect_id'];
		} else {
			$rServerID = SERVER_ID;
		}

		if (is_array($rChannelInfo) && 0 < ($rChannelInfo['monitor_pid'] ?? 0) && 0 < ($rChannelInfo['pid'] ?? 0) && ($rServers[$rServerID]['last_status'] ?? 0) == 1) {
			if (file_exists(STREAMS_PATH . $rStreamID . '_.stream_info')) {
				$rInfo = file_get_contents(STREAMS_PATH . $rStreamID . '_.stream_info');
			} else {
				$rInfo = $rChannelInfo['stream_info'];
			}

			$rInfo = json_decode($rInfo, true);
			echo json_encode(['codecs' => $rInfo['codecs'] ?? null, 'container' => $rInfo['container'] ?? null, 'bitrate' => $rInfo['bitrate'] ?? null]);

			exit();
		}
	}
}

generate404();

function shutdown() {
	global $db;
	if (is_object($db)) {
		$db->close_mysql();
	}
}
