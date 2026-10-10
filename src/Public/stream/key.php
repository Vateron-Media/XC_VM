<?php

use XcVm\Core\Cluster\NodeLease;
use XcVm\Core\Cluster\ViewerKey;
use XcVm\Core\Config\ConfigReader;
use XcVm\Core\Util\Encryption;
use XcVm\Core\Util\NetworkUtils;

/**
 * HLS encryption key endpoint
 *
 * @package XC_VM_Web_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

header('Access-Control-Allow-Origin: *');
$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
$rServers = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'servers'));
if (!defined('SERVER_ID')) {
	define('SERVER_ID', intval(ConfigReader::get('server_id')));
}

// No stream secret, and no key of its own (ViewerKey) either: nothing to read a token with.
if (empty($rSettings['live_streaming_pass']) && ViewerKey::own() === []) {
	generate404();
}

// As segment.php: past the lease's window and its drain, this node hands out no
// more decryption keys either (NodeLease).
if (NodeLease::refusesEverything($rSettings)) {
	generate404();
}

if (isset($_GET['token'])) {
	$rIP = getuserip();
	$rTokenArray = explode('/', (string) Encryption::readToken($_GET['token'], $rSettings['live_streaming_pass'], OPENSSL_EXTRA, empty($rSettings['secure_stream_tokens'])));
	$rIPMatch = NetworkUtils::ipMatches((bool) $rSettings['ip_subnet_match'], $rTokenArray[0], $rIP);

	// An unreadable token splits into one empty piece: no stream, no key.
	if (count($rTokenArray) >= 2 && ($rIPMatch || !$rSettings['restrict_same_ip'])) {
		header('Content-Type: application/octet-stream');
		header('X-Content-Type-Options: nosniff');
		// nosemgrep: php.lang.security.injection.echoed-request.echoed-request
		echo file_get_contents(STREAMS_PATH . intval($rTokenArray[1]) . '_.key');
		exit();
	}
}

generate404();

function getuserip() {
	return $_SERVER['REMOTE_ADDR'];
}
