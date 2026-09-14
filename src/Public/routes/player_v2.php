<?php

use XcVm\Public\Controllers\Player\ListingsController;
use XcVm\Public\Controllers\Player\PlayerProxyController;
use XcVm\Public\Controllers\Player\PlayerResizeController;
use XcVm\Public\Controllers\PlayerV2\FavoritesController;
use XcVm\Public\Controllers\PlayerV2\HomeController;
use XcVm\Public\Controllers\PlayerV2\LiveController;
use XcVm\Public\Controllers\PlayerV2\MoviesController;
use XcVm\Public\Controllers\PlayerV2\PlayerLoginController;
use XcVm\Public\Controllers\PlayerV2\PlayerLogoutController;
use XcVm\Public\Controllers\PlayerV2\PlayerMovieController;
use XcVm\Public\Controllers\PlayerV2\PlayerWatchController;
use XcVm\Public\Controllers\PlayerV2\ProfileController;
use XcVm\Public\Controllers\PlayerV2\RadioController;
use XcVm\Public\Controllers\PlayerV2\RefreshController;
use XcVm\Public\Controllers\PlayerV2\SearchController;
use XcVm\Public\Controllers\PlayerV2\SeriesController;

/**
 * Web Player V2 Routes
 *
 * Route definitions for Web Player Next-Gen (V2).
 * Loaded by the Front Controller (index.php) when $scope === 'player_v2'.
 * The $router (Router::getInstance()) variable is available in this scope.
 *
 * @package XC_VM_Public_Routes
 */

// ─── Standard Pages ─────────────────────────────────────────────
$router->get('index', [HomeController::class, 'index']);
$router->get('live', [LiveController::class, 'index']);
$router->get('movies', [MoviesController::class, 'index']);
$router->get('movie', [PlayerMovieController::class, 'index']);
$router->get('series', [SeriesController::class, 'index']);
$router->get('series_detail', [SeriesController::class, 'index']);
$router->get('episodes', [SeriesController::class, 'index']);
$router->get('radio', [RadioController::class, 'index']);
$router->get('player', [PlayerWatchController::class, 'index']);
$router->get('watch', [PlayerWatchController::class, 'index']);
$router->get('profile', [ProfileController::class, 'index']);
$router->post('profile', [ProfileController::class, 'saveBouquets']);
$router->get('refresh', [RefreshController::class, 'index']);
$router->post('refresh', [RefreshController::class, 'index']);
$router->get('search', [SearchController::class, 'index']);
$router->post('search', [SearchController::class, 'index']);
$router->get('favorites', [FavoritesController::class, 'index']);
$router->post('favorites', [FavoritesController::class, 'index']);

// ─── Auth (noBootstrapPages) ────────────────────────────────────
$router->get('login', [PlayerLoginController::class, 'index']);
$router->post('login', [PlayerLoginController::class, 'index']);
$router->get('logout', [PlayerLogoutController::class, 'index']);
$router->post('logout', [PlayerLogoutController::class, 'index']);

// ─── Shared Utilities ───────────────────────────────────────────
$router->get('listings', [ListingsController::class, 'index']);
$router->get('proxy', [PlayerProxyController::class, 'index']);
$router->get('resize', [PlayerResizeController::class, 'index']);

