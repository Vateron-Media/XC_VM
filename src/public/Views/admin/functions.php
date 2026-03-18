<?php
if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', '/home/xc_vm/');
}

// Импорт глобальных переменных bootstrap'а.
// XC_Bootstrap::boot(CONTEXT_ADMIN) использует `global` для записи переменных.
// Когда functions.php включается из метода контроллера (не из глобального scope),
// эти переменные без `global` не видны в текущем scope.
// В глобальном scope (прямой вызов через nginx) — это no-op.
global $db, $rSettings, $rMobile, $rServers, $rProxyServers, $rDetect,
       $rTimeout, $rProtocol, $allServers, $rPermissions, $language, $allowedLangs,
       $rServerError, $allServersHealthy, $updateRequired, $rUserInfo, $_INFO;

require_once MAIN_HOME . 'includes/admin.php';

if ($rMobile) {
	$rSettings['js_navigate'] = 0;
}

if (isset($_SESSION['hash'])) {
	$rUserInfo = UserRepository::getRegisteredUserById($_SESSION['hash']);

	if (!empty($rUserInfo['timezone'])) {
		date_default_timezone_set($rUserInfo['timezone']);
	}

	if (!empty($rUserInfo['hue']) && (!isset($_COOKIE['hue']) || $_COOKIE['hue'] != $rUserInfo['hue'])) {
		if (!headers_sent()) {
			setcookie('hue', $rUserInfo['hue'], time() + 604800);
		}
	}

	if (!isset($_COOKIE['theme']) || $_COOKIE['theme'] != $rUserInfo['theme']) {
		if (!headers_sent()) {
			setcookie('theme', $rUserInfo['theme'], time() + 604800);
		}
	}

	if (!isset($_COOKIE['lang']) || $_COOKIE['lang'] != $rUserInfo['lang']) {
		$language::setLanguage($rUserInfo['lang']);
	}

	$rPermissions = getPermissions($rUserInfo['member_group_id']);
	$rPermissions['advanced'] = json_decode($rPermissions['allowed_pages'], true);
	$rIP = NetworkUtils::getUserIP();
	$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $_SESSION['ip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $_SESSION['ip'] == $rIP);

	if (!$rUserInfo || !$rPermissions || !$rPermissions['is_admin'] || !$rIPMatch && $rSettings['ip_logout'] || $_SESSION['verify'] != md5($rUserInfo['username'] . '||' . $rUserInfo['password'])) {
		unset($rUserInfo, $rPermissions);

		destroySession();
		if (!headers_sent()) {
			header('Location: index');
		}

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

	if (!version_compare($rServers[SERVER_ID]['xc_vm_version'], SettingsManager::getAll()['update_version'], '>=')) {
		$updateRequired = true;
	}
}

if (isset(RequestManager::getAll()['status'])) {
	$_STATUS = intval(RequestManager::getAll()['status']);
	$rArgs = RequestManager::getAll();
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
