<?php
/**
 * Legacy player resize image handler.
 * Extracted from player/resize.php.
 * Variables expected from caller: MAIN_HOME, $rServers (from bootstrap).
 */
set_time_limit(2);
ini_set('default_socket_timeout', 2);
if (!defined('IMAGES_PATH')) {
	define('IMAGES_PATH', MAIN_HOME . 'public/assets/player/images/thumbs/');
}

if (!is_dir(IMAGES_PATH)) {
	@mkdir(IMAGES_PATH, 0755, true);
}

if (!isset($rServers) || !$rServers) {
	$rServers = ServerRepository::getAll();
}

$rURL = $_GET['url'] ?? '';
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

if (isset($_GET['h']) && isset($_GET['w'])) {
	$rImageSize = ['width' => $_GET['w'], 'height' => $_GET['h']];
}

if (isset($_GET['icon'])) {
	$rMaxH = $rMaxW = 48;
}

if (substr($rURL, 0, 2) === 's:') {
	$rSplit = explode(':', $rURL, 3);
	$rServerID = intval($rSplit[1]);
	$rDomain = empty($rServers[$rServerID]['domain_name'])
		? $rServers[$rServerID]['server_ip']
		: explode(',', $rServers[$rServerID]['domain_name'])[0];
	$rServerURL = $rServers[$rServerID]['server_protocol'] . '://' . $rDomain . ':' . $rServers[$rServerID]['request_port'] . '/';
	$rURL = $rServerURL . 'images/' . basename($rURL);
}

header('Content-Type: image/png');

if ($rURL && ($rMaxW > 0 && $rMaxH > 0 || isset($rImageSize))) {
	$rImagePath = IMAGES_PATH . md5($rURL) . '_' . $rMaxW . '_' . $rMaxH . '.png';

	if (!file_exists($rImagePath) || filesize($rImagePath) == 0) {
		if (ImageUtils::isAbsoluteUrl($rURL)) {
			$rActURL = $rURL;
		} else {
			$rActURL = IMAGES_PATH . basename($rURL);
		}

		$rImageInfo = @getimagesize($rActURL);

		if (!$rImageInfo) {
			goto fallback;
		}

		if (!isset($rImageSize)) {
			$rImageSize = player_resize_keep_aspect_ratio($rImageInfo[0], $rImageInfo[1], $rMaxW, $rMaxH);
		}

		if ($rImageSize['width'] && $rImageSize['height']) {
			if ($rImageInfo['mime'] == 'image/png') {
				$rImage = @imagecreatefrompng($rActURL);
			} elseif ($rImageInfo['mime'] == 'image/jpeg') {
				$rImage = @imagecreatefromjpeg($rActURL);
			} else {
				$rImage = null;
			}

			if ($rImage) {
				$rImageP = imagecreatetruecolor($rImageSize['width'], $rImageSize['height']);
				imagealphablending($rImageP, false);
				imagesavealpha($rImageP, true);
				imagecopyresampled($rImageP, $rImage, 0, 0, 0, 0, $rImageSize['width'], $rImageSize['height'], $rImageInfo[0], $rImageInfo[1]);
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
if (isset($_GET['icon'])) {
	header('Content-Type: image/png');
	$rImage = imagecreatetruecolor(1, 1);
	imagesavealpha($rImage, true);
	imagefill($rImage, 0, 0, imagecolorallocatealpha($rImage, 0, 0, 0, 127));
	imagepng($rImage);
} else {
	$placeholderPath = MAIN_HOME . 'public/assets/player/images/placeholder.png';
	if (file_exists($placeholderPath)) {
		echo file_get_contents($placeholderPath);
	}
	exit();
}

/**
 * Keep aspect ratio helper for resize.
 */
function player_resize_keep_aspect_ratio($origWidth, $origHeight, $maxWidth, $maxHeight) {
	if ($maxWidth == 0) {
		$maxWidth = $origWidth;
	}
	if ($maxHeight == 0) {
		$maxHeight = $origHeight;
	}

	$widthRatio = $maxWidth / ($origWidth ?: 1);
	$heightRatio = $maxHeight / ($origHeight ?: 1);
	$ratio = min($widthRatio, $heightRatio);

	if ($ratio < 1) {
		$newWidth = (int) ($origWidth * $ratio);
		$newHeight = (int) ($origHeight * $ratio);
	} else {
		$newHeight = $origHeight;
		$newWidth = $origWidth;
	}

	return ['height' => round($newHeight, 0), 'width' => round($newWidth, 0)];
}
