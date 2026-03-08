<?php
/**
 * ResellerApiController — AJAX API handler for reseller panel.
 *
 * Migrated from reseller/api.php.
 * Handles dashboard stats, connection management, line/mag/enigma/user CRUD,
 * package info, EPG data, search, etc.
 *
 * Called via: GET/POST api?action=dashboard (or any other action).
 * Bootstrap (session + functions) is loaded by Front Controller before dispatch.
 */
class ResellerApiController extends BaseResellerController
{
    public function index()
    {
        session_write_close();

        $rUserInfo = $GLOBALS['rUserInfo'] ?? null;
        $rPermissions = $GLOBALS['rPermissions'] ?? [];
        global $db;

        if (!PHP_ERRORS) {
            if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
                exit();
            }
        }

        if (SettingsManager::getAll()['redis_handler']) {
            RedisManager::ensureConnected();
        }

        if (!$rUserInfo || !$rUserInfo['id']) {
            echo json_encode(['result' => false]);
            exit();
        }

        if (!isset($rUserInfo['reports'])) {
            echo json_encode(['result' => false]);
            exit();
        }

        $action = RequestManager::getAll()['action'] ?? '';

        // Delegate to the legacy API logic file
        require MAIN_HOME . 'infrastructure/legacy/reseller_api_actions.php';
    }
}
