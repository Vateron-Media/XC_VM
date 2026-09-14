<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\User\UserRepository;

/**
 * ActiveCodeApiController — Unified REST API for Smart Activation Codes
 *
 * Provides:
 * 1. Admin API & Reseller API Management (when api_key is provided)
 * 2. Client Device Authentication & Delayed Countdown Activation (when code is provided)
 * 3. Non-destructive Code Status Inspection (action=check)
 *
 * Endpoints:
 * - /api/active_codes or /api/active_code
 * - /active_codes.php or /active_code.php
 *
 * @package XC_VM_Public_Controllers_Api
 */
class ActiveCodeApiController extends BaseApiController {
	public function index() {
		set_time_limit(0);

		header('Content-Type: application/json; charset=utf-8');
		header('Access-Control-Allow-Origin: *');
		header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
		header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, X-Api-Key');

		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
			http_response_code(200);
			exit();
		}

		global $db, $rSettings, $rServers;
		if (!is_object($db)) {
			$db = new DatabaseHandler();
		}

		// Parse request payload across GET, POST, and raw JSON body
		$rawBody = @file_get_contents('php://input');
		$jsonInput = !empty($rawBody) ? json_decode($rawBody, true) : [];
		if (!is_array($jsonInput)) {
			$jsonInput = [];
		}

		$allData = array_merge($_GET, $_POST, $jsonInput);

		// Extract the API key from the Authorization header, the X-Api-Key header
		// or the request body only. It is a long-lived secret, so it is never read
		// from the query string, where it would leak into access logs, the Referer
		// header and browser history.
		$apiKey = '';
		$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
		if (preg_match('/Bearer\s+(\S+)/i', $authHeader, $m)) {
			$apiKey = trim($m[1]);
		} elseif (!empty($_SERVER['HTTP_X_API_KEY'])) {
			$apiKey = trim((string) $_SERVER['HTTP_X_API_KEY']);
		} elseif (!empty($_POST['api_key'])) {
			$apiKey = trim((string) $_POST['api_key']);
		} elseif (!empty($jsonInput['api_key'])) {
			$apiKey = trim((string) $jsonInput['api_key']);
		}

		$action = trim((string) ($allData['action'] ?? 'auth'));
		$start = max(0, intval($allData['start'] ?? 0));
		$limit = max(1, min(500, intval($allData['limit'] ?? 50)));

		$showColumns = !empty($allData['show_columns']) ? explode(',', (string) $allData['show_columns']) : null;
		$hideColumns = !empty($allData['hide_columns']) ? explode(',', (string) $allData['hide_columns']) : null;

		// ═════════════════════════════════════════════════════════════════════════
		// MODE A: Management API (Admin API or Reseller API via api_key)
		// ═════════════════════════════════════════════════════════════════════════
		if (!empty($apiKey)) {
			$this->deny = false;

			// 1. Try Administrator Authentication
			AdminAPIWrapper::$db = &$db;
			AdminAPIWrapper::$rKey = $apiKey;
			if (AdminAPIWrapper::createSession()) {
				$this->handleAdminAction($action, $allData, $start, $limit, $showColumns, $hideColumns);
				exit();
			}

			// 2. Try Reseller Authentication
			ResellerAPIWrapper::$db = &$db;
			ResellerAPIWrapper::$rKey = $apiKey;
			if (ResellerAPIWrapper::createSession()) {
				$this->handleResellerAction($action, $allData, $start, $limit, $showColumns, $hideColumns);
				exit();
			}

			// Invalid API key
			$this->deny = true;
			http_response_code(401);
			echo json_encode([
				'status' => 'STATUS_FAILURE',
				'error' => 'Invalid or expired API key.',
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			exit();
		}

		// ═════════════════════════════════════════════════════════════════════════
		// MODE B: Client Device & Subscriber Operations (via activation code)
		// ═════════════════════════════════════════════════════════════════════════
		$code = trim(
			$allData['code']
			?? $allData['activation_code']
			?? $allData['pin']
			?? $allData['c']
			?? ''
		);

		$mac = trim(
			$allData['mac']
			?? $allData['mac_address']
			?? ''
		);

		$deviceId = trim(
			$allData['device_id']
			?? $allData['device']
			?? ''
		);

		if (empty($code)) {
			$this->deny = true;
			http_response_code(400);
			echo json_encode([
				'status' => 'ERROR',
				'user_info' => ['auth' => 0],
				'message' => 'Missing activation code or API key. Please provide "code" or "api_key".'
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			exit();
		}

		// Sub-action: Non-destructive status check
		if ($action === 'check') {
			$res = ActiveCodeService::checkCode($code);
			http_response_code(200);
			echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			exit();
		}

		// Sub-action: Client activation / Authentication / Hardware Binding
		$clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
		$deviceInfo = [
			'mac' => $mac,
			'device_id' => $deviceId,
			'ip' => $clientIp,
			'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
		];

		$res = ActiveCodeService::activateCode($code, $deviceInfo);

		if ($res['status'] !== 'SUCCESS') {
			$this->deny = true;
			BruteforceGuard::checkBruteforce(null, null, $code);
			http_response_code(200);
			echo json_encode([
				'status' => 'ERROR',
				'error_code' => $res['status'],
				'user_info' => ['auth' => 0],
				'message' => $res['message'] ?? 'Activation failed.'
			], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
			exit();
		}

		$this->deny = false;

		$username = $res['line']['username'] ?? $res['credentials']['username'] ?? '';
		$password = $res['line']['password'] ?? $res['credentials']['password'] ?? '';
		$maxConnections = strval($res['line']['max_connections'] ?? $res['max_connections'] ?? '1');
		$codeDetails = $res['code_details'] ?? [];

		$serverId = defined('SERVER_ID') ? SERVER_ID : 1;
		$domainName = DomainResolver::resolve($serverId);
		$domain = parse_url($domainName, PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? 'localhost');
		$protocol = $rServers[$serverId]['server_protocol'] ?? 'http';
		$port = strval($rServers[$serverId]['http_broadcast_port'] ?? 80);
		$httpsPort = strval($rServers[$serverId]['https_broadcast_port'] ?? 443);
		$rtmpPort = strval($rServers[$serverId]['rtmp_port'] ?? 25462);

		$serverUrl = $protocol . '://' . $domain . ($port != '80' && $port != '443' ? ':' . $port : '');
		if (!empty($codeDetails['dns_base'])) {
			$serverUrl = rtrim($codeDetails['dns_base'], '/');
		}

		$m3uTs = $serverUrl . '/get.php?username=' . urlencode($username) . '&password=' . urlencode($password) . '&type=m3u_plus&output=ts';
		$m3uHls = $serverUrl . '/get.php?username=' . urlencode($username) . '&password=' . urlencode($password) . '&type=m3u_plus&output=m3u8';

		$output = [
			'status' => 'SUCCESS',
			'user_info' => [
				'username' => $username,
				'password' => $password,
				'message' => $rSettings['message_of_day'] ?? 'Welcome to IPTV',
				'auth' => 1,
				'status' => 'Active',
				'exp_date' => !empty($res['exp_date']) ? strval($res['exp_date']) : null,
				'exp_formatted' => $res['exp_formatted'] ?? ($res['exp_date_formatted'] ?? ''),
				'is_trial' => strval($codeDetails['is_trial'] ?? '0'),
				'active_cons' => '0',
				'created_at' => strval($codeDetails['created_at'] ?? time()),
				'max_connections' => $maxConnections,
				'allowed_output_formats' => ['m3u8', 'ts', 'rtmp']
			],
			'server_info' => [
				'version' => defined('XC_VM_VERSION') ? XC_VM_VERSION : '1.0.0',
				'url' => $domain,
				'port' => $port,
				'https_port' => $httpsPort,
				'server_protocol' => $protocol,
				'rtmp_port' => $rtmpPort,
				'timestamp_now' => time(),
				'time_now' => date('Y-m-d H:i:s'),
				'timezone' => ($rSettings['force_epg_timezone'] ?? false) ? 'UTC' : ($rSettings['default_timezone'] ?? 'UTC'),
				'process' => true
			],
			'active_code' => [
				'code' => $res['code'],
				'batch_name' => $codeDetails['batch_name'] ?? '',
				'package_name' => $res['package_name'] ?? 'Premium IPTV',
				'status' => 'Active',
				'is_new_activation' => $res['is_new_activation'] ?? false,
			],
			'playlists' => [
				'm3u_ts' => $m3uTs,
				'm3u_hls' => $m3uHls,
			],
			'player_api_url' => $serverUrl . '/player_api.php?username=' . urlencode($username) . '&password=' . urlencode($password),
			'web_player_url' => $res['web_player_url'] ?? null,
		];

		echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		exit();
	}

	/**
	 * Dispatch Admin Active Code actions.
	 */
	protected function handleAdminAction(string $action, array $data, int $start, int $limit, ?array $showColumns, ?array $hideColumns): void {
		switch ($action) {
			case 'get_active_codes':
			case 'list':
			case 'get_codes':
				echo json_encode(AdminAPIWrapper::getActiveCodes($start, $limit, $data, $showColumns, $hideColumns), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'get_active_code':
			case 'get':
			case 'details':
				$idOrCode = $data['id'] ?? $data['code'] ?? 0;
				echo json_encode(AdminAPIWrapper::getActiveCode($idOrCode), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'generate_active_codes':
			case 'create_active_code':
			case 'generate':
			case 'create':
				echo json_encode(AdminAPIWrapper::generateActiveCodes($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'edit_active_code':
			case 'edit':
			case 'update':
				$id = (int) ($data['id'] ?? 0);
				unset($data['id']);
				echo json_encode(AdminAPIWrapper::editActiveCode($id, $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'delete_active_code':
			case 'delete':
				$id = (int) ($data['id'] ?? 0);
				echo json_encode(AdminAPIWrapper::deleteActiveCode($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'enable_active_code':
			case 'enable':
				$id = (int) ($data['id'] ?? 0);
				echo json_encode(AdminAPIWrapper::enableActiveCode($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'disable_active_code':
			case 'disable':
				$id = (int) ($data['id'] ?? 0);
				echo json_encode(AdminAPIWrapper::disableActiveCode($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'reset_active_code_device':
			case 'reset_device':
				$idOrCode = $data['id'] ?? $data['code'] ?? 0;
				echo json_encode(AdminAPIWrapper::resetActiveCodeDevice($idOrCode), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'mass_active_codes':
			case 'mass':
				$subAction = (string) ($data['sub_action'] ?? $data['action_type'] ?? '');
				$ids = $data['ids'] ?? [];
				echo json_encode(AdminAPIWrapper::massActiveCodes($subAction, $ids, $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'get_active_codes_batches':
			case 'batches':
				$batchName = !empty($data['batch_name']) ? (string) $data['batch_name'] : null;
				echo json_encode(AdminAPIWrapper::getActiveCodesBatches($batchName), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'export_active_code_batch':
			case 'export':
				$batchName = (string) ($data['batch_name'] ?? '');
				$format = (string) ($data['format'] ?? 'json');
				echo json_encode(AdminAPIWrapper::exportActiveCodeBatch($batchName, $format), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			default:
				echo json_encode([
					'status' => 'STATUS_FAILURE',
					'error' => "Invalid or unrecognized admin active code action: '{$action}'"
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;
		}
	}

	/**
	 * Dispatch Reseller Active Code actions.
	 */
	protected function handleResellerAction(string $action, array $data, int $start, int $limit, ?array $showColumns, ?array $hideColumns): void {
		switch ($action) {
			case 'get_active_codes':
			case 'list':
			case 'get_codes':
				echo json_encode(ResellerAPIWrapper::getActiveCodes($start, $limit, $data, $showColumns, $hideColumns), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'get_active_code':
			case 'get':
			case 'details':
				$idOrCode = $data['id'] ?? $data['code'] ?? 0;
				echo json_encode(ResellerAPIWrapper::getActiveCode($idOrCode), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'generate_active_codes':
			case 'create_active_code':
			case 'generate':
			case 'create':
				echo json_encode(ResellerAPIWrapper::generateActiveCodes($data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'edit_active_code':
			case 'edit':
			case 'update':
				$id = (int) ($data['id'] ?? 0);
				unset($data['id']);
				echo json_encode(ResellerAPIWrapper::editActiveCode($id, $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'delete_active_code':
			case 'delete':
				$id = (int) ($data['id'] ?? 0);
				echo json_encode(ResellerAPIWrapper::deleteActiveCode($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'enable_active_code':
			case 'enable':
				$id = (int) ($data['id'] ?? 0);
				echo json_encode(ResellerAPIWrapper::enableActiveCode($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'disable_active_code':
			case 'disable':
				$id = (int) ($data['id'] ?? 0);
				echo json_encode(ResellerAPIWrapper::disableActiveCode($id), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'reset_active_code_device':
			case 'reset_device':
				$idOrCode = $data['id'] ?? $data['code'] ?? 0;
				echo json_encode(ResellerAPIWrapper::resetActiveCodeDevice($idOrCode), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'mass_active_codes':
			case 'mass':
				$subAction = (string) ($data['sub_action'] ?? $data['action_type'] ?? '');
				$ids = $data['ids'] ?? [];
				echo json_encode(ResellerAPIWrapper::massActiveCodes($subAction, $ids, $data), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'get_active_codes_batches':
			case 'batches':
				$batchName = !empty($data['batch_name']) ? (string) $data['batch_name'] : null;
				echo json_encode(ResellerAPIWrapper::getActiveCodesBatches($batchName), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			case 'export_active_code_batch':
			case 'export':
				$batchName = (string) ($data['batch_name'] ?? '');
				$format = (string) ($data['format'] ?? 'json');
				echo json_encode(ResellerAPIWrapper::exportActiveCodeBatch($batchName, $format), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;

			default:
				echo json_encode([
					'status' => 'STATUS_FAILURE',
					'error' => "Invalid or unrecognized reseller active code action: '{$action}'"
				], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
				break;
		}
	}
}
