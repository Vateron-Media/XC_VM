<?php

namespace XcVm\Streaming\Auth;

use XcVm\Core\Logging\DatabaseLogger;
use XcVm\Core\Util\Encryption;

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

		if (empty($rSettings['send_unique_header_domain']) && ($rDomain = self::uniqueHeaderDomain()) !== null) {
			$rSettings['send_unique_header_domain'] = $rDomain;
		}

		if (!empty($rSettings['send_unique_header'])) {
			$rExpires = new \DateTime('+6 months', new \DateTimeZone('GMT'));
			header('Set-Cookie: ' . $rSettings['send_unique_header'] . '=' . Encryption::randomString(11) . '; Domain=' . $rSettings['send_unique_header_domain'] . '; Expires=' . $rExpires->format(DATE_RFC2822) . '; Path=/; Secure; HttpOnly; SameSite=none');
		}
	}

	/**
	 * The leading-dot cookie domain derived from the request host, or null when
	 * there is no usable host name (HOST undefined, empty, or a bare IP — a dot
	 * prefix on which is not a valid cookie domain).
	 */
	private static function uniqueHeaderDomain(): ?string {
		$rHost = defined('HOST') ? (string) HOST : (string) ($_SERVER['HTTP_HOST'] ?? '');
		if ($rHost === '' || filter_var($rHost, FILTER_VALIDATE_IP)) {
			return null;
		}
		return '.' . $rHost;
	}

	/**
	 * MAIN's clock as this server sees it. A token's times are MAIN's (auth.php
	 * mints them so, wherever it runs), and this server's clock is ahead of
	 * MAIN's by its time_offset (node − MAIN, measured each minute).
	 */
	public static function mainNow(array $rServers, ?int $rNow = null): int {
		return ($rNow ?? time()) - intval($rServers[SERVER_ID]['time_offset'] ?? 0);
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
	public static function decryptToken(string $rToken, array $rSettings, array $rServers, string $rIP) {
		$rTokenData = json_decode(
			Encryption::readToken($rToken, $rSettings['live_streaming_pass'], OPENSSL_EXTRA, empty($rSettings['secure_stream_tokens'])),
			true
		);

		if (!is_array($rTokenData)) {
			DatabaseLogger::clientLog(0, 0, 'LB_TOKEN_INVALID', $rIP);
			generateError('LB_TOKEN_INVALID');
		}

		// The connection id names files under this server's own directories. It
		// is the 32 hex characters auth.php mints; a legacy token is not
		// authenticated, so the form is checked rather than taken on trust.
		if (array_key_exists('uuid', $rTokenData) && !(is_string($rTokenData['uuid']) && preg_match('/^[0-9a-f]{32}\z/', $rTokenData['uuid']))) {
			DatabaseLogger::clientLog(0, 0, 'LB_TOKEN_INVALID', $rIP);
			generateError('LB_TOKEN_INVALID');
		}

		if (isset($rTokenData['expires']) && $rTokenData['expires'] < self::mainNow($rServers)) {
			generateError('TOKEN_EXPIRED');
		}

		return $rTokenData;
	}
}
