<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Telegram\TelegramBotService;

/**
 * TelegramBotsController — Telegram Bots Management Overview.
 *
 * @renders Views/admin/telegram_bots.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TelegramBotsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Telegram Bots');

        $bots = TelegramBotService::getBots();
        $stats = TelegramBotService::getStats();
        $recentLogs = TelegramBotService::getRecentLogs(30);

        $this->render('telegram_bots', [
            'bots'       => $bots,
            'stats'      => $stats,
            'recentLogs' => $recentLogs,
        ]);
    }
}
