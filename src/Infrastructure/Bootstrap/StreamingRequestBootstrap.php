<?php

namespace XcVm\Infrastructure\Bootstrap;

use XcVm\Core\Logging\Logger;

/**
 * StreamingRequestBootstrap — bootstrap для streaming HTTP endpoint'ов
 *
 * @package XC_VM_Infrastructure_Bootstrap
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/\XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamingRequestBootstrap {
	/**
	 * Bootstrap a streaming HTTP endpoint.
	 *
	 * Loads the required core modules and initializes the streaming request
	 * context for the calling entry script.
	 *
	 * @param string $rFilename Entry-script filename invoking the bootstrap.
	 * @return void
	 */
	public static function init(string $rFilename): void {
		// ── 1. Базовые модули ────────────────────────────────────
		require_once MAIN_HOME . 'Core/Error/ErrorCodes.php';
		require_once MAIN_HOME . 'Core/Error/ErrorHandler.php';
		require_once MAIN_HOME . 'Core/Config/Paths.php';
		require_once MAIN_HOME . 'Core/Config/AppConfig.php';
		require_once MAIN_HOME . 'Core/Config/Binaries.php';

		@ini_set('user_agent', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/83.0.4103.61 Safari/537.36');
		@ini_set('default_socket_timeout', 5);

		$rIsCli = (PHP_SAPI === 'cli');

		// ── 2. Flood-protection (HTTP only) ──────────────────────
		if (!$rIsCli) {
			$rIP = $_SERVER['REMOTE_ADDR'] ?? '';
			if ($rIP !== '' && file_exists(FLOOD_TMP_PATH . 'block_' . $rIP)) {
				http_response_code(403);
				exit();
			}
		}

		// ── 3. Настройки из файлового кэша ───────────────────────
		global $rSettings;
		if (file_exists(CACHE_TMP_PATH . 'settings')) {
			$rSettings = igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'settings'));
		} else {
			// Fail-closed: блокировать всё кроме status если кэш отсутствует
			$rSettings = ['verify_host' => false, 'debug_show_errors' => false, 'enable_cache' => false, 'exit' => true];
		}

		$rShowErrors = false;

		// ── 4. Host verification (HTTP only) ─────────────────────
		if (!$rIsCli) {
			if (!defined('HOST')) {
				define('HOST', trim(explode(':', $_SERVER['HTTP_HOST'] ?? '')[0]));
			}

			if (is_array($rSettings) && !empty($rSettings['verify_host'])) {
				$rAllowedDomains = (igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'allowed_domains')) ?: []);

				if (is_array($rAllowedDomains)
					&& count($rAllowedDomains) > 0
					&& !in_array(HOST, $rAllowedDomains, true)
					&& HOST !== 'xc_vm'
					&& !filter_var(HOST, FILTER_VALIDATE_IP)
				) {
					generateError('INVALID_HOST');
				}

				unset($rAllowedDomains);
			}

			$rShowErrors = isset($rSettings['debug_show_errors']) ? (bool)$rSettings['debug_show_errors'] : false;
		}

		// ── 5. Logger ────────────────────────────────────────────
		if (!defined('PHP_ERRORS')) {
			define('PHP_ERRORS', $rShowErrors);
		}

		require_once MAIN_HOME . 'Core/Logging/Logger.php';
		Logger::init(PHP_ERRORS, LOGS_TMP_PATH . 'error_log.log');

		// ── 6. Fail-closed gate (настройки недоступны) ───────────
		if (isset($rSettings['exit']) && $rFilename !== 'status') {
			generate404();
		}

		// ── 7. Передача в \XcVm\Streaming\StreamingBootstrap ────────────────────
		$rStreamingEndpoints = ['probe', 'player_api', 'live', 'thumb', 'subtitle', 'timeshift', 'vod', 'status', 'rtmp', 'portal'];
		if (in_array($rFilename, $rStreamingEndpoints, true)) {
			\XcVm\Streaming\StreamingBootstrap::bootstrap($rFilename, $rSettings);
		}
	}
}
