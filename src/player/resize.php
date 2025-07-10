<?php

session_start();
session_write_close();

if (isset($_SESSION['phash'])) {
	set_time_limit(2);
	ini_set('default_socket_timeout', 2);
	define('IMAGES_PATH', 'img/thumbs/');

	if (file_exists('config.php')) {
		require_once 'config.php';
	} else {
		$rConfig = array('platform' => 'xc_vm');
	}

	if (extension_loaded('xc_vm') && $rConfig['platform'] == 'xc_vm') {
		$rServers = igbinary_unserialize(file_get_contents('/home/xc_vm/tmp/cache/servers'));
	} else {
		$rServers = (function_exists('igbinary_unserialize') ? igbinary_unserialize(file_get_contents($rConfig['tmp_path'] . 'servers')) : unserialize(file_get_contents($rConfig['tmp_path'] . 'servers')));
	}

	if ($rServers) {
	} else {
		$rSkipVerify = true;
		require_once 'functions.php';
		$rServers = CoreUtilities::$rServers;
	}

	$rURL = $_GET['url'];
	$rMaxW = 0;
	$rMaxH = 0;

	if (!isset($_GET['maxw'])) {
	} else {
		$rMaxW = intval($_GET['maxw']);
	}

	if (!isset($_GET['maxh'])) {
	} else {
		$rMaxH = intval($_GET['maxh']);
	}

	if (!isset($_GET['max'])) {
	} else {
		$rMaxW = intval($_GET['max']);
		$rMaxH = intval($_GET['max']);
	}

	if (!(isset($_GET['h']) && isset($_GET['w']))) {
	} else {
		$rImageSize = array('width' => $_GET['w'], 'height' => $_GET['h']);
	}

	if (!isset($_GET['icon'])) {
	} else {
		$rMaxH = $rMaxW = 48;
	}

	if (substr($rURL, 0, 2) != 's:') {
	} else {
		$rSplit = explode(':', $rURL, 3);
		$rServerID = intval($rSplit[1]);
		$rDomain = (empty($rServers[$rServerID]['domain_name']) ? $rServers[$rServerID]['server_ip'] : explode(',', $rServers[$rServerID]['domain_name'])[0]);
		$rServerURL = $rServers[$rServerID]['server_protocol'] . '://' . $rDomain . ':' . $rServers[$rServerID]['request_port'] . '/';
		$rURL = $rServerURL . 'images/' . basename($rURL);
	}

	header('Content-Type: image/png');

	if (!($rURL && (0 < $rMaxW && 0 < $rMaxH || isset($rImageSize)))) {
	} else {
		$rImagePath = IMAGES_PATH . md5($rURL) . '_' . $rMaxW . '_' . $rMaxH . '.png';

		if (file_exists($rImagePath) && filesize($rImagePath) != 0) {
		} else {
			if (isabsoluteurl($rURL)) {
				$rActURL = $rURL;
			} else {
				$rActURL = IMAGES_PATH . basename($rURL);
			}

			$rImageInfo = getimagesize($rActURL);

			if (isset($rImageSize)) {
			} else {
				$rImageSize = getimagesizekeepaspectratio($rImageInfo[0], $rImageInfo[1], $rMaxW, $rMaxH);
			}

			if (!($rImageSize['width'] && $rImageSize['height'])) {
			} else {
				if ($rImageInfo['mime'] == 'image/png') {
					$rImage = imagecreatefrompng($rActURL);
				} else {
					if ($rImageInfo['mime'] == 'image/jpeg') {
						$rImage = imagecreatefromjpeg($rActURL);
					} else {
						$rImage = null;
					}
				}

				if (!$rImage) {
				} else {
					$rImageP = imagecreatetruecolor($rImageSize['width'], $rImageSize['height']);
					imagealphablending($rImageP, false);
					imagesavealpha($rImageP, true);
					imagecopyresampled($rImageP, $rImage, 0, 0, 0, 0, $rImageSize['width'], $rImageSize['height'], $rImageInfo[0], $rImageInfo[1]);
					imagepng($rImageP, $rImagePath);
				}
			}
		}

		if (!file_exists($rImagePath)) {
		} else {
			echo file_get_contents($rImagePath);

			exit();
		}
	}

	if (isset($_GET['icon'])) {
		header('Content-Type: image/png');
		$rImage = imagecreatetruecolor(1, 1);
		imagesavealpha($rImage, true);
		imagefill($rImage, 0, 0, imagecolorallocatealpha($rImage, 0, 0, 0, 127));
		imagepng($rImage);
	} else {
		echo file_get_contents('img/placeholder.png');

		exit();
	}
} else {
	exit();
}

function getimagesizekeepaspectratio($origWidth, $origHeight, $maxWidth, $maxHeight) {
	if ($maxWidth != 0) {
	} else {
		$maxWidth = $origWidth;
	}

	if ($maxHeight != 0) {
	} else {
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

function isabsoluteurl($rURL) {
	$rPattern = "/^(?:ftp|https?|feed)?:?\\/\\/(?:(?:(?:[\\w\\.\\-\\+!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+:)*" . "\r\n" . "    (?:[\\w\\.\\-\\+%!\$&'\\(\\)*\\+,;=]|%[0-9a-f]{2})+@)?(?:" . "\r\n" . '    (?:[a-z0-9\\-\\.]|%[0-9a-f]{2})+|(?:\\[(?:[0-9a-f]{0,4}:)*(?:[0-9a-f]{0,4})\\]))(?::[0-9]+)?(?:[\\/|\\?]' . "\r\n" . "    (?:[\\w#!:\\.\\?\\+\\|=&@\$'~*,;\\/\\(\\)\\[\\]\\-]|%[0-9a-f]{2})*)?\$/xi";

	return (bool) preg_match($rPattern, $rURL);
}
