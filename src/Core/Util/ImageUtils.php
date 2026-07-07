<?php

namespace XcVm\Core\Util;

use XcVm\Core\Config\SettingsManager;

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
	/**
	 * Resolve an image URL, expanding internal `s:<serverId>:` references.
	 *
	 * @param string      $rURL           Image URL or internal `s:` reference.
	 * @param string|null $rForceProtocol Force http/https when resolving server URLs.
	 * @return string Resolved public URL ('' if the server cannot be resolved).
	 */
	public static function validateURL($rURL, $rForceProtocol = null) {
		if (substr($rURL, 0, 2) == 's:') {
			$rSplit = explode(':', $rURL, 3);
			$rServerURL = \XcVm\Domain\Server\ServerRepository::getPublicURL(intval($rSplit[1]), $rForceProtocol);
			if ($rServerURL) {
				return $rServerURL . 'images/' . basename($rURL);
			}
			return '';
		}
		return $rURL;
	}

	/**
	 * Return the URL of a cached resized image, or the validated original.
	 *
	 * @param string $rURL  Source image URL.
	 * @param int    $rMaxW Max width.
	 * @param int    $rMaxH Max height.
	 * @return string Public URL of the resized image, or the validated source.
	 */
	public static function resize($rURL, $rMaxW, $rMaxH) {
		list($rExtension) = explode('.', strtolower(pathinfo($rURL)['extension']));
		$rImagePath = IMAGES_PATH . 'admin/' . md5($rURL) . '_' . $rMaxW . '_' . $rMaxH . '.' . $rExtension;

		if (file_exists($rImagePath)) {
			$rServerInfo = \XcVm\Domain\Server\ServerRepository::getAll()[SERVER_ID];
			$rDomain = (empty($rServerInfo['domain_name']) ? $rServerInfo['server_ip'] : explode(',', $rServerInfo['domain_name'])[0]);

			return $rServerInfo['server_protocol'] . '://' . $rDomain . ':' . $rServerInfo['request_port'] . '/images/admin/' . md5($rURL) . '_' . $rMaxW . '_' . $rMaxH . '.' . $rExtension;
		}

		return self::validateURL($rURL);
	}

	/**
	 * Generate and cache a thumbnail sized for the given stream type.
	 *
	 * @param string $rImage Source image path/URL.
	 * @param int    $rType  Stream type controlling target dimensions.
	 * @return bool True if the thumbnail exists/was created, false otherwise.
	 */
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

	/**
	 * Download a remote image into the local image cache.
	 *
	 * Stores jpg/jpeg/png images and returns an internal `s:<serverId>:` reference;
	 * returns the original URL unchanged when not downloadable.
	 *
	 * @param string   $rImage Remote image URL.
	 * @param int|null $rType  Optional stream type (unused placeholder).
	 * @return string Internal `s:` reference, or the original URL.
	 */
	public static function downloadImage($rImage, $rType = null) {
		if (0 < strlen($rImage) && substr(strtolower($rImage), 0, 4) == 'http') {
			$rPathInfo = pathinfo(parse_url($rImage, PHP_URL_PATH) ?: $rImage);
			$rExt = strtolower($rPathInfo['extension'] ?? '');
			if (!$rExt) {
				$rImageInfo = @getimagesize($rImage);
				if (is_array($rImageInfo) && !empty($rImageInfo['mime']) && strpos($rImageInfo['mime'], '/') !== false) {
					list(, $rExt) = explode('/', strtolower($rImageInfo['mime']), 2);
				}
			}
			if (in_array(strtolower($rExt), array('jpg', 'jpeg', 'png'))) {
				$rFilename = Encryption::encrypt($rImage, SettingsManager::getAll()['live_streaming_pass'], OPENSSL_EXTRA);
				// A single filename component is capped at 255 bytes on ext4/most
				// filesystems. Long source URLs produce an encrypted name that
				// exceeds this, so file_put_contents fails with "File name too
				// long" and the whole import (e.g. Plex Sync) stalls. Fall back to
				// a deterministic hash for those — the tools-images self-heal can't
				// reverse a hash, but the image still downloads and caches. The
				// "h_" prefix marks these so the self-heal can skip them.
				if (strlen($rFilename . '.' . $rExt) > 250) {
					$rFilename = 'h_' . hash('sha256', $rImage);
				}
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
					// The images cache dir may not exist yet on a given node (e.g. an
					// LB running the watch import), so file_put_contents would fail
					// with ENOENT. Ensure the directory exists before writing.
					if (!is_dir(IMAGES_PATH)) {
						@mkdir(IMAGES_PATH, 0775, true);
					}
					file_put_contents($rPath, $rData);
					if (file_exists($rPath)) {
						return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
					}
				}
			}
		}
		return $rImage;
	}

	/**
	 * Compute dimensions that fit within a bounding box, preserving aspect ratio.
	 *
	 * @param int $origWidth  Original width.
	 * @param int $origHeight Original height.
	 * @param int $maxWidth   Max width (0 = no limit).
	 * @param int $maxHeight  Max height (0 = no limit).
	 * @return array ['width' => int, 'height' => int].
	 */
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

	/**
	 * Determine whether a string is an absolute URL.
	 *
	 * @param string $rURL Candidate URL.
	 * @return bool True if absolute.
	 */
	public static function isAbsoluteUrl($rURL) {
		$rPattern = "/^(?:ftp|https?|feed)?:?\\/\\/(?:(?:(?:[\\w\\.\\-\\+!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+:)*" . "\n" . "        (?:[\\w\\.\\-\\+%!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+@)?(?:" . "\n" . '        (?:[a-z0-9\\-\\.]|%[0-9a-f]{2})+|(?:\\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\\]))(?::[0-9]+)?(?:[\\/|\\?]' . "\n" . "        (?:[\\w#!:\\.\\?\\+\\|=&@\$'~*,;\\/\\(\\)\\[\\]\\-]|%[0-9a-f]{2})*)?\$/xi";
		return (bool) preg_match($rPattern, $rURL);
	}
}
