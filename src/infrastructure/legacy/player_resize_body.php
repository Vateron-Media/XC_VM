<?php
/**
 * Legacy player resize image handler.
 * @deprecated Use resize_body.php directly. This wrapper exists for backward compatibility.
 */

if (!defined('IMAGES_PATH')) {
	define('IMAGES_PATH', MAIN_HOME . 'public/assets/player/images/thumbs/');
}
$rResizeCacheDir = IMAGES_PATH;
$rResizePlaceholder = MAIN_HOME . 'public/assets/player/images/placeholder.png';
$rResizeSupportExtras = true;
require __DIR__ . '/resize_body.php';
