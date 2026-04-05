<?php

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
	public static function fixCookie($rCookie) {
		$rPath = false;
		$rDomain = false;
		$rSplit = explode(';', $rCookie);
		foreach ($rSplit as $rPiece) {
			list($rKey, $rValue) = explode('=', $rPiece, 1);
			if (strtolower($rKey) == 'path') {
				$rPath = true;
			} else {
				if (strtolower($rKey) != 'domain') {
				} else {
					$rDomain = true;
				}
			}
		}
		if (!substr($rCookie, -1) != ';') {
		} else {
			$rCookie .= ';';
		}
		if ($rPath) {
		} else {
			$rCookie .= 'path=/;';
		}
		if ($rDomain) {
		} else {
			$rCookie .= 'domain=;';
		}
		return $rCookie;
	}

	public static function getArguments($rArguments, $rProtocol, $rType) {
		$rReturn = array();
		if (!empty($rArguments)) {
			foreach ($rArguments as $rArgument_id => $rArgument) {
				if ($rArgument['argument_cat'] == $rType && (is_null($rArgument['argument_wprotocol']) || stristr($rProtocol, $rArgument['argument_wprotocol']) || is_null($rProtocol))) {
					if ($rArgument['argument_key'] == 'cookie') {
						$rArgument['value'] = self::fixCookie($rArgument['value']);
					}
					if ($rArgument['argument_type'] == 'text') {
						$rReturn[] = sprintf($rArgument['argument_cmd'], $rArgument['value']);
					} else {
						$rReturn[] = $rArgument['argument_cmd'];
					}
				}
			}
		}
		return $rReturn;
	}

	public static function parseTranscode($rArgs) {
		$rFitlerComplex = array();
		foreach ($rArgs as $rKey => $rArgument) {
			if (!($rKey == 'gpu' || $rKey == 'software_decoding' || $rKey == '16')) {
				if (isset($rArgument['cmd'])) {
					$rArgs[$rKey] = $rArgument = $rArgument['cmd'];
				}
				if (preg_match('/-filter_complex "(.*?)"/', $rArgument, $rMatches)) {
					$rArgs[$rKey] = trim(str_replace($rMatches[0], '', $rArgs[$rKey]));
					$rFitlerComplex[] = $rMatches[1];
				}
			}
		}
		if (!empty($rFitlerComplex)) {
			$rArgs[] = '-filter_complex "' . implode(',', $rFitlerComplex) . '"';
		}
		$rNewArgs = array();
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
		uasort($rNewArgs, array('StreamUtils', 'customOrder'));
		return array_map('trim', array_values(array_filter($rNewArgs)));
	}

	public static function customOrder($a, $b) {
		if (substr($a, 0, 3) == '-i ') {
			return -1;
		}
		return 1;
	}

	public static function parseStreamURL($rURL) {
		$rProtocol = strtolower(substr($rURL, 0, 4));
		if ($rProtocol == 'rtmp') {
			if (stristr($rURL, '$OPT')) {
				$rPattern = 'rtmp://$OPT:rtmp-raw=';
				$rURL = trim(substr($rURL, stripos($rURL, $rPattern) + strlen($rPattern)));
			}
			$rURL .= ' live=1 timeout=10';
		} else {
			if ($rProtocol == 'http') {
				$rPlatforms = array('livestream.com', 'ustream.tv', 'twitch.tv', 'vimeo.com', 'facebook.com', 'dailymotion.com', 'cnn.com', 'edition.cnn.com', 'youtube.com', 'youtu.be');
				$rHost = str_ireplace('www.', '', parse_url($rURL, PHP_URL_HOST));
				if (in_array($rHost, $rPlatforms)) {
					$rURLs = trim(shell_exec(YOUTUBE_BIN . ' ' . escapeshellarg($rURL) . ' -q --get-url --skip-download -f best'));
					list($rURL) = explode("\n", $rURLs);
				}
			}
		}
		return $rURL;
	}

	public static function detectXC_VM($rURL) {
		$rPath = parse_url($rURL)['path'];
		$rPathSize = count(explode('/', $rPath));
		$rRegex = array('/\\/auth\\/(.*)$/m' => 3, '/\\/play\\/(.*)$/m' => 3, '/\\/play\\/(.*)\\/(.*)$/m' => 4, '/\\/live\\/(.*)\\/(\\d+)$/m' => 4, '/\\/live\\/(.*)\\/(\\d+)\\.(.*)$/m' => 4, '/\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m' => 4, '/\\/(.*)\\/(.*)\\/(\\d+)$/m' => 4, '/\\/live\\/(.*)\\/(.*)\\/(\\d+)\\.(.*)$/m' => 5, '/\\/live\\/(.*)\\/(.*)\\/(\\d+)$/m' => 5);
		foreach ($rRegex as $rQuery => $rCount) {
			if ($rPathSize != $rCount) {
			} else {
				preg_match($rQuery, $rPath, $rMatches);
				if (0 >= count($rMatches)) {
				} else {
					return true;
				}
			}
		}
		return false;
	}

	public static function getPlaylistSegments($rPlaylist, $rPrebuffer = 0, $rSegmentDuration = 10) {
		if (!file_exists($rPlaylist)) {
		} else {
			$rSource = file_get_contents($rPlaylist);
			if (!preg_match_all('/(.*?).ts/', $rSource, $rMatches)) {
			} else {
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
	}

	public static function generateAdminHLS($rM3U8, $rPassword, $rStreamID, $rUIToken) {
		if (!file_exists($rM3U8)) {
		} else {
			$rSource = file_get_contents($rM3U8);
			if (!preg_match_all('/(.*?)\\.ts/', $rSource, $rMatches)) {
			} else {
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

	public static function isValidStream($rPlaylist, $rPID) {
		return (ProcessManager::isRunning($rPID, 'ffmpeg') || ProcessManager::isRunning($rPID, 'php')) && file_exists($rPlaylist);
	}

	public static function findKeyframe($rSegment) {
		$rPacketSize = 188;
		$rKeyframe = $rPosition = 0;
		$rFoundStart = false;
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

	public static function getStreamBitrate($rType, $rPath, $rForceDuration = null) {
		clearstatcache();
		if (file_exists($rPath)) {
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
					$rBitrates = array();
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

	public static function getTSInfo($rFilename) {
		return json_decode(shell_exec(BIN_PATH . 'tsinfo ' . escapeshellarg($rFilename)), true);
	}
}
