<?php

/**
 * Theft Detection Module
 *
 * Модуль обнаружения кражи VOD-контента.
 * Мониторит паттерны просмотра VOD и выявляет потенциальное
 * распространение/кражу контента через анализ количества просмотров.
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Страницы:
 *     - theft_detection — фильтрованная таблица просмотров VOD
 *                         по пользователям (фильтр по диапазону)
 *
 *   Источник данных:
 *     - cache_engine.php generateTheftDetection() — крон-задача ядра,
 *       генерирует кеш-файл (igbinary) в CACHE_TMP_PATH/theft_detection
 *
 * @see admin/theft_detection.php
 */

class TheftDetectionModule implements ModuleInterface {

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'theft-detection';
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
     * Theft Detection использует общую инфраструктуру admin-панели —
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
     * (theft_detection.php загружается напрямую).
     *
     * @param Router $router
     */
    public function registerRoutes(Router $router): void {
        // Маршрутизация — через admin page navigation (theft_detection.php)
    }

    /**
     * CLI-команды модуля
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
