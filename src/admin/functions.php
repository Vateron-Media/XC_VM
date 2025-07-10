<?php
if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', '/home/xc_vm/');
}

require_once MAIN_HOME . 'includes/admin.php';

if ($rMobile) {
	$rSettings['js_navigate'] = 0;
}

if (isset($_SESSION['hash'])) {
	$rUserInfo = getRegisteredUser($_SESSION['hash']);

	if (0 >= strlen($rUserInfo['timezone'])) {
	} else {
		date_default_timezone_set($rUserInfo['timezone']);
	}

	if (isset($_COOKIE['hue']) && $_COOKIE['hue'] == $rUserInfo['hue']) {
	} else {
		setcookie('hue', $rUserInfo['hue'], time() + 604800);
	}

	if (isset($_COOKIE['theme']) && $_COOKIE['theme'] == $rUserInfo['theme']) {
	} else {
		setcookie('theme', $rUserInfo['theme'], time() + 604800);
	}

	$rPermissions = getPermissions($rUserInfo['member_group_id']);
	$rPermissions['advanced'] = json_decode($rPermissions['allowed_pages'], true);
	$rIP = getIP();
	$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $_SESSION['ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $_SESSION['ip'] == $rIP);

	if (!$rUserInfo || !$rPermissions || !$rPermissions['is_admin'] || !$rIPMatch && $rSettings['ip_logout'] || $_SESSION['verify'] != md5($rUserInfo['username'] . '||' . $rUserInfo['password'])) {
		unset($rUserInfo, $rPermissions);

		destroySession();
		header('Location: index');

		exit();
	}

	if ($_SESSION['ip'] == $rIP || $rSettings['ip_logout']) {
	} else {
		$_SESSION['ip'] = $rIP;
	}

	$rServerError = false;

	foreach ($rServers as $rServer) {
		if (!$rServer['server_online'] && $rServer['enabled'] && $rServer['status'] != 3 && $rServer['status'] != 5) {
			$rServerError = true;
		}
	}
	$allServersHealthy = false;

	foreach ($rProxyServers as $rServer) {
		if (!$rServer['server_online'] && $rServer['enabled'] && $rServer['status'] != 3 && $rServer['status'] != 5) {
			$allServersHealthy = true;
		}
	}
	$updateRequired = false;

	if (!version_compare($rServers[SERVER_ID]['xc_vm_version'], CoreUtilities::$rSettings['update_version'], '>=')) {
		$updateRequired = true;
	}
}

if (isset(CoreUtilities::$rRequest['status'])) {
	$_STATUS = intval(CoreUtilities::$rRequest['status']);
	$rArgs = CoreUtilities::$rRequest;
	unset($rArgs['status']);
	$customScript = setArgs($rArgs);
}

if (getPageName() != 'setup') {
	$db->query('SELECT COUNT(`id`) AS `count` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `users_groups`.`is_admin` = 1;');

	if ($db->get_row()['count'] == 0) {
		header('Location: ./setup.php');
		exit();
	}
}
