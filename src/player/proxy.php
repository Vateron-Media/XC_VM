<?php

$rSkipVerify = true;
include 'functions.php';
ini_set('default_socket_timeout', 10);
$rURL = CoreUtilities::decrypt($_GET['url'], CoreUtilities::$rSettings['live_streaming_pass'], 'd8de497ebccf4f4697a1da20219c7c33');

if (substr($rURL, 0, 4) != 'http') {
} else {
	$rData = file_get_contents($rURL);

	if (0 >= strlen($rData)) {
	} else {
		header('Content-Description: File Transfer');
		header('Content-type: application/octet-stream');
		header('Content-Disposition: attachment; filename="' . md5($rURL . CoreUtilities::$rSettings['live_streaming_pass']) . '.vtt"');
		echo $rData;

		exit();
	}
}

header('HTTP/1.0 404 Not Found');

exit();
