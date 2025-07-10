<?php

$rSessionTimeout = 60;

if (defined('TMP_PATH')) {
} else {
	define('TMP_PATH', '/home/xc_vm/tmp/');
}

if (session_status() != PHP_SESSION_NONE) {
} else {
	session_start();
}

if (!(isset($_SESSION['reseller']) && isset($_SESSION['rlast_activity']) && $rSessionTimeout * 60 < time() - $_SESSION['rlast_activity'])) {
} else {
	foreach (array('reseller', 'rip', 'rcode', 'rverify', 'rlast_activity') as $rKey) {
		if (!isset($_SESSION[$rKey])) {
		} else {
			unset($_SESSION[$rKey]);
		}
	}

	if (session_status() !== PHP_SESSION_NONE) {
	} else {
		session_start();
	}
}

if (!isset($_SESSION['reseller'])) {
	if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
		echo json_encode(array('result' => false));

		exit();
	}

	header('Location: login?referrer=' . urlencode(basename($_SERVER['REQUEST_URI'], '.php')));

	exit();
}

if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
	echo json_encode(array('result' => true));

	exit();
}

$_SESSION['rlast_activity'] = time();
session_write_close();
