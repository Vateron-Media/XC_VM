<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\Encryption;

/**
 * PlayerProxyController — Subtitle proxy for player.
 *
 * Migrated from player/proxy.php.
 * Decrypts subtitle URL and returns content as octet-stream.
 * Requires authenticated player session (bootstrap handles this).
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlayerProxyController extends BasePlayerV2Controller {
	public function index() {
		ini_set('default_socket_timeout', 10);

		// The URL is fetched from this server: it must be one this panel sealed.
		$rURL = (string) Encryption::readToken(
			$_GET['url'] ?? '',
			SettingsManager::get('live_streaming_pass'),
			'd8de497ebccf4f4697a1da20219c7c33',
			!SettingsManager::get('secure_stream_tokens')
		);

		if (filter_var($rURL, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string) parse_url($rURL, PHP_URL_SCHEME)), ['http', 'https'], true)) {
			$rData = file_get_contents($rURL);

			if ((string) $rData !== '') {
				header('Content-Description: File Transfer');
				header('Content-type: application/octet-stream');
				header('Content-Disposition: attachment; filename="' . md5($rURL . SettingsManager::get('live_streaming_pass')) . '.vtt"');
				header('X-Content-Type-Options: nosniff');
				header('Content-Length: ' . strlen($rData));
				// nosemgrep: php.lang.security.injection.echoed-request.echoed-request
				echo $rData;
				exit();
			}
		}

		header('HTTP/1.0 404 Not Found');
		exit();
	}
}
