<?php

namespace XcVm\Module\Telegram;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * TelegramBotService — Business logic for Telegram Bots management.
 *
 * Provides CRUD operations, token validation, channel test pings,
 * category filtering, real movie test broadcasts, and logging.
 *
 * @package XC_VM_Module_Telegram
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TelegramBotService
{
    use DatabaseAware;

    /**
     * Retrieve all configured Telegram bots.
     *
     * @return array<int, array>
     */
    public static function getBots(): array
    {
        $db = self::db();
        $db->query('SELECT * FROM `telegram_bots` ORDER BY `id` DESC;');
        $rows = $db->get_rows() ?: [];

        foreach ($rows as &$row) {
            $row['categories_array'] = !empty($row['categories'])
                ? (json_decode($row['categories'], true) ?: [])
                : [];
            $row['content_types_array'] = !empty($row['content_types'])
                ? explode(',', (string)$row['content_types'])
                : ['movies'];
        }
        unset($row);

        return $rows;
    }

    /**
     * Get a single bot by ID.
     *
     * @param int $id Bot ID
     * @return array|null
     */
    public static function getBotById(int $id): ?array
    {
        if ($id <= 0) {
            return null;
        }

        $db = self::db();
        $db->query('SELECT * FROM `telegram_bots` WHERE `id` = ? LIMIT 1;', $id);
        if ($db->num_rows() <= 0) {
            return null;
        }

        $row = $db->get_row();
        $row['categories_array'] = !empty($row['categories'])
            ? (json_decode($row['categories'], true) ?: [])
            : [];
        $row['content_types_array'] = !empty($row['content_types'])
            ? explode(',', (string)$row['content_types'])
            : ['movies'];

        return $row;
    }

    /**
     * Create or update a Telegram bot.
     *
     * @param array $data Form data from the multi-step wizard
     * @return array{success: bool, id?: int, message: string}
     */
    public static function saveBot(array $data): array
    {
        $db = self::db();

        $id = !empty($data['id']) ? (int)$data['id'] : 0;
        $name = trim((string)($data['name'] ?? ''));
        $token = trim((string)($data['bot_token'] ?? ''));
        $chatId = trim((string)($data['chat_id'] ?? ''));

        if ($name === '') {
            return ['success' => false, 'message' => 'Bot name is required.'];
        }
        if ($token === '') {
            return ['success' => false, 'message' => 'Telegram bot token is required.'];
        }
        if ($chatId === '') {
            return ['success' => false, 'message' => 'Target channel or chat ID is required.'];
        }

        // Verify token against Telegram if changed or on creation
        $tokenCheck = TelegramClient::getMe($token);
        if (!$tokenCheck['ok']) {
            return ['success' => false, 'message' => 'Invalid Telegram Bot Token: ' . ($tokenCheck['error'] ?? 'API error')];
        }
        $botUsername = $tokenCheck['result']['username'] ?? null;

        // Content types
        $contentTypes = [];
        if (!empty($data['type_movies'])) $contentTypes[] = 'movies';
        if (!empty($data['type_episodes'])) $contentTypes[] = 'episodes';
        if (!empty($data['type_live'])) $contentTypes[] = 'live';
        if (empty($contentTypes)) {
            $contentTypes[] = 'movies'; // Default to movies
        }
        $contentTypesStr = implode(',', $contentTypes);

        // Categories filter: empty array or null = all categories
        $categoriesJson = null;
        if (!empty($data['categories_mode']) && $data['categories_mode'] === 'custom') {
            $cats = $data['categories'] ?? [];
            if (is_array($cats)) {
                $categoriesJson = json_encode(array_map('intval', $cats));
            }
        }

        $imageType = in_array($data['image_type'] ?? '', ['poster', 'backdrop', 'none'], true)
            ? $data['image_type']
            : 'poster';

        $notifyMovie = !empty($data['notify_on_movie_complete']) || in_array('movies', $contentTypes, true) ? 1 : 0;
        $notifyEpisode = !empty($data['notify_on_episode']) || in_array('episodes', $contentTypes, true) ? 1 : 0;
        $notifyLive = !empty($data['notify_on_live']) || in_array('live', $contentTypes, true) ? 1 : 0;

        $customTemplate = !empty($data['custom_template']) ? trim((string)$data['custom_template']) : null;
        $silent = !empty($data['silent_notification']) ? 1 : 0;
        $status = isset($data['status']) ? ((int)$data['status'] ? 1 : 0) : 1;

        $includeButton = !empty($data['include_button']) ? 1 : 0;
        $buttonText = !empty($data['button_text']) ? trim((string)$data['button_text']) : null;
        $buttonUrl = !empty($data['button_url']) ? trim((string)$data['button_url']) : null;

        $chatTitle = !empty($data['chat_title']) ? trim((string)$data['chat_title']) : null;
        $now = date('Y-m-d H:i:s');

        if ($id > 0) {
            $db->query(
                'UPDATE `telegram_bots` SET
                    `name` = ?,
                    `bot_token` = ?,
                    `bot_username` = ?,
                    `chat_id` = ?,
                    `chat_title` = ?,
                    `content_types` = ?,
                    `categories` = ?,
                    `image_type` = ?,
                    `notify_on_movie_complete` = ?,
                    `notify_on_episode` = ?,
                    `notify_on_live` = ?,
                    `custom_template` = ?,
                    `silent_notification` = ?,
                    `include_button` = ?,
                    `button_text` = ?,
                    `button_url` = ?,
                    `status` = ?,
                    `updated_at` = ?
                WHERE `id` = ?;',
                $name, $token, $botUsername, $chatId, $chatTitle,
                $contentTypesStr, $categoriesJson, $imageType,
                $notifyMovie, $notifyEpisode, $notifyLive,
                $customTemplate, $silent,
                $includeButton, $buttonText, $buttonUrl,
                $status, $now, $id
            );
            return ['success' => true, 'id' => $id, 'message' => 'Bot updated successfully.'];
        } else {
            $db->query(
                'INSERT INTO `telegram_bots` (
                    `name`, `bot_token`, `bot_username`, `chat_id`, `chat_title`,
                    `content_types`, `categories`, `image_type`,
                    `notify_on_movie_complete`, `notify_on_episode`, `notify_on_live`,
                    `custom_template`, `silent_notification`,
                    `include_button`, `button_text`, `button_url`,
                    `status`, `created_at`, `updated_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?);',
                $name, $token, $botUsername, $chatId, $chatTitle,
                $contentTypesStr, $categoriesJson, $imageType,
                $notifyMovie, $notifyEpisode, $notifyLive,
                $customTemplate, $silent,
                $includeButton, $buttonText, $buttonUrl,
                $status, $now, $now
            );
            $newId = (int)$db->last_insert_id();
            return ['success' => true, 'id' => $newId, 'message' => 'Bot created successfully.'];
        }
    }

    /**
     * Delete a bot and its logs.
     *
     * @param int $id Bot ID
     * @return bool
     */
    public static function deleteBot(int $id): bool
    {
        if ($id <= 0) {
            return false;
        }

        $db = self::db();
        $db->query('DELETE FROM `telegram_bots` WHERE `id` = ?;', $id);
        return true;
    }

    /**
     * Toggle bot active/paused status.
     *
     * @param int $id Bot ID
     * @return array{success: bool, status?: int, message: string}
     */
    public static function toggleStatus(int $id): array
    {
        $bot = self::getBotById($id);
        if (!$bot) {
            return ['success' => false, 'message' => 'Bot not found.'];
        }

        $newStatus = (int)$bot['status'] === 1 ? 0 : 1;
        $db = self::db();
        $db->query('UPDATE `telegram_bots` SET `status` = ?, `updated_at` = ? WHERE `id` = ?;', $newStatus, date('Y-m-d H:i:s'), $id);

        return [
            'success' => true,
            'status'  => $newStatus,
            'message' => $newStatus === 1 ? 'Bot activated.' : 'Bot paused.',
        ];
    }

    /**
     * Test a bot token against getMe.
     *
     * @param string $token
     * @return array{success: bool, result?: array, message: string}
     */
    public static function verifyToken(string $token): array
    {
        $res = TelegramClient::getMe($token);
        if ($res['ok']) {
            $user = $res['result'];
            return [
                'success' => true,
                'result'  => [
                    'id'         => $user['id'] ?? '',
                    'name'       => $user['first_name'] ?? '',
                    'username'   => $user['username'] ?? '',
                    'can_join'   => !empty($user['can_join_groups']),
                ],
                'message' => 'Bot token is valid: @' . ($user['username'] ?? ''),
            ];
        }

        return [
            'success' => false,
            'message' => $res['error'] ?? 'Failed to verify token with Telegram.',
        ];
    }

    /**
     * Send a test ping to a target chat/channel.
     *
     * @param string $token
     * @param string $chatId
     * @param string $botName
     * @return array{success: bool, message: string}
     */
    public static function testChat(string $token, string $chatId, string $botName = 'XC_VM Bot'): array
    {
        $text = TelegramMessageFormatter::formatTestPing($botName);
        $res = TelegramClient::sendMessage($token, $chatId, $text);

        if ($res['ok']) {
            return [
                'success' => true,
                'message' => 'Test message sent successfully to ' . htmlspecialchars($chatId) . '!',
            ];
        }

        return [
            'success' => false,
            'message' => 'Failed to send message: ' . ($res['error'] ?? 'Unknown error'),
        ];
    }

    /**
     * Send an actual test movie broadcast to a configured bot.
     *
     * @param int $botId Bot ID
     * @param int|null $streamId Optional movie ID from database
     * @return array{success: bool, message: string, warning?: string}
     */
    public static function broadcastTest(int $botId, ?int $streamId = null): array
    {
        $bot = self::getBotById($botId);
        if (!$bot) {
            return ['success' => false, 'message' => 'Bot not found.'];
        }

        $db = self::db();
        $movie = null;

        if ($streamId !== null && $streamId > 0) {
            $db->query('SELECT t1.*, t2.resolution, t2.audio_codec, t2.video_codec, t2.bitrate
                        FROM `streams` t1
                        LEFT JOIN `streams_servers` t2 ON t2.stream_id = t1.id
                        WHERE t1.id = ? AND t1.type = 2 LIMIT 1;', $streamId);
            if ($db->num_rows() > 0) {
                $movie = $db->get_row();
            }
        }

        // Fallback to any recent movie in database
        if (!$movie) {
            $db->query('SELECT t1.*, t2.resolution, t2.audio_codec, t2.video_codec, t2.bitrate
                        FROM `streams` t1
                        LEFT JOIN `streams_servers` t2 ON t2.stream_id = t1.id
                        WHERE t1.type = 2 ORDER BY t1.id DESC LIMIT 1;');
            if ($db->num_rows() > 0) {
                $movie = $db->get_row();
            }
        }

        if ($movie) {
            $catId = is_numeric($movie['category_id'] ?? null) ? (int)$movie['category_id'] : (int)(json_decode((string)($movie['category_id'] ?? '[]'), true)[0] ?? 0);
            $categoryName = null;
            if ($catId > 0) {
                $db->query('SELECT `category_name` FROM `stream_categories` WHERE `id` = ? LIMIT 1;', $catId);
                if ($db->num_rows() > 0) {
                    $categoryName = $db->get_row()['category_name'];
                }
            }
            $movie['category_name'] = $categoryName;
        }

        // Mock movie if DB has zero movies
        if (!$movie) {
            $movie = [
                'id'                  => 99999,
                'stream_display_name' => 'Inception',
                'year'                => '2010',
                'category_id'         => 1,
                'category_name'       => 'Sci-Fi Movies',
                'resolution'          => '1080',
                'video_codec'         => 'HEVC',
                'audio_codec'         => 'AAC',
                'movie_properties'    => json_encode([
                    'name'         => 'Inception',
                    'release_date' => '2010-07-16',
                    'rating'       => '8.8',
                    'genre'        => 'Action, Sci-Fi, Adventure',
                    'duration'     => '02:28:00',
                    'plot'         => 'A thief who steals corporate secrets through the use of dream-sharing technology is given the inverse task of planting an idea into the mind of a C.E.O.',
                    'movie_image'  => 'https://image.tmdb.org/t/p/w600_and_h900_bestv2/oYuLEt3zVCKq57qu2F8dT7NIa6f.jpg',
                ]),
            ];
        }

        $formatted = TelegramMessageFormatter::formatMovie(
            $movie,
            $bot['image_type'],
            $bot['custom_template'],
            $movie['category_name'] ?? null
        );

        $options = [
            'disable_notification' => (int)$bot['silent_notification'] === 1,
        ];

        if ((int)$bot['include_button'] === 1 && !empty($bot['button_url'])) {
            $btnText = $bot['button_text'] ?: '🎬 Watch Now';
            $btnUrl = str_replace('{stream_id}', (string)$movie['id'], $bot['button_url']);
            $options['reply_markup'] = [
                'inline_keyboard' => [
                    [
                        ['text' => $btnText, 'url' => $btnUrl],
                    ],
                ],
            ];
        }

        if ($bot['image_type'] !== 'none' && !empty($formatted['image'])) {
            $res = TelegramClient::sendPhoto($bot['bot_token'], $bot['chat_id'], $formatted['image'], $formatted['text'], $options);
        } else {
            $res = TelegramClient::sendMessage($bot['bot_token'], $bot['chat_id'], $formatted['text'], $options);
        }

        $now = date('Y-m-d H:i:s');
        $status = $res['ok'] ? 'sent' : 'failed';
        $messageId = isset($res['result']['message_id']) ? (string)$res['result']['message_id'] : null;
        $errorMsg = !$res['ok'] ? ($res['error'] ?? 'Delivery failed') : null;

        // Log the test send
        $db->query(
            'INSERT INTO `telegram_logs` (
                `bot_id`, `stream_id`, `content_type`, `chat_id`, `message_id`, `status`, `title`, `details`, `sent_at`
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
            $botId, (int)$movie['id'], 'test', $bot['chat_id'], $messageId,
            $status, $movie['stream_display_name'], $errorMsg, $now
        );

        if ($res['ok']) {
            $db->query('UPDATE `telegram_bots` SET `total_sent` = `total_sent` + 1, `last_sent_at` = UNIX_TIMESTAMP(), `last_error` = NULL WHERE `id` = ?;', $botId);
            return [
                'success' => true,
                'message' => 'Broadcast test successfully delivered to ' . htmlspecialchars($bot['chat_id']),
                'warning' => $res['warning'] ?? null,
            ];
        } else {
            $db->query('UPDATE `telegram_bots` SET `last_error` = ? WHERE `id` = ?;', $errorMsg, $botId);
            return [
                'success' => false,
                'message' => 'Broadcast test failed: ' . $errorMsg,
            ];
        }
    }

    /**
     * Fetch stream categories grouped for filter selection.
     *
     * @return array{movies: array, series: array, live: array}
     */
    public static function getCategoriesForFilter(): array
    {
        $db = self::db();
        $db->query('SELECT `id`, `category_name`, `category_type` FROM `stream_categories` ORDER BY `category_type` ASC, `category_name` ASC;');
        $rows = $db->get_rows() ?: [];

        $grouped = [
            'movies' => [],
            'series' => [],
            'live'   => [],
        ];

        foreach ($rows as $r) {
            $type = strtolower((string)($r['category_type'] ?? 'live'));
            if ($type === 'movie' || $type === 'movies') {
                $grouped['movies'][] = $r;
            } elseif ($type === 'series') {
                $grouped['series'][] = $r;
            } else {
                $grouped['live'][] = $r;
            }
        }

        return $grouped;
    }

    /**
     * Get recent logs.
     *
     * @param int $limit
     * @return array
     */
    public static function getRecentLogs(int $limit = 50): array
    {
        $db = self::db();
        $db->query(
            'SELECT l.*, b.name AS `bot_name`
             FROM `telegram_logs` l
             LEFT JOIN `telegram_bots` b ON b.id = l.bot_id
             ORDER BY l.id DESC LIMIT ' . (int)$limit . ';'
        );
        return $db->get_rows() ?: [];
    }

    /**
     * Get overall statistics.
     *
     * @return array{total_bots: int, active_bots: int, total_sent: int, last_sent_at: int|null}
     */
    public static function getStats(): array
    {
        $db = self::db();
        $db->query('SELECT
            COUNT(*) AS `total_bots`,
            SUM(CASE WHEN `status` = 1 THEN 1 ELSE 0 END) AS `active_bots`,
            COALESCE(SUM(`total_sent`), 0) AS `total_sent`,
            MAX(`last_sent_at`) AS `last_sent_at`
        FROM `telegram_bots`;');

        $row = $db->get_row() ?: [];
        return [
            'total_bots'   => (int)($row['total_bots'] ?? 0),
            'active_bots'  => (int)($row['active_bots'] ?? 0),
            'total_sent'   => (int)($row['total_sent'] ?? 0),
            'last_sent_at' => !empty($row['last_sent_at']) ? (int)$row['last_sent_at'] : null,
        ];
    }
}
