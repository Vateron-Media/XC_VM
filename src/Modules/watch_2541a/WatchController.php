<?php

namespace XcVm\Module\Watch;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

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
}
