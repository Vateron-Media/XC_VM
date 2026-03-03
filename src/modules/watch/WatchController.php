<?php

/**
 * XC_VM — Watch Module Controller
 *
 * Обрабатывает все маршруты модуля Watch:
 * - Список Watch Folder'ов (index)
 * - Добавление/редактирование (add)
 * - Настройки Watch (settings)
 * - Логи Watch (output)
 * - Запись (record)
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
 *   admin/record.php         → record()
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
 *       $r->get('record',   [WatchController::class, 'record']);
 *   });
 *
 * @see WatchService
 * @see RecordingService
 * @see WatchModule
 */

class WatchController {

    /**
     * Путь к директории views модуля
     * @var string
     */
    protected $viewsPath;

    public function __construct() {
        $this->viewsPath = dirname(__DIR__) . '/modules/watch/views';
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
        include $this->viewsPath . '/watch.php';
    }

    /**
     * Форма добавления/редактирования Watch Folder
     *
     * Заменяет admin/watch_add.php
     * Подготавливает данные: $rFolder (при edit), $rBouquets
     */
    public function add() {
        global $db;

        if (isset(CoreUtilities::$rRequest['id'])) {
            $rFolder = getWatchFolder(CoreUtilities::$rRequest['id']);
            if (!$rFolder) {
                goHome();
                return;
            }
        }

        $rBouquets = BouquetService::getAllSimple();
        $_TITLE = isset($rFolder) ? 'Edit Folder' : 'Add Folder';

        include 'header.php';
        include $this->viewsPath . '/watch_add.php';
    }

    /**
     * Настройки Watch Folder
     *
     * Заменяет admin/settings_watch.php
     * Подготавливает: $rBouquets, категории
     */
    public function settings() {
        $rBouquets = BouquetService::getAllSimple();
        $_TITLE = 'Watch Settings';

        include 'header.php';
        include $this->viewsPath . '/settings_watch.php';
    }

    /**
     * Логи Watch Folder / Plex Sync
     *
     * Заменяет admin/watch_output.php
     */
    public function output() {
        $_TITLE = 'Watch Folder Logs';

        include 'header.php';
        include $this->viewsPath . '/watch_output.php';
    }

    /**
     * Форма записи (Recording)
     *
     * Заменяет admin/record.php
     * Подготавливает: $rStream, $rProgramme, $rAvailableServers
     */
    public function record() {
        global $db;

        $rAvailableServers = $rServers = array();
        $rStream = $rProgramme = null;

        if (isset(CoreUtilities::$rRequest['id'])) {
            $rStream = StreamRepository::getById(CoreUtilities::$rRequest['id']);
            $rProgramme = CoreUtilities::getProgramme(
                CoreUtilities::$rRequest['id'],
                CoreUtilities::$rRequest['programme']
            );
            if (!$rStream || $rStream['type'] != 1 || !$rProgramme) {
                goHome();
                return;
            }
        } elseif (isset(CoreUtilities::$rRequest['archive'])) {
            $rArchive = json_decode(base64_decode(CoreUtilities::$rRequest['archive']), true);
            $rStream = StreamRepository::getById($rArchive['stream_id']);
            $rProgramme = [
                'start'       => $rArchive['start'],
                'end'         => $rArchive['end'],
                'title'       => $rArchive['title'],
                'description' => $rArchive['description'],
                'archive'     => true,
            ];
            if (!$rStream || $rStream['type'] != 1 || !$rProgramme) {
                goHome();
                return;
            }
        } elseif (isset(CoreUtilities::$rRequest['stream_id'])) {
            $rStream = StreamRepository::getById(CoreUtilities::$rRequest['stream_id']);
            $rProgramme = [
                'start'       => strtotime(CoreUtilities::$rRequest['start_date']),
                'end'         => strtotime(CoreUtilities::$rRequest['start_date']) + intval(CoreUtilities::$rRequest['duration']) * 60,
                'title'       => '',
                'description' => '',
            ];
            if (!$rStream || $rStream['type'] != 1 || !$rProgramme || $rProgramme['end'] < time()) {
                header('Location: record');
                return;
            }
        }

        if ($rStream) {
            $rBitrate = null;
            $db->query('SELECT `server_id`, `bitrate` FROM `streams_servers` WHERE `stream_id` = ?;', $rStream['id']);
            foreach ($db->get_rows() as $rRow) {
                $rAvailableServers[] = $rRow['server_id'];
                if ((!$rBitrate && $rRow['bitrate']) || ($rRow['bitrate'] && $rBitrate < $rRow['bitrate'])) {
                    $rBitrate = $rRow['bitrate'];
                }
            }
        }

        $_TITLE = 'Schedule Recording';

        include 'header.php';
        include $this->viewsPath . '/record.php';
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
                WatchService::forceWatch($rFolder['server_id'], $rFolder['id']);
                echo json_encode(['result' => true]);
                exit();
            }
        }

        echo json_encode(['result' => false]);
        exit();
    }
}
