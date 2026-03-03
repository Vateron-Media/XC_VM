<?php

require_once 'init.php';
header('Access-Control-Allow-Origin: *');

if (empty($rSettings['send_server_header'])) {
} else {
	header('Server: ' . $rSettings['send_server_header']);
}

if (!$rSettings['send_protection_headers']) {
} else {
	header('X-XSS-Protection: 0');
	header('X-Content-Type-Options: nosniff');
}

if (!$rSettings['send_altsvc_header']) {
} else {
	header('Alt-Svc: h3-29=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-T051=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q050=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q046=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q043=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,quic=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000; v="46,43"');
}

if (!empty($rSettings['send_unique_header_domain']) || filter_var(HOST, FILTER_VALIDATE_IP)) {
} else {
	$rSettings['send_unique_header_domain'] = '.' . HOST;
}

if (empty($rSettings['send_unique_header'])) {
} else {
	$rExpires = new DateTime('+6 months', new DateTimeZone('GMT'));
	header('Set-Cookie: ' . $rSettings['send_unique_header'] . '=' . Encryption::randomString(11) . '; Domain=' . $rSettings['send_unique_header_domain'] . '; Expires=' . $rExpires->format(DATE_RFC2822) . '; Path=/; Secure; HttpOnly; SameSite=none');
}

$rStreamID = null;
$rSubID = 0;

if (!isset($rRequest['token'])) {
} else {
	$rTokenData = json_decode(Encryption::decrypt($rRequest['token'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);

	if (is_array($rTokenData) && !(isset($rTokenData['expires']) && $rTokenData['expires'] < time() - intval($rServers[SERVER_ID]['time_offset']))) {
	} else {
		generateError('TOKEN_EXPIRED');
	}

	$rStreamID = $rTokenData['stream_id'];
	$rSubID = (intval($rTokenData['sub_id']) ?: 0);
	$rWebVTT = (intval($rTokenData['webvtt']) ?: 0);
}

if ($rStreamID && file_exists(VOD_PATH . $rStreamID . '_' . $rSubID . '.srt')) {
	header('Content-Description: File Transfer');
	header('Content-type: application/octet-stream');
	header('Content-Disposition: attachment; filename="' . $rStreamID . '_' . $rSubID . '.' . (($rWebVTT ? 'vtt' : 'srt')) . '"');
	$rOutput = file_get_contents(VOD_PATH . $rStreamID . '_' . $rSubID . '.srt');

	if (!$rWebVTT) {
	} else {
		$rOutput = convertVTT($rOutput);
	}

	header('Content-Length: ' . strlen($rOutput));
	echo $rOutput;

	exit();
}

generateError('THUMBNAIL_DOESNT_EXIST');
function convertVTT($rSubtitle) {
	$rLines = explode("\n", $rSubtitle);
	$rLength = count($rLines);

	for ($rIndex = 1; $rIndex < $rLength; $rIndex++) {
		if (!($rIndex === 1 || trim($rLines[$rIndex - 2]) === '')) {
		} else {
			$rLines[$rIndex] = str_replace(',', '.', $rLines[$rIndex]);
		}
	}
	$rHeader = "WEBVTT\n\n";

	return $rHeader . implode("\n", $rLines);
}
