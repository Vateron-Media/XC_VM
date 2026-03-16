<?php

/**
 * Plex Module
 *
 * Модуль Plex Sync Integration.
 * Регистрирует сервисы, маршруты, API-действия и крон-задачи.
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Сервисы:
 *     - PlexService     — CRUD Plex Sync, настройки, force
 *     - PlexRepository  — получение Plex серверов и секций
 *     - PlexAuth        — аутентификация Plex (getToken, checkToken)
 *     - PlexCron        — крон синхронизации
 *     - PlexItem        — CLI обработка элементов
 *
 *   Контроллер:
 *     - PlexController  — обработка HTTP-запросов и API
 *
 *   Страницы:
 *     - plex            — список Plex серверов
 *     - plex/add        — добавление/редактирование библиотеки
 *     - plex/settings   — настройки Plex (settings_plex)
 *
 *   API-действия:
 *     - enable_plex     — включить все серверы
 *     - disable_plex    — отключить все серверы
 *     - kill_plex       — убить процессы
 *     - library         — удалить/запустить библиотеку
 *     - plex_sections   — получить секции Plex-сервера
 *
 * @see PlexService
 * @see PlexRepository
 * @see PlexAuth
 * @see PlexController
 */

class PlexModule implements ModuleInterface {

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'plex';
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
        $container->set('plex.service', 'PlexService');
        $container->set('plex.repository', 'PlexRepository');
        $container->set('plex.auth', 'PlexAuth');
        $container->set('plex.controller', function ($c) {
            return new PlexController();
        });
    }

    /**
     * Регистрация маршрутов модуля
     *
     * Страницы:
     *   GET plex              → PlexController::index
     *   GET plex/add          → PlexController::add
     *   GET settings/plex     → PlexController::settings
     *
     * API:
     *   enable_plex           → PlexController::apiEnable
     *   disable_plex          → PlexController::apiDisable
     *   kill_plex             → PlexController::apiKill
     *   library               → PlexController::apiLibrary
     *   plex_sections         → PlexController::apiSections
     *
     * @param Router $router
     */
    public function registerRoutes(Router $router): void {
        // ── Страницы ──────────────────────────────────────────
        $router->group('plex', function (Router $r) {
            $r->get('', [PlexController::class, 'index'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
            $r->get('add', [PlexController::class, 'add'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
        });

        // settings_plex → settings/plex (через normalizePage)
        $router->get('settings/plex', [PlexController::class, 'settings'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);

        // ── API-действия ──────────────────────────────────────
        $router->api('enable_plex', [PlexController::class, 'apiEnable'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
        $router->api('disable_plex', [PlexController::class, 'apiDisable'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
        $router->api('kill_plex', [PlexController::class, 'apiKill'], [
            'permission' => ['adv', 'folder_watch'],
        ]);
        $router->api('library', [PlexController::class, 'apiLibrary'], [
            'permission' => ['adv', 'folder_watch'],
        ]);
        $router->api('plex_sections', [PlexController::class, 'apiSections'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
    }

    /**
     * CLI-команды модуля
     *
     * @param CommandRegistry $registry
     */
    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new PlexCronJob());
        $registry->register(new PlexItemCommand());
    }

    /**
     * Подписки на события ядра
     *
     * @return array
     */
    public function getEventSubscribers(): array {
        return [];
    }

    /**
     * {@inheritdoc}
     */
    public function install(): void {
    }

    /**
     * {@inheritdoc}
     */
    public function uninstall(): void {
    }
}
