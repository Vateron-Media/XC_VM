<?php

/**
 * MAGSCAN Module
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
 *
 * @package XC_VM_Module_Magscan
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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
     * CLI-команды модуля
     *
     * MAGSCAN не имеет CLI-команд.
     *
     * @param CommandRegistry $registry
     */
    public function registerCommands(CommandRegistry $registry): void {
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
