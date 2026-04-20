<?php

/*
 * XC_VM — Утилитарные функции
 *
 * Статический класс для UI-хелперов, форматирования, валидации и генерации.
 */

class AdminHelpers {
	public static function validateCIDR($rCIDR) {
		$rParts = explode('/', $rCIDR);
		$rIP = $rParts[0];
		$rNetmask = null;

		if (count($rParts) != 2) {
		} else {
			$rNetmask = intval($rParts[1]);

			if ($rNetmask >= 0) {
			} else {
				return false;
			}
		}

		if (!filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
			if (!filter_var($rIP, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
				return false;
			}

			return (is_null($rNetmask) ? true : $rNetmask <= 128);
		}

		return (is_null($rNetmask) ? true : $rNetmask <= 32);
	}

	public static function overwriteData($rData, $rOverwrite, $rSkip = array()) {
		foreach ($rOverwrite as $rKey => $rValue) {
			if (!array_key_exists($rKey, $rData) || in_array($rKey, $rSkip)) {
			} else {
				if (empty($rValue) && is_null($rData[$rKey])) {
					$rData[$rKey] = null;
				} else {
					$rData[$rKey] = $rValue;
				}
			}
		}

		return $rData;
	}

	public static function confirmIDs($rIDs) {
		return InputValidator::confirmIDs($rIDs);
	}

	public static function filterIDs($ids, $availableIDs, $checkPositive = true) {
		$filtered = [];

		if (!is_array($ids)) {
			return $filtered;
		}

		foreach ($ids as $id) {
			$intID = (int)$id;
			$isValid = (!$checkPositive || $intID > 0) && in_array($intID, $availableIDs);

			if ($isValid) {
				$filtered[] = $intID;
			}
		}

		return $filtered;
	}

	public static function getNearest($arr, $search) {
		return StreamSorter::getNearest($arr, $search);
	}

	public static function roundUpToAny($n, $x = 5) {
		return round(($n + $x / 2) / $x) * $x;
	}

	public static function generateString($strength = 10) {
		$input = '23456789abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ';
		$input_length = strlen($input);
		$random_string = '';

		for ($i = 0; $i < $strength; $i++) {
			$random_character = $input[mt_rand(0, $input_length - 1)];
			$random_string .= $random_character;
		}

		return $random_string;
	}

	public static function sortArrayByArray($rArray, $rSort) {
		if (!(empty($rArray) || empty($rSort))) {
			$rOrdered = array();

			foreach ($rSort as $rValue) {
				if (($rKey = array_search($rValue, $rArray)) === false) {
				} else {
					$rOrdered[] = $rValue;
					unset($rArray[$rKey]);
				}
			}

			return $rOrdered + $rArray;
		} else {
			return array();
		}
	}

	public static function getBarColour($rInt) {
		if (75 <= $rInt) {
			return 'bg-danger';
		}

		if (50 <= $rInt) {
			return 'bg-warning';
		}

		return 'bg-success';
	}

	public static function formatUptime($rUptime) {
		if (86400 <= $rUptime) {
			$rUptime = sprintf('%02dd %02dh %02dm', $rUptime / 86400, ($rUptime / 3600) % 24, ($rUptime / 60) % 60);
		} else {
			$rUptime = sprintf('%02dh %02dm %02ds', $rUptime / 3600, ($rUptime / 60) % 60, $rUptime % 60);
		}

		return $rUptime;
	}

	public static function getFooter() {
		$currentYear = date('Y');
		$startYear = 2025;
		$yearRange = ($startYear === (int)$currentYear) ? $startYear : "{$startYear}-{$currentYear}";

		return "&copy; {$yearRange} <img height='20px' style='padding-left: 10px; padding-right: 10px; margin-top: -2px;' src='./assets/images/logo-topbar.png' /> v" . XC_VM_VERSION;
	}

	public static function TimeZoneList() {
		if (!function_exists('timezone_identifiers_list')) {
			throw new RuntimeException('Timezone identifiers list function is not available.');
		}

		$zones_array = [];
		$timestamp = time();
		$original_timezone = date_default_timezone_get();

		try {
			foreach (timezone_identifiers_list() as $key => $zone) {
				if (empty($zone) || !is_string($zone)) {
					continue;
				}

				if (date_default_timezone_set($zone) === false) {
					continue;
				}

				$zones_array[$key] = [
					'zone' => $zone,
					'diff_from_GMT' => '[UTC/GMT ' . date('P', $timestamp) . ']'
				];
			}
		} catch (Exception $e) {
			date_default_timezone_set($original_timezone);
			throw new RuntimeException('Error processing timezone list: ' . $e->getMessage());
		}

		date_default_timezone_set($original_timezone);

		return $zones_array;
	}

	public static function convertToCSV($rData) {
		$rHeader = false;
		$rFilename = TMP_PATH . self::generateString(32) . '.csv';
		$rFile = fopen($rFilename, 'w');

		foreach ($rData as $rRow) {
			if (!empty($rHeader)) {
			} else {
				$rHeader = array_keys($rRow);
				fputcsv($rFile, $rHeader);
				$rHeader = array_flip($rHeader);
			}

			fputcsv($rFile, array_merge($rHeader, $rRow));
		}
		fclose($rFile);

		return $rFilename;
	}

	public static function generateReport($rURL, $rParams) {
		$rPost = http_build_query($rParams);
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $rURL);
		curl_setopt($ch, CURLOPT_POST, true);
		curl_setopt($ch, CURLOPT_POSTFIELDS, $rPost);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);

		return curl_exec($ch);
	}

	public static function issecure() {
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		$port443 = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443;

		return $https || $port443;
	}

	public static function getProtocol() {
		if (self::issecure()) {
			return 'https';
		}

		return 'http';
	}

	public static function goHome() {
		header('Location: dashboard');

		exit();
	}

	public static function getPageName() {
		if (defined('PAGE_NAME') && PAGE_NAME) {
			return strtolower(PAGE_NAME);
		}

		return strtolower(basename(get_included_files()[0], '.php'));
	}

	public static function getPageFromURL($rURL) {
		if ($rURL) {
			return strtolower(basename(ltrim(parse_url($rURL)['path'], '/'), '.php'));
		}

		return null;
	}

	public static function setArgs($rArgs, $rGet = true) {
		$rURL = self::getPageName();

		if (count($rArgs) > 0) {
			$rURL .= '?' . http_build_query($rArgs);

			if ($rGet) {
				foreach ($rArgs as $rKey => $rValue) {
					RequestManager::getAll()[$rKey] = $rValue;
				}
			}
		}

		return "<script>history.replaceState({},'','" . $rURL . "');</script>";
	}

	public static function parserelease($rRelease) {
		if (SettingsManager::getAll()['parse_type'] == 'guessit') {
			$rCommand = MAIN_HOME . 'bin/guess ' . escapeshellarg(pathinfo($rRelease)['filename'] . '.mkv');
		} else {
			$rCommand = '/usr/bin/python3 ' . MAIN_HOME . 'bin/python/release.py ' . escapeshellarg(pathinfo(str_replace('-', '_', $rRelease))['filename']);
		}

		return json_decode(shell_exec($rCommand), true);
	}
}
