<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Auth\BruteforceGuard;
use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\ActiveCodeService;

/**
 * ActiveCodeApiController — Dedicated REST API for Smart Activation Codes
 *
 * Provides authentication, first-time delayed countdown activation,
 * and Xtream Codes compatible response payloads for client applications
 * (Android TV, Smart TVs, Enigma, STB, Web, and Mobile players).
 *
 * Endpoint: /api/active_code or /api/v1/active-code/auth
 *
 * @package XC_VM_Public_Controllers_Api
 */
class ActiveCodeApiController extends BaseApiController {

    public function index() {
        set_time_limit(0);

        header('Content-Type: application/json; charset=utf-8');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
        header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
            http_response_code(200);
            exit();
        }

        global $rSettings, $rServers;

        $rawBody = @file_get_contents('php://input');
        $jsonInput = !empty($rawBody) ? json_decode($rawBody, true) : null;

        $code = trim(
            RequestManager::get('code')
            ?? RequestManager::get('activation_code')
            ?? RequestManager::get('pin')
            ?? RequestManager::get('c')
            ?? ($jsonInput['code'] ?? $jsonInput['activation_code'] ?? $jsonInput['pin'] ?? '')
        );

        $mac = trim(
            RequestManager::get('mac')
            ?? RequestManager::get('mac_address')
            ?? ($jsonInput['mac'] ?? $jsonInput['mac_address'] ?? '')
        );

        $deviceId = trim(
            RequestManager::get('device_id')
            ?? RequestManager::get('device')
            ?? ($jsonInput['device_id'] ?? $jsonInput['device'] ?? '')
        );

        $action = trim(
            RequestManager::get('action')
            ?? ($jsonInput['action'] ?? 'auth')
        );

        if (empty($code)) {
            $this->deny = true;
            http_response_code(400);
            echo json_encode([
                'status' => 'ERROR',
                'user_info' => ['auth' => 0],
                'message' => 'Missing activation code. Please provide "code" or "activation_code".'
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            exit();
        }

        $clientIp = $_SERVER['REMOTE_ADDR'] ?? '';
        $deviceInfo = [
            'mac' => $mac,
            'device_id' => $deviceId,
            'ip' => $clientIp,
        ];

        // Process activation / stock-countdown trigger
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

        // Build standard server_info
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
            'player_api_url' => $serverUrl . '/player_api.php?username=' . urlencode($username) . '&password=' . urlencode($password)
        ];

        echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        exit();
    }
}
