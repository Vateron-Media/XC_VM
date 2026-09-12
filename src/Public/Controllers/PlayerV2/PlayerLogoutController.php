<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Auth\SessionManager;

/**
 * PlayerLogoutController — Log out from Web Player V2.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class PlayerLogoutController
{
    public function index()
    {
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        SessionManager::clearContext('player');

        $code = $_SERVER['XC_CODE'] ?? '';
        $redirectUrl = $code ? '/' . $code . '/login' : 'login';
        header('Location: ' . $redirectUrl);
        exit();
    }
}
