<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Telegram\TelegramBotService;

/**
 * TelegramBotController — Multi-step Wizard for Adding/Editing Telegram Bots.
 *
 * @renders Views/admin/telegram_bot.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TelegramBotController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $id = RequestManager::has('id') ? (int)RequestManager::get('id') : 0;
        $bot = null;

        if ($id > 0) {
            $bot = TelegramBotService::getBotById($id);
            if (!$bot) {
                header('Location: telegram_bots');
                exit;
            }
            $this->setTitle('Edit Telegram Bot: ' . $bot['name']);
        } else {
            $this->setTitle('Add Telegram Bot');
        }

        $categories = TelegramBotService::getCategoriesForFilter();

        $this->render('telegram_bot', [
            'bot'        => $bot,
            'isEdit'     => ($bot !== null),
            'categories' => $categories,
        ]);
    }
}
