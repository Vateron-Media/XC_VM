<?php
/**
 * Reseller session bootstrap.
 *
 * Extracted from reseller/session.php for Front Controller use.
 * Manages reseller session lifecycle: timeout, login redirect, heartbeat.
 *
 * Session keys: 'reseller' (user ID), 'rip', 'rcode', 'rverify', 'rlast_activity'
 */

$rSessionTimeout = 60;

if (!defined('TMP_PATH')) {
	define('TMP_PATH', '/home/xc_vm/tmp/');
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
	session_start();
}

// Expire session after timeout
if (isset($_SESSION['reseller'], $_SESSION['rlast_activity'])
    && ($rSessionTimeout * 60) < (time() - $_SESSION['rlast_activity'])) {
	foreach (['reseller', 'rip', 'rcode', 'rverify', 'rlast_activity'] as $rKey) {
		unset($_SESSION[$rKey]);
	}

	if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
		session_start();
	}
}

// Not logged in → redirect to login (unless this is a direct session check via FC)
if (!isset($_SESSION['reseller'])) {
	// FC handles login/index pages via noBootstrapPages — this code runs only
	// for authenticated pages. Redirect to login with referrer.
	$referrer = defined('PAGE_NAME') ? PAGE_NAME : basename($_SERVER['REQUEST_URI'] ?? '', '.php');
	header('Location: login?referrer=' . urlencode($referrer));
	exit();
}

$_SESSION['rlast_activity'] = time();
session_write_close();
