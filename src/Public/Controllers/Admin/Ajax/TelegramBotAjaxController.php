<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Telegram\TelegramBotService;

/**
 * TelegramBotAjaxController — AJAX controller for Telegram Bot endpoints.
 *
 * Handles API actions:
 * - telegram_bot_test_token
 * - telegram_bot_test_chat
 * - telegram_bot_save
 * - telegram_bot_delete
 * - telegram_bot_toggle
 * - telegram_bot_broadcast_test
 * - telegram_bot_get
 * - telegram_bot_logs
 *
 * @package XC_VM_Public_Controllers_Admin_Ajax
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TelegramBotAjaxController extends BaseAjaxController
{
    /**
     * Action: telegram_bot_test_token
     * Verifies bot token with Telegram getMe API.
     */
    public function testToken(): never
    {
        $this->requireXhr();
        $token = trim((string)RequestManager::get('bot_token', ''));

        if ($token === '') {
            $this->fail(['message' => 'Please provide a bot token.']);
        }

        $res = TelegramBotService::verifyToken($token);
        if ($res['success']) {
            $this->ok($res);
        } else {
            $this->fail($res);
        }
    }

    /**
     * Action: telegram_bot_test_chat
     * Sends a test ping to target chat or channel.
     */
    public function testChat(): never
    {
        $this->requireXhr();
        $token = trim((string)RequestManager::get('bot_token', ''));
        $chatId = trim((string)RequestManager::get('chat_id', ''));
        $botName = trim((string)RequestManager::get('bot_name', 'XC_VM Bot'));

        if ($token === '' || $chatId === '') {
            $this->fail(['message' => 'Bot token and Chat/Channel ID are required.']);
        }

        $res = TelegramBotService::testChat($token, $chatId, $botName);
        if ($res['success']) {
            $this->ok($res);
        } else {
            $this->fail($res);
        }
    }

    /**
     * Action: telegram_bot_save
     * Creates or updates a Telegram bot.
     */
    public function save(): never
    {
        $this->requireXhr();
        $data = $_POST;

        $res = TelegramBotService::saveBot($data);
        if ($res['success']) {
            $this->ok($res);
        } else {
            $this->fail($res);
        }
    }

    /**
     * Action: telegram_bot_delete
     * Deletes a Telegram bot.
     */
    public function delete(): never
    {
        $this->requireXhr();
        $id = (int)RequestManager::get('id', 0);

        if ($id <= 0) {
            $this->fail(['message' => 'Invalid bot ID.']);
        }

        if (TelegramBotService::deleteBot($id)) {
            $this->ok(['message' => 'Bot deleted successfully.']);
        } else {
            $this->fail(['message' => 'Failed to delete bot.']);
        }
    }

    /**
     * Action: telegram_bot_toggle
     * Toggles bot active/paused state.
     */
    public function toggleStatus(): never
    {
        $this->requireXhr();
        $id = (int)RequestManager::get('id', 0);

        if ($id <= 0) {
            $this->fail(['message' => 'Invalid bot ID.']);
        }

        $res = TelegramBotService::toggleStatus($id);
        if ($res['success']) {
            $this->ok($res);
        } else {
            $this->fail($res);
        }
    }

    /**
     * Action: telegram_bot_broadcast_test
     * Sends a real movie broadcast to test poster and formatting.
     */
    public function broadcastTest(): never
    {
        $this->requireXhr();
        $botId = (int)RequestManager::get('bot_id', 0);
        $streamId = RequestManager::has('stream_id') ? (int)RequestManager::get('stream_id') : null;

        if ($botId <= 0) {
            $this->fail(['message' => 'Bot ID is required.']);
        }

        $res = TelegramBotService::broadcastTest($botId, $streamId);
        if ($res['success']) {
            $this->ok($res);
        } else {
            $this->fail($res);
        }
    }

    /**
     * Action: telegram_bot_get
     * Retrieves bot data as JSON.
     */
    public function get(): never
    {
        $this->requireXhr();
        $id = (int)RequestManager::get('id', 0);

        if ($id <= 0) {
            $this->fail(['message' => 'Invalid bot ID.']);
        }

        $bot = TelegramBotService::getBotById($id);
        if ($bot) {
            $this->ok(['bot' => $bot]);
        } else {
            $this->fail(['message' => 'Bot not found.']);
        }
    }

    /**
     * Action: telegram_bot_logs
     * Returns recent broadcast logs.
     */
    public function logs(): never
    {
        $this->requireXhr();
        $limit = RequestManager::has('limit') ? (int)RequestManager::get('limit') : 50;
        $logs = TelegramBotService::getRecentLogs($limit);

        $this->ok(['logs' => $logs]);
    }
}
