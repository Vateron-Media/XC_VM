<?php

require_once 'init.php';
header('Access-Control-Allow-Origin: *');

if (empty(CoreUtilities::$rSettings['send_server_header'])) {
} else {
	header('Server: ' . CoreUtilities::$rSettings['send_server_header']);
}

if (!CoreUtilities::$rSettings['send_protection_headers']) {
} else {
	header('X-XSS-Protection: 0');
	header('X-Content-Type-Options: nosniff');
}

if (!CoreUtilities::$rSettings['send_altsvc_header']) {
} else {
	header('Alt-Svc: h3-29=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-T051=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q050=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q046=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q043=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,quic=":' . CoreUtilities::$rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000; v="46,43"');
}

if (!empty(CoreUtilities::$rSettings['send_unique_header_domain']) || filter_var(HOST, FILTER_VALIDATE_IP)) {
} else {
	CoreUtilities::$rSettings['send_unique_header_domain'] = '.' . HOST;
}

if (empty(CoreUtilities::$rSettings['send_unique_header'])) {
} else {
	$rExpires = new DateTime('+6 months', new DateTimeZone('GMT'));
	header('Set-Cookie: ' . CoreUtilities::$rSettings['send_unique_header'] . '=' . CoreUtilities::generateString(11) . '; Domain=' . CoreUtilities::$rSettings['send_unique_header_domain'] . '; Expires=' . $rExpires->format(DATE_RFC2822) . '; Path=/; Secure; HttpOnly; SameSite=none');
}

$rStreamID = null;
$rSubID = 0;

if (!isset(CoreUtilities::$rRequest['token'])) {
} else {
	$rTokenData = json_decode(CoreUtilities::decryptData(CoreUtilities::$rRequest['token'], CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA), true);

	if (is_array($rTokenData) && !(isset($rTokenData['expires']) && $rTokenData['expires'] < time() - intval(CoreUtilities::$rServers[SERVER_ID]['time_offset']))) {
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
