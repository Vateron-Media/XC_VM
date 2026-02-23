<?php

/**
 * XC_VM — Fingerprint Module
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
     * Крон-задачи модуля
     *
     * Fingerprint не имеет собственных крон-задач.
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
