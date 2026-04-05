<?php

/**
 * BaseApiController — base api controller
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class BaseApiController {
	protected $deny = true;
	protected $downloading = false;
	protected $userInfo = null;
	protected $downloadType = '';

	public function shutdown() {
		global $db;

		if ($this->deny) {
			BruteforceGuard::checkFlood();
		}

		if (is_object($db)) {
			$db->close_mysql();
		}

		if ($this->downloading) {
			NetworkUtils::stopDownload($this->downloadType, $this->userInfo, getmypid(), intval(SettingsManager::getAll()['max_simultaneous_downloads']));
		}
	}

	protected function authenticate($rIP, $rFullLoad = false) {
		if (isset(RequestManager::getAll()['username']) && isset(RequestManager::getAll()['password'])) {
			$rUsername = RequestManager::getAll()['username'];
			$rPassword = RequestManager::getAll()['password'];

			if (empty($rUsername) || empty($rPassword)) {
				generateError('NO_CREDENTIALS');
			}

			return UserRepository::getUserInfo(null, $rUsername, $rPassword, $rFullLoad, false, $rIP);
		}

		if (isset(RequestManager::getAll()['token'])) {
			$rToken = RequestManager::getAll()['token'];

			if (empty($rToken)) {
				generateError('NO_CREDENTIALS');
			}

			return UserRepository::getUserInfo(null, $rToken, null, $rFullLoad, false, $rIP);
		}

		generateError('NO_CREDENTIALS');
	}

	protected function validateUser($rUserInfo, $rUserAgent, $rIP, $rCountryCode) {
		if ($rUserInfo['bypass_ua'] == 0) {
			if (BlocklistService::checkAndBlockUA(BlocklistService::getBlockedUA(), $rUserAgent, true)) {
				generateError('BLOCKED_USER_AGENT');
			}
		}

		if (!is_null($rUserInfo['exp_date']) && $rUserInfo['exp_date'] <= time()) {
			generateError('EXPIRED');
		}

		if ($rUserInfo['is_mag'] || $rUserInfo['is_e2']) {
			generateError('DEVICE_NOT_ALLOWED');
		}

		if (!$rUserInfo['admin_enabled']) {
			generateError('BANNED');
		}

		if (!$rUserInfo['enabled']) {
			generateError('DISABLED');
		}

		if (!SettingsManager::getAll()['restrict_playlists']) {
			return;
		}

		if (empty($rUserAgent) && SettingsManager::getAll()['disallow_empty_user_agents'] == 1) {
			generateError('EMPTY_USER_AGENT');
		}

		if (!empty($rUserInfo['allowed_ips']) && !in_array($rIP, array_map('gethostbyname', $rUserInfo['allowed_ips']))) {
			generateError('NOT_IN_ALLOWED_IPS');
		}

		if (!empty($rCountryCode)) {
			$rForceCountry = !empty($rUserInfo['forced_country']);

			if ($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country']) {
				generateError('FORCED_COUNTRY_INVALID');
			}

			if (!$rForceCountry && !in_array('ALL', SettingsManager::getAll()['allow_countries']) && !in_array($rCountryCode, SettingsManager::getAll()['allow_countries'])) {
				generateError('NOT_IN_ALLOWED_COUNTRY');
			}
		}

		if (!empty($rUserInfo['allowed_ua']) && !in_array($rUserAgent, $rUserInfo['allowed_ua'])) {
			generateError('NOT_IN_ALLOWED_UAS');
		}

		if ($rUserInfo['isp_violate'] == 1) {
			generateError('ISP_BLOCKED');
		}

		if ($rUserInfo['isp_is_server'] == 1 && !$rUserInfo['is_restreamer']) {
			generateError('ASN_BLOCKED');
		}
	}
}
