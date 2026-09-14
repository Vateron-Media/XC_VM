<?php

namespace XcVm\Public\Controllers\Player;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\ActiveCodeService;

/**
 * PortalController — Public Subscriber Activation Portal
 *
 * Provides a responsive activation interface for clients to redeem
 * their Active Codes, view credentials, and download playlists.
 *
 * @package XC_VM_Public_Controllers_Player
 */
class PortalController {
	public function index() {
		$action = RequestManager::get('action') ?? '';
		$code = trim(RequestManager::get('code') ?? '');

		// Extract client IP (supporting reverse proxies & Cloudflare)
		$clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
			?? $_SERVER['HTTP_X_FORWARDED_FOR']
			?? $_SERVER['REMOTE_ADDR']
			?? '';
		if (str_contains($clientIp, ',')) {
			$clientIp = trim(explode(',', $clientIp)[0]);
		}

		// Detect MAC address from query, body, or custom HTTP device headers
		$mac = trim(
			RequestManager::get('mac')
			?? $_SERVER['HTTP_X_MAC_ADDRESS']
			?? $_SERVER['HTTP_X_STB_MAC']
			?? $_SERVER['HTTP_MAC']
			?? ''
		);

		// Detect Device ID / UUID from query, body, or custom HTTP headers
		$deviceId = trim(
			RequestManager::get('device_id')
			?? $_SERVER['HTTP_X_DEVICE_ID']
			?? $_SERVER['HTTP_DEVICE_ID']
			?? ''
		);

		$deviceInfo = [
			'mac' => $mac,
			'device_id' => $deviceId,
			'ip' => $clientIp,
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
		];

		$result = null;

		// If code is supplied (via GET or POST), evaluate/activate immediately
		if (!empty($code)) {
			$result = ActiveCodeService::activateCode($code, $deviceInfo);

			// If AJAX request, return JSON
			if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode($result);
				exit();
			}
		} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' || $action === 'activate') {
			$result = ['status' => 'ERROR', 'message' => 'Please enter an activation code.'];
			if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
				header('Content-Type: application/json; charset=utf-8');
				echo json_encode($result);
				exit();
			}
		}

		require MAIN_HOME . 'Public/Views/portal/index.php';
		exit();
	}
}
