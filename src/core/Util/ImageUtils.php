<?php

class ImageUtils {
	public static function validateURL($rURL, $rForceProtocol = null, $rPublicURLResolver = null) {
		if (substr($rURL, 0, 2) == 's:') {
			$rSplit = explode(':', $rURL, 3);
			if (is_callable($rPublicURLResolver)) {
				$rServerURL = call_user_func($rPublicURLResolver, intval($rSplit[1]), $rForceProtocol);
			} else {
				$rServerURL = CoreUtilities::getPublicURL(intval($rSplit[1]), $rForceProtocol);
			}
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
			$rDomain = (empty(CoreUtilities::$rServers[SERVER_ID]['domain_name']) ? CoreUtilities::$rServers[SERVER_ID]['server_ip'] : explode(',', CoreUtilities::$rServers[SERVER_ID]['domain_name'])[0]);

			return CoreUtilities::$rServers[SERVER_ID]['server_protocol'] . '://' . $rDomain . ':' . CoreUtilities::$rServers[SERVER_ID]['request_port'] . '/images/admin/' . md5($rURL) . '_' . $rMaxW . '_' . $rMaxH . '.' . $rExtension;
		}

		return self::validateURL($rURL, null, array('CoreUtilities', 'getPublicURL'));
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
				if (CoreUtilities::isAbsoluteUrl($rImage)) {
					$rActURL = $rImage;
				} else {
					$rActURL = IMAGES_PATH . basename($rImage);
				}
				list($rWidth, $rHeight) = getimagesize($rActURL);
				$rImageSize = CoreUtilities::getImageSizeKeepAspectRatio($rWidth, $rHeight, $rMaxW, $rMaxH);
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
}
