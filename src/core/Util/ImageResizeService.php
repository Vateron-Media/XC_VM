<?php

/**
 * ImageResizeService — HTTP image resize handler
 *
 * Resizes a remote/local image on demand, caches the result as PNG,
 * and outputs the file directly to the HTTP response.
 *
 * Caller sets up options and delegates:
 *
 *   ImageResizeService::serve([
 *     'cacheDir'     => IMAGES_PATH . 'admin/',   // required
 *     'placeholder'  => null,                     // optional path, null → 1×1 transparent
 *     'extraParams'  => false,                    // true → support ?w, ?h, ?icon (player)
 *   ]);
 *
 * @package XC_VM_Core_Util
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ImageResizeService {

	/**
	 * Process resize request and send image response.
	 *
	 * @param array $rOptions {
	 *   string      cacheDir    Cache directory (trailing slash). Default: IMAGES_PATH . 'admin/'
	 *   string|null placeholder Path to placeholder file. Default: null (1×1 transparent)
	 *   bool        extraParams Support ?w, ?h, ?icon params (player panel). Default: false
	 * }
	 */
	public static function serve(array $rOptions = []): void {
		set_time_limit(2);
		ini_set('default_socket_timeout', 2);

		$rCacheDir     = $rOptions['cacheDir']     ?? (defined('IMAGES_PATH') ? IMAGES_PATH . 'admin/' : '');
		$rPlaceholder  = $rOptions['placeholder']  ?? null;
		$rExtraParams  = $rOptions['extraParams']  ?? false;

		if ($rCacheDir && !is_dir($rCacheDir)) {
			@mkdir($rCacheDir, 0755, true);
		}

		$rServers = $GLOBALS['rServers'] ?? ServerRepository::getAll();

		$rURL  = $_GET['url'] ?? '';
		$rMaxW = 0;
		$rMaxH = 0;

		if (isset($_GET['maxw'])) {
			$rMaxW = intval($_GET['maxw']);
		}
		if (isset($_GET['maxh'])) {
			$rMaxH = intval($_GET['maxh']);
		}
		if (isset($_GET['max'])) {
			$rMaxW = intval($_GET['max']);
			$rMaxH = intval($_GET['max']);
		}

		$rImageSize = null;

		if ($rExtraParams) {
			if (isset($_GET['h'], $_GET['w'])) {
				$rImageSize = ['width' => intval($_GET['w']), 'height' => intval($_GET['h'])];
			}
			if (isset($_GET['icon'])) {
				$rMaxH = $rMaxW = 48;
			}
		}

		// Resolve server-prefixed URL (s:<id>:<path>)
		if (substr($rURL, 0, 2) === 's:') {
			$rSplit    = explode(':', $rURL, 3);
			$rServerID = intval($rSplit[1]);
			if (isset($rServers[$rServerID])) {
				$rSrv      = $rServers[$rServerID];
				$rDomain   = empty($rSrv['domain_name'])
					? $rSrv['server_ip']
					: explode(',', $rSrv['domain_name'])[0];
				$rServerURL = $rSrv['server_protocol'] . '://' . $rDomain . ':' . $rSrv['request_port'] . '/';
				$rURL = $rServerURL . 'images/' . basename($rURL);
			}
		}

		header('Content-Type: image/png');
		header('X-Content-Type-Options: nosniff');

		if ($rURL && ($rMaxW > 0 && $rMaxH > 0 || $rImageSize !== null)) {
			$rImagePath = $rCacheDir . md5($rURL) . '_' . $rMaxW . '_' . $rMaxH . '.png';

			if (!file_exists($rImagePath) || filesize($rImagePath) === 0) {
				$rActURL = ImageUtils::isAbsoluteUrl($rURL)
					? $rURL
					: (defined('IMAGES_PATH') ? IMAGES_PATH . basename($rURL) : $rURL);

				$rImageInfo = @getimagesize($rActURL);

				if (!$rImageInfo) {
					goto fallback;
				}

				if ($rImageSize === null) {
					$rImageSize = ImageUtils::getImageSizeKeepAspectRatio(
						$rImageInfo[0], $rImageInfo[1], $rMaxW, $rMaxH
					);
				}

				if ($rImageSize['width'] && $rImageSize['height']) {
					if ($rImageInfo['mime'] === 'image/png') {
						$rImage = @imagecreatefrompng($rActURL);
					} elseif ($rImageInfo['mime'] === 'image/jpeg') {
						$rImage = @imagecreatefromjpeg($rActURL);
					} else {
						$rImage = null;
					}

					if ($rImage) {
						$rImageP = imagecreatetruecolor($rImageSize['width'], $rImageSize['height']);
						imagealphablending($rImageP, false);
						imagesavealpha($rImageP, true);
						imagecopyresampled(
							$rImageP, $rImage,
							0, 0, 0, 0,
							$rImageSize['width'], $rImageSize['height'],
							$rImageInfo[0], $rImageInfo[1]
						);
						imagepng($rImageP, $rImagePath);
					}
				}
			}

			if (file_exists($rImagePath)) {
				echo file_get_contents($rImagePath);
				exit();
			}
		}

		fallback:
		if ($rPlaceholder && file_exists($rPlaceholder) && !isset($_GET['icon'])) {
			echo file_get_contents($rPlaceholder);
			exit();
		}

		$rImg = imagecreatetruecolor(1, 1);
		imagesavealpha($rImg, true);
		imagefill($rImg, 0, 0, imagecolorallocatealpha($rImg, 0, 0, 0, 127));
		imagepng($rImg);
	}
}
