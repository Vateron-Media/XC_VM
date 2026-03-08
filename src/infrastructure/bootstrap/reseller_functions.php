<?php
/**
 * Reseller functions bootstrap.
 *
 * Extracted from reseller/functions.php for Front Controller use.
 * Loads includes/admin.php, then sets up $rUserInfo, $rPermissions
 * and validates reseller session integrity.
 */

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', '/home/xc_vm/');
}

require_once MAIN_HOME . 'includes/admin.php';

if ($rMobile) {
	$rSettings['js_navigate'] = 0;
}

if (isset($_SESSION['reseller'])) {
	$rUserInfo = UserRepository::getRegisteredUserById($_SESSION['reseller']);

	if (strlen($rUserInfo['timezone']) > 0) {
		date_default_timezone_set($rUserInfo['timezone']);
	}

	setcookie('hue', $rUserInfo['hue'], time() + 604800);
	setcookie('theme', $rUserInfo['theme'], time() + 604800);
	$language::setLanguage($rUserInfo['lang']);

	$rPermissions = array_merge(getPermissions($rUserInfo['member_group_id']), getGroupPermissions($rUserInfo['id']));
	$rPermissions['direct_reports'] = $rPermissions['direct_reports'] ?? [];
	$rPermissions['all_reports'] = $rPermissions['all_reports'] ?? [];
	$rPermissions['stream_ids'] = $rPermissions['stream_ids'] ?? [];
	$rPermissions['category_ids'] = $rPermissions['category_ids'] ?? [];
	$rPermissions['series_ids'] = $rPermissions['series_ids'] ?? [];
	$rPermissions['subresellers'] = $rPermissions['subresellers'] ?? [];
	$rUserInfo['reports'] = array_map('intval', array_merge(array($rUserInfo['id']), $rPermissions['all_reports']));
	$rIP = NetworkUtils::getUserIP();
	$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $_SESSION['rip']), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $_SESSION['rip'] == $rIP);

	if (!$rUserInfo || !$rPermissions || !$rPermissions['is_reseller'] || !$rIPMatch && $rSettings['ip_logout'] || $_SESSION['rverify'] != md5($rUserInfo['username'] . '||' . $rUserInfo['password'])) {
		unset($rUserInfo, $rPermissions);

		destroySession('reseller');
		header('Location: ./index');

		exit();
	}
	if ($_SESSION['rip'] != $rIP && !$rSettings['ip_logout']) {
		$_SESSION['rip'] = $rIP;
	}
}

if (isset(RequestManager::getAll()['status'])) {
	$_STATUS = intval(RequestManager::getAll()['status']);
	$rArgs = RequestManager::getAll();
	unset($rArgs['status']);
	$customScript = setArgs($rArgs);
}
