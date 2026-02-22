<?php

/**
 * XC_VM — Plex Module Controller
 *
 * Обрабатывает все маршруты модуля Plex:
 * - Список Plex Sync серверов (index)
 * - Добавление/редактирование библиотеки (add)
 * - Настройки Plex (settings)
 * - API: enable/disable/kill/library/sections actions
 *
 * ──────────────────────────────────────────────────────────────────
 * Заменяет:
 * ──────────────────────────────────────────────────────────────────
 *
 *   admin/plex.php           → index()
 *   admin/plex_add.php       → add()
 *   admin/settings_plex.php  → settings()
 *   admin/api.php actions:
 *     enable_plex  → apiEnable()
 *     disable_plex → apiDisable()
 *     kill_plex    → apiKill()
 *     library (sub=delete|force) → apiLibrary()
 *     plex_sections → apiSections()
 *
 * ──────────────────────────────────────────────────────────────────
 * Использование (через Router):
 * ──────────────────────────────────────────────────────────────────
 *
 *   $router->group('plex', function(Router $r) {
 *       $r->get('',         [PlexController::class, 'index']);
 *       $r->get('add',      [PlexController::class, 'add']);
 *       $r->get('settings', [PlexController::class, 'settings']);
 *   });
 *
 * @see PlexService
 * @see PlexRepository
 * @see PlexModule
 */

class PlexController {

    /**
     * Путь к директории views модуля
     * @var string
     */
    protected $viewsPath;

    public function __construct() {
        $this->viewsPath = dirname(__DIR__) . '/modules/plex/views';
    }

    // ───────────────────────────────────────────────────────────
    //  Страницы (GET)
    // ───────────────────────────────────────────────────────────

    /**
     * Список Plex Sync серверов
     *
     * Заменяет admin/plex.php
     * Подготавливает: $rPlexServers
     */
    public function index() {
        global $db;

        $rPlexServers = PlexRepository::getPlexServers($db);
        $_TITLE = 'Plex Sync';

        include 'header.php';
        include $this->viewsPath . '/index.php';
        include 'footer.php';
        include $this->viewsPath . '/library_scripts.php';
    }

    /**
     * Добавление/редактирование Plex библиотеки
     *
     * Заменяет admin/plex_add.php
     * Подготавливает: $rFolder (при edit), $rBouquets
     */
    public function add() {
        if (isset(CoreUtilities::$rRequest['id'])) {
            $rFolder = getWatchFolder(CoreUtilities::$rRequest['id']);
            if (!$rFolder) {
                goHome();
                return;
            }
        }

        $rBouquets = getBouquets();
        $_TITLE = isset($rFolder) ? 'Edit Library' : 'Add Library';

        include 'header.php';
        include $this->viewsPath . '/library_edit.php';
        include 'footer.php';
        include $this->viewsPath . '/library_edit_scripts.php';
    }

    /**
     * Настройки Plex Sync
     *
     * Заменяет admin/settings_plex.php
     * Подготавливает: $rBouquets
     */
    public function settings() {
        $rBouquets = getBouquets();
        $_TITLE = 'Plex Settings';

        include 'header.php';
        include $this->viewsPath . '/settings.php';
        include 'footer.php';
        include $this->viewsPath . '/settings_scripts.php';
    }

    // ───────────────────────────────────────────────────────────
    //  API-действия (JSON)
    // ───────────────────────────────────────────────────────────

    /**
     * Включить все Plex Sync серверы
     *
     * Заменяет admin/api.php action=enable_plex
     */
    public function apiEnable() {
        global $db;
        $db->query("UPDATE `watch_folders` SET `active` = 1 WHERE `type` = 'plex';");
        echo json_encode(['result' => true]);
        exit();
    }

    /**
     * Отключить все Plex Sync серверы
     *
     * Заменяет admin/api.php action=disable_plex
     */
    public function apiDisable() {
        global $db;
        $db->query("UPDATE `watch_folders` SET `active` = 0 WHERE `type` = 'plex';");
        echo json_encode(['result' => true]);
        exit();
    }

    /**
     * Убить все процессы Plex Sync
     *
     * Заменяет admin/api.php action=kill_plex
     */
    public function apiKill() {
        killPlexSync();
        echo json_encode(['result' => true]);
        exit();
    }

    /**
     * Действия с отдельной библиотекой (delete / force)
     *
     * Заменяет admin/api.php action=library&sub=delete|force
     */
    public function apiLibrary() {
        $rSub = CoreUtilities::$rRequest['sub'] ?? '';
        $rFolderID = CoreUtilities::$rRequest['folder_id'] ?? 0;

        if ($rSub === 'delete') {
            deleteWatchFolder($rFolderID);
            echo json_encode(['result' => true]);
            exit();
        }

        if ($rSub === 'force') {
            $rFolder = getWatchFolder($rFolderID);
            if ($rFolder) {
                PlexService::forcePlex($rFolder['server_id'], $rFolder['id'], 'systemapirequest');
                echo json_encode(['result' => true]);
                exit();
            }
        }

        echo json_encode(['result' => false]);
        exit();
    }

    /**
     * Получить секции Plex сервера (AJAX)
     *
     * Заменяет admin/api.php action=plex_sections
     */
    public function apiSections() {
        $rIP       = CoreUtilities::$rRequest['ip'] ?? '';
        $rPort     = CoreUtilities::$rRequest['port'] ?? '';
        $rUsername  = CoreUtilities::$rRequest['username'] ?? '';
        $rPassword = CoreUtilities::$rRequest['password'] ?? '';

        $rToken = PlexAuth::getPlexToken($rIP, $rPort, $rUsername, $rPassword);
        $rSections = PlexRepository::getPlexSections($rIP, $rPort, $rToken);

        if ($rSections && count($rSections) > 0) {
            echo json_encode(['result' => true, 'data' => $rSections]);
        } else {
            echo json_encode(['result' => false]);
        }
        exit();
    }
}
