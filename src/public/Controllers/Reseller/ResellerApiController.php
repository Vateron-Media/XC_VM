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
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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

        ResellerApiDispatcher::dispatch($action, $rUserInfo, $rPermissions);
    }
}
