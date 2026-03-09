<?php

/**
 * Player Routes
 *
 * Маршруты веб-плеера.
 * Файл подключается Front Controller'ом (index.php) при scope = 'player'.
 * Переменная $router (Router::getInstance()) доступна из вызывающего контекста.
 *
 * @see public/index.php
 * @see core/Http/Router.php
 */

// ─── Standard Pages (Controller + View) ────────────────────────

$router->get('index', [HomeController::class, 'index']);
$router->get('live', [LiveController::class, 'index']);
$router->get('movies', [MoviesController::class, 'index']);
$router->get('movie', [PlayerMovieController::class, 'index']);
$router->get('series', [SeriesController::class, 'index']);
$router->get('episodes', [EpisodesController::class, 'index']);
$router->any('profile', [PlayerProfileController::class, 'index']);

// ─── Special Pages (no view) ───────────────────────────────────

$router->get('logout', [LogoutController::class, 'index']);
$router->get('listings', [ListingsController::class, 'index']);

// ─── Auth (noBootstrapPages) ───────────────────────────────────

$router->get('login', [PlayerLoginController::class, 'index']);
$router->post('login', [PlayerLoginController::class, 'index']);

// ─── Binary/API (no HTML render) ───────────────────────────────

$router->get('proxy', [PlayerProxyController::class, 'index']);
$router->get('resize', [PlayerResizeController::class, 'index']);

// ─── Legacy Fallback ───────────────────────────────────────────
//
// All player pages are now routed through controllers.
// Static assets served from public/assets/player/ via nginx alias.
