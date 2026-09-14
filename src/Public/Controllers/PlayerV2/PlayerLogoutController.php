<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Auth\SessionManager;
use XcVm\Domain\External\ExternalXtreamService;

/**
 * PlayerLogoutController — Securely log out and purge all player sessions, caches, and files.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class PlayerLogoutController {
	/**
	 * Completely purge all player session data, external caches, and session files.
	 */
	public static function purgePlayerSession(): void {
		if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
			@session_start();
		}

		// 1. Clear external Xtream session caches (all ext_* keys)
		if (class_exists(ExternalXtreamService::class)) {
			ExternalXtreamService::clearCache();
		}

		// 2. Unset all player-specific session keys
		$playerKeys = [
			'phash',
			'pverify',
			'is_external_xc',
			'external_xc',
			'rUserInfo',
			'rMemberHash',
			'user_id',
			'saved_account_sync',
			'clear_client_cache'
		];

		foreach ($playerKeys as $key) {
			unset($_SESSION[$key]);
		}

		// 3. Unset any session keys starting with ext_, player_, or xc_
		if (!empty($_SESSION) && is_array($_SESSION)) {
			foreach (array_keys($_SESSION) as $k) {
				if (str_starts_with($k, 'ext_') || str_starts_with($k, 'player_') || str_starts_with($k, 'xc_')) {
					unset($_SESSION[$k]);
				}
			}
		}

		// 4. Use Core SessionManager clearContext for player
		SessionManager::clearContext('player');

		// 5. If no other scope (admin or reseller) is logged in, completely destroy the session
		$isAdmin = !empty($_SESSION['hash']);
		$isReseller = !empty($_SESSION['reseller']);

		if (!$isAdmin && !$isReseller) {
			$sessId = session_id();
			$_SESSION = [];
			if (ini_get('session.use_cookies')) {
				$params = session_get_cookie_params();
				setcookie(
					session_name(),
					'',
					time() - 42000,
					$params['path'] ?? '/',
					$params['domain'] ?? '',
					$params['secure'] ?? false,
					$params['httponly'] ?? true
				);
			}
			@session_destroy();

			// Explicitly delete session file from disk if present
			if (!empty($sessId)) {
				$savePath = session_save_path() ?: '/tmp';
				$sessFile = rtrim($savePath, '/') . '/sess_' . $sessId;
				if (file_exists($sessFile)) {
					@unlink($sessFile);
				}
			}
		}
	}

	/**
	 * Handle logout request.
	 */
	public function index(): void {
		self::purgePlayerSession();

		$code = $_SERVER['XC_CODE'] ?? '';
		$redirectUrl = $code ? '/' . $code . '/login' : 'login';

		$isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
			|| (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'));

		if ($isAjax) {
			header('Content-Type: application/json; charset=utf-8');
			echo json_encode([
				'success' => true,
				'message' => 'Logged out successfully.',
				'redirect' => $redirectUrl,
				'clear_client_cache' => true
			]);
			exit();
		}

		$target = $redirectUrl . (str_contains($redirectUrl, '?') ? '&' : '?') . 'logged_out=1';
		header('Location: ' . $target);
		exit();
	}
}
