<?php

namespace XcVm\Module\Telegram;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * TelegramNotifier — Central event dispatcher for Telegram broadcasts.
 *
 * Handles automatic notifications when:
 * 1. A movie has finished downloading completely & correctly (VodCronJob -> VALID)
 * 2. A TV series episode is analyzed and ready
 * 3. A live stream channel is created
 *
 * Enforces multi-bot category filters, custom templates, media resolution,
 * and idempotency tracking to prevent duplicate broadcasts.
 *
 * @package XC_VM_Module_Telegram
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TelegramNotifier
{
    use DatabaseAware;

    /**
     * Triggered by VodCronJob or MediaAnalyzedEvent immediately after media analysis completes (VALID).
     *
     * @param int $streamId Stream ID
     * @param int $streamType Stream type (2 = Movie, 5 = Episode, etc.)
     * @return void
     */
    public static function onMediaAnalyzed(int $streamId, int $streamType): void
    {
        if ($streamId <= 0) {
            return;
        }

        try {
            if ($streamType === 2) {
                self::onMovieDownloadComplete($streamId);
            } elseif ($streamType === 5) {
                self::onEpisodeReady($streamId);
            }
        } catch (\Throwable $e) {
            error_log('[TelegramNotifier] Error in onMediaAnalyzed: ' . $e->getMessage());
        }
    }

    /**
     * Broadcast a movie when download and encoding are fully verified.
     *
     * @param int $streamId Movie stream ID
     * @return int Number of bots notified
     */
    public static function onMovieDownloadComplete(int $streamId): int
    {
        $db = self::db();

        // 1. Fetch movie & server details
        $db->query(
            'SELECT t1.*, t2.resolution, t2.audio_codec, t2.video_codec, t2.bitrate
             FROM `streams` t1
             LEFT JOIN `streams_servers` t2 ON t2.stream_id = t1.id
             WHERE t1.id = ? AND t1.type = 2 LIMIT 1;',
            $streamId
        );

        if ($db->num_rows() <= 0) {
            return 0;
        }

        $movie = $db->get_row();

        // Resolve movie category IDs and category name
        $movieCategoryIds = [];
        $rawCat = $movie['category_id'] ?? null;
        if (is_numeric($rawCat)) {
            $movieCategoryIds[] = (int)$rawCat;
        } elseif (is_string($rawCat)) {
            $decoded = json_decode($rawCat, true);
            if (is_array($decoded)) {
                $movieCategoryIds = array_map('intval', $decoded);
            }
        }

        $categoryName = null;
        if (!empty($movieCategoryIds[0])) {
            $db->query('SELECT `category_name` FROM `stream_categories` WHERE `id` = ? LIMIT 1;', $movieCategoryIds[0]);
            if ($db->num_rows() > 0) {
                $categoryName = $db->get_row()['category_name'];
            }
        }
        $movie['category_name'] = $categoryName;

        // 2. Fetch all active bots subscribed to movie downloads
        $db->query(
            "SELECT * FROM `telegram_bots`
             WHERE `status` = 1
               AND (`notify_on_movie_complete` = 1 OR `content_types` LIKE '%movies%');"
        );

        if ($db->num_rows() <= 0) {
            return 0;
        }

        $bots = $db->get_rows();
        $notifiedCount = 0;

        foreach ($bots as $bot) {
            $botId = (int)$bot['id'];

            // 3. Category matching
            if (!empty($bot['categories'])) {
                $allowedCats = json_decode($bot['categories'], true);
                if (is_array($allowedCats) && !empty($allowedCats)) {
                    $overlap = array_intersect($movieCategoryIds, array_map('intval', $allowedCats));
                    if (empty($overlap)) {
                        continue; // Movie category not permitted for this bot
                    }
                }
            }

            // 4. Idempotency check: Don't broadcast the same movie twice to the same bot
            $db->query(
                "SELECT `id` FROM `telegram_logs`
                 WHERE `bot_id` = ? AND `stream_id` = ? AND `content_type` = 'movie' AND `status` = 'sent'
                 LIMIT 1;",
                $botId, $streamId
            );
            if ($db->num_rows() > 0) {
                continue; // Already broadcasted
            }

            // 5. Format message
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

            // 6. Send to Telegram
            if ($bot['image_type'] !== 'none' && !empty($formatted['image'])) {
                $res = TelegramClient::sendPhoto($bot['bot_token'], $bot['chat_id'], $formatted['image'], $formatted['text'], $options);
            } else {
                $res = TelegramClient::sendMessage($bot['bot_token'], $bot['chat_id'], $formatted['text'], $options);
            }

            // 7. Log & update metrics
            $now = date('Y-m-d H:i:s');
            $status = $res['ok'] ? 'sent' : 'failed';
            $messageId = isset($res['result']['message_id']) ? (string)$res['result']['message_id'] : null;
            $errorMsg = !$res['ok'] ? ($res['error'] ?? 'Delivery failed') : null;

            $db->query(
                'INSERT INTO `telegram_logs` (
                    `bot_id`, `stream_id`, `content_type`, `chat_id`, `message_id`, `status`, `title`, `details`, `sent_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
                $botId, $streamId, 'movie', $bot['chat_id'], $messageId,
                $status, $movie['stream_display_name'], $errorMsg, $now
            );

            if ($res['ok']) {
                $db->query(
                    'UPDATE `telegram_bots` SET `total_sent` = `total_sent` + 1, `last_sent_at` = UNIX_TIMESTAMP(), `last_error` = NULL WHERE `id` = ?;',
                    $botId
                );
                $notifiedCount++;
            } else {
                $db->query('UPDATE `telegram_bots` SET `last_error` = ? WHERE `id` = ?;', $errorMsg, $botId);
            }
        }

        return $notifiedCount;
    }

    /**
     * Broadcast when a series episode finishes downloading and is ready.
     *
     * @param int $streamId Episode stream ID
     * @return int Number of bots notified
     */
    public static function onEpisodeReady(int $streamId): int
    {
        $db = self::db();

        $db->query(
            'SELECT t1.*, s.title AS `series_title`, s.cover AS `series_cover`, s.backdrop_path AS `series_backdrop`, s.rating AS `series_rating`, s.genre AS `series_genre`
             FROM `streams` t1
             LEFT JOIN `series_episodes` se ON se.stream_id = t1.id
             LEFT JOIN `series` s ON s.id = se.series_id
             WHERE t1.id = ? AND t1.type = 5 LIMIT 1;',
            $streamId
        );

        if ($db->num_rows() <= 0) {
            return 0;
        }

        $episode = $db->get_row();
        $catId = is_numeric($episode['category_id'] ?? null) ? (int)$episode['category_id'] : (int)(json_decode((string)($episode['category_id'] ?? '[]'), true)[0] ?? 0);
        $categoryName = null;
        if ($catId > 0) {
            $db->query('SELECT `category_name` FROM `stream_categories` WHERE `id` = ? LIMIT 1;', $catId);
            if ($db->num_rows() > 0) {
                $categoryName = $db->get_row()['category_name'];
            }
        }
        $episode['category_name'] = $categoryName;
        $series = [
            'title'         => $episode['series_title'] ?? 'TV Series',
            'cover'         => $episode['series_cover'] ?? '',
            'backdrop_path' => $episode['series_backdrop'] ?? '',
            'rating'        => $episode['series_rating'] ?? '',
            'genre'         => $episode['series_genre'] ?? '',
        ];

        $db->query(
            "SELECT * FROM `telegram_bots`
             WHERE `status` = 1
               AND (`notify_on_episode` = 1 OR `content_types` LIKE '%episodes%');"
        );

        if ($db->num_rows() <= 0) {
            return 0;
        }

        $bots = $db->get_rows();
        $notifiedCount = 0;

        foreach ($bots as $bot) {
            $botId = (int)$bot['id'];

            // Idempotency check
            $db->query(
                "SELECT `id` FROM `telegram_logs`
                 WHERE `bot_id` = ? AND `stream_id` = ? AND `content_type` = 'episode' AND `status` = 'sent'
                 LIMIT 1;",
                $botId, $streamId
            );
            if ($db->num_rows() > 0) {
                continue;
            }

            $formatted = TelegramMessageFormatter::formatEpisode($episode, $series, $episode['category_name'] ?? null);

            $options = [
                'disable_notification' => (int)$bot['silent_notification'] === 1,
            ];

            if ($bot['image_type'] !== 'none' && !empty($formatted['image'])) {
                $res = TelegramClient::sendPhoto($bot['bot_token'], $bot['chat_id'], $formatted['image'], $formatted['text'], $options);
            } else {
                $res = TelegramClient::sendMessage($bot['bot_token'], $bot['chat_id'], $formatted['text'], $options);
            }

            $now = date('Y-m-d H:i:s');
            $status = $res['ok'] ? 'sent' : 'failed';
            $messageId = isset($res['result']['message_id']) ? (string)$res['result']['message_id'] : null;
            $errorMsg = !$res['ok'] ? ($res['error'] ?? 'Delivery failed') : null;

            $db->query(
                'INSERT INTO `telegram_logs` (
                    `bot_id`, `stream_id`, `content_type`, `chat_id`, `message_id`, `status`, `title`, `details`, `sent_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
                $botId, $streamId, 'episode', $bot['chat_id'], $messageId,
                $status, $episode['stream_display_name'], $errorMsg, $now
            );

            if ($res['ok']) {
                $db->query(
                    'UPDATE `telegram_bots` SET `total_sent` = `total_sent` + 1, `last_sent_at` = UNIX_TIMESTAMP(), `last_error` = NULL WHERE `id` = ?;',
                    $botId
                );
                $notifiedCount++;
            } else {
                $db->query('UPDATE `telegram_bots` SET `last_error` = ? WHERE `id` = ?;', $errorMsg, $botId);
            }
        }

        return $notifiedCount;
    }

    /**
     * Broadcast when a new live stream channel is created.
     *
     * @param int $streamId Live stream ID
     * @return int Number of bots notified
     */
    public static function onLiveStreamCreated(int $streamId): int
    {
        $db = self::db();

        $db->query(
            'SELECT t1.*
             FROM `streams` t1
             WHERE t1.id = ? AND t1.type = 1 LIMIT 1;',
            $streamId
        );

        if ($db->num_rows() <= 0) {
            return 0;
        }

        $stream = $db->get_row();
        $catId = is_numeric($stream['category_id'] ?? null) ? (int)$stream['category_id'] : (int)(json_decode((string)($stream['category_id'] ?? '[]'), true)[0] ?? 0);
        $categoryName = null;
        if ($catId > 0) {
            $db->query('SELECT `category_name` FROM `stream_categories` WHERE `id` = ? LIMIT 1;', $catId);
            if ($db->num_rows() > 0) {
                $categoryName = $db->get_row()['category_name'];
            }
        }
        $stream['category_name'] = $categoryName;

        $db->query(
            "SELECT * FROM `telegram_bots`
             WHERE `status` = 1
               AND (`notify_on_live` = 1 OR `content_types` LIKE '%live%');"
        );

        if ($db->num_rows() <= 0) {
            return 0;
        }

        $bots = $db->get_rows();
        $notifiedCount = 0;

        foreach ($bots as $bot) {
            $botId = (int)$bot['id'];

            // Idempotency check
            $db->query(
                "SELECT `id` FROM `telegram_logs`
                 WHERE `bot_id` = ? AND `stream_id` = ? AND `content_type` = 'live' AND `status` = 'sent'
                 LIMIT 1;",
                $botId, $streamId
            );
            if ($db->num_rows() > 0) {
                continue;
            }

            $formatted = TelegramMessageFormatter::formatLiveStream($stream, $stream['category_name'] ?? null);

            $options = [
                'disable_notification' => (int)$bot['silent_notification'] === 1,
            ];

            if ($bot['image_type'] !== 'none' && !empty($formatted['image'])) {
                $res = TelegramClient::sendPhoto($bot['bot_token'], $bot['chat_id'], $formatted['image'], $formatted['text'], $options);
            } else {
                $res = TelegramClient::sendMessage($bot['bot_token'], $bot['chat_id'], $formatted['text'], $options);
            }

            $now = date('Y-m-d H:i:s');
            $status = $res['ok'] ? 'sent' : 'failed';
            $messageId = isset($res['result']['message_id']) ? (string)$res['result']['message_id'] : null;
            $errorMsg = !$res['ok'] ? ($res['error'] ?? 'Delivery failed') : null;

            $db->query(
                'INSERT INTO `telegram_logs` (
                    `bot_id`, `stream_id`, `content_type`, `chat_id`, `message_id`, `status`, `title`, `details`, `sent_at`
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?);',
                $botId, $streamId, 'live', $bot['chat_id'], $messageId,
                $status, $stream['stream_display_name'], $errorMsg, $now
            );

            if ($res['ok']) {
                $db->query(
                    'UPDATE `telegram_bots` SET `total_sent` = `total_sent` + 1, `last_sent_at` = UNIX_TIMESTAMP(), `last_error` = NULL WHERE `id` = ?;',
                    $botId
                );
                $notifiedCount++;
            } else {
                $db->query('UPDATE `telegram_bots` SET `last_error` = ? WHERE `id` = ?;', $errorMsg, $botId);
            }
        }

        return $notifiedCount;
    }
}
