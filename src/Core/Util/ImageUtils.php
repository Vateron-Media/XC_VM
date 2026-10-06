<?php

namespace XcVm\Core\Util;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Server\ServerRepository;

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
	 * @param string|null $rURL           Image URL or internal `s:` reference; null/empty (a stream,
	 *                                    movie or series without an image — the columns are
	 *                                    nullable) resolves to ''.
	 * @param string|null $rForceProtocol Force http/https when resolving server URLs.
	 * @return string Resolved public URL ('' if there is none or the server cannot be resolved).
	 */
	public static function validateURL(?string $rURL = null, ?string $rForceProtocol = null): string {
		if (empty($rURL)) {
			return '';
		}
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

	/**
	 * Download a remote image into the local image cache.
	 *
	 * Stores jpg/jpeg/png images and returns an internal `s:<serverId>:` reference;
	 * returns the original URL unchanged when not downloadable.
	 *
	 * @param string|null $rImage Remote image URL; callers pass optional columns
	 *                            (an icon, a backdrop), so it may be null.
	 * @param int|null    $rType  Optional stream type (unused placeholder).
	 * @return string|null Internal `s:` reference, or $rImage unchanged.
	 */
	public static function downloadImage(?string $rImage, ?int $rType = null) {
		if ((string) $rImage !== '' && substr(strtolower($rImage), 0, 4) == 'http') {
			$rPathInfo = pathinfo(parse_url($rImage, PHP_URL_PATH) ?: $rImage);
			$rExt = strtolower($rPathInfo['extension'] ?? '');
			if (!$rExt) {
				$rImageInfo = @getimagesize($rImage);
				if (is_array($rImageInfo) && !empty($rImageInfo['mime']) && strpos($rImageInfo['mime'], '/') !== false) {
					list(, $rExt) = explode('/', strtolower($rImageInfo['mime']), 2);
				}
			}
			if (in_array(strtolower($rExt), ['jpg', 'jpeg', 'png'])) {
				$rFilename = self::cacheName($rImage, $rExt);
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
				if ((string) $rData !== '') {
					$rPath = IMAGES_PATH . $rFilename . '.' . $rExt;
					// The images cache dir may not exist yet on a given node (e.g. an
					// LB running the watch import), so file_put_contents would fail
					// with ENOENT. Ensure the directory exists before writing.
					if (!is_dir(IMAGES_PATH)) {
						@mkdir(IMAGES_PATH, 0775, true);
					}
					@file_put_contents($rPath, $rData);
					if (file_exists($rPath)) {
						return 's:' . SERVER_ID . ':/images/' . $rFilename . '.' . $rExt;
					}
				}
			}
		}
		return $rImage;
	}

	/**
	 * Download many images at once: for each URL, what downloadImage() returns for
	 * it inside an import, with up to $rParallel transfers in flight. Each URL is
	 * fetched once; an answer of 400 or more is a failure. Once three transfers to
	 * one host failed in a row, that host's other images keep their URL.
	 *
	 * Unlike downloadImage(), a URL without a jpg/jpeg/png extension is never
	 * asked for its type: it is returned unchanged, as an import's downloadImage()
	 * (default_socket_timeout 0) left it.
	 *
	 * @param array<mixed> $rImages Image URLs; anything but a string is skipped
	 * @return array<string, string> URL => internal `s:` reference, or the URL unchanged
	 */
	public static function downloadImages(array $rImages, int $rParallel = 6): array {
		$rOut = $rQueue = $rActive = $rFails = [];
		foreach ($rImages as $rImage) {
			if (!is_string($rImage) || isset($rOut[$rImage])) {
				continue;
			}
			$rOut[$rImage] = $rImage;
			$rExt = strtolower(pathinfo(parse_url($rImage, PHP_URL_PATH) ?: $rImage)['extension'] ?? '');
			if (substr(strtolower($rImage), 0, 4) != 'http' || !in_array($rExt, ['jpg', 'jpeg', 'png'])) {
				continue;
			}
			$rName = self::cacheName($rImage, $rExt) . '.' . $rExt;
			if (file_exists(IMAGES_PATH . $rName)) {
				$rOut[$rImage] = 's:' . SERVER_ID . ':/images/' . $rName;
			} else {
				$rQueue[] = [$rImage, $rName, strtolower((string) parse_url($rImage, PHP_URL_HOST))];
			}
		}
		if ($rQueue !== [] && !is_dir(IMAGES_PATH)) {
			@mkdir(IMAGES_PATH, 0775, true);
		}
		$rMulti = curl_multi_init();
		while ($rQueue !== [] || $rActive !== []) {
			while ($rQueue !== [] && count($rActive) < $rParallel) {
				$rJob = array_shift($rQueue);
				if (3 <= ($rFails[$rJob[2]] ?? 0)) {
					continue;
				}
				$rCurl = curl_init();
				curl_setopt_array($rCurl, [CURLOPT_URL => $rJob[0], CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 5]);
				curl_multi_add_handle($rMulti, $rCurl);
				$rActive[spl_object_id($rCurl)] = [$rCurl, $rJob];
			}
			curl_multi_exec($rMulti, $rRunning);
			while ($rInfo = curl_multi_info_read($rMulti)) {
				[$rCurl, [$rImage, $rName, $rHost]] = $rActive[spl_object_id($rInfo['handle'])];
				unset($rActive[spl_object_id($rInfo['handle'])]);
				$rDone = $rInfo['result'] === CURLE_OK && curl_getinfo($rCurl, CURLINFO_RESPONSE_CODE) < 400;
				$rData = $rDone ? (string) curl_multi_getcontent($rCurl) : '';
				$rFails[$rHost] = $rDone ? 0 : ($rFails[$rHost] ?? 0) + 1;
				curl_multi_remove_handle($rMulti, $rCurl);
				curl_close($rCurl);
				if ($rData !== '') {
					@file_put_contents(IMAGES_PATH . $rName, $rData);
					if (file_exists(IMAGES_PATH . $rName)) {
						$rOut[$rImage] = 's:' . SERVER_ID . ':/images/' . $rName;
					}
				}
			}
			if ($rActive !== []) {
				curl_multi_select($rMulti, 1.0);
			}
		}
		curl_multi_close($rMulti);
		return $rOut;
	}

	/**
	 * The cached image's file name (without extension): the encrypted URL.
	 *
	 * A single filename component is capped at 255 bytes on ext4/most
	 * filesystems. Long source URLs produce an encrypted name that exceeds
	 * this, so file_put_contents fails with "File name too long" and the whole
	 * import (e.g. Plex Sync) stalls. Fall back to a deterministic hash for
	 * those — the tools-images self-heal can't reverse a hash, but the image
	 * still downloads and caches. The "h_" prefix marks these so the self-heal
	 * can skip them.
	 */
	private static function cacheName(string $rImage, string $rExt): string {
		$rFilename = Encryption::encrypt($rImage, SettingsManager::get('live_streaming_pass'), OPENSSL_EXTRA);
		return strlen($rFilename . '.' . $rExt) > 250 ? 'h_' . hash('sha256', $rImage) : $rFilename;
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
	public static function getImageSizeKeepAspectRatio(int $origWidth, int $origHeight, int $maxWidth, int $maxHeight) {
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
			$newWidth = $origWidth * $ratio;
			$newHeight = $origHeight * $ratio;
		} else {
			$newHeight = $origHeight;
			$newWidth = $origWidth;
		}
		return ['height' => round($newHeight, 0), 'width' => round($newWidth, 0)];
	}

	/**
	 * Determine whether a string is an absolute URL.
	 *
	 * @param string $rURL Candidate URL.
	 * @return bool True if absolute.
	 */
	public static function isAbsoluteUrl(string $rURL) {
		$rPattern = "/^(?:ftp|https?|feed)?:?\\/\\/(?:(?:(?:[\\w\\.\\-\\+!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+:)*" . "\n" . "        (?:[\\w\\.\\-\\+%!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+@)?(?:" . "\n" . '        (?:[a-z0-9\\-\\.]|%[0-9a-f]{2})+|(?:\\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\\]))(?::[0-9]+)?(?:[\\/|\\?]' . "\n" . "        (?:[\\w#!:\\.\\?\\+\\|=&@\$'~*,;\\/\\(\\)\\[\\]\\-]|%[0-9a-f]{2})*)?\$/xi";
		return (bool) preg_match($rPattern, $rURL);
	}
}
