<?php
/**
 * ResellerLoginController — Login page for reseller panel.
 *
 * Migrated from reseller/login.php.
 * This controller handles its own bootstrap since the login page
 * must work without an active session.
 */
class ResellerLoginController
{
    public function index()
    {
        // Already logged in → dashboard
        if (isset($_SESSION['reseller'])) {
            header('Location: dashboard');
            exit();
        }

        // Start session if needed
        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        $rIP = NetworkUtils::getUserIP();

        // Flood protection
        $rSettings = SettingsManager::getAll();
        global $db, $language, $rHues;

        if (intval($rSettings['login_flood']) > 0) {
            $db->query(
                "SELECT COUNT(`id`) AS `count` FROM `login_logs` WHERE `status` = 'INVALID_LOGIN' AND `login_ip` = ? AND TIME_TO_SEC(TIMEDIFF(NOW(), `date`)) <= 86400;",
                $rIP
            );

            if ($db->num_rows() === 1 && intval($db->get_row()['count']) >= intval($rSettings['login_flood'])) {
                BlocklistService::blockIP(['ip' => $rIP, 'notes' => 'LOGIN FLOOD ATTACK']);
                exit();
            }
        }

        // Process login POST
        $_STATUS = null;
        if (isset(RequestManager::getAll()['login'])) {
            $rReturn = ResellerAPI::processLogin(RequestManager::getAll());
            $_STATUS = $rReturn['status'];

            if ($_STATUS === STATUS_SUCCESS) {
                $rReferer = RequestManager::getAll()['referrer'] ?? '';
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
        $__viewFile = MAIN_HOME . 'public/Views/reseller/login.php';
        $referrer = htmlspecialchars(RequestManager::getAll()['referrer'] ?? '');

        if (file_exists($__viewFile)) {
            require $__viewFile;
        } else {
            http_response_code(500);
            echo 'Login view not found';
        }
    }
}
