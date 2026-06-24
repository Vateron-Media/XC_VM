<?php

use XcVm\Core\Util\NetworkUtils;
use XcVm\Core\Util\GeoIP;
use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Config\SettingsManager;
/**
 * PlaylistApiController — playlist api controller
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlaylistApiController extends BaseApiController {
	protected $downloadType = 'playlist';

	public function index() {
		set_time_limit(0);
		header('Access-Control-Allow-Origin: *');
		$rRequestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
		$rLegacyAction = strtolower(explode('.', ltrim((string) $rRequestPath, '/'))[0] ?? '');

		if ($rLegacyAction == 'get' && !SettingsManager::getAll()['legacy_get']) {
			$this->deny = false;
			generateError('LEGACY_GET_DISABLED');
		}

		$rIP = NetworkUtils::getUserIP();
		$rCountry = GeoIP::getCountry($rIP);
		$rCountryCode = (is_array($rCountry) && isset($rCountry['country']['iso_code']) ? $rCountry['country']['iso_code'] : null);
		$rUserAgent = (empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlentities(trim($_SERVER['HTTP_USER_AGENT'])));
		$rDeviceKey = (empty(RequestManager::getAll()['type']) ? 'm3u_plus' : RequestManager::getAll()['type']);
		$rTypeKey = (empty(RequestManager::getAll()['key']) ? null : explode(',', RequestManager::getAll()['key']));
		$rOutputKey = (empty(RequestManager::getAll()['output']) ? '' : RequestManager::getAll()['output']);
		$rNoCache = !empty(RequestManager::getAll()['nocache']);
		$rUsername = RequestManager::getAll()['username'] ?? '';

		$rUserInfo = $this->authenticate($rIP, true);

		if (!$rUserInfo) {
			BruteforceGuard::checkBruteforce(null, null, $rUsername);
			generateError('INVALID_CREDENTIALS');
		}

		$this->deny = false;
		$this->userInfo = $rUserInfo;
		ini_set('memory_limit', -1);

		if (!$rUserInfo['is_restreamer'] && SettingsManager::getAll()['disable_playlist']) {
			generateError('PLAYLIST_DISABLED');
		}

		if ($rUserInfo['is_restreamer'] && SettingsManager::getAll()['disable_playlist_restreamer']) {
			generateError('PLAYLIST_DISABLED');
		}

		$this->validateUser($rUserInfo, $rUserAgent, $rIP, $rCountryCode);

		$this->downloading = true;

		if (NetworkUtils::startDownload('playlist', $rUserInfo, getmypid(), intval(SettingsManager::getAll()['max_simultaneous_downloads']))) {
			global $db;
			$db = new DatabaseHandler();
			DatabaseFactory::set($db);
			$rProxyIP = ($_SERVER['HTTP_X_IP'] ?? ($_SERVER['REMOTE_ADDR'] ?? ''));

			if (!PlaylistGenerator::generate($rUserInfo, $rDeviceKey, $rOutputKey, $rTypeKey, $rNoCache, BlocklistService::isProxy($rProxyIP))) {
				generateError('GENERATE_PLAYLIST_FAILED');
			}
		} else {
			generateError('DOWNLOAD_LIMIT_REACHED', false);
			http_response_code(429);
			exit();
		}
	}
}
