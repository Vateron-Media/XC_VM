<?php

if (defined('MAIN_HOME')) {
} else {
	define('MAIN_HOME', '/home/xc_vm/');
}

require_once MAIN_HOME . 'includes/admin.php';

if (!$rMobile) {
} else {
	$rSettings['js_navigate'] = 0;
}

if (!isset($_SESSION['reseller'])) {
} else {
	$rUserInfo = getRegisteredUser($_SESSION['reseller']);

	if (0 >= strlen($rUserInfo['timezone'])) {
	} else {
		date_default_timezone_set($rUserInfo['timezone']);
	}

	setcookie('hue', $rUserInfo['hue'], time() + 604800);
	setcookie('theme', $rUserInfo['theme'], time() + 604800);
	$rPermissions = array_merge(getPermissions($rUserInfo['member_group_id']), getGroupPermissions($rUserInfo['id']));
	$rUserInfo['reports'] = array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']));
	$rIP = getIP();
	$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $_SESSION['rip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $_SESSION['rip'] == $rIP);

	if (!$rUserInfo || !$rPermissions || !$rPermissions['is_reseller'] || !$rIPMatch && $rSettings['ip_logout'] || $_SESSION['rverify'] != md5($rUserInfo['username'] . '||' . $rUserInfo['password'])) {
		unset($rUserInfo, $rPermissions);

		destroySession('reseller');
		header('Location: ./index');

		exit();
	}

	if ($_SESSION['rip'] == $rIP || $rSettings['ip_logout']) {
	} else {
		$_SESSION['rip'] = $rIP;
	}
}

if (!isset(CoreUtilities::$rRequest['status'])) {
} else {
	$_STATUS = intval(CoreUtilities::$rRequest['status']);
	$rArgs = CoreUtilities::$rRequest;
	unset($rArgs['status']);
	$customScript = setArgs($rArgs);
}
