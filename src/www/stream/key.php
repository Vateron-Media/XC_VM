<?php

header('Access-Control-Allow-Origin: *');
require_once 'init.php';
$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
$rServers = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'servers'));
$rConfig = parse_ini_file(CONFIG_PATH . 'config.ini');
define('SERVER_ID', intval($rConfig['server_id']));

if (empty($rSettings['live_streaming_pass'])) {
	generate404();
}

if (isset($_GET['token'])) {
	$rIP = getuserip();
	$rTokenArray = explode('/', StreamingUtilities::decryptData($_GET['token'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA));
	$rIPMatch = ($rSettings['ip_subnet_match'] ? implode('.', array_slice(explode('.', $rTokenArray[0]), 0, -1)) == implode('.', array_slice(explode('.', $rIP), 0, -1)) : $rTokenArray[0] == $rIP);

	if (is_array($rTokenArray) && ($rIPMatch || !$rSettings['restrict_same_ip'])) {
		echo file_get_contents(STREAMS_PATH . intval($rTokenArray[1]) . '_.key');
		exit();
	}
}

generate404();
function getuserip() {
	return $_SERVER['REMOTE_ADDR'];
}
