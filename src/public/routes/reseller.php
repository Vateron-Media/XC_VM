<?php

/**
 * Reseller Routes
 *
 * Маршруты панели реселлера.
 * Файл подключается Front Controller'ом (index.php) при scope = 'reseller'.
 * Переменная $router (Router::getInstance()) доступна из вызывающего контекста.
 *
 * @see public/index.php
 * @see core/Http/Router.php
 */

// ─── Dashboard & Profile (Phase 6.4) ───────────────────────────

$router->get('dashboard', [ResellerDashboardController::class, 'index']);
$router->get('edit_profile', [ResellerEditProfileController::class, 'index']);
$router->get('logout', [ResellerLogoutController::class, 'index']);
$router->get('session', [ResellerSessionController::class, 'index']);

// ─── Auth & Infrastructure ─────────────────────────────────────

$router->get('login', [ResellerLoginController::class, 'index']);
$router->post('login', [ResellerLoginController::class, 'index']);
$router->get('index', [ResellerLoginController::class, 'index']);
$router->post('post', [ResellerPostController::class, 'index']);
$router->get('api', [ResellerApiController::class, 'index']);
$router->post('api', [ResellerApiController::class, 'index']);
$router->get('table', [ResellerTableController::class, 'index']);
$router->post('table', [ResellerTableController::class, 'index']);
$router->get('resize', [ResellerResizeController::class, 'index']);

// ─── Lines (Phase 6.4) ─────────────────────────────────────────

$router->get('lines', [ResellerLinesController::class, 'index']);
$router->get('line', [ResellerLineController::class, 'index']);
$router->get('line_activity', [ResellerLineActivityController::class, 'index']);
$router->get('live_connections', [ResellerLiveConnectionsController::class, 'index']);

// ─── Devices MAG / Enigma (Phase 6.4) ──────────────────────────

$router->get('mags', [ResellerMagsController::class, 'index']);
$router->get('mag', [ResellerMagController::class, 'index']);
$router->get('enigmas', [ResellerEnigmasController::class, 'index']);
$router->get('enigma', [ResellerEnigmaController::class, 'index']);

// ─── Content (Phase 6.4) ───────────────────────────────────────

$router->get('streams', [ResellerStreamsController::class, 'index']);
$router->get('movies', [ResellerMoviesController::class, 'index']);
$router->get('radios', [ResellerRadiosController::class, 'index']);
$router->get('episodes', [ResellerEpisodesController::class, 'index']);
$router->get('created_channels', [ResellerCreatedChannelsController::class, 'index']);
$router->get('epg_view', [ResellerEpgViewController::class, 'index']);

// ─── Tickets (Phase 6.4) ───────────────────────────────────────

$router->get('tickets', [ResellerTicketsController::class, 'index']);
$router->get('ticket', [ResellerTicketController::class, 'index']);
$router->get('ticket_view', [ResellerTicketViewController::class, 'index']);

// ─── Users (Phase 6.4) ─────────────────────────────────────────

$router->get('users', [ResellerUsersController::class, 'index']);
$router->get('user', [ResellerUserController::class, 'index']);
$router->get('user_logs', [ResellerUserLogsController::class, 'index']);
