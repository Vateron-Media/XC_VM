<?php

namespace XcVm\Module\Telegram;

/**
 * TelegramClient — Lightweight, robust client for the Telegram Bot API.
 *
 * Implements core API calls (getMe, getChat, sendMessage, sendPhoto)
 * with multipart local file uploading, remote URL support, safe fallbacks,
 * and comprehensive error reporting.
 *
 * @package XC_VM_Module_Telegram
 * @author  XC_VM Team
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TelegramClient
{
    private const API_BASE = 'https://api.telegram.org/bot';

    /**
     * Test and verify a bot token via getMe.
     *
     * @param string $token Bot API token
     * @return array{ok: bool, result?: array, error?: string}
     */
    public static function getMe(string $token): array
    {
        return self::request($token, 'getMe');
    }

    /**
     * Get chat/channel information.
     *
     * @param string $token  Bot API token
     * @param string $chatId Target Chat or Channel ID (@channel or -100...)
     * @return array{ok: bool, result?: array, error?: string}
     */
    public static function getChat(string $token, string $chatId): array
    {
        return self::request($token, 'getChat', ['chat_id' => $chatId]);
    }

    /**
     * Send a formatted text message to a chat or channel.
     *
     * @param string $token   Bot API token
     * @param string $chatId  Target Chat or Channel ID
     * @param string $text    HTML-formatted message text
     * @param array  $options Additional options (disable_notification, reply_markup, etc.)
     * @return array{ok: bool, result?: array, error?: string}
     */
    public static function sendMessage(string $token, string $chatId, string $text, array $options = []): array
    {
        $params = array_merge([
            'chat_id'                  => $chatId,
            'text'                     => $text,
            'parse_mode'               => 'HTML',
            'disable_web_page_preview' => false,
            'disable_notification'     => !empty($options['disable_notification']),
        ], $options);

        return self::request($token, 'sendMessage', $params);
    }

    /**
     * Send a photo with an HTML-formatted caption.
     * If the photo cannot be downloaded by Telegram or the upload fails,
     * it automatically falls back to sendMessage to ensure delivery.
     *
     * @param string $token   Bot API token
     * @param string $chatId  Target Chat or Channel ID
     * @param string $photo   Remote image URL or local file path
     * @param string $caption HTML caption text (<= 1024 chars)
     * @param array  $options Additional options
     * @return array{ok: bool, result?: array, error?: string}
     */
    public static function sendPhoto(string $token, string $chatId, string $photo, string $caption = '', array $options = []): array
    {
        $caption = mb_substr($caption, 0, 1024);

        $params = [
            'chat_id'              => $chatId,
            'caption'              => $caption,
            'parse_mode'           => 'HTML',
            'disable_notification' => !empty($options['disable_notification']),
        ];

        if (!empty($options['reply_markup'])) {
            $params['reply_markup'] = is_array($options['reply_markup'])
                ? json_encode($options['reply_markup'])
                : $options['reply_markup'];
        }

        $isLocalFile = false;
        $cleanPhoto = trim($photo);

        if ($cleanPhoto !== '') {
            if (file_exists($cleanPhoto) && is_readable($cleanPhoto)) {
                $isLocalFile = true;
                $params['photo'] = new \CURLFile($cleanPhoto);
            } elseif (str_starts_with($cleanPhoto, 'http://') || str_starts_with($cleanPhoto, 'https://')) {
                $params['photo'] = $cleanPhoto;
            }
        }

        // If no valid image source is available, send as text message
        if (empty($params['photo'])) {
            return self::sendMessage($token, $chatId, $caption, $options);
        }

        $res = self::request($token, 'sendPhoto', $params, $isLocalFile);

        // If sending photo failed (e.g., photo URL broken, bad format, or rejected), fallback to text message
        if (!$res['ok']) {
            $fallback = self::sendMessage($token, $chatId, $caption, $options);
            if ($fallback['ok']) {
                $fallback['warning'] = 'Photo failed (' . ($res['error'] ?? 'unknown') . '), delivered as text message.';
                return $fallback;
            }
        }

        return $res;
    }

    /**
     * Execute a cURL request against the Telegram Bot API.
     *
     * @param string $token       Bot API token
     * @param string $method      API method (e.g. 'sendMessage', 'getMe')
     * @param array  $params      Query or POST parameters
     * @param bool   $isMultipart Whether to send as multipart/form-data
     * @return array{ok: bool, result?: array, error?: string}
     */
    private static function request(string $token, string $method, array $params = [], bool $isMultipart = false): array
    {
        $token = trim($token);
        if ($token === '') {
            return ['ok' => false, 'error' => 'Empty bot token provided.'];
        }

        $url = self::API_BASE . $token . '/' . $method;

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        if (!empty($params)) {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($isMultipart) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $params);
            } else {
                curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            }
        }

        $response = curl_exec($ch);
        $curlError = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $response === '') {
            return [
                'ok'    => false,
                'error' => 'cURL connection error: ' . ($curlError ?: 'No response received from Telegram server.'),
            ];
        }

        $json = json_decode($response, true);
        if (!is_array($json)) {
            return [
                'ok'    => false,
                'error' => "HTTP {$httpCode}: Failed to parse JSON response ({$response})",
            ];
        }

        if (!empty($json['ok'])) {
            return [
                'ok'     => true,
                'result' => $json['result'] ?? [],
            ];
        }

        return [
            'ok'    => false,
            'error' => $json['description'] ?? "Telegram API error (code {$httpCode})",
        ];
    }
}
