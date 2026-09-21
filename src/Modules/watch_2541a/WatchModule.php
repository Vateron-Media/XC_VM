<?php

namespace XcVm\Module\Watch;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Events\Bouquet\BouquetDeletedEvent;
use XcVm\Core\Events\ListensTo;
use XcVm\Core\Events\Stream\StreamsDeletedEvent;
use XcVm\Core\Events\Vod\VodImportedEvent;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Core\Module\PermissionRegistry;
use XcVm\Core\Module\QuickToolsRegistry;
use XcVm\Core\Module\TableRegistry;
use XcVm\Core\Module\TopbarRegistry;

/**
 * Watch Module
 *
 * Модуль Watch Folder / Recording.
 * Регистрирует сервисы, маршруты, API-действия и крон-задачи.
 *
 * ──────────────────────────────────────────────────────────────────
 * Что включает:
 * ──────────────────────────────────────────────────────────────────
 *
 *   Сервисы:
 *     - WatchService    — CRUD Watch Folder'ов, настройки, enable/disable/kill
 *     - RecordingService — планирование записей (DVR)
 *
 *   Контроллер:
 *     - WatchController — обработка HTTP-запросов и API
 *
 *   Страницы:
 *     - watch          — список folder'ов
 *     - watch/add      — добавление/редактирование
 *     - watch/settings — настройки watch (settings_watch)
 *     - watch/output   — логи (watch_output)
 *     - watch/record   — планирование записи (record)
 *
 *   API-действия:
 *     - enable_watch   — включить все folder'ы
 *     - disable_watch  — отключить все folder'ы
 *     - kill_watch     — убить процессы
 *     - folder         — удалить/запустить folder
 *
 * @see WatchService
 * @see RecordingService
 * @see WatchController
 *
 * @package XC_VM_Module_Watch
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class WatchModule extends BaseModule {

    public function getName(): string {
        return 'watch';
    }

    public function getVersion(): string {
        return '1.0.5';
    }

    /**
     * Clean up watch folders when a bouquet is deleted (core fires the event).
     */
    #[ListensTo(BouquetDeletedEvent::class)]
    public function onBouquetDeleted(BouquetDeletedEvent $rEvent): void {
        WatchService::handleBouquetDeleted($rEvent->bouquetId);
    }

    /**
     * Clean up watch scan logs/refresh rows when streams are deleted.
     */
    #[ListensTo(StreamsDeletedEvent::class)]
    public function onStreamsDeleted(StreamsDeletedEvent $rEvent): void {
        WatchService::handleStreamsDeleted($rEvent->streamIds);
    }

    /**
     * Mark a watch-folder log row imported when core creates a VOD item from a
     * path (core dispatches VodImportedEvent instead of touching watch_logs).
     */
    #[ListensTo(VodImportedEvent::class)]
    public function onVodImported(VodImportedEvent $rEvent): void {
        WatchService::markImported($rEvent->streamId, $rEvent->sourcePath, $rEvent->type);
    }

    public function boot(ServiceContainer $container): void {
        $db = $container->get('db');
        WatchService::setDb($db);
        RecordingService::setDb($db);
        WatchCron::setDb($db);
        WatchItem::setDb($db);

        $container->set('watch.service', 'WatchService');
        $container->set('watch.recording', 'RecordingService');
        $container->set('watch.controller', function ($c) {
            return new WatchController();
        });
    }

    public function registerRoutes(Router $router): void {
        $router->group('watch', function (Router $r) {
            $r->get('', [WatchController::class, 'index'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
            $r->get('add', [WatchController::class, 'add'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
            $r->get('output', [WatchController::class, 'output'], [
                'permission' => ['adv', 'folder_watch'],
            ]);
        });

        $router->get('settings/watch', [WatchController::class, 'settings'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);

        $router->api('enable_watch', [WatchController::class, 'apiEnable'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
        $router->api('disable_watch', [WatchController::class, 'apiDisable'], [
            'permission' => ['adv', 'folder_watch_settings'],
        ]);
        $router->api('kill_watch', [WatchController::class, 'apiKill'], [
            'permission' => ['adv', 'folder_watch'],
        ]);
        $router->api('folder', [WatchController::class, 'apiFolder'], [
            'permission' => ['adv', 'folder_watch'],
        ]);
        // watch_logs table maintenance — owned by this module (moved out of core
        // MiscAjaxController / BackupAjaxController).
        $router->api('watch_output', [WatchController::class, 'apiWatchOutput'], [
            'permission' => ['adv', 'folder_watch_output'],
        ]);
        $router->api('watch_clear_logs', [WatchController::class, 'apiClearLogs'], [
            'permission' => ['adv', 'folder_watch_output'],
        ]);
    }

    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new WatchCronJob());
        $registry->register(new WatchItemCommand());
    }

    public function registerNavbar(NavbarRegistry $registry): void {
        // Profile dropdown: core registers items under the 'profile' parent
        // (CoreNavbarProvider::_profile), reserving order 100–980 for modules.
        // watch owns the divider that separates core items from the folder
        // settings group (watch + the plex dependency); orphan dividers are
        // collapsed by NavbarRegistry::collapseDividers() when hidden by perms.
        $registry->add((new NavbarItem('profile.folder_divider'))
            ->parent('profile')->makeDivider()->order(100));
        $registry->add((new NavbarItem('profile.watch_settings'))
            ->parent('profile')->url('settings_watch')
            ->label('watch_settings')->permissions(['folder_watch_settings'])->order(110));
        $registry->add((new NavbarItem('management.service_setup.watch'))
            ->parent('management.service_setup')->url('watch')
            ->label('folder_watch')->permissions(['folder_watch'])->order(60));
        // Logs moved to their own top-level tab (core _logs()); folder-watch
        // output is an operational log, so it lives under the System logs group.
        $registry->add((new NavbarItem('logs.system.watch_output'))
            ->parent('logs.system')->url('watch_output')
            ->label('watch_folder_logs')->permissions(['folder_watch'])->order(50));
    }

    /**
     * Per-page topbar buttons owned by this module.
     *
     * add($page, $label, $url, $permission, $attr, $order). The module both
     * defines its OWN pages (watch, watch_add, settings_watch, watch_output)
     * and injects buttons into existing CORE pages (movies, series, settings,
     * backups, cache). Core no longer hard-codes any of these.
     */
    public function registerTopbar(TopbarRegistry $registry): void {
        // Own page: Watch Folders list.
        $registry->add('watch', 'Add Folder', 'watch_add', 'folder_watch_add', null, 10);
        $registry->add('watch', 'Settings', 'settings_watch', 'folder_watch_settings', null, 20);
        $registry->add('watch', 'Watch Output Logs', 'watch_output', 'folder_watch_output', null, 30);
        $registry->add('watch', 'Kill Running', null, 'folder_watch_settings', 'onClick="killWatchFolder();"', 40);
        $registry->add('watch', 'Enable All', null, 'folder_watch_settings', 'onClick="enableAll();"', 50);
        $registry->add('watch', 'Disable All', null, 'folder_watch_settings', 'onClick="disableAll();"', 60);

        // Own page: add/edit a folder.
        $registry->add('watch_add', 'Manage Folders', 'watch', 'folder_watch', null, 10);

        // Own page: watch settings.
        $registry->add('settings_watch', 'Folders', 'watch', 'folder_watch', null, 10);
        $registry->add('settings_watch', 'General Settings', 'settings', 'settings', null, 20);
        $registry->add('settings_watch', 'Backup Settings', 'backups', 'database', null, 30);
        $registry->add('settings_watch', 'Watch Folder Logs', 'watch_output', 'folder_watch_output', null, 40);

        // Own page: watch output logs. Declare it as an export/log page so the
        // Export CSV/JSON gate and the Clear-Logs type come from the module, not
        // from core's EXPORT_PAGES / LOG_TYPES lists.
        $registry->markExportPage('watch_output');
        $registry->setLogType('watch_output', 'watch_logs');
        $registry->add('watch_output', 'Export as CSV', null, null, 'id="btn-export-csv"', 10);
        $registry->add('watch_output', 'Export as JSON', null, null, 'id="btn-export-json"', 20);
        $registry->add('watch_output', 'Clear Logs', null, null, 'id="btn-clear-logs"', 30);
        $registry->add('watch_output', 'Watch Folder', 'watch', 'folder_watch', null, 40);

        // Inject into existing core content pages.
        $registry->add('movies', 'Watch Folder', 'watch', 'folder_watch', null, 200);
        $registry->add('movies', 'Watch Output Logs', 'watch_output', 'folder_watch_output', null, 210);
        $registry->add('series', 'Watch Folder', 'watch', 'folder_watch', null, 200);
        $registry->add('series', 'Watch Output Logs', 'watch_output', 'folder_watch_output', null, 210);

        // Inject the Watch Settings link into the core settings pages.
        $registry->add('settings', 'Watch Settings', 'settings_watch', 'folder_watch_settings', null, 200);
        $registry->add('backups', 'Watch Settings', 'settings_watch', 'folder_watch_settings', null, 200);
        $registry->add('cache', 'Watch Settings', 'settings_watch', 'folder_watch_settings', null, 200);
    }

    /**
     * serverSide DataTable handlers this module owns. The 'watch_output' log
     * table used to live as a case in core TableController; it is now built by
     * WatchController::tableWatchOutput and looked up via the TableRegistry.
     */
    public function registerTables(TableRegistry $registry): void {
        $registry->register('watch_output', [WatchController::class, 'tableWatchOutput']);
    }

    /**
     * Reseller sub-permission keys this module gates on (moved out of core
     * PermissionReference). Shared with the dependent plex module. Labels come
     * from the Translator as `permission_<key>` / `permission_<key>_text`.
     */
    public function registerPermissions(PermissionRegistry $registry): void {
        $registry->add('folder_watch');
        $registry->add('folder_watch_settings');
        $registry->add('folder_watch_add');
        $registry->add('folder_watch_output');
    }

    /**
     * One-shot Quick Tools actions this module owns (moved out of core
     * quick_tools.php / post.php). Adds "Clear Watch Logs" to the Logs tab.
     */
    public function registerQuickTools(QuickToolsRegistry $registry): void {
        $registry->add('logs', 'clear_watch_logs', 'clear_watch_logs', static function (): void {
            WatchService::clearAllLogs();
        });
    }
}
