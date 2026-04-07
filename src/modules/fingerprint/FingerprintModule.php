<?php

/**
 * Fingerprint Module
 *
 * Модуль «Отпечаток стрима» (Fingerprint Stream).
 * Позволяет администратору накладывать текст (ID активности, имя пользователя
 * или произвольное сообщение) поверх живых потоков для идентификации зрителя.
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Страницы:
 *     - fingerprint     — выбор потока (поиск + категория + DataTable)
 *                         + активация отпечатка (тип/размер/цвет/позиция)
 *                         + таблица активности
 *
 *   API-действия:
 *     - fingerprint     — отправка сигнала на активные серверы
 *     - line_activity   — принудительное завершение соединения
 *
 * @see admin/fingerprint.php
 *
 * @package XC_VM_Module_Fingerprint
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class FingerprintModule implements ModuleInterface {

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'fingerprint';
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
     * Fingerprint использует общую инфраструктуру admin-панели
     * (session, functions.php, api.php) — отдельные сервисы не нужны.
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
     * (fingerprint.php загружается напрямую).
     *
     * @param Router $router
     */
    public function registerRoutes(Router $router): void {
        // Маршрутизация — через admin page navigation (fingerprint.php)
    }

    /**
     * CLI-команды модуля
     *
     * Fingerprint не имеет CLI-команд.
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
