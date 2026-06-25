<?php
/**
 * Admin session bootstrap (Front Controller path).
 *
 * Extracted from admin/session.php for FC use.
 * Manages admin session lifecycle: timeout, login redirect, heartbeat.
 *
 * Session keys: 'hash' (user ID), 'ip', 'code', 'verify', 'last_activity'
 *
 * @see admin/session.php — оригинал (для direct nginx access)
 * @since Phase 10.5
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

$rSessionTimeout = 60;

if (!defined('TMP_PATH')) {
	define('TMP_PATH', '/home/xc_vm/tmp/');
}

if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
	session_start();
}

// Expire session after timeout
if (isset($_SESSION['hash'], $_SESSION['last_activity'])
    && ($rSessionTimeout * 60) < (time() - $_SESSION['last_activity'])) {
	foreach (['hash', 'ip', 'code', 'verify', 'last_activity'] as $rKey) {
		unset($_SESSION[$rKey]);
	}
}

// Not authenticated → redirect to login (or JSON response for AJAX)
if (!isset($_SESSION['hash'])) {
	if (!empty($_SERVER['HTTP_X_REQUESTED_WITH'])
	    && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
		header('Content-Type: application/json');
		echo json_encode(['result' => false]);
		exit;
	}

	$referrer = '';
	if (defined('PAGE_NAME') && PAGE_NAME !== 'login') {
		$referrer = '?referrer=' . urlencode(PAGE_NAME);
	}

	header('Location: ./login' . $referrer);
	exit;
}

$_SESSION['last_activity'] = time();
session_write_close();
