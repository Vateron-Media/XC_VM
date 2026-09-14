<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Auth\SessionManager;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\GeoIP;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\User\UserRepository;
use XcVm\Public\Controllers\PlayerV2\PlayerLogoutController;

/**
 * PlayerLoginController — Handles multi-mode login for Web Player V2.
 *
 * Supports direct subscriber credentials, native activation codes,
 * and AJAX responses for the multi-account manager.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class PlayerLoginController
{
    public const CLIENT_INVALID = 0;
    public const CLIENT_IS_E2 = 1;
    public const CLIENT_IS_MAG = 2;
    public const CLIENT_IS_STALKER = 3;
    public const CLIENT_EXPIRED = 4;
    public const CLIENT_BANNED = 5;
    public const CLIENT_DISABLED = 6;
    public const CLIENT_DISALLOWED = 7;

    public function index()
    {
        if (!defined('CLIENT_INVALID')) {
            define('CLIENT_INVALID', 0);
            define('CLIENT_IS_E2', 1);
            define('CLIENT_IS_MAG', 2);
            define('CLIENT_IS_STALKER', 3);
            define('CLIENT_EXPIRED', 4);
            define('CLIENT_BANNED', 5);
            define('CLIENT_DISABLED', 6);
            define('CLIENT_DISALLOWED', 7);
        }

        $rErrors = [
            0 => 'Invalid username or password.',
            1 => 'Enigma lines are not permitted here.',
            2 => 'MAG lines are not permitted here.',
            3 => 'Stalker lines are not permitted here.',
            4 => 'Your line has expired.',
            5 => 'Your line has been banned.',
            6 => 'Your line has been disabled.',
            7 => 'You are not allowed to access this player.',
        ];

        // Core bootstrap
        require_once MAIN_HOME . 'bootstrap.php';
        \XC_Bootstrap::boot(\XC_Bootstrap::CONTEXT_ADMIN);

        // Start session
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        // Destroy existing session for player context
        SessionManager::clearContext('player');

        // Merge inputs from RequestManager, POST, and JSON body
        $rawInput = @file_get_contents('php://input');
        $jsonData = json_decode($rawInput, true) ?: [];
        $req = array_merge(RequestManager::getAll(), $_POST, $jsonData);

        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
            || (!empty($_SERVER['HTTP_ACCEPT']) && str_contains(strtolower($_SERVER['HTTP_ACCEPT']), 'application/json'))
            || !empty($req['ajax'])
            || !empty($req['is_ajax']);

        $_STATUS = null;

        // 1. Check for M3U / Playlist URL submission
        $playlistUrl = trim($req['playlist_url'] ?? $req['m3u_url'] ?? $req['url'] ?? '');
        $isPlaylistAction = (!empty($req['action']) && in_array($req['action'], ['login_playlist_url', 'parse_playlist', 'login_m3u'], true));

        if ($isPlaylistAction || (!empty($playlistUrl) && empty($req['server']) && empty($req['username']))) {
            $parsed = \XcVm\Domain\External\ExternalXtreamService::parsePlaylistUrl($playlistUrl);
            if (!$parsed) {
                $err = 'Could not extract Server, Username, or Password from this URL. Please verify the URL format.';
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => $err]);
                    exit;
                }
                $_ERROR_MSG = $err;
            } else {
                $extResult = $this->processExternalXtreamLogin($parsed['server'], $parsed['username'], $parsed['password'], $playlistUrl);
                if ($isAjax) {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($extResult);
                    exit;
                }

                if ($extResult['success']) {
                    if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                        @session_start();
                    }
                    $_SESSION['saved_account_sync'] = $extResult['account'];
                    header('Location: ' . $extResult['redirect']);
                    exit;
                }

                $_ERROR_MSG = $extResult['message'] ?? 'Connection to external server failed.';
            }
        }

        // 2. Check for External Xtream Codes submission
        $serverUrl = trim($req['server'] ?? $req['server_url'] ?? $req['host'] ?? '');
        $isExternalAction = (!empty($req['action']) && $req['action'] === 'login_external_xc') || !empty($serverUrl);

        if ($isExternalAction && !empty($serverUrl)) {
            $extUsername = trim($req['username'] ?? '');
            $extPassword = trim($req['password'] ?? '');

            // Fallback: If serverUrl itself is a playlist URL, parse it automatically
            $parsedFromUrl = \XcVm\Domain\External\ExternalXtreamService::parsePlaylistUrl($serverUrl);
            if ($parsedFromUrl) {
                $serverUrl = $parsedFromUrl['server'];
                if (empty($extUsername)) $extUsername = $parsedFromUrl['username'];
                if (empty($extPassword)) $extPassword = $parsedFromUrl['password'];
                if (empty($playlistUrl)) $playlistUrl = $req['server'];
            }

            $extResult = $this->processExternalXtreamLogin($serverUrl, $extUsername, $extPassword, $playlistUrl);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($extResult);
                exit;
            }

            if ($extResult['success']) {
                if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                    @session_start();
                }
                $_SESSION['saved_account_sync'] = $extResult['account'];
                header('Location: ' . $extResult['redirect']);
                exit;
            }

            $_ERROR_MSG = $extResult['message'] ?? 'Connection to external server failed.';
        } elseif ($isExternalAction && empty($serverUrl)) {
            $err = 'Please enter a valid server URL (Host:Port).';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $err]);
                exit;
            }
            $_ERROR_MSG = $err;
        }

        // 2. Check for Activation Code submission
        $activationCode = trim($req['activation_code'] ?? $req['code'] ?? '');
        $isCodeAction = (!empty($req['action']) && $req['action'] === 'activate_code') || !empty($activationCode);

        if ($isCodeAction && !empty($activationCode)) {
            $codeResult = $this->processCodeLogin($activationCode);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($codeResult);
                exit;
            }

            if ($codeResult['success']) {
                if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                    @session_start();
                }
                $_SESSION['saved_account_sync'] = $codeResult['account'];
                header('Location: ' . $codeResult['redirect']);
                exit;
            }

            $_ERROR_MSG = $codeResult['message'] ?? 'Activation failed.';
        } elseif ($isCodeAction && empty($activationCode)) {
            $err = 'Please enter a valid activation code.';
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode(['success' => false, 'message' => $err]);
                exit;
            }
            $_ERROR_MSG = $err;
        }

        // 2. Check for Direct Username & Password submission
        $username = trim($req['username'] ?? '');
        $password = trim($req['password'] ?? '');

        if (!empty($username) && !empty($password)) {
            $credResult = $this->processCredentialLogin($username, $password, $rErrors);
            if ($isAjax) {
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode($credResult);
                exit;
            }

            if ($credResult['success']) {
                if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                    @session_start();
                }
                $_SESSION['saved_account_sync'] = $credResult['account'];
                header('Location: ' . $credResult['redirect']);
                exit;
            }

            $_STATUS = $credResult['status'] ?? null;
            $_ERROR_MSG = $credResult['message'] ?? ($rErrors[$_STATUS] ?? 'Login failed.');
        }

        // Render V2 login view
        $__viewFile = MAIN_HOME . 'Public/Views/player_v2/login.php';
        if (file_exists($__viewFile)) {
            require $__viewFile;
        } else {
            http_response_code(500);
            echo 'Login view not found';
        }
    }

    /**
     * Process direct subscriber username & password authentication.
     */
    private function processCredentialLogin(string $username, string $password, array $rErrors): array
    {
        $rIP = NetworkUtils::getUserIP();
        $rCountryCode = GeoIP::getCountry($rIP)['country']['iso_code'] ?? '';
        $rUserInfo = UserRepository::getUserInfo(null, $username, $password, true);
        $rUserAgent = empty($_SERVER['HTTP_USER_AGENT']) ? '' : htmlspecialchars(trim($_SERVER['HTTP_USER_AGENT']));

        if (!$rUserInfo) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_INVALID, 'message' => $rErrors[self::CLIENT_INVALID]];
        }

        if (!empty($rUserInfo['is_e2'])) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_IS_E2, 'message' => $rErrors[self::CLIENT_IS_E2]];
        }

        if (!empty($rUserInfo['is_mag'])) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_IS_MAG, 'message' => $rErrors[self::CLIENT_IS_MAG]];
        }

        if (!empty($rUserInfo['is_stalker'])) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_IS_STALKER, 'message' => $rErrors[self::CLIENT_IS_STALKER]];
        }

        if (!is_null($rUserInfo['exp_date']) && $rUserInfo['exp_date'] <= time()) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_EXPIRED, 'message' => $rErrors[self::CLIENT_EXPIRED]];
        }

        if (isset($rUserInfo['admin_enabled']) && $rUserInfo['admin_enabled'] == 0) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_BANNED, 'message' => $rErrors[self::CLIENT_BANNED]];
        }

        if (isset($rUserInfo['enabled']) && $rUserInfo['enabled'] == 0) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_DISABLED, 'message' => $rErrors[self::CLIENT_DISABLED]];
        }

        // IP/Country/UA/ISP checks
        if (!empty($rUserInfo['allowed_ips']) && !in_array($rIP, array_map('gethostbyname', (array)$rUserInfo['allowed_ips']))) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_DISALLOWED, 'message' => $rErrors[self::CLIENT_DISALLOWED]];
        }

        if (!empty($rCountryCode)) {
            $rForceCountry = !empty($rUserInfo['forced_country']);
            if ($rForceCountry && $rUserInfo['forced_country'] != 'ALL' && $rCountryCode != $rUserInfo['forced_country']) {
                BruteforceGuard::checkFlood();
                return ['success' => false, 'status' => self::CLIENT_DISALLOWED, 'message' => $rErrors[self::CLIENT_DISALLOWED]];
            }
            $allowedCountries = SettingsManager::get('allow_countries') ?: [];
            if (!$rForceCountry && !in_array('ALL', $allowedCountries) && !in_array($rCountryCode, $allowedCountries)) {
                BruteforceGuard::checkFlood();
                return ['success' => false, 'status' => self::CLIENT_DISALLOWED, 'message' => $rErrors[self::CLIENT_DISALLOWED]];
            }
        }

        if (!empty($rUserInfo['allowed_ua']) && !in_array($rUserAgent, (array)$rUserInfo['allowed_ua'])) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_DISALLOWED, 'message' => $rErrors[self::CLIENT_DISALLOWED]];
        }

        if (!empty($rUserInfo['isp_violate'])) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_DISALLOWED, 'message' => $rErrors[self::CLIENT_DISALLOWED]];
        }

        if (!empty($rUserInfo['isp_is_server']) && empty($rUserInfo['is_restreamer'])) {
            BruteforceGuard::checkFlood();
            return ['success' => false, 'status' => self::CLIENT_DISALLOWED, 'message' => $rErrors[self::CLIENT_DISALLOWED]];
        }

        // Success - Purge any previous session and regenerate session ID
        PlayerLogoutController::purgePlayerSession();
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        @session_regenerate_id(true);

        $_SESSION['phash'] = (int)$rUserInfo['id'];
        $_SESSION['pverify'] = md5($rUserInfo['username'] . '||' . $rUserInfo['password']);
        $_SESSION['is_external_xc'] = false;
        unset($_SESSION['external_xc']);

        $xcCode = $_SERVER['XC_CODE'] ?? '';
        $redirectUrl = $xcCode ? '/' . $xcCode . '/' : 'index';

        return [
            'success' => true,
            'redirect' => $redirectUrl,
            'clear_client_cache' => true,
            'message' => 'Signed in successfully! Launching player...',
            'account' => [
                'id' => (int)$rUserInfo['id'],
                'username' => $rUserInfo['username'],
                'password' => $password,
                'name' => $rUserInfo['username'],
                'activation_code' => '',
                'package_name' => '',
                'exp_date' => (int)($rUserInfo['exp_date'] ?? 0),
                'exp_date_formatted' => !empty($rUserInfo['exp_date']) ? date('Y-m-d H:i:s', (int)$rUserInfo['exp_date']) : 'Unlimited',
                'type' => 'credentials',
                'added_at' => time()
            ]
        ];
    }

    /**
     * Process Activation Code submission via ActiveCodeService.
     */
    private function processCodeLogin(string $code): array
    {
        $deviceInfo = [
            'ip' => NetworkUtils::getUserIP(),
            'user_agent' => empty($_SERVER['HTTP_USER_AGENT']) ? '' : trim($_SERVER['HTTP_USER_AGENT']),
        ];

        $res = ActiveCodeService::activateCode($code, $deviceInfo);
        if (($res['status'] ?? '') !== 'SUCCESS') {
            return [
                'success' => false,
                'message' => $res['message'] ?? 'Invalid, expired, or locked activation code.'
            ];
        }

        $line = $res['line'] ?? null;
        if (!$line && !empty($res['credentials']['username']) && !empty($res['credentials']['password'])) {
            $line = UserRepository::getUserInfo(null, $res['credentials']['username'], $res['credentials']['password'], true);
        }

        if (!$line) {
            return [
                'success' => false,
                'message' => 'Linked subscriber subscription could not be found.'
            ];
        }

        // Line status checks
        if (isset($line['admin_enabled']) && (int)$line['admin_enabled'] === 0) {
            return ['success' => false, 'message' => 'This account has been banned by an administrator.'];
        }
        if (isset($line['enabled']) && (int)$line['enabled'] === 0) {
            return ['success' => false, 'message' => 'This account has been disabled.'];
        }
        if (!is_null($line['exp_date']) && (int)$line['exp_date'] <= time()) {
            return ['success' => false, 'message' => 'This activation subscription has expired.'];
        }

        // Authenticate session - Purge any previous session and regenerate session ID
        PlayerLogoutController::purgePlayerSession();
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        @session_regenerate_id(true);

        $_SESSION['phash'] = (int)$line['id'];
        $_SESSION['pverify'] = md5($line['username'] . '||' . $line['password']);
        $_SESSION['is_external_xc'] = false;
        unset($_SESSION['external_xc']);

        $xcCode = $_SERVER['XC_CODE'] ?? '';
        $redirectUrl = $xcCode ? '/' . $xcCode . '/' : 'index';

        return [
            'success' => true,
            'redirect' => $redirectUrl,
            'clear_client_cache' => true,
            'message' => 'Activation successful! Enjoy streaming...',
            'account' => [
                'id' => (int)$line['id'],
                'username' => $line['username'],
                'password' => $line['password'],
                'name' => 'Code: ' . ($res['code'] ?? $code),
                'activation_code' => $res['code'] ?? $code,
                'package_name' => $res['package_name'] ?? 'Premium IPTV',
                'exp_date' => (int)($line['exp_date'] ?? 0),
                'exp_date_formatted' => $res['exp_date_formatted'] ?? (!empty($line['exp_date']) ? date('Y-m-d H:i:s', (int)$line['exp_date']) : 'Unlimited'),
                'type' => 'code',
                'added_at' => time()
            ]
        ];
    }

    /**
     * Process External Xtream Codes server authentication.
     */
    private function processExternalXtreamLogin(string $serverUrl, string $username, string $password, string $playlistUrl = ''): array
    {
        // Auto-extract if serverUrl is actually a full playlist URL
        $parsed = \XcVm\Domain\External\ExternalXtreamService::parsePlaylistUrl($serverUrl);
        if ($parsed) {
            $serverUrl = $parsed['server'];
            if (empty($username)) $username = $parsed['username'];
            if (empty($password)) $password = $parsed['password'];
            if (empty($playlistUrl)) $playlistUrl = $serverUrl;
        }

        $authRes = \XcVm\Domain\External\ExternalXtreamService::testAndAuthenticate($serverUrl, $username, $password);

        if (!$authRes['success']) {
            return $authRes;
        }

        $cleanServer = $authRes['clean_server'];
        $userInfo = $authRes['user_info'];
        $serverInfo = $authRes['server_info'] ?? [];

        // Purge old session before establishing external session
        PlayerLogoutController::purgePlayerSession();
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            @session_start();
        }
        @session_regenerate_id(true);

        // Setup session
        $_SESSION['is_external_xc'] = true;
        $_SESSION['external_xc'] = [
            'server' => $cleanServer,
            'username' => $username,
            'password' => $password,
            'playlist_url' => $playlistUrl,
            'user_info' => $userInfo,
            'server_info' => $serverInfo,
            'auth_time' => time(),
        ];
        $_SESSION['phash'] = 'ext_' . md5($cleanServer . ':' . $username);
        $_SESSION['pverify'] = md5($username . '||' . $password);

        $xcCode = $_SERVER['XC_CODE'] ?? '';
        $redirectUrl = $xcCode ? '/' . $xcCode . '/' : 'index';

        $serverHost = parse_url($cleanServer, PHP_URL_HOST) ?: $cleanServer;
        $serverPort = parse_url($cleanServer, PHP_URL_PORT);
        $displayName = $serverHost . ($serverPort ? ':' . $serverPort : '') . ' (' . $username . ')';

        $expTimestamp = !empty($userInfo['exp_date']) ? (int)$userInfo['exp_date'] : 0;
        $expFormatted = $expTimestamp ? date('Y-m-d H:i:s', $expTimestamp) : 'Unlimited';

        return [
            'success' => true,
            'redirect' => $redirectUrl,
            'clear_client_cache' => true,
            'message' => 'Connected to Xtream server successfully! Loading player...',
            'account' => [
                'id' => 'ext_' . md5($cleanServer . ':' . $username),
                'server' => $cleanServer,
                'username' => $username,
                'password' => $password,
                'playlist_url' => $playlistUrl,
                'name' => $displayName,
                'type' => 'external_xc',
                'package_name' => 'Xtream Codes Server',
                'exp_date' => $expTimestamp,
                'exp_date_formatted' => $expFormatted,
                'added_at' => time(),
            ],
        ];
    }
}

