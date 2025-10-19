<?php

require_once 'init.php';
header('Access-Control-Allow-Origin: *');

if (empty(StreamingUtilities::$rSettings['send_server_header'])) {
} else {
	header('Server: ' . StreamingUtilities::$rSettings['send_server_header']);
}

if (!StreamingUtilities::$rSettings['send_protection_headers']) {
} else {
	header('X-XSS-Protection: 0');
	header('X-Content-Type-Options: nosniff');
}

if (!StreamingUtilities::$rSettings['send_altsvc_header']) {
} else {
	header('Alt-Svc: h3-29=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-T051=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q050=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q046=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q043=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,quic=":' . StreamingUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000; v="46,43"');
}

if (!empty(StreamingUtilities::$rSettings['send_unique_header_domain']) || filter_var(HOST, FILTER_VALIDATE_IP)) {
} else {
	StreamingUtilities::$rSettings['send_unique_header_domain'] = '.' . HOST;
}

if (empty(StreamingUtilities::$rSettings['send_unique_header'])) {
} else {
	$rExpires = new DateTime('+6 months', new DateTimeZone('GMT'));
	header('Set-Cookie: ' . StreamingUtilities::$rSettings['send_unique_header'] . '=' . StreamingUtilities::generateString(11) . '; Domain=' . StreamingUtilities::$rSettings['send_unique_header_domain'] . '; Expires=' . $rExpires->format(DATE_RFC2822) . '; Path=/; Secure; HttpOnly; SameSite=none');
}

$rStreamID = null;

if (!isset(StreamingUtilities::$rRequest['token'])) {
} else {
	$rTokenData = json_decode(StreamingUtilities::decryptData(StreamingUtilities::$rRequest['token'], StreamingUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);

	if (is_array($rTokenData) && !(isset($rTokenData['expires']) && $rTokenData['expires'] < time() - intval(StreamingUtilities::$rServers[SERVER_ID]['time_offset']))) {
	} else {
		generateError('TOKEN_EXPIRED');
	}

	$rStreamID = $rTokenData['stream'];
}

if ($rStreamID && file_exists(STREAMS_PATH . $rStreamID . '_.jpg') && time() - filemtime(STREAMS_PATH . $rStreamID . '_.jpg') < 60) {
	header('Age: ' . intval(time() - filemtime(STREAMS_PATH . $rStreamID . '_.jpg')));
	header('Content-type: image/jpg');
	echo file_get_contents(STREAMS_PATH . $rStreamID . '_.jpg');

	exit();
}

generateError('THUMBNAIL_DOESNT_EXIST');
