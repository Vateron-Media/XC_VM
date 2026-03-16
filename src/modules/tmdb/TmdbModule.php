<?php

/**
 * TMDB Module
 *
 * Модуль интеграции с TheMovieDB.
 * Регистрирует сервисы, API-действия и крон-задачи.
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Сервисы:
 *     - TmdbService       — поиск, получение деталей фильмов/сериалов
 *     - TmdbCron          — крон обработки очереди watch_refresh
 *     - TmdbPopularCron   — крон сбора популярных фильмов/сериалов
 *
 *   API-действия:
 *     - tmdb_search       — поиск в TMDB (по тексту или ID)
 *     - tmdb              — получение деталей фильма/сериала
 *
 *   Библиотека:
 *     - includes/libs/tmdb.php      — TMDB API v3 PHP wrapper
 *     - includes/libs/TMDb/         — модели (Movie, TVShow, Season, ...)
 *     - includes/libs/tmdb_release.php — парсер release-имён
 *
 * @see TmdbService
 * @see TmdbCron
 * @see TmdbPopularCron
 */

class TmdbModule implements ModuleInterface {

    /**
     * {@inheritdoc}
     */
    public function getName(): string {
        return 'tmdb';
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
        $container->set('tmdb.service', 'TmdbService');
    }

    /**
     * Регистрация маршрутов модуля
     *
     * TMDB не имеет собственных страниц — только API-действия.
     * Действия tmdb_search и tmdb пока остаются в admin/api.php
     * (делегируют в TmdbService) и будут перенесены при рефакторинге api.php.
     *
     * @param Router $router
     */
    public function registerRoutes(Router $router): void {
        // API-действия tmdb_search и tmdb остаются в api.php
        // до рефакторинга god-объекта (Шаг 5.2 ARCHITECTURE.md)
    }

    /**
     * CLI-команды модуля
     *
     * @param CommandRegistry $registry
     */
    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new TmdbCronJob());
        $registry->register(new TmdbPopularCronJob());
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
