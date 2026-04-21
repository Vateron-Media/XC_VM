<?php

/**
 * EpgApiController — epg api controller
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EpgApiController extends BaseApiController {
	protected $downloadType = 'epg';

	public function index() {
		set_time_limit(0);
		header('Access-Control-Allow-Origin: *');
		$rRequestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
		$rLegacyAction = strtolower(explode('.', ltrim((string) $rRequestPath, '/'))[0] ?? '');

		if ($rLegacyAction == 'xmltv' && !SettingsManager::getAll()['legacy_xmltv']) {
			$this->deny = false;
			generateError('LEGACY_EPG_DISABLED');
		}

		$rIP = NetworkUtils::getUserIP();
		$rCountryCode = GeoIP::getCountry($rIP)['country']['iso_code'];
		$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
		$rGZ = !empty(RequestManager::getAll()['gzip']) && intval(RequestManager::getAll()['gzip']) == 1;
		$rUsername = RequestManager::getAll()['username'] ?? '';

		$rUserInfo = $this->authenticate($rIP);

		if (!$rUserInfo) {
			BruteforceGuard::checkBruteforce(null, null, $rUsername);
			generateError('INVALID_CREDENTIALS');
		}

		$this->deny = false;
		$this->userInfo = $rUserInfo;
		ini_set('memory_limit', -1);

		if (!$rUserInfo['is_restreamer'] && SettingsManager::getAll()['disable_xmltv']) {
			generateError('EPG_DISABLED');
		}

		if ($rUserInfo['is_restreamer'] && SettingsManager::getAll()['disable_xmltv_restreamer']) {
			generateError('EPG_DISABLED');
		}

		$this->validateUser($rUserInfo, $rUserAgent, $rIP, $rCountryCode);

		$rBouquets = array();

		foreach ($rUserInfo['bouquet'] as $rBouquetID) {
			if (in_array($rBouquetID, array_keys(BouquetService::getAll()))) {
				$rBouquets[] = $rBouquetID;
			}
		}

		sort($rBouquets);
		$rBouquetGroup = md5(implode('_', $rBouquets));

		if (file_exists(EPG_PATH . 'epg_' . $rBouquetGroup . '.xml')) {
			$rFile = EPG_PATH . 'epg_' . $rBouquetGroup . '.xml';
		} else {
			$rFile = EPG_PATH . 'epg_all.xml';
		}

		$rFilename = 'epg.xml';

		if ($rGZ) {
			$rFile .= '.gz';
			$rFilename .= '.gz';
		}

		if (!file_exists($rFile)) {
			generateError('EPG_FILE_MISSING');
		}

		if (NetworkUtils::startDownload('epg', $rUserInfo, getmypid(), intval(SettingsManager::getAll()['max_simultaneous_downloads']))) {
			$this->downloading = true;
			header('Content-disposition: attachment; filename="' . $rFilename . '"');

			if ($rGZ) {
				header('Content-Type: application/octet-stream');
				header('Content-Transfer-Encoding: Binary');
			} else {
				header('Content-Type: application/xml; charset=utf-8');
			}

			self::readChunked($rFile);
		} else {
			generateError('DOWNLOAD_LIMIT_REACHED', false);
			http_response_code(429);
			exit();
		}

		exit();
	}

	private static function readChunked($rFilename) {
		$rHandle = fopen($rFilename, 'rb');

		if ($rHandle !== false) {
			while (!feof($rHandle)) {
				$rBuffer = fread($rHandle, 1048576);
				echo $rBuffer;
				ob_flush();
				flush();
			}

			fclose($rHandle);
		}
	}
}
