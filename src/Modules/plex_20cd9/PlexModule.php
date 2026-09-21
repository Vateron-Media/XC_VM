<?php

namespace XcVm\Module\Plex;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Module\TopbarRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Plex Module
 *
 * Модуль Plex Sync Integration.
 * Регистрирует сервисы, маршруты, API-действия и крон-задачи.
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Сервисы:
 *     - PlexService     — CRUD Plex Sync, настройки, force
 *     - PlexRepository  — получение Plex серверов и секций
 *     - PlexAuth        — аутентификация Plex (getToken, checkToken)
 *     - PlexCron        — крон синхронизации
 *     - PlexItem        — CLI обработка элементов
 *
 *   Контроллер:
 *     - PlexController  — обработка HTTP-запросов и API
 *
 *   Страницы:
 *     - plex            — список Plex серверов
 *     - plex/add        — добавление/редактирование библиотеки
 *     - plex/settings   — настройки Plex (settings_plex)
 *
 *   API-действия:
 *     - enable_plex     — включить все серверы
 *     - disable_plex    — отключить все серверы
 *     - kill_plex       — убить процессы
 *     - library         — удалить/запустить библиотеку
 *     - plex_sections   — получить секции Plex-сервера
 *
 * @see PlexService
 * @see PlexRepository
 * @see PlexAuth
 * @see PlexController
 *
 * @package XC_VM_Module_Plex
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PlexModule extends BaseModule {

    public function getName(): string {
        return 'plex';
    }

    public function getVersion(): string {
        return '1.0.2';
    }

    /**
     * Remove plex-owned data.
     *
     * Plex declares a dependency on the watch module and REUSES its tables
     * (watch_folders rows with type='plex', plus the movie/show categories in
     * watch_categories). It therefore owns no tables of its own and drops none
     * on uninstall — it deletes only the rows it created. The table-existence
     * guards keep this safe even if watch has somehow already gone.
     */
    public function uninstall(): void {
        $rDb = DatabaseFactory::get();
        if ($rDb === null) {
            return;
        }
        if (self::tableExists($rDb, 'watch_folders')) {
            $rDb->query("DELETE FROM `watch_folders` WHERE `type` = 'plex';");
        }
        if (self::tableExists($rDb, 'watch_categories')) {
            // Plex owns the movie (3) and show (4) category types in the shared table.
            $rDb->query("DELETE FROM `watch_categories` WHERE `type` IN (3, 4);");
        }
    }

    /**
     * @param object $rDb    Database handler.
     * @param string $rTable Table name.
     */
    private static function tableExists($rDb, string $rTable): bool {
        $rDb->query("SHOW TABLES LIKE '" . $rTable . "';");
        return method_exists($rDb, 'num_rows') ? ($rDb->num_rows() > 0) : true;
    }

    public function boot(ServiceContainer $container): void {
        $db = $container->get('db');
        PlexService::setDb($db);
        PlexRepository::setDb($db);
        PlexCron::setDb($db);
        PlexItem::setDb($db);

        $container->set('plex.service', 'PlexService');
        $container->set('plex.repository', 'PlexRepository');
        $container->set('plex.auth', 'PlexAuth');
        $container->set('plex.controller', function ($c) {
            return new PlexController();
        });
    }

    public function registerRoutes(Router $router): void {
        $router->group('plex', function (Router $r) {
            $r->get('', [PlexController::class, 'index'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
            $r->get('add', [PlexController::class, 'add'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
        });

        $router->get('settings/plex', [PlexController::class, 'settings'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);

        $router->api('enable_plex', [PlexController::class, 'apiEnable'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
        $router->api('disable_plex', [PlexController::class, 'apiDisable'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
        $router->api('kill_plex', [PlexController::class, 'apiKill'], [
            'permission' => ['adv', 'folder_watch'],
        ]);
        $router->api('library', [PlexController::class, 'apiLibrary'], [
            'permission' => ['adv', 'folder_watch'],
        ]);
        $router->api('plex_sections', [PlexController::class, 'apiSections'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
    }

    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new PlexCronJob());
        $registry->register(new PlexItemCommand());
    }

    public function registerNavbar(NavbarRegistry $registry): void {
        // Profile dropdown: core registers items under the 'profile' parent
        // (CoreNavbarProvider::_profile), reserving order 100–980 for modules.
        // The 'watch' dependency owns the folder-settings divider at order 100.
        $registry->add((new NavbarItem('profile.plex_settings'))
            ->parent('profile')->url('settings_plex')
            ->label('plex_settings')->permissions(['folder_watch_settings'])->order(120));
        $registry->add((new NavbarItem('management.service_setup.plex'))
            ->parent('management.service_setup')->url('plex')
            ->label('', 'Plex Sync')->permissions(['folder_watch'])->order(70));
    }

    /**
     * Per-page topbar buttons owned by this module.
     *
     * Defines its own pages (plex, plex_add, settings_plex) and — because plex
     * depends on watch — also injects a "Plex Settings" cross-link into the
     * watch/settings/backups/cache pages. Core no longer hard-codes any of these.
     */
    public function registerTopbar(TopbarRegistry $registry): void {
        // Own page: Plex libraries list.
        $registry->add('plex', 'Add Library', 'plex_add', 'folder_watch_add', null, 10);
        $registry->add('plex', 'Settings', 'settings_plex', 'folder_watch_settings', null, 20);
        $registry->add('plex', 'Watch Folder Logs', 'watch_output', 'folder_watch_output', null, 30);
        $registry->add('plex', 'Kill Running', null, 'folder_watch_settings', 'onClick="killPlexSync();"', 40);
        $registry->add('plex', 'Enable All', null, 'folder_watch_settings', 'onClick="enableAll();"', 50);
        $registry->add('plex', 'Disable All', null, 'folder_watch_settings', 'onClick="disableAll();"', 60);

        // Own page: add/edit a library.
        $registry->add('plex_add', 'Manage Libraries', 'plex', 'folder_watch', null, 10);

        // Own page: plex settings.
        $registry->add('settings_plex', 'Libraries', 'plex', 'folder_watch', null, 10);
        $registry->add('settings_plex', 'General Settings', 'settings', 'settings', null, 20);
        $registry->add('settings_plex', 'Backup Settings', 'backups', 'database', null, 30);
        $registry->add('settings_plex', 'Watch Settings', 'settings_watch', 'folder_watch_settings', null, 40);
        $registry->add('settings_plex', 'Watch Folder Logs', 'watch_output', 'folder_watch_output', null, 50);

        // Cross-link into the watch settings page and the core settings pages.
        $registry->add('settings_watch', 'Plex Settings', 'settings_plex', 'folder_watch_settings', null, 50);
        $registry->add('settings', 'Plex Settings', 'settings_plex', 'folder_watch_settings', null, 210);
        $registry->add('backups', 'Plex Settings', 'settings_plex', 'folder_watch_settings', null, 210);
        $registry->add('cache', 'Plex Settings', 'settings_plex', 'folder_watch_settings', null, 210);
    }
}
