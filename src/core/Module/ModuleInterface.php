<?php

/**
 * XC_VM — Контракт модуля
 *
 * Каждый модуль в modules/ ДОЛЖЕН реализовать этот интерфейс.
 * Модуль — это изолированная директория с известным контрактом.
 * Его можно удалить, и система продолжит работать.
 *
 * ──────────────────────────────────────────────────────────────────
 * Жизненный цикл:
 * ──────────────────────────────────────────────────────────────────
 *
 *   1. ModuleLoader сканирует config/modules.php
 *   2. Для каждого модуля: require module.json → проверка зависимостей
 *   3. boot(ServiceContainer) — регистрация сервисов
 *   4. registerRoutes(Router) — регистрация маршрутов и API-действий
 *   5. registerCrons() — возвращает массив крон-задач
 *   6. getEventSubscribers() — подписки на события ядра
 *
 * ──────────────────────────────────────────────────────────────────
 * Пример:
 * ──────────────────────────────────────────────────────────────────
 *
 *   class WatchModule implements ModuleInterface {
 *       public function getName(): string { return 'watch'; }
 *       public function getVersion(): string { return '1.0.0'; }
 *
 *       public function boot(ServiceContainer $container): void {
 *           $container->set('watch.service', function($c) {
 *               return new WatchService($c->get('db'));
 *           });
 *       }
 *
 *       public function registerRoutes(Router $router): void {
 *           $router->group('watch', function(Router $r) {
 *               $r->get('', [WatchController::class, 'index']);
 *               $r->get('add', [WatchController::class, 'add']);
 *           });
 *       }
 *
 *       public function registerCrons(): array { return []; }
 *       public function getEventSubscribers(): array { return []; }
 *   }
 *
 * @see Router::group()
 * @see ServiceContainer::set()
 */

interface ModuleInterface {

    /**
     * Уникальное имя модуля (совпадает с именем директории)
     *
     * @return string Напр. 'watch', 'plex', 'ministra'
     */
    public function getName(): string;

    /**
     * Версия модуля (semver)
     *
     * @return string Напр. '1.0.0'
     */
    public function getVersion(): string;

    /**
     * Инициализация модуля: регистрация сервисов в DI-контейнере
     *
     * Вызывается один раз при загрузке модуля.
     * Модуль может регистрировать свои сервисы, фабрики и значения.
     *
     * @param ServiceContainer $container DI-контейнер
     */
    public function boot(ServiceContainer $container): void;

    /**
     * Регистрация маршрутов модуля
     *
     * Вызывается после boot(). Модуль регистрирует:
     * - GET/POST маршруты для страниц
     * - API-маршруты для AJAX-действий
     *
     * @param Router $router HTTP-роутер
     */
    public function registerRoutes(Router $router): void;

    /**
     * Получить список крон-задач модуля
     *
     * Возвращает массив крон-конфигураций:
     *   [
     *       ['class' => WatchCron::class, 'method' => 'run', 'interval' => 60],
     *   ]
     *
     * @return array Массив крон-задач
     */
    public function registerCrons(): array;

    /**
     * Получить массив подписок на события ядра
     *
     * Формат:
     *   [
     *       EventClass::class => [HandlerClass::class, 'onEvent'],
     *   ]
     *
     * @return array Подписки: EventClass => handler
     */
    public function getEventSubscribers(): array;
}
