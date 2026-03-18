<?php

/**
 * Diagnostics Service
 *
 * downloadPanelLogs, submitPanelLogs, getApiIP.
 *
 * Panel-log methods accept a $db parameter; other methods are stateless.
 */
class DiagnosticsService {

	/**
	 * Parse SSL certificate info from nginx config or a specific file
	 *
	 * @param string|null $certificate  Path to certificate file (auto-detects from nginx if null)
	 * @return array ['serial', 'expiration', 'subject', 'path']
	 */
	public static function getCertificateInfo($certificate = null) {
		$result = ['serial' => null, 'expiration' => null, 'subject' => null, 'path' => null];

		if (!$certificate) {
			$config = explode("\n", file_get_contents(BIN_PATH . 'nginx/conf/ssl.conf'));
			foreach ($config as $line) {
				$rTrimmed = trim($line);
				if (strncasecmp($rTrimmed, 'ssl_certificate ', 16) === 0) {
					$certificate = trim(explode(';', substr($rTrimmed, 16), 2)[0]);
					break;
				}
			}
		}

		if (!$certificate || !file_exists($certificate)) {
			return null;
		}

		$result['path'] = pathinfo($certificate)['dirname'];
		$output = [];
		exec('openssl x509 -serial -enddate -subject -noout -in ' . escapeshellarg($certificate), $output, $returnVar);
		if ($returnVar !== 0) {
			return null;
		}
		foreach ($output as $line) {
			if (stripos($line, 'serial=') !== false) {
				$result['serial'] = trim(explode('serial=', $line)[1]);
			} elseif (stripos($line, 'subject=') !== false) {
				$result['subject'] = trim(explode('subject=', $line)[1]);
			} elseif (stripos($line, 'notAfter=') !== false) {
				$result['expiration'] = strtotime(trim(explode('notAfter=', $line)[1]));
			}
		}

		return $result;
	}

	/**
	 * Check if stream codecs are compatible with the player
	 *
	 * @param array|string $data       FFProbe output (array or JSON string)
	 * @param bool         $allowHEVC  Whether HEVC/H265 + AC3 are allowed
	 * @return bool
	 */
	public static function checkCompatibility($data, $allowHEVC = false) {
		if (!is_array($data)) {
			$data = json_decode($data, true);
		}

		if (!is_array($data) || !isset($data['codecs']) || !is_array($data['codecs'])) {
			return false;
		}

		$audioCodec = $data['codecs']['audio']['codec_name'] ?? null;
		$videoCodec = $data['codecs']['video']['codec_name'] ?? null;

		$audioCodecs = ['aac', 'libfdk_aac', 'opus', 'vorbis', 'pcm_s16le', 'mp2', 'mp3', 'flac'];
		$videoCodecs = ['h264', 'vp8', 'vp9', 'ogg', 'av1'];

		if ($allowHEVC) {
			$videoCodecs[] = 'hevc';
			$videoCodecs[] = 'h265';
			$audioCodecs[] = 'ac3';
		}

		if (!$videoCodec) {
			return false;
		}

		if (!in_array(strtolower($videoCodec), $videoCodecs, true)) {
			return false;
		}

		if ($audioCodec && !in_array(strtolower($audioCodec), $audioCodecs, true)) {
			return false;
		}

		return true;
	}

	/**
	 * Download panel logs from database, format them and clear the logs table
	 *
	 * @param object $db  Database handler (must have ->query(), ->get_rows())
	 * @return array ['errors' => [...], 'version' => string]
	 * @throws Exception
	 */
	public static function downloadPanelLogs($db): array {
		ini_set('default_socket_timeout', 60);
		$errors = [];

		try {
			$query = "SELECT `type`, `log_message`, `log_extra`, `line`, `date` 
                  FROM `panel_logs` 
                  WHERE `type` <> 'epg' 
                  ORDER BY `date` DESC 
                  LIMIT 1000";

			$result = $db->query($query);
			if (!$result) {
				throw new Exception('Failed to execute database query');
			}

			$allErrors = $db->get_rows() ?: [];

			foreach ($allErrors as $error) {
				$errorData = [
					'type'    => isset($error['type']) ? htmlspecialchars($error['type'], ENT_QUOTES, 'UTF-8') : 'unknown',
					'message' => isset($error['log_message']) ? htmlspecialchars($error['log_message'], ENT_QUOTES, 'UTF-8') : '',
					'file'    => isset($error['log_extra']) ? htmlspecialchars($error['log_extra'], ENT_QUOTES, 'UTF-8') : '',
					'line'    => isset($error['line']) ? (int)$error['line'] : 0,
					'date'    => isset($error['date']) ? (int)$error['date'] : 0,
				];

				try {
					if ($errorData['date'] > 0) {
						$dt = new DateTime('@' . $errorData['date']);
						$dt->setTimezone(new DateTimeZone('UTC'));
						$errorData['human_date'] = $dt->format('Y-m-d H:i:s');
					} else {
						$errorData['human_date'] = 'invalid_timestamp';
					}
				} catch (Exception $e) {
					$errorData['human_date'] = 'conversion_error';
				}

				$errors[] = $errorData;
			}

			if (!empty($errors)) {
				$truncateResult = $db->query('TRUNCATE `panel_logs`;');
				if (!$truncateResult) {
					throw new Exception('Failed to truncate panel logs table');
				}
			}
		} catch (Exception $e) {
			throw new Exception('Failed to process panel logs');
		}

		return [
			'errors'  => $errors,
			'version' => defined('XC_VM_VERSION') ? XC_VM_VERSION : 'unknown',
		];
	}

	/**
	 * Submit panel logs to the central API server
	 *
	 * @param object $db  Database handler
	 * @return string|false  API response or false on failure
	 */
	public static function submitPanelLogs($db) {
		ini_set('default_socket_timeout', 60);

		$apiIP = self::getApiIP();
		if ($apiIP === false) {
			print("[ERR] Failed to get API IP\n");
			return false;
		}

		$db->query("SELECT `type`, `log_message`, `log_extra`, `line`, `date` FROM `panel_logs` WHERE `type` <> 'epg' GROUP BY CONCAT(`type`, `log_message`, `log_extra`) ORDER BY `date` DESC LIMIT 1000;");

		$rAPI = 'http://' . $apiIP . '/api/v1/report';
		print("[1] API endpoint: $rAPI\n");

		$rData = [
			'errors'  => $db->get_rows(),
			'version' => XC_VM_VERSION,
		];

		$payload = json_encode($rData, JSON_UNESCAPED_UNICODE);

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $rAPI);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, 60);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [
			'Content-Type: application/json',
			'Content-Length: ' . strlen($payload),
		]);

		print("[2] Sending request...\n");
		$response = curl_exec($ch);

		if ($response === false) {
			$err = curl_error($ch);
			print("[ERR] cURL error: $err\n");
		}

		print("[3] Raw response: " . var_export($response, true) . "\n");
		curl_close($ch);

		if ($response !== false) {
			$responseData = json_decode($response, true);
			if (isset($responseData['status']) && $responseData['status'] === 'success') {
				$db->query('TRUNCATE `panel_logs`;');
			}
		}

		return $response;
	}

	/**
	 * Fetch API server IP from the public update repository
	 *
	 * @return string|false  IP address or false on failure
	 */
	public static function getApiIP() {
		$url = 'https://raw.githubusercontent.com/Vateron-Media/XC_VM_Update/refs/heads/main/api_server.json';

		$json = file_get_contents($url);
		if ($json === false) {
			return false;
		}

		$data = json_decode($json, true);
		if (json_last_error() !== JSON_ERROR_NONE || empty($data['ip'])) {
			return false;
		}

		return $data['ip'];
	}
}
