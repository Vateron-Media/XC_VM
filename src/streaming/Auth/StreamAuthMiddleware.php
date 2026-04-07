<?php

/**
 * Общий streaming auth middleware для live/vod/timeshift.
 *
 * Извлекает дублированные блоки:
 *  - Заголовки ответа (CORS, Server, Protection, Alt-Svc, unique cookie)
 *  - Дешифровка и базовая валидация токена
 *
 * Ограничение: static-only, без DI-container. Globals через параметры.
 *
 * @package XC_VM_Streaming_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamAuthMiddleware {

    /**
     * Отправка общих streaming response headers.
     *
     * Дублировалось в live.php, vod.php, timeshift.php (~25 строк каждый).
     */
    public static function sendStreamHeaders($rSettings, $rServers) {
        header('Access-Control-Allow-Origin: *');

        if (!empty($rSettings['send_server_header'])) {
            header('Server: ' . $rSettings['send_server_header']);
        }

        if ($rSettings['send_protection_headers']) {
            header('X-XSS-Protection: 0');
            header('X-Content-Type-Options: nosniff');
        }

        if ($rSettings['send_altsvc_header']) {
            header('Alt-Svc: h3-29=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-T051=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q050=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q046=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,h3-Q043=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000,quic=":' . $rServers[SERVER_ID]['https_broadcast_port'] . '"; ma=2592000; v="46,43"');
        }

        if (empty($rSettings['send_unique_header_domain']) && !filter_var(HOST, FILTER_VALIDATE_IP)) {
            $rSettings['send_unique_header_domain'] = '.' . HOST;
        }

        if (!empty($rSettings['send_unique_header'])) {
            $rExpires = new DateTime('+6 months', new DateTimeZone('GMT'));
            header('Set-Cookie: ' . $rSettings['send_unique_header'] . '=' . Encryption::randomString(11) . '; Domain=' . $rSettings['send_unique_header_domain'] . '; Expires=' . $rExpires->format(DATE_RFC2822) . '; Path=/; Secure; HttpOnly; SameSite=none');
        }
    }

    /**
     * Дешифровка токена и базовая валидация (формат + срок действия).
     *
     * @param string $rToken       Зашифрованный токен из запроса
     * @param array  $rSettings    Настройки (нужен live_streaming_pass)
     * @param array  $rServers     Серверы (нужен time_offset)
     * @param string $rIP          IP клиента для логирования
     * @return array               Расшифрованные данные токена
     */
    public static function decryptToken($rToken, $rSettings, $rServers, $rIP) {
        $rTokenData = json_decode(
            Encryption::decrypt($rToken, $rSettings['live_streaming_pass'], OPENSSL_EXTRA),
            true
        );

        if (!is_array($rTokenData)) {
            DatabaseLogger::clientLog(0, 0, 'LB_TOKEN_INVALID', $rIP);
            generateError('LB_TOKEN_INVALID');
        }

        if (isset($rTokenData['expires']) && $rTokenData['expires'] < time() - intval($rServers[SERVER_ID]['time_offset'])) {
            generateError('TOKEN_EXPIRED');
        }

        return $rTokenData;
    }
}
