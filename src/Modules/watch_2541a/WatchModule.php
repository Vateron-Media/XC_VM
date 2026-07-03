<?php

namespace XcVm\Module\Watch;

use XcVm\Cli\CommandRegistry;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Events\Bouquet\BouquetDeletedEvent;
use XcVm\Core\Events\ListensTo;
use XcVm\Core\Events\Stream\StreamsDeletedEvent;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;

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
        return '1.0.2';
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
    }

    public function registerCommands(CommandRegistry $registry): void {
        $registry->register(new WatchCronJob());
        $registry->register(new WatchItemCommand());
    }

    public function registerNavbar(NavbarRegistry $registry): void {
        $registry->add((new NavbarItem('topbar.settings.divider_modules'))
            ->parent('topbar.settings')->makeDivider()->order(45));
        $registry->add((new NavbarItem('topbar.settings.watch_settings'))
            ->parent('topbar.settings')->url('settings_watch')
            ->label('watch_settings')->permissions(['folder_watch_settings'])->order(50));
        $registry->add((new NavbarItem('management.service_setup.watch'))
            ->parent('management.service_setup')->url('watch')
            ->label('folder_watch')->permissions(['folder_watch'])->order(60));
        $registry->add((new NavbarItem('management.logs.watch_output'))
            ->parent('management.logs')->url('watch_output')
            ->label('watch_folder_logs')->permissions(['folder_watch'])->order(170));
    }
}
