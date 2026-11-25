<?php

use XcVm\Core\Auth\SessionManager;
use XcVm\Core\Http\RequestSecurity;
use XcVm\Core\Localization\Translator;
use XcVm\Infrastructure\Bootstrap\AdminScopeBootstrap;

RequestSecurity::sendHeaders();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
	http_response_code(405);
	exit('Method Not Allowed');
}
RequestSecurity::enforceSameOrigin();

global $db, $rSettings, $rMobile, $rServers, $rProxyServers, $rDetect,
	$rTimeout, $rProtocol, $allServers, $rPermissions, $allowedLangs,
	$rServerError, $allServersHealthy, $updateRequired, $rUserInfo,
	$_STATUS, $customScript, $language;
$language = Translator::class;
AdminScopeBootstrap::hydrateAdminContext();
SessionManager::clearContext('admin');
header('Location: ./login');

exit();
