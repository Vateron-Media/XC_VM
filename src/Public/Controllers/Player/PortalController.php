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

        $result = null;
        if ($_SERVER['REQUEST_METHOD'] === 'POST' || $action === 'activate') {
            if (!empty($code)) {
                $deviceInfo = [
                    'mac' => trim(RequestManager::get('mac') ?? ''),
                    'device_id' => trim(RequestManager::get('device_id') ?? ''),
                    'ip' => $_SERVER['REMOTE_ADDR'] ?? '',
                ];
                $result = ActiveCodeService::activateCode($code, $deviceInfo);

                // If AJAX request, return JSON
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($result);
                    exit();
                }
            } else {
                $result = ['status' => 'ERROR', 'message' => 'Please enter an activation code.'];
                if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') {
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode($result);
                    exit();
                }
            }
        }

        require MAIN_HOME . 'Public/Views/portal/index.php';
        exit();
    }
}
