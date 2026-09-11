<?php

namespace XcVm\Module\Telegram;

use XcVm\Core\Auth\PageAuthorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;

/**
 * Telegram Module Controller
 *
 * Handles admin page views and AJAX API requests for the Telegram Bot Module.
 * - Overview / List: index() (route: telegram_bots)
 * - 4-step Wizard:   bot()   (route: telegram_bot)
 * - AJAX actions:    apiTestToken, apiTestChat, apiSave, apiDelete,
 *                    apiToggle, apiBroadcastTest, apiGet, apiLogs
 *
 * @package XC_VM_Module_Telegram
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TelegramController
{
    /** @var string Path to module views */
    protected string $viewsPath;

    /** @var string Path to admin layouts */
    protected string $layoutsPath;

    public function __construct()
    {
        $this->viewsPath = __DIR__ . '/views';
        $this->layoutsPath = MAIN_HOME . 'Public/Views/layouts/';
        require_once $this->layoutsPath . 'admin.php';
        require_once $this->layoutsPath . 'footer.php';
    }

    // ───────────────────────────────────────────────────────────
    //  Pages (GET)
    // ───────────────────────────────────────────────────────────

    /**
     * Overview list of all bots, KPI cards, recent logs.
     */
    public function index(): void
    {
        global $rMobile, $rSettings;
        $language = \XcVm\Core\Localization\Translator::class;
        $_TITLE = 'Telegram Bots';

        $bots = TelegramBotService::getBots();
        $stats = TelegramBotService::getStats();
        $recentLogs = TelegramBotService::getRecentLogs(30);

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/telegram_bots.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/telegram_bots_scripts.php';
    }

    /**
     * 4-step wizard to create or edit a Telegram bot.
     */
    public function bot(): void
    {
        global $rMobile, $rSettings;
        $language = \XcVm\Core\Localization\Translator::class;

        $id = RequestManager::has('id') ? (int)RequestManager::get('id') : 0;
        $bot = null;

        if ($id > 0) {
            $bot = TelegramBotService::getBotById($id);
            if (!$bot) {
                header('Location: telegram_bots');
                exit();
            }
            $_TITLE = 'Edit Telegram Bot: ' . $bot['name'];
        } else {
            $_TITLE = 'Add Telegram Bot';
        }

        $categories = TelegramBotService::getCategoriesForFilter();
        $isEdit = ($bot !== null);

        renderUnifiedLayoutHeader('admin', ['_TITLE' => $_TITLE]);
        include $this->viewsPath . '/telegram_bot.php';
        renderUnifiedLayoutFooter('admin');
        include $this->viewsPath . '/telegram_bot_scripts.php';
    }

    // ───────────────────────────────────────────────────────────
    //  AJAX API Actions (JSON)
    // ───────────────────────────────────────────────────────────

    protected function json(array $data): never
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }
        echo json_encode($data);
        exit();
    }

    protected function ok(array $extra = []): never
    {
        $this->json(['result' => true] + $extra);
    }

    protected function fail(array $extra = []): never
    {
        $this->json(['result' => false] + $extra);
    }

    /**
     * Action: telegram_bot_test_token
     * Verifies bot token with Telegram getMe API.
     */
    public function apiTestToken(): never
    {
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
    public function apiTestChat(): never
    {
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
    public function apiSave(): never
    {
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
    public function apiDelete(): never
    {
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
    public function apiToggle(): never
    {
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
    public function apiBroadcastTest(): never
    {
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
    public function apiGet(): never
    {
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
    public function apiLogs(): never
    {
        $limit = RequestManager::has('limit') ? (int)RequestManager::get('limit') : 50;
        $logs = TelegramBotService::getRecentLogs($limit);

        $this->ok(['logs' => $logs]);
    }
}
