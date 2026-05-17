<?php

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
 * ──────────────────────────────────────────────────────────────────
 * Заменяет:
 * ──────────────────────────────────────────────────────────────────
 *
 *   admin/watch.php          → index()
 *   admin/watch_add.php      → add()
 *   admin/settings_watch.php → settings()
 *   admin/watch_output.php   → output()
 *   admin/api.php actions:
 *     enable_watch  → apiEnable()
 *     disable_watch → apiDisable()
 *     kill_watch    → apiKill()
 *     folder (sub=delete|force) → apiFolder()
 *
 * ──────────────────────────────────────────────────────────────────
 * Использование (через Router):
 * ──────────────────────────────────────────────────────────────────
 *
 *   $router->group('watch', function(Router $r) {
 *       $r->get('',         [WatchController::class, 'index']);
 *       $r->get('add',      [WatchController::class, 'add']);
 *       $r->get('settings', [WatchController::class, 'settings']);
 *       $r->get('output',   [WatchController::class, 'output']);
 *   });
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
        $this->layoutsPath = MAIN_HOME . 'public/Views/layouts/';
        require_once $this->layoutsPath . 'admin.php';
        require_once $this->layoutsPath . 'footer.php';
    }

    // ───────────────────────────────────────────────────────────
    //  Страницы (GET)
    // ───────────────────────────────────────────────────────────

    /**
     * Список Watch Folder'ов
     *
     * Заменяет admin/watch.php
     */
    public function index() {
        global $rMobile, $rSettings, $rServers;
        $_TITLE = 'Watch Folder';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/watch.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/watch_scripts.php';
    }

    /**
     * Форма добавления/редактирования Watch Folder
     *
     * Заменяет admin/watch_add.php
     * Подготавливает данные: $rFolder (при edit), $rBouquets
     */
    public function add() {
        global $db, $rMobile, $rSettings, $rPermissions, $language;

        if (isset(RequestManager::getAll()['id'])) {
            $rFolder = StreamRepository::getWatchFolder(RequestManager::getAll()['id']);
            if (!$rFolder) {
                AdminHelpers::goHome();
                return;
            }
        }

        $rBouquets = BouquetService::getAllSimple();
        $_TITLE = isset($rFolder) ? 'Edit Folder' : 'Add Folder';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/watch_add.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/watch_add_scripts.php';
    }

    /**
     * Настройки Watch Folder
     *
     * Заменяет admin/settings_watch.php
     * Подготавливает: $rBouquets, категории
     */
    public function settings() {
        global $db, $rMobile, $rSettings;
        $rBouquets = BouquetService::getAllSimple();
        $_TITLE = 'Watch Settings';

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/settings_watch.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/settings_watch_scripts.php';
    }

    /**
     * Логи Watch Folder / Plex Sync
     *
     * Заменяет admin/watch_output.php
     */
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

    /**
     * Включить все Watch Folder'ы
     *
     * Заменяет admin/api.php action=enable_watch
     */
    public function apiEnable() {
        global $db;
        WatchService::enableWatch();
        echo json_encode(['result' => true]);
        exit();
    }

    /**
     * Отключить все Watch Folder'ы
     *
     * Заменяет admin/api.php action=disable_watch
     */
    public function apiDisable() {
        global $db;
        WatchService::disableWatch();
        echo json_encode(['result' => true]);
        exit();
    }

    /**
     * Убить все процессы Watch Folder
     *
     * Заменяет admin/api.php action=kill_watch
     */
    public function apiKill() {
        global $db;
        WatchService::killWatch();
        echo json_encode(['result' => true]);
        exit();
    }

    /**
     * Действия с отдельным Folder'ом (delete / force)
     *
     * Заменяет admin/api.php action=folder&sub=delete|force
     */
    public function apiFolder() {
        global $db;
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
