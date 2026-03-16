<?php

/**
 * Контракт модуля
 *
 * Каждый модуль в modules/ ДОЛЖЕН реализовать этот интерфейс.
 * Модуль — это изолированная директория с известным контрактом.
 * Его можно удалить, и система продолжит работать.
 *
 * ──────────────────────────────────────────────────────────────────
 * Источник истины — PHP-класс:
 * ──────────────────────────────────────────────────────────────────
 *
 *   module.json — только метаданные (name, description, version, requires_core).
 *   Вся runtime-конфигурация — ЗДЕСЬ, в PHP-методах:
 *     - boot()               — регистрация сервисов
 *     - registerRoutes()     — HTTP-маршруты
 *     - registerCommands()   — CLI-команды и кроны
 *     - getEventSubscribers() — подписки на события
 *
 * ──────────────────────────────────────────────────────────────────
 * Жизненный цикл:
 * ──────────────────────────────────────────────────────────────────
 *
 *   1. ModuleLoader сканирует modules/star/module.json
 *   2. config/modules.php проверяется на overrides (enabled => false)
 *   3. boot(ServiceContainer) — регистрация сервисов
 *   4. registerRoutes(Router) — регистрация маршрутов
 *   5. registerCommands(CommandRegistry) — CLI-команды и кроны
 *   6. getEventSubscribers() — подписки на события
 *
 *   Установка/удаление:
 *   7. install() — создание таблиц, начальные данные
 *   8. uninstall() — удаление таблиц, очистка данных
 *
 * @see Router::group()
 * @see ServiceContainer::set()
 * @see CommandRegistry::register()
 */

interface ModuleInterface {

    /**
     * Уникальное имя модуля (совпадает с именем директории)
     */
    public function getName(): string;

    /**
     * Версия модуля (semver)
     */
    public function getVersion(): string;

    /**
     * Инициализация модуля: регистрация сервисов в DI-контейнере
     *
     * Вызывается один раз при загрузке модуля.
     *
     * @param ServiceContainer $container DI-контейнер
     */
    public function boot(ServiceContainer $container): void;

    /**
     * Регистрация HTTP-маршрутов модуля
     *
     * Вызывается после boot(). Модуль регистрирует:
     * - GET/POST маршруты для страниц
     * - API-маршруты для AJAX-действий
     *
     * @param Router $router HTTP-роутер
     */
    public function registerRoutes(Router $router): void;

    /**
     * Регистрация CLI-команд и крон-задач модуля
     *
     * Модуль явно создаёт и регистрирует экземпляры CommandInterface.
     * Никакого filesystem scanning — вся регистрация в PHP.
     *
     * Пример:
     *   $registry->register(new MyCronCommand());
     *   $registry->register(new MyToolCommand());
     *
     * @param CommandRegistry $registry Реестр CLI-команд
     */
    public function registerCommands(CommandRegistry $registry): void;

    /**
     * Получить массив подписок на события ядра
     *
     * @return array Подписки: EventClass => handler
     */
    public function getEventSubscribers(): array;

    /**
     * Установка модуля (создание таблиц, начальные данные)
     *
     * Вызывается один раз при включении модуля.
     */
    public function install(): void;

    /**
     * Удаление модуля (очистка таблиц, записей в settings, cron)
     *
     * Вызывается при отключении/удалении модуля.
     */
    public function uninstall(): void;
}
