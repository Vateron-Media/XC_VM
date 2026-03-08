<?php
/**
 * ResellerTableController — DataTables JSON endpoint for reseller panel.
 *
 * Migrated from reseller/table.php.
 * Handles tables: lines, mags, enigmas, streams, radios, movies, episodes,
 * line_activity, live_connections, reg_user_logs, reg_users.
 *
 * Supports both session-based access and API key access.
 */
class ResellerTableController extends BaseResellerController
{
    public function index()
    {
        session_write_close();

        $rUserInfo = $GLOBALS['rUserInfo'] ?? null;
        $rPermissions = $GLOBALS['rPermissions'] ?? [];
        $rSettings = $GLOBALS['rSettings'] ?? SettingsManager::getAll();
        global $db;

        if (!PHP_ERRORS) {
            if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) !== 'xmlhttprequest') {
                exit();
            }
        }

        $rReturn = array('draw' => intval(RequestManager::getAll()['draw']), 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => array());
        $rIsAPI = false;

        if (isset(RequestManager::getAll()['api_key'])) {
            $rReturn = array('status' => 'STATUS_SUCCESS', 'data' => array());
            $db->query('SELECT `id` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `api_key` = ? AND LENGTH(`api_key`) > 0 AND `is_reseller` = 1 AND `status` = 1;', RequestManager::getAll()['api_key']);
            if ($db->num_rows() != 0) {
                $rUserID = $db->get_row()['id'];
                $rIsAPI = true;
                require_once MAIN_HOME . 'includes/admin.php';
                $rUserInfo = UserRepository::getRegisteredUserById($rUserID);
                $rPermissions = array_merge(getPermissions($rUserInfo['member_group_id']), getGroupPermissions($rUserInfo['id']));
                $rPermissions['direct_reports'] = $rPermissions['direct_reports'] ?? [];
                $rPermissions['all_reports'] = $rPermissions['all_reports'] ?? [];
                $rPermissions['stream_ids'] = $rPermissions['stream_ids'] ?? [];
                $rPermissions['category_ids'] = $rPermissions['category_ids'] ?? [];
                $rPermissions['series_ids'] = $rPermissions['series_ids'] ?? [];
                $rPermissions['subresellers'] = $rPermissions['subresellers'] ?? [];
                if (0 < strlen($rUserInfo['timezone'])) {
                    date_default_timezone_set($rUserInfo['timezone']);
                }
            } else {
                echo json_encode(array('status' => 'STATUS_FAILURE', 'error' => 'Invalid API key.'));
                exit();
            }
        } else {
            if (!$rUserInfo || !$rUserInfo['id']) {
                echo json_encode($rReturn);
                exit();
            }
        }

        if (!$rUserInfo['id']) {
            echo json_encode($rReturn);
            exit();
        }

        if (!isset($rUserInfo['reports'])) {
            echo json_encode($rReturn);
            exit();
        }

        // Delegate to the legacy table logic file
        require MAIN_HOME . 'infrastructure/legacy/reseller_table_body.php';
    }
}
