<?php
/**
 * Legacy reseller resize image handler.
 * @deprecated Use resize_body.php directly. This wrapper exists for backward compatibility.
 */

$rResizeCacheDir = IMAGES_PATH . 'admin/';
require __DIR__ . '/resize_body.php';
