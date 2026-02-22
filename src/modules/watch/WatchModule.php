<?php

/**
 * XC_VM — Watch Module
 *
 * Модуль Watch Folder / Recording.
 * Регистрирует сервисы, маршруты, API-действия и крон-задачи.
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Сервисы:
 *     - WatchService    — CRUD Watch Folder'ов, настройки, enable/disable/kill
 *     - RecordingService — планирование записей (DVR)
 *
 *   Контроллер:
 *     - WatchController — обработка HTTP-запросов и API
 *
 *   Страницы:
 *     - watch          — список folder'ов
 *     - watch/add      — добавление/редактирование
 *     - watch/settings — настройки watch (settings_watch)
 *     - watch/output   — логи (watch_output)
 *     - watch/record   — планирование записи (record)
 *
 *   API-действия:
 *     - enable_watch   — включить все folder'ы
 *     - disable_watch  — отключить все folder'ы
 *     - kill_watch     — убить процессы
 *     - folder         — удалить/запустить folder
 *
 * @see WatchService
 * @see RecordingService
 * @see WatchController
 */

class WatchModule implements ModuleInterface {

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'watch';
    }

    /**
     * {@inheritdoc}
     */
    public function getVersion(): string {
        return '1.0.0';
    }

    /**
     * Регистрация сервисов модуля в DI-контейнере
     *
     * @param ServiceContainer $container
     */
    public function boot(ServiceContainer $container): void {
        // WatchService и RecordingService — статические классы,
        // регистрируем их имена для обнаружения
        $container->set('watch.service', 'WatchService');
        $container->set('watch.recording', 'RecordingService');
        $container->set('watch.controller', function ($c) {
            return new WatchController();
        });
    }

    /**
     * Регистрация маршрутов модуля
     *
     * Страницы:
     *   GET watch              → WatchController::index
     *   GET watch/add          → WatchController::add
     *   GET settings/watch     → WatchController::settings
     *   GET watch/output       → WatchController::output
     *   GET watch/record       → WatchController::record (через record)
     *
     * API:
     *   enable_watch           → WatchController::apiEnable
     *   disable_watch          → WatchController::apiDisable
     *   kill_watch             → WatchController::apiKill
     *   folder                 → WatchController::apiFolder
     *
     * @param Router $router
     */
    public function registerRoutes(Router $router): void {
        // ── Страницы ──────────────────────────────────────────
        $router->group('watch', function (Router $r) {
            $r->get('', [WatchController::class, 'index'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
            $r->get('add', [WatchController::class, 'add'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
            $r->get('output', [WatchController::class, 'output'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
        });

        // settings_watch → settings/watch (через normalizePage)
        $router->get('settings/watch', [WatchController::class, 'settings'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);

        // record → отдельная страница
        $router->get('record', [WatchController::class, 'record'], [
            'permission' => ['adv', 'folder_watch'],
        ]);

        // ── API-действия ──────────────────────────────────────
        $router->api('enable_watch', [WatchController::class, 'apiEnable'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
        $router->api('disable_watch', [WatchController::class, 'apiDisable'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
        $router->api('kill_watch', [WatchController::class, 'apiKill'], [
            'permission' => ['adv', 'folder_watch'],
        ]);
        $router->api('folder', [WatchController::class, 'apiFolder'], [
            'permission' => ['adv', 'folder_watch'],
        ]);
    }

    /**
     * Крон-задачи модуля
     *
     * @return array
     */
    public function registerCrons(): array {
        return [
            [
                'class'    => WatchCron::class,
                'method'   => 'run',
                'interval' => 60,
            ],
        ];
    }

    /**
     * Подписки на события ядра
     *
     * @return array
     */
    public function getEventSubscribers(): array {
        return [];
    }
}
