<?php

namespace XcVm\Module\Watch;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Public\Controllers\Admin\TableController;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;
use XcVm\Core\Localization\Translator;

/**
 * Watch Module Controller
 *
 * Обрабатывает все маршруты модуля Watch:
 * - Список Watch Folder'ов (index)
 * - Добавление/редактирование (add)
 * - Настройки Watch (settings)
 * - Логи Watch (output)
 * - API: enable/disable/kill/folder actions
 *
 * @see WatchService
 * @see WatchModule
 *
 * @package XC_VM_Module_Watch
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WatchController {

    /**
     * Путь к директории views модуля
     * @var string
     */
    protected $viewsPath;

    /** @var string Путь к layout-файлам */
    protected $layoutsPath;

    public function __construct() {
        $this->viewsPath = __DIR__ . '/views';
        $this->layoutsPath = MAIN_HOME . 'Public/Views/layouts/';
        require_once $this->layoutsPath . 'admin.php';
        require_once $this->layoutsPath . 'footer.php';
    }

    // ───────────────────────────────────────────────────────────
    //  Страницы (GET)
    // ───────────────────────────────────────────────────────────

    public function index() {
        global $rMobile, $rSettings, $rServers;
        $_TITLE = 'Watch Folder';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/watch.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/watch_scripts.php';
    }

    public function add() {
        global $rMobile, $rSettings, $rPermissions, $language, $rTMDBLanguages;
        $language = $language ?: Translator::class;

        if (isset(RequestManager::getAll()['id'])) {
            $rFolder = StreamRepository::getWatchFolder(RequestManager::getAll()['id']);
            if (!$rFolder) {
                AdminHelpers::goHome();
            }
        }

        $rBouquets = BouquetService::getAllSimple();
        $_TITLE = isset($rFolder) ? 'Edit Folder' : 'Add Folder';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/watch_add.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/watch_add_scripts.php';
    }

    public function settings() {
        global $rMobile, $rSettings;
        $db = DatabaseFactory::get();
        $rBouquets = BouquetService::getAllSimple();
        $_TITLE = 'Watch Settings';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/settings_watch.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/settings_watch_scripts.php';
    }

    public function output() {
        global $rMobile, $rSettings, $rServers, $language;
        $language = $language ?: Translator::class;
        $_TITLE = 'Watch Folder Logs';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/watch_output.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/watch_output_scripts.php';
    }

    // ───────────────────────────────────────────────────────────
    //  API-действия (JSON)
    // ───────────────────────────────────────────────────────────

    public function apiEnable() {
        WatchService::enableWatch();
        echo json_encode(['result' => true]);
        exit();
    }

    public function apiDisable() {
        WatchService::disableWatch();
        echo json_encode(['result' => true]);
        exit();
    }

    public function apiKill() {
        WatchService::killWatch();
        echo json_encode(['result' => true]);
        exit();
    }

    public function apiFolder() {
        $rSub = RequestManager::getAll()['sub'] ?? '';
        $rFolderID = RequestManager::getAll()['folder_id'] ?? 0;

        if ($rSub === 'delete') {
            StreamRepository::deleteWatchFolder($rFolderID);
            echo json_encode(['result' => true]);
            exit();
        }

        if ($rSub === 'force') {
            $rFolder = StreamRepository::getWatchFolder($rFolderID);
            if ($rFolder) {
                WatchService::forceWatch($rFolder['server_id'], $rFolder['id']);
                echo json_encode(['result' => true]);
                exit();
            }
        }

        echo json_encode(['result' => false]);
        exit();
    }

    /**
     * action=watch_output — delete a single folder-watch log row.
     *
     * Owns the module's `watch_logs` table (moved out of core MiscAjaxController).
     * Route gate already enforces the folder_watch_output permission.
     */
    public function apiWatchOutput() {
        global $db;
        if ((RequestManager::getAll()['sub'] ?? '') === 'delete') {
            $db->query('DELETE FROM `watch_logs` WHERE `id` = ?;', RequestManager::getAll()['result_id'] ?? 0);
            echo json_encode(['result' => true]);
            exit();
        }
        echo json_encode(['result' => false]);
        exit();
    }

    /**
     * action=watch_clear_logs — clear folder-watch logs, optionally by date range
     * (from/to = YYYY-MM-DD). Empty range truncates the whole `watch_logs` table.
     * Moved out of core BackupAjaxController's generic clear_logs handler.
     */
    public function apiClearLogs() {
        global $db;
        $rFrom = RequestManager::getAll()['from'] ?? '';
        $rTo = RequestManager::getAll()['to'] ?? '';
        $rStart = strlen((string) $rFrom) ? strtotime($rFrom . ' 00:00:00') : null;
        $rEnd = strlen((string) $rTo) ? strtotime($rTo . ' 23:59:59') : null;
        if ($rStart && $rEnd) {
            $db->query('DELETE FROM `watch_logs` WHERE UNIX_TIMESTAMP(`dateadded`) >= ? AND UNIX_TIMESTAMP(`dateadded`) <= ?;', $rStart, $rEnd);
        } elseif ($rStart) {
            $db->query('DELETE FROM `watch_logs` WHERE UNIX_TIMESTAMP(`dateadded`) >= ?;', $rStart);
        } elseif ($rEnd) {
            $db->query('DELETE FROM `watch_logs` WHERE UNIX_TIMESTAMP(`dateadded`) <= ?;', $rEnd);
        } else {
            $db->query('TRUNCATE `watch_logs`;');
        }
        echo json_encode(['result' => true]);
        exit();
    }

    /**
     * serverSide DataTable builder for the 'watch_output' table.
     *
     * Registered with the core TableRegistry (see WatchModule::registerTables)
     * so this module owns its table instead of the id living in core
     * TableController. Returns clean, keyed JSON — the watch_output view renders
     * every cell client-side. Must NOT echo/exit (TableController encodes it).
     *
     * @param array $rReturn DataTables response skeleton (recordsTotal / recordsFiltered / data).
     * @param int   $rStart  Offset.
     * @param int   $rLimit  Page length.
     * @param bool  $rIsAPI  Whether the caller is the REST API (raw column filtering).
     * @return array Populated response.
     */
    public static function tableWatchOutput(array $rReturn, int $rStart, int $rLimit, bool $rIsAPI): array {
        global $db;
        if (!Authorization::check('adv', 'folder_watch_output')) {
            return $rReturn;
        }
        $rOrder = ['`watch_logs`.`id`', '`watch_logs`.`type`', '`watch_logs`.`server_id`', '`watch_logs`.`filename`', '`watch_logs`.`status`', '`watch_logs`.`dateadded`', false];
        $rOrderColumn = RequestManager::get('order')[0]['column'] ?? '';
        $rOrderRow = (0 < strlen((string) $rOrderColumn)) ? (int) $rOrderColumn : 0;
        $rWhere = $rWhereV = [];
        if (0 < strlen(RequestManager::get('search')['value'] ?? '')) {
            foreach (range(1, 3) as $rInt) {
                $rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
            }
            $rWhere[] = '(`watch_logs`.`id` LIKE ? OR `watch_logs`.`filename` LIKE ? OR `watch_logs`.`dateadded` LIKE ?)';
        }
        if (0 < (int) (RequestManager::get('server') ?? 0)) {
            $rWhere[] = '`watch_logs`.`server_id` = ?';
            $rWhereV[] = (int) (RequestManager::get('server') ?? 0);
        }
        if (0 < strlen(RequestManager::get('type') ?? '')) {
            $rWhere[] = '`watch_logs`.`type` = ?';
            $rWhereV[] = RequestManager::get('type');
        }
        if (0 < strlen(RequestManager::get('status') ?? '')) {
            $rWhere[] = '`watch_logs`.`status` = ?';
            $rWhereV[] = RequestManager::get('status');
        }
        $rOrderBy = '';
        if (isset($rOrder[$rOrderRow]) && $rOrder[$rOrderRow]) {
            $rOrderDirection = strtolower(RequestManager::get('order')[0]['dir'] ?? '') === 'desc' ? 'desc' : 'asc';
            $rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
        }
        $rWhereString = (0 < count($rWhere)) ? 'WHERE ' . implode(' AND ', $rWhere) : '';
        $db->query('SELECT COUNT(*) AS `count` FROM `watch_logs` LEFT JOIN `servers` ON `servers`.`id` = `watch_logs`.`server_id` ' . $rWhereString . ';', ...$rWhereV);
        $rReturn['recordsTotal'] = ($db->num_rows() == 1) ? $db->get_row()['count'] : 0;
        $rReturn['recordsFiltered'] = ($rIsAPI ? ($rReturn['recordsTotal'] < $rLimit ? $rReturn['recordsTotal'] : $rLimit) : $rReturn['recordsTotal']);
        if (0 < $rReturn['recordsTotal']) {
            $db->query('SELECT `watch_logs`.`id`, `watch_logs`.`type`, `watch_logs`.`server_id`, `servers`.`server_name`, `watch_logs`.`filename`, `watch_logs`.`status`, `watch_logs`.`stream_id`, `watch_logs`.`dateadded` FROM `watch_logs` LEFT JOIN `servers` ON `servers`.`id` = `watch_logs`.`server_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';', ...$rWhereV);
            if (0 < $db->num_rows()) {
                foreach ($db->get_rows() as $rRow) {
                    if ($rIsAPI) {
                        $rReturn['data'][] = TableController::filterRow($rRow, RequestManager::get('show_columns') ?? '', RequestManager::get('hide_columns') ?? '');
                    } else {
                        $rReturn['data'][] = [
                            'id'          => (int) $rRow['id'],
                            'type'        => (int) $rRow['type'],
                            'server_id'   => (int) $rRow['server_id'],
                            'server_name' => $rRow['server_name'],
                            'filename'    => $rRow['filename'],
                            'status'      => (int) $rRow['status'],
                            'stream_id'   => isset($rRow['stream_id']) ? (int) $rRow['stream_id'] : 0,
                            'dateadded'   => $rRow['dateadded'],
                        ];
                    }
                }
            }
        }
        return $rReturn;
    }
}
