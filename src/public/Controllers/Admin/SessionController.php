<?php

/**
 * SessionController — JSON endpoint проверки статуса сессии.
 *
 * Возвращает {"result": true} если сессия активна, {"result": false} если нет.
 *
 * @renders (none — JSON response)
 *
 * @package XC_VM_Public_Controllers_Admin
 */

class SessionController extends BaseAdminController {
    private const SESSION_TIMEOUT_MINUTES = 60;

    public function index() {
        if (!defined('TMP_PATH')) {
            define('TMP_PATH', '/home/xc_vm/tmp/');
        }

        if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
            session_start();
        }

        if (
            isset($_SESSION['hash'], $_SESSION['last_activity'])
            && (time() - $_SESSION['last_activity']) > self::SESSION_TIMEOUT_MINUTES * 60
        ) {
            foreach (['hash', 'ip', 'code', 'verify', 'last_activity'] as $rKey) {
                unset($_SESSION[$rKey]);
            }
            if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
                session_start();
            }
        }

        if (!isset($_SESSION['hash'])) {
            exit(json_encode(['result' => false]));
        }

        $_SESSION['last_activity'] = time();
        session_write_close();

        exit(json_encode(['result' => true]));
    }
}
