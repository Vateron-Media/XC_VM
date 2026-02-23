<?php

class PlexAuth {
	public static function getPlexToken($plexIP = null, $plexPort = null, $plexUsername = null, $plexPassword = null) {
		$serverKey = self::getPlexServerCacheKey($plexIP, $plexPort, $plexUsername, $plexPassword);
		$rToken = self::getCachedPlexToken($serverKey);
		if ($rToken) {
			$rToken = self::checkPlexToken($plexIP, $plexPort, $rToken);
		}
		if (!$rToken) {
			echo "Plex token not found in cache or invalid, logging in for server {$plexIP}:{$plexPort}...\n";
			$rData = self::getPlexLogin($plexUsername, $plexPassword);
			if (isset($rData['user']['authToken'])) {
				$rToken = self::checkPlexToken($plexIP, $plexPort, $rData['user']['authToken']);
				if ($rToken) {
					self::cachePlexToken($serverKey, $rToken);
					echo "New Plex token successfully cached for key: $serverKey\n";
				}
			} else {
				echo "Failed to login to Plex (wrong credentials or network issue)!\n";
				$rToken = false;
			}
		}
		return $rToken;
	}

	public static function getPlexServerCacheKey($ip, $port, $username = null, $password = null) {
		if ($username && $password) {
			return md5($ip . ':' . $port . ':' . $username . ':' . $password);
		}
		return md5($ip . ':' . $port);
	}

	public static function getCachedPlexToken($serverKey) {
		$cacheFile = CONFIG_PATH . 'plex/plex_token_' . $serverKey . '.json';
		if (!file_exists($cacheFile)) {
			return null;
		}
		$data = json_decode(file_get_contents($cacheFile), true);
		if (!$data || !isset($data['token']) || !isset($data['expires'])) {
			return null;
		}
		if ($data['expires'] < time() + 86400) {
			@unlink($cacheFile);
			return null;
		}
		return $data['token'];
	}

	public static function getPlexLogin($rUsername, $rPassword) {
		$headers = [
			'Content-Type: application/xml; charset=utf-8',
			'X-Plex-Client-Identifier: 526e163c-8dbd-11eb-8dcd-0242ac130003',
			'X-Plex-Product: XC_VM',
			'X-Plex-Version: v' . XC_VM_VERSION
		];

		$ch = curl_init('https://plex.tv/users/sign_in.json');
		curl_setopt_array($ch, [
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_HEADER         => false,
			CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
			CURLOPT_USERPWD        => $rUsername . ':' . $rPassword,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_SSL_VERIFYPEER => false,
			CURLOPT_POST           => true,
			CURLOPT_RETURNTRANSFER => true,
		]);

		$response = curl_exec($ch);
		curl_close($ch);

		return json_decode($response, true);
	}

	public static function checkPlexToken($rIP, $rPort, $rToken) {
		$checkURL = 'http://' . $rIP . ':' . $rPort . '/myplex/account?X-Plex-Token=' . $rToken;

		$ch = curl_init($checkURL);
		curl_setopt_array($ch, [
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_CONNECTTIMEOUT => 10,
			CURLOPT_TIMEOUT        => 10,
			CURLOPT_SSL_VERIFYPEER => false,
		]);

		$data = curl_exec($ch);
		curl_close($ch);

		$xml = simplexml_load_string($data);
		if ($xml === false) {
			return '';
		}

		$json = json_decode(json_encode($xml), true);

		return (isset($json['@attributes']['signInState']) && $json['@attributes']['signInState'] === 'ok') ? $rToken : '';
	}

	public static function cachePlexToken($serverKey, $token) {
		$cacheFile = CONFIG_PATH . 'plex/plex_token_' . $serverKey . '.json';
		$data = [
			'token'     => $token,
			'cached_at' => time(),
			'expires'   => time() + 30 * 86400
		];
		if (!is_dir(dirname($cacheFile))) {
			mkdir(dirname($cacheFile), 0755, true);
		}
		file_put_contents($cacheFile, json_encode($data, JSON_PRETTY_PRINT));
		@chmod($cacheFile, 0600);
	}
}
