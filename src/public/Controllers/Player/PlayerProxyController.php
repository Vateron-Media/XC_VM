<?php
/**
 * PlayerProxyController — Subtitle proxy for player.
 *
 * Migrated from player/proxy.php.
 * Decrypts subtitle URL and returns content as octet-stream.
 * Requires authenticated player session (bootstrap handles this).
 */
class PlayerProxyController extends BasePlayerController
{
	public function index()
	{
		ini_set('default_socket_timeout', 10);

		$rURL = Encryption::decrypt(
			$_GET['url'] ?? '',
			SettingsManager::getAll()['live_streaming_pass'],
			'd8de497ebccf4f4697a1da20219c7c33'
		);

		if (substr($rURL, 0, 4) === 'http') {
			$rData = file_get_contents($rURL);

			if (strlen($rData) > 0) {
				header('Content-Description: File Transfer');
				header('Content-type: application/octet-stream');
				header('Content-Disposition: attachment; filename="' . md5($rURL . SettingsManager::getAll()['live_streaming_pass']) . '.vtt"');
				header('X-Content-Type-Options: nosniff');
				header('Content-Length: ' . strlen($rData));
				echo $rData;
				exit();
			}
		}

		header('HTTP/1.0 404 Not Found');
		exit();
	}
}
