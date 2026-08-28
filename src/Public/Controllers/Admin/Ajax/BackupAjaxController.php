<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Backup\BackupService;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Diagnostics\DiagnosticsService;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Public\Controllers\Admin\TableController;

/**
 * Admin-ajax controller for the "Backups, Logs & Reports" group.
 *
 * Extracted from the legacy `admin/api.php`. Actions: clear_logs, backup,
 * report, download_panel_logs. Block logic ported faithfully (scaffolding via
 * gate/gateAny/ok/fail from {@see BaseAjaxController}; empty-then / nested
 * if-else cascades flattened — behaviour-preserving; comments English).
 *
 * `report` is the one action opened via full-page navigation (window.location)
 * rather than XHR — in api.php it is the sole entry exempt from the XHR guard,
 * so {@see self::report()} does NOT call requireXhr(); it streams a CSV/JSON
 * download by dispatching {@see TableController} in-process. `download_panel_logs`
 * has no per-action permission gate in api.php; that is preserved.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class BackupAjaxController extends BaseAjaxController {

    /** action=clear_logs — delete/truncate a log table over an optional date range. */
    public function clearLogs(): never {
        $this->requireXhr();
        $this->gateAny(array(
            array('adv', 'reg_userlog'),
            array('adv', 'client_request_log'),
            array('adv', 'connection_logs'),
            array('adv', 'stream_errors'),
            array('adv', 'credits_log'),
            array('adv', 'folder_watch_settings'),
        ));

        global $db;

        if (strlen(RequestManager::get('from')) == 0) {
            $rStartTime = null;
        } else {
            $rStartTime = strtotime(RequestManager::get('from') . ' 00:00:00');

            if (!$rStartTime) {
                $this->fail();
            }
        }

        if (strlen(RequestManager::get('to')) == 0) {
            $rEndTime = null;
        } else {
            $rEndTime = strtotime(RequestManager::get('to') . ' 23:59:59');

            if (!$rEndTime) {
                $this->fail();
            }
        }

        if (in_array(RequestManager::get('type'), array('lines_logs', 'streams_errors', 'lines_activity', 'users_credits_logs', 'users_logs', 'panel_logs'))) {
            $rColumn = (RequestManager::get('type') == 'lines_activity') ? 'date_start' : 'date';
            $rTable = QueryHelper::prepareColumn(RequestManager::get('type'));

            if ($rStartTime && $rEndTime) {
                $db->query('DELETE FROM ' . $rTable . ' WHERE `' . $rColumn . '` >= ? AND `' . $rColumn . '` <= ?;', $rStartTime, $rEndTime);
            } elseif ($rStartTime) {
                $db->query('DELETE FROM ' . $rTable . ' WHERE `' . $rColumn . '` >= ?;', $rStartTime);
            } elseif ($rEndTime) {
                $db->query('DELETE FROM ' . $rTable . ' WHERE `' . $rColumn . '` <= ?;', $rEndTime);
            } else {
                $db->query('TRUNCATE ' . $rTable . ';');
            }
        } elseif (RequestManager::get('type') == 'watch_logs') {
            if ($rStartTime && $rEndTime) {
                $db->query('DELETE FROM `watch_logs` WHERE UNIX_TIMESTAMP(`dateadded`) >= ? AND UNIX_TIMESTAMP(`dateadded`) <= ?;', $rStartTime, $rEndTime);
            } elseif ($rStartTime) {
                $db->query('DELETE FROM `watch_logs` WHERE UNIX_TIMESTAMP(`dateadded`) >= ?;', $rStartTime);
            } elseif ($rEndTime) {
                $db->query('DELETE FROM `watch_logs` WHERE UNIX_TIMESTAMP(`dateadded`) <= ?;', $rEndTime);
            } else {
                $db->query('TRUNCATE `watch_logs`;');
            }
        }

        $this->ok();
    }

    /** action=backup — delete/restore a DB backup or trigger a new one (local + Dropbox). */
    public function backup(): never {
        $this->requireXhr();
        $this->gate('adv', 'database');

        global $rSettings;
        $rSub = RequestManager::get('sub');

        if ($rSub == 'delete') {
            $rBackup = pathinfo(RequestManager::get('filename'))['filename'];

            if (file_exists(MAIN_HOME . 'backups/' . $rBackup . '.sql')) {
                unlink(MAIN_HOME . 'backups/' . $rBackup . '.sql');
            }

            if (0 < strlen($rSettings['dropbox_token'])) {
                BackupService::deleteRemote('/' . $rBackup . '.sql');
            }

            $this->ok();
        }

        if ($rSub == 'restore') {
            $rBackup = pathinfo(RequestManager::get('filename'))['filename'];
            $rFilename = MAIN_HOME . 'backups/' . $rBackup . '.sql';

            if (!file_exists($rFilename)) {
                $rFilename = MAIN_HOME . 'tmp/restore.sql';

                if (0 < strlen($rSettings['dropbox_token'])) {
                    if (!BackupService::downloadRemote('/' . $rBackup . '.sql', $rFilename)) {
                        $this->fail();
                    }
                } else {
                    $this->fail();
                }
            }

            $this->json(array('result' => BackupService::restore($rFilename)));
        }

        if ($rSub != 'backup') {
            $this->fail();
        }

        shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:backups 1 > /dev/null 2>/dev/null &');

        $this->ok();
    }

    /**
     * action=report — stream a DataTables result as a CSV/JSON download.
     *
     * Opened via full-page navigation (no XHR header), so it does NOT call
     * requireXhr(). The DataTables handler is dispatched in-process (the old
     * loopback curl is unreachable under the Front Controller); its echoed JSON
     * is captured from a shutdown hook and converted to the requested format.
     */
    public function report(): never {
        $this->gate('adv', 'backups');

        global $rUserInfo;
        set_time_limit(60);
        ini_set('memory_limit', '-1');
        $rParams = json_decode(RequestManager::get('params'), true) ?: array();
        $rParams['report'] = true;
        $rParams['start'] = 0;
        $rParams['length'] = 100000;
        $rParams['draw'] = 0;

        $rReportName = preg_replace('/[^A-Za-z0-9 ]/', '', ($rParams['id'] ?? 'report')) . '_' . date('YmdHis');
        $rWantJson = RequestManager::get('format') === 'json';

        register_shutdown_function(static function () use ($rReportName, $rWantJson) {
            $rJson = ob_get_level() > 0 ? ob_get_clean() : '';
            $rDecoded = json_decode((string) $rJson, true);
            $rRows = (is_array($rDecoded) && !empty($rDecoded['data'])) ? $rDecoded['data'] : array();

            if ($rWantJson) {
                header('Content-Type: application/json; charset=utf-8');
                header('Content-Disposition: attachment; filename=report_' . $rReportName . '.json');
                echo json_encode($rRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                return;
            }

            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename=report_' . $rReportName . '.csv');

            if (0 < count($rRows)) {
                echo file_get_contents(AdminHelpers::convertToCSV($rRows));
            }
        });

        // Authenticate the in-process dispatch via the loopback + api_user_id branch
        // in TableController::index() (avoids its session/functions.php include path).
        $rParams['api_user_id'] = $rUserInfo['id'];
        RequestManager::set(array_merge(RequestManager::getAll(), $rParams));
        $_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';

        ob_start();
        (new TableController())->index();

        exit();
    }

    /** action=download_panel_logs — collect recent panel error-log rows (no per-action gate). */
    public function downloadPanelLogs(): never {
        $this->requireXhr();

        $this->ok(array('data' => DiagnosticsService::downloadPanelLogs(DatabaseFactory::get())));
    }
}
