<?php

/**
 * Stream progress endpoint
 *
 * @package XC_VM_Web
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

ignore_user_abort(true);
require 'constants.php';
$rPost = trim(file_get_contents('php://input'));

if (!($_SERVER['REMOTE_ADDR'] != '127.0.0.1' || empty($_GET['stream_id']) || empty($rPost))) {
} else {
	generate404();
}

$rStreamID = intval($_GET['stream_id']);
$rData = array_filter(array_map('trim', explode("\n", $rPost)));
$rOutput = array();

foreach ($rData as $rRow) {
	list($rKey, $rValue) = explode('=', $rRow);
	$rOutput[trim($rKey)] = trim($rValue);
}
file_put_contents(STREAMS_PATH . $rStreamID . '_.progress', json_encode($rOutput));
