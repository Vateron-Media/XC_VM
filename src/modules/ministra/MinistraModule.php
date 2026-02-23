<?php

/**
 * XC_VM — Ministra (Stalker Portal) Module
 *
 * Модуль MAG/Ministra-портала для STB-устройств.
 * Обрабатывает HTTP-запросы от MAG-приставок через portal.php.
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Обработчики:
 *     - PortalHandler    — диспетчер switch/case для type+action запросов
 *                          (pre-init, stb, itv, vod, series, epg, radio,
 *                           tv_archive, watchdog, audioclub, account_info,
 *                           handshake)
 *
 *   Хелперы:
 *     - PortalHelpers    — вспомогательные функции портала
 *                          (getDevice, updateCache, getEPG, getItems,
 *                           getMovies, getSeries, getStreams, getStations,
 *                           getSeriesItems, sort*, getHeaders, shutdown)
 *
 *   Точка входа:
 *     - ministra/portal.php — тонкая обёртка: init/auth → PortalHandler
 *
 * @see PortalHandler
 * @see PortalHelpers
 */

class MinistraModule implements ModuleInterface {

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'ministra';
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
     * Ministra — автономный портал (отдельная точка входа portal.php),
     * не использует общий DI-контейнер напрямую.
     *
     * @param ServiceContainer $container
     */
    public function boot(ServiceContainer $container): void {
        // Ministra — standalone endpoint, сервисы не регистрируются
    }

    /**
     * Регистрация маршрутов модуля
     *
     * Ministra обслуживается через ministra/portal.php (прямой HTTP),
     * не через Router admin-панели.
     *
     * @param Router $router
     */
    public function registerRoutes(Router $router): void {
        // Маршрутизация Ministra — через portal.php напрямую
    }

    /**
     * Крон-задачи модуля
     *
     * Ministra не имеет собственных крон-задач.
     *
     * @return array
     */
    public function registerCrons(): array {
        return [];
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
