<?php

/**
 * XC_VM — MAGSCAN Module
 *
 * Модуль настроек MAGSCAN.
 * Управляет белыми/чёрными списками для сканирования MAG-устройств
 * (MAC-адреса и IP-адреса).
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Страницы:
 *     - magscan_settings — управление белым/чёрным списком MAC
 *                          + белый список IP (три вкладки)
 *
 *   Обработчик формы:
 *     - submit_magscan   — POST-обработка сохранения настроек
 *
 * @see admin/magscan_settings.php
 */

class MagscanModule implements ModuleInterface {

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'magscan';
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
     * MAGSCAN использует общую инфраструктуру admin-панели —
     * отдельные сервисы не регистрируются.
     *
     * @param ServiceContainer $container
     */
    public function boot(ServiceContainer $container): void {
        // Собственных сервисов нет — используется общая admin-инфраструктура
    }

    /**
     * Регистрация маршрутов модуля
     *
     * Маршрутизация осуществляется через навигацию admin-панели
     * (magscan_settings.php загружается напрямую).
     *
     * @param Router $router
     */
    public function registerRoutes(Router $router): void {
        // Маршрутизация — через admin page navigation (magscan_settings.php)
    }

    /**
     * Крон-задачи модуля
     *
     * MAGSCAN не имеет собственных крон-задач.
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
