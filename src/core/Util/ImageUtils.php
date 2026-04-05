<?php

/**
 * ImageUtils — image utils
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ImageUtils {
	public static function validateURL($rURL, $rForceProtocol = null) {
		if (substr($rURL, 0, 2) == 's:') {
			$rSplit = explode(':', $rURL, 3);
			$rServerURL = ServerRepository::getPublicURL(intval($rSplit[1]), $rForceProtocol);
			if ($rServerURL) {
				return $rServerURL . 'images/' . basename($rURL);
			}
			return '';
		}
		return $rURL;
	}

	public static function resize($rURL, $rMaxW, $rMaxH) {
		list($rExtension) = explode('.', strtolower(pathinfo($rURL)['extension']));
		$rImagePath = IMAGES_PATH . 'admin/' . md5($rURL) . '_' . $rMaxW . '_' . $rMaxH . '.' . $rExtension;

		if (file_exists($rImagePath)) {
			$rServerInfo = ServerRepository::getAll()[SERVER_ID];
			$rDomain = (empty($rServerInfo['domain_name']) ? $rServerInfo['server_ip'] : explode(',', $rServerInfo['domain_name'])[0]);

			return $rServerInfo['server_protocol'] . '://' . $rDomain . ':' . $rServerInfo['request_port'] . '/images/admin/' . md5($rURL) . '_' . $rMaxW . '_' . $rMaxH . '.' . $rExtension;
		}

		return self::validateURL($rURL);
	}

	public static function generateThumbnail($rImage, $rType) {
		if ($rType == 1 || $rType == 5 || $rType == 4) {
			$rMaxW = 96;
			$rMaxH = 32;
		} else {
			if ($rType == 2) {
				$rMaxW = 58;
				$rMaxH = 32;
			} else {
				if ($rType == 5) {
					$rMaxW = 32;
					$rMaxH = 64;
				} else {
					return false;
				}
			}
		}
		list($rExtension) = explode('.', strtolower(pathinfo($rImage)['extension']));
		if (!in_array($rExtension, array('png', 'jpg', 'jpeg'))) {
		} else {
			$rImagePath = IMAGES_PATH . 'admin/' . md5($rImage) . '_' . $rMaxW . '_' . $rMaxH . '.' . $rExtension;
			if (file_exists($rImagePath)) {
			} else {
				if (self::isAbsoluteUrl($rImage)) {
					$rActURL = $rImage;
				} else {
					$rActURL = IMAGES_PATH . basename($rImage);
				}
				list($rWidth, $rHeight) = getimagesize($rActURL);
				$rImageSize = self::getImageSizeKeepAspectRatio($rWidth, $rHeight, $rMaxW, $rMaxH);
				if (!($rImageSize['width'] && $rImageSize['height'])) {
				} else {
					$rImageP = imagecreatetruecolor($rImageSize['width'], $rImageSize['height']);
					if ($rExtension == 'png') {
						$rImage = imagecreatefrompng($rActURL);
					} else {
						$rImage = imagecreatefromjpeg($rActURL);
					}
					imagealphablending($rImageP, false);
					imagesavealpha($rImageP, true);
					imagecopyresampled($rImageP, $rImage, 0, 0, 0, 0, $rImageSize['width'], $rImageSize['height'], $rWidth, $rHeight);
					imagepng($rImageP, $rImagePath);
				}
			}
			if (!file_exists($rImagePath)) {
			} else {
				return true;
			}
		}
		return false;
	}

	public static function downloadImage($rImage, $rType = null) {
		if (0 < strlen($rImage) && substr(strtolower($rImage), 0, 4) == 'http') {
			$rPathInfo = pathinfo($rImage);
			$rExt = $rPathInfo['extension'];
			if (!$rExt) {
				$rImageInfo = getimagesize($rImage);
				if ($rImageInfo['mime']) {
					list(, $rExt) = explode('/', $rImageInfo['mime']);
				}
			}
			if (in_array(strtolower($rExt), array('jpg', 'jpeg', 'png'))) {
				$rFilename = Encryption::encrypt($rImage, SettingsManager::getAll()['live_streaming_pass'], OPENSSL_EXTRA);
				$rPrevPath = IMAGES_PATH . $rFilename . '.' . $rExt;
				if (file_exists($rPrevPath)) {
					return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
				}
				$rCurl = curl_init();
				curl_setopt($rCurl, CURLOPT_URL, $rImage);
				curl_setopt($rCurl, CURLOPT_RETURNTRANSFER, true);
				curl_setopt($rCurl, CURLOPT_CONNECTTIMEOUT, 5);
				curl_setopt($rCurl, CURLOPT_TIMEOUT, 5);
				$rData = curl_exec($rCurl);
				if (strlen($rData) > 0) {
					$rPath = IMAGES_PATH . $rFilename . '.' . $rExt;
					file_put_contents($rPath, $rData);
					if (file_exists($rPath)) {
						return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
					}
				}
			}
		}
		return $rImage;
	}

	public static function getImageSizeKeepAspectRatio($origWidth, $origHeight, $maxWidth, $maxHeight) {
		if ($maxWidth == 0) {
			$maxWidth = $origWidth;
		}
		if ($maxHeight == 0) {
			$maxHeight = $origHeight;
		}
		$widthRatio = $maxWidth / (($origWidth ?: 1));
		$heightRatio = $maxHeight / (($origHeight ?: 1));
		$ratio = min($widthRatio, $heightRatio);
		if ($ratio < 1) {
			$newWidth = (int) $origWidth * $ratio;
			$newHeight = (int) $origHeight * $ratio;
		} else {
			$newHeight = $origHeight;
			$newWidth = $origWidth;
		}
		return array('height' => round($newHeight, 0), 'width' => round($newWidth, 0));
	}

	public static function isAbsoluteUrl($rURL) {
		$rPattern = "/^(?:ftp|https?|feed)?:?\\/\\/(?:(?:(?:[\\w\\.\\-\\+!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+:)*" . "\n" . "        (?:[\\w\\.\\-\\+%!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+@)?(?:" . "\n" . '        (?:[a-z0-9\\-\\.]|%[0-9a-f]{2})+|(?:\\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\\]))(?::[0-9]+)?(?:[\\/|\\?]' . "\n" . "        (?:[\\w#!:\\.\\?\\+\\|=&@\$'~*,;\\/\\(\\)\\[\\]\\-]|%[0-9a-f]{2})*)?\$/xi";
		return (bool) preg_match($rPattern, $rURL);
	}
}
