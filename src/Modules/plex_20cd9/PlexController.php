<?php

namespace XcVm\Module\Plex;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerService;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Plex Module Controller
 *
 * Обрабатывает все маршруты модуля Plex:
 * - Список Plex Sync серверов (index)
 * - Добавление/редактирование библиотеки (add)
 * - Настройки Plex (settings)
 * - API: enable/disable/kill/library/sections actions
 *
 * @see PlexService
 * @see PlexRepository
 * @see PlexModule
 *
 * @package XC_VM_Module_Plex
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlexController {

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

    public function index() {
        global $rMobile, $rSettings, $rServers;
        $rPlexServers = PlexRepository::getPlexServers();
        $_TITLE = 'Plex Sync';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/index.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/library_scripts.php';
    }

    public function add() {
        global $rMobile, $rSettings, $rPermissions, $language;

        if (isset(RequestManager::getAll()['id'])) {
            $rFolder = StreamRepository::getWatchFolder(RequestManager::getAll()['id']);
            if (!$rFolder) {
                AdminHelpers::goHome();
            }
        }

        $rBouquets = BouquetService::getAllSimple();
        $_TITLE = isset($rFolder) ? 'Edit Library' : 'Add Library';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/library_edit.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/library_edit_scripts.php';
    }

    public function settings() {
        global $rMobile, $rSettings;
        $db = DatabaseFactory::get();
        $rBouquets = BouquetService::getAllSimple();
        $_TITLE = 'Plex Settings';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/settings.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/settings_scripts.php';
    }

    // ───────────────────────────────────────────────────────────
    //  API-действия (JSON)
    // ───────────────────────────────────────────────────────────

    public function apiEnable() {
        PlexRepository::enableAll();
        echo json_encode(['result' => true]);
        exit();
    }

    public function apiDisable() {
        PlexRepository::disableAll();
        echo json_encode(['result' => true]);
        exit();
    }

    public function apiKill() {
        ServerService::killPlexSync();
        echo json_encode(['result' => true]);
        exit();
    }

    public function apiLibrary() {
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
                PlexService::forcePlex($rFolder['server_id'], $rFolder['id']);
                echo json_encode(['result' => true]);
                exit();
            }
        }

        echo json_encode(['result' => false]);
        exit();
    }

    public function apiSections() {
        $rIP       = RequestManager::getAll()['ip'] ?? '';
        $rPort     = RequestManager::getAll()['port'] ?? '';
        $rUsername  = RequestManager::getAll()['username'] ?? '';
        $rPassword = RequestManager::getAll()['password'] ?? '';

        $rToken = PlexAuth::getPlexToken($rIP, $rPort, $rUsername, $rPassword);
        if (!$rToken) {
            echo json_encode(['result' => false]);
            exit();
        }

        $rSections = PlexRepository::getPlexSections($rIP, $rPort, $rToken);

        if ($rSections && count($rSections) > 0) {
            echo json_encode(['result' => true, 'data' => $rSections]);
        } else {
            echo json_encode(['result' => false]);
        }
        exit();
    }
}
