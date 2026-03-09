<?php
/**
 * PlayerLoginController — Login page for player panel.
 *
 * Migrated from player/login.php.
 * Handles its own bootstrap since the login page
 * must work without an active session.
 */
class PlayerLoginController
{
	public function index()
	{
		define('CLIENT_INVALID', 0);
		define('CLIENT_IS_E2', 1);
		define('CLIENT_IS_MAG', 2);
		define('CLIENT_IS_STALKER', 3);
		define('CLIENT_EXPIRED', 4);
		define('CLIENT_BANNED', 5);
		define('CLIENT_DISABLED', 6);
		define('CLIENT_DISALLOWED', 7);

		$rErrors = [
			'Invalid username or password.',
			'Enigma lines are not permitted here.',
			'MAG lines are not permitted here.',
			'Stalker lines are not permitted here.',
			'Your line has expired.',
			'Your line has been banned.',
			'Your line has been disabled.',
			'You are not allowed to access this player.',
		];

		// Start session
		if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
			session_start();
		}

		// Destroy existing session
		destroySession('player');

		$_STATUS = null;

		if (!empty(RequestManager::getAll()['username']) && !empty(RequestManager::getAll()['password'])) {
			$_STATUS = $this->processLogin();
			if ($_STATUS === null) {
				// Success — redirect already sent
				return;
			}
		}

		// Render login view
		$__viewFile = MAIN_HOME . 'public/Views/player/login.php';
		if (file_exists($__viewFile)) {
			require $__viewFile;
		} else {
			http_response_code(500);
			echo 'Login view not found';
		}
	}

	private function processLogin()
	{
		$rIP = NetworkUtils::getUserIP();
		$rCountryCode = GeoIP::getCountry($rIP)['country']['iso_code'];
		$rUserInfo = UserRepository::getUserInfo(null, RequestManager::getAll()['username'], RequestManager::getAll()['password'], true);
		$rUserAgent = empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlspecialchars(trim($_SERVER['HTTP_USER_AGENT']));

		if (!$rUserInfo) {
			BruteforceGuard::checkFlood();
			return CLIENT_INVALID;
		}

		if ($rUserInfo['is_e2']) {
			BruteforceGuard::checkFlood();
			return CLIENT_IS_E2;
		}

		if ($rUserInfo['is_mag']) {
			BruteforceGuard::checkFlood();
			return CLIENT_IS_MAG;
		}

		if ($rUserInfo['is_stalker']) {
			BruteforceGuard::checkFlood();
			return CLIENT_IS_STALKER;
		}

		if (!is_null($rUserInfo['exp_date']) && $rUserInfo['exp_date'] <= time()) {
			BruteforceGuard::checkFlood();
			return CLIENT_EXPIRED;
		}

		if ($rUserInfo['admin_enabled'] == 0) {
			BruteforceGuard::checkFlood();
			return CLIENT_BANNED;
		}

		if ($rUserInfo['enabled'] == 0) {
			BruteforceGuard::checkFlood();
			return CLIENT_DISABLED;
		}

		// IP/Country/UA/ISP checks
		if (!empty($rUserInfo['allowed_ips']) && !in_array($rIP, array_map('gethostbyname', $rUserInfo['allowed_ips']))) {
			BruteforceGuard::checkFlood();
			return CLIENT_DISALLOWED;
		}

		if (!empty($rCountryCode)) {
			$rForceCountry = !empty($rUserInfo['forced_country']);
			if ($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country']) {
				BruteforceGuard::checkFlood();
				return CLIENT_DISALLOWED;
			}
			if (!$rForceCountry && !in_array('ALL', SettingsManager::getAll()['allow_countries']) && !in_array($rCountryCode, SettingsManager::getAll()['allow_countries'])) {
				BruteforceGuard::checkFlood();
				return CLIENT_DISALLOWED;
			}
		}

		if (!empty($rUserInfo['allowed_ua']) && !in_array($rUserAgent, $rUserInfo['allowed_ua'])) {
			BruteforceGuard::checkFlood();
			return CLIENT_DISALLOWED;
		}

		if ($rUserInfo['isp_violate']) {
			BruteforceGuard::checkFlood();
			return CLIENT_DISALLOWED;
		}

		if ($rUserInfo['isp_is_server'] && !$rUserInfo['is_restreamer']) {
			BruteforceGuard::checkFlood();
			return CLIENT_DISALLOWED;
		}

		// Success — set session and redirect
		$_SESSION['phash'] = $rUserInfo['id'];
		$_SESSION['pverify'] = md5($rUserInfo['username'] . '||' . $rUserInfo['password']);
		header('Location: index');
		exit();
	}
}
