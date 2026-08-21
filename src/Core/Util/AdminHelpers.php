<?php

namespace XcVm\Core\Util;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Validation\InputValidator;

/*
 * AdminHelpers — utility functions.
 *
 * Static class for UI helpers, formatting, validation and generation.
 */

class AdminHelpers {
	/**
	 * Validate an IPv4/IPv6 address or CIDR (with optional /mask).
	 *
	 * @param string $rCIDR IP or CIDR notation.
	 * @return bool True if valid (mask within range when present).
	 */
	public static function validateCIDR($rCIDR) {
		$rParts = explode('/', $rCIDR);
		$rIP = $rParts[0];
		$rNetmask = null;

		if (count($rParts) == 2) {
			$rNetmask = intval($rParts[1]);

			if ($rNetmask < 0) {
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

	/**
	 * Overwrite existing keys of a row with submitted values.
	 *
	 * Only keys already present in $rData are updated; listed keys are skipped.
	 *
	 * @param array $rData      Base row.
	 * @param array $rOverwrite New values.
	 * @param array $rSkip      Keys to leave untouched.
	 * @return array The merged row.
	 */
	public static function overwriteData($rData, $rOverwrite, $rSkip = array()) {
		foreach ($rOverwrite as $rKey => $rValue) {
			if (array_key_exists($rKey, $rData) && !in_array($rKey, $rSkip)) {
				if (!(empty($rValue) && is_null($rData[$rKey]))) {
					$rData[$rKey] = $rValue;
				}
			}
		}

		return $rData;
	}

	/**
	 * Normalize a list of ids (delegates to InputValidator::confirmIDs()).
	 *
	 * @param mixed $rIDs Raw id list.
	 * @return array Validated integer ids.
	 */
	public static function confirmIDs($rIDs) {
		return InputValidator::confirmIDs($rIDs);
	}

	/**
	 * Keep only integer ids that exist in the allowed set.
	 *
	 * @param mixed $ids           Candidate ids.
	 * @param array $availableIDs  Allowed integer ids.
	 * @param bool  $checkPositive Require ids to be > 0.
	 * @return int[] Filtered ids.
	 */
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

	/**
	 * Find the nearest value in an array (delegates to \XcVm\Domain\Stream\StreamSorter::getNearest()).
	 *
	 * @param array     $arr    Values to search.
	 * @param int|float $search Target value.
	 * @return mixed The nearest value.
	 */
	public static function getNearest($arr, $search) {
		return \XcVm\Domain\Stream\StreamSorter::getNearest($arr, $search);
	}

	/**
	 * Round a number to the nearest multiple of $x.
	 *
	 * @param int|float $n Value to round.
	 * @param int       $x Multiple to round to.
	 * @return float Rounded value.
	 */
	public static function roundUpToAny($n, $x = 5) {
		return round(($n + $x / 2) / $x) * $x;
	}

	/**
	 * Generate a random string from an unambiguous alphanumeric charset.
	 *
	 * @param int $strength Number of characters.
	 * @return string Random string.
	 */
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

	/**
	 * Reorder an array to match the order of values in another array.
	 *
	 * Values found in $rSort come first (in that order); the rest are appended.
	 *
	 * @param array $rArray Values to reorder.
	 * @param array $rSort  Desired order of values.
	 * @return array Reordered array.
	 */
	public static function sortArrayByArray($rArray, $rSort) {
		if (empty($rArray) || empty($rSort)) {
			return array();
		}

		$rOrdered = array();

		foreach ($rSort as $rValue) {
			if (($rKey = array_search($rValue, $rArray)) !== false) {
				$rOrdered[] = $rValue;
				unset($rArray[$rKey]);
			}
		}

		return $rOrdered + $rArray;
	}

	/**
	 * Map a percentage to a Bootstrap progress-bar colour class.
	 *
	 * @param int $rInt Percentage (0–100).
	 * @return string 'bg-danger' (>=75), 'bg-warning' (>=50) or 'bg-success'.
	 */
	public static function getBarColour($rInt) {
		if (75 <= $rInt) {
			return 'bg-danger';
		}

		if (50 <= $rInt) {
			return 'bg-warning';
		}

		return 'bg-success';
	}

	/**
	 * Format a duration in seconds as a human-readable uptime string.
	 *
	 * @param int $rUptime Seconds.
	 * @return string e.g. "02d 03h 04m" or "03h 04m 05s".
	 */
	public static function formatUptime($rUptime) {
		$rUptime = (int) $rUptime;
		if (86400 <= $rUptime) {
			$rUptime = sprintf('%02dd %02dh %02dm', intdiv($rUptime, 86400), intdiv($rUptime, 3600) % 24, intdiv($rUptime, 60) % 60);
		} else {
			$rUptime = sprintf('%02dh %02dm %02ds', intdiv($rUptime, 3600), intdiv($rUptime, 60) % 60, $rUptime % 60);
		}

		return $rUptime;
	}

	/**
	 * Build the admin footer HTML (copyright year range + version).
	 *
	 * @return string Footer HTML.
	 */
	public static function getFooter() {
		$currentYear = date('Y');
		$startYear = 2025;
		$yearRange = ($startYear === (int)$currentYear) ? $startYear : "{$startYear}\u{2013}{$currentYear}";

		$brand = "<a href='https://github.com/Vateron-Media/XC_VM' target='_blank' rel='noopener noreferrer'>Vateron Media</a>";
		$license = "<a href='https://www.gnu.org/licenses/agpl-3.0.html' target='_blank' rel='noopener noreferrer'>AGPL-3.0</a>";

		return "{$brand} &nbsp;&middot;&nbsp; &copy; {$yearRange} &middot; {$license} &middot; v" . XC_VM_VERSION;
	}

	/**
	 * List all timezones with their current GMT/UTC offsets.
	 *
	 * @return array[] Each entry: ['zone' => string, 'diff_from_GMT' => string].
	 * @throws \RuntimeException If timezone functions are unavailable or fail.
	 */
	public static function TimeZoneList() {
		if (!function_exists('timezone_identifiers_list')) {
			throw new \RuntimeException('Timezone identifiers list function is not available.');
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
		} catch (\Exception $e) {
			date_default_timezone_set($original_timezone);
			throw new \RuntimeException('Error processing timezone list: ' . $e->getMessage());
		}

		date_default_timezone_set($original_timezone);

		return $zones_array;
	}

	/**
	 * Write rows of data to a temporary CSV file.
	 *
	 * Uses the first row's keys as the header.
	 *
	 * @param array[] $rData Rows (associative arrays).
	 * @return string Path to the generated CSV file in TMP_PATH.
	 */
	public static function convertToCSV($rData) {
		$rHeader = false;
		$rFilename = TMP_PATH . self::generateString(32) . '.csv';
		$rFile = fopen($rFilename, 'w');

		foreach ($rData as $rRow) {
			if (empty($rHeader)) {
				$rHeader = array_keys($rRow);
				fputcsv($rFile, $rHeader);
				$rHeader = array_flip($rHeader);
			}

			fputcsv($rFile, array_merge($rHeader, $rRow));
		}
		fclose($rFile);

		return $rFilename;
	}

	/**
	 * POST parameters to a URL and return the raw response.
	 *
	 * @param string $rURL    Target URL.
	 * @param array  $rParams POST parameters.
	 * @return string|bool Response body, or false on failure.
	 */
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

	/**
	 * Whether the current request is over HTTPS.
	 *
	 * @return bool True if HTTPS or port 443.
	 */
	public static function issecure() {
		$https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
		$port443 = isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443;

		return $https || $port443;
	}

	/**
	 * Current request protocol.
	 *
	 * @return string 'https' or 'http'.
	 */
	public static function getProtocol() {
		if (self::issecure()) {
			return 'https';
		}

		return 'http';
	}

	/**
	 * Redirect to the dashboard and terminate the request.
	 *
	 * @return never
	 */
	public static function goHome() {
		header('Location: dashboard');

		exit();
	}

	/**
	 * Resolve the current page name.
	 *
	 * Prefers the PAGE_NAME constant, falling back to the entry script name.
	 *
	 * @return string Lowercased page name.
	 */
	public static function getPageName() {
		if (defined('PAGE_NAME') && PAGE_NAME) {
			return strtolower(PAGE_NAME);
		}

		return strtolower(basename(get_included_files()[0], '.php'));
	}

	/**
	 * Extract the page name (script basename) from a URL.
	 *
	 * @param string $rURL URL to parse.
	 * @return string|null Lowercased page name, or null if $rURL is empty.
	 */
	public static function getPageFromURL($rURL) {
		if ($rURL) {
			return strtolower(basename(ltrim(parse_url($rURL)['path'], '/'), '.php'));
		}

		return null;
	}

	/**
	 * Build a history.replaceState() script that updates the URL query string.
	 *
	 * @param array $rArgs Query parameters to set.
	 * @param bool  $rGet  Also merge the args into the request manager.
	 * @return string Inline <script> updating the browser URL.
	 */
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

	/**
	 * Parse a release filename into metadata via guessit or the python parser.
	 *
	 * @param string $rRelease Release/file name.
	 * @return array|null Parsed metadata, or null on failure.
	 */
	public static function parserelease($rRelease) {
		if (SettingsManager::getAll()['parse_type'] == 'guessit') {
			$rCommand = MAIN_HOME . 'bin/guess ' . escapeshellarg(pathinfo($rRelease)['filename'] . '.mkv');
		} else {
			$rCommand = '/usr/bin/python3 ' . MAIN_HOME . 'bin/python/release.py ' . escapeshellarg(pathinfo(str_replace('-', '_', $rRelease))['filename']);
		}

		return json_decode(shell_exec($rCommand), true);
	}
}
