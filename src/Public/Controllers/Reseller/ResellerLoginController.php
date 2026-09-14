<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Auth\Authenticator;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\User\ResellerAPI;

/**
 * ResellerLoginController — Login page for reseller panel.
 *
 * Migrated from reseller/login.php.
 * This controller handles its own bootstrap since the login page
 * must work without an active session.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerLoginController {
    public function index() {
        // Bootstrap (login page is in noBootstrapPages, so FC skips bootstrap)
        require_once MAIN_HOME . 'bootstrap.php';
        \XC_Bootstrap::boot(\XC_Bootstrap::CONTEXT_ADMIN);

        // Already logged in → dashboard
        if (isset($_SESSION['reseller'])) {
            header('Location: dashboard');
            exit();
        }

        $rIP = NetworkUtils::getUserIP();

        // Flood protection
        $rSettings = SettingsManager::getAll();
        global $db;

        // Translator FQCN for reseller/login.php's `$language::get(...)` calls.
        // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- consumed by required view reseller/login.php
        $language = Translator::class;

        if (Authenticator::loginFloodExceeded($rIP, intval($rSettings['login_flood']))) {
            BlocklistService::blockIP(['ip' => $rIP, 'notes' => 'LOGIN FLOOD ATTACK']);
            exit();
        }

        // Process login POST
        $_STATUS = null;
        if (RequestManager::has('login')) {
            $rReturn = ResellerAPI::processLogin(RequestManager::getAll());
            $_STATUS = $rReturn['status'];

            if ($_STATUS === STATUS_SUCCESS) {
                $rReferer = RequestManager::get('referrer') ?? '';
                if (strlen($rReferer) > 0) {
                    $rReferer = basename($rReferer);
                    if (substr($rReferer, 0, 6) === 'logout') {
                        $rReferer = 'dashboard';
                    }
                    header('Location: ' . $rReferer);
                } else {
                    header('Location: dashboard');
                }
                exit();
            }
        }

        // Render login view
        $__viewFile = MAIN_HOME . 'Public/Views/reseller/login.php';
        // phpcs:ignore SlevomatCodingStandard.Variables.UnusedVariable.UnusedVariable -- consumed by required view reseller/login.php
        $referrer = htmlspecialchars(RequestManager::get('referrer') ?? '');

        if (file_exists($__viewFile)) {
            require $__viewFile;
        } else {
            http_response_code(500);
            echo 'Login view not found';
        }
    }
}
