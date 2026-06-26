<?php

namespace XcVm\Module\Tmdb;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;

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
 *     - TmdbApiService    — поиск, получение деталей фильмов/сериалов
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
 * @see TmdbApiService
 * @see TmdbCron
 * @see TmdbPopularCron
 *
 * @package XC_VM_Module_Tmdb
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TmdbModule extends BaseModule {

    public function getName(): string {
        return 'tmdb';
    }

    public function getVersion(): string {
        return '1.0.0';
    }

    public function boot(ServiceContainer $container): void {
        $db = $container->get('db');
        TmdbCron::setDb($db);
        TmdbPopularCron::setDb($db);

        $container->set('tmdb.service', 'TmdbApiService');
    }

    public function registerRoutes(Router $router): void {
        $router->api('tmdb_search', [TmdbController::class, 'search']);
        $router->api('tmdb',        [TmdbController::class, 'details']);
    }

    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new TmdbCronJob());
        $registry->register(new TmdbPopularCronJob());
    }
}
