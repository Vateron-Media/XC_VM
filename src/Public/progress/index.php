<?php

/**
 * Stream progress endpoint.
 *
 * Receives FFmpeg progress data from localhost and writes it to a stream
 * progress file. Only accepts POST from 127.0.0.1.
 *
 * @package XC_VM_Public_Progress
 */

ignore_user_abort(true);

if (!defined('MAIN_HOME')) {
	define('MAIN_HOME', dirname(dirname(__DIR__)) . '/');
}

require_once MAIN_HOME . 'autoload.php';
require_once MAIN_HOME . 'Core/Error/ErrorCodes.php';
require_once MAIN_HOME . 'Core/Error/ErrorHandler.php';
require_once MAIN_HOME . 'Core/Config/Paths.php';

$rPost = trim(file_get_contents('php://input'));

if (!($_SERVER['REMOTE_ADDR'] != '127.0.0.1' || empty($_GET['stream_id']) || empty($rPost))) {
} else {
	generate404();
}

$rStreamID = intval($_GET['stream_id']);
$rData = array_filter(array_map('trim', explode("\n", $rPost)));
$rOutput = array();

foreach ($rData as $rRow) {
	$rParts = explode('=', $rRow, 2);
	if (count($rParts) !== 2) {
		continue;
	}
	list($rKey, $rValue) = $rParts;
	$rOutput[trim($rKey)] = trim($rValue);
}
file_put_contents(STREAMS_PATH . $rStreamID . '_.progress', json_encode($rOutput));
