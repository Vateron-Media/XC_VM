<?php

namespace XcVm\Module\Telegram;

use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Events\ListensTo;
use XcVm\Core\Events\Vod\MediaAnalyzedEvent;
use XcVm\Core\Http\Router;
use XcVm\Core\Module\BaseModule;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;
use XcVm\Infrastructure\Database\DatabaseFactory;

/**
 * Telegram Module
 *
 * Automated Telegram broadcasting integration module.
 * Registers services, HTTP routes, AJAX API endpoints, event subscribers, and navigation entries.
 *
 * @package XC_VM_Module_Telegram
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TelegramModule extends BaseModule
{
    public function getName(): string
    {
        return 'telegram';
    }

    public function getVersion(): string
    {
        return '1.0.0';
    }

    /**
     * Boot module services into the DI container and wire database dependencies.
     */
    public function boot(ServiceContainer $container): void
    {
        $db = $container->get('db');
        TelegramBotService::setDb($db);
        TelegramNotifier::setDb($db);

        $container->set('telegram.service', TelegramBotService::class);
        $container->set('telegram.client', TelegramClient::class);
        $container->set('telegram.formatter', TelegramMessageFormatter::class);
        $container->set('telegram.notifier', TelegramNotifier::class);
        $container->set('telegram.controller', function () {
            return new TelegramController();
        });

        // Dynamic backward-compatibility class aliases for legacy callers
        if (!class_exists('XcVm\\Domain\\Telegram\\TelegramBotService', false)) {
            class_alias(TelegramBotService::class, 'XcVm\\Domain\\Telegram\\TelegramBotService');
            class_alias(TelegramClient::class, 'XcVm\\Domain\\Telegram\\TelegramClient');
            class_alias(TelegramMessageFormatter::class, 'XcVm\\Domain\\Telegram\\TelegramMessageFormatter');
            class_alias(TelegramNotifier::class, 'XcVm\\Domain\\Telegram\\TelegramNotifier');
        }
    }

    /**
     * Register module HTTP and API routes.
     */
    public function registerRoutes(Router $router): void
    {
        // Admin Page routes
        $router->get('telegram_bots', [TelegramController::class, 'index']);
        $router->get('telegram_bot',  [TelegramController::class, 'bot']);
        $router->get('telegram',      [TelegramController::class, 'index']);

        // AJAX API endpoints
        $router->api('telegram_bot_test_token',     [TelegramController::class, 'apiTestToken']);
        $router->api('telegram_bot_test_chat',      [TelegramController::class, 'apiTestChat']);
        $router->api('telegram_bot_save',           [TelegramController::class, 'apiSave']);
        $router->api('telegram_bot_delete',         [TelegramController::class, 'apiDelete']);
        $router->api('telegram_bot_toggle',         [TelegramController::class, 'apiToggle']);
        $router->api('telegram_bot_broadcast_test', [TelegramController::class, 'apiBroadcastTest']);
        $router->api('telegram_bot_get',            [TelegramController::class, 'apiGet']);
        $router->api('telegram_bot_logs',           [TelegramController::class, 'apiLogs']);
    }

    /**
     * Register sidebar menu and settings links.
     */
    public function registerNavbar(NavbarRegistry $registry): void
    {
        // Top-level Navigation Menu
        $registry->add((new NavbarItem('telegram'))
            ->url('#')->icon('ti tabler-brand-telegram')
            ->label('', 'Telegram Bots')->order(605));
        $registry->add((new NavbarItem('telegram.bots'))
            ->parent('telegram')->url('telegram_bots')
            ->label('', 'Manage Bots')->order(10));
        $registry->add((new NavbarItem('telegram.add'))
            ->parent('telegram')->url('telegram_bot')
            ->label('', 'Add Bot (Wizard)')->order(20));

        // Also integrate into Service Setup alongside Watch and Plex
        $registry->add((new NavbarItem('management.service_setup.telegram'))
            ->parent('management.service_setup')->url('telegram_bots')
            ->label('', 'Telegram Bots')->icon('ti tabler-brand-telegram')->order(80));
    }

    /**
     * React to media stream completion event from VodCronJob.
     */
    #[ListensTo(MediaAnalyzedEvent::class)]
    public function onMediaAnalyzed(MediaAnalyzedEvent $event): void
    {
        TelegramNotifier::onMediaAnalyzed($event->streamId, $event->type);
    }

    /**
     * Clean up module data on uninstallation.
     */
    public function uninstall(): void
    {
        $db = DatabaseFactory::get();
        if ($db === null) {
            return;
        }

        $db->query("DROP TABLE IF EXISTS `telegram_logs`;");
        $db->query("DROP TABLE IF EXISTS `telegram_bots`;");
    }
}
