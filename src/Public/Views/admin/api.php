<?php

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Infrastructure\Redis\RedisManager;

/** @var \XcVm\Core\Database\Database $db */

include 'functions.php';
session_write_close();

if (!PHP_ERRORS) {
	// The report/export download is opened via a full-page navigation (window.location),
	// which never carries the X-Requested-With header. It stays gated by the session check
	// below and the per-action `backups` permission, so exempt it from the XHR guard.
	$rDirectActions = array('report');

	if (!in_array(RequestManager::get('action'), $rDirectActions, true)) {
		if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
			exit();
		}
	}
}

if (isset($_SESSION['hash'])) {
	if (SettingsManager::get('redis_handler')) {
		RedisManager::ensureConnected();
	}

	// All admin-ajax actions are now handled by dedicated controllers, reached
	// via Router::dispatchApi() before this legacy fallback (see routes/admin.php
	// and Public/index.php). Anything still routed here is unknown -> failure.
	echo json_encode(array('result' => false));
} else {
	echo json_encode(array('result' => false, 'error' => 'Not logged in'));
	exit();
}
