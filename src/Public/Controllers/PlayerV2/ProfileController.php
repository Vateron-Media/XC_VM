<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\User\UserRepository;

/**
 * ProfileController — Subscriber Profile & Settings Controller for Web Player V2.
 *
 * Provides subscriber account metrics, active connection tracking, M3U/EPG playlist
 * generation, bouquet re-ordering, and API configuration according to Sneat standards.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class ProfileController extends BasePlayerV2Controller
{
    /**
     * Display subscriber profile overview & settings.
     */
    public function index()
    {
        global $db, $rUserInfo, $rSettings;

        if (empty($rUserInfo) || empty($rUserInfo['id'])) {
            header('Location: login');
            exit;
        }

        $userId = (int)$rUserInfo['id'];

        // Fresh line data from database
        $db->query('SELECT * FROM `lines` WHERE `id` = ? LIMIT 1', $userId);
        $lineData = $db->get_row() ?: $rUserInfo;

        // Active connection sessions for this subscriber
        $db->query(
            'SELECT `activity_id`, `stream_id`, `user_ip`, `user_agent`, `container`, `date_start`, `geoip_country_code`, `isp`
             FROM `lines_activity`
             WHERE `user_id` = ? AND `date_end` IS NULL
             ORDER BY `date_start` DESC LIMIT 10',
            $userId
        );
        $activeSessions = $db->get_rows() ?: [];
        $activeConsCount = count($activeSessions);

        // Bouquet definitions & mapping
        $allBouquets = BouquetService::getAll();
        $bouquetMap = [];
        foreach ($allBouquets as $b) {
            if (isset($b['id'], $b['bouquet_name'])) {
                $bouquetMap[$b['id']] = $b;
            }
        }

        // Output devices for M3U Playlist generator
        $db->query('SELECT * FROM `output_devices` WHERE `copy_text` IS NULL ORDER BY `device_id` ASC');
        $outputDevices = $db->get_rows() ?: [];

        // Public server URL for playlist and streaming endpoints
        $serverPublicUrl = '';
        if (defined('SERVER_ID')) {
            $serverPublicUrl = rtrim(DomainResolver::resolve(SERVER_ID), '/');
        }
        if (empty($serverPublicUrl)) {
            $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? '127.0.0.1';
            $serverPublicUrl = $proto . '://' . $host;
        }

        // Calculate expiration metrics
        $expTimestamp = !empty($lineData['exp_date']) ? (int)$lineData['exp_date'] : null;
        $now = time();
        $isExpired = $expTimestamp !== null && $expTimestamp <= $now;
        $isExpiringSoon = $expTimestamp !== null && !$isExpired && ($expTimestamp - $now < 7 * 86400);
        $daysRemaining = $expTimestamp !== null ? (int)ceil(($expTimestamp - $now) / 86400) : null;

        // Content totals
        $totalLive   = count($rUserInfo['live_ids'] ?? []);
        $totalVod    = count($rUserInfo['vod_ids'] ?? []);
        $totalSeries = count($rUserInfo['series_ids'] ?? []);
        $totalRadio  = count($rUserInfo['radio_ids'] ?? []);

        // Subscriber bouquets array
        $userBouquetIds = is_array($lineData['bouquet']) ? $lineData['bouquet'] : (json_decode($lineData['bouquet'] ?? '[]', true) ?: []);
        $userBouquets = [];
        foreach ($userBouquetIds as $bId) {
            $bId = (int)$bId;
            if (isset($bouquetMap[$bId])) {
                $bData = $bouquetMap[$bId];
                $bChannels = is_array($bData['bouquet_channels'] ?? null) ? count($bData['bouquet_channels']) : 0;
                $bSeries = is_array($bData['bouquet_series'] ?? null) ? count($bData['bouquet_series']) : 0;
                $userBouquets[] = [
                    'id' => $bId,
                    'name' => $bData['bouquet_name'],
                    'channels_count' => $bChannels,
                    'series_count' => $bSeries,
                ];
            }
        }

        $GLOBALS['_TITLE'] = 'Subscriber Profile';
        $GLOBALS['_PAGE'] = 'profile';

        $this->render('profile', [
            'lineData'           => $lineData,
            'activeConsCount'    => $activeConsCount,
            'activeSessions'     => $activeSessions,
            'userBouquets'       => $userBouquets,
            'outputDevices'      => $outputDevices,
            'serverPublicUrl'    => $serverPublicUrl,
            'expTimestamp'       => $expTimestamp,
            'isExpired'          => $isExpired,
            'isExpiringSoon'     => $isExpiringSoon,
            'daysRemaining'      => $daysRemaining,
            'totalLive'          => $totalLive,
            'totalVod'           => $totalVod,
            'totalSeries'        => $totalSeries,
            'totalRadio'         => $totalRadio,
        ]);
    }

    /**
     * Handle bouquet reordering submission.
     */
    public function saveBouquets()
    {
        global $db, $rUserInfo;

        if (empty($rUserInfo) || empty($rUserInfo['id'])) {
            http_response_code(401);
            echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
            exit;
        }

        $userId = (int)$rUserInfo['id'];
        $rawOrder = RequestManager::get('bouquet_order');

        if (empty($rawOrder)) {
            $rawInput = file_get_contents('php://input');
            $jsonData = json_decode($rawInput, true);
            $rawOrder = $jsonData['bouquet_order'] ?? null;
        }

        if (is_string($rawOrder)) {
            $orderArray = json_decode($rawOrder, true);
        } else {
            $orderArray = $rawOrder;
        }

        if (!is_array($orderArray)) {
            http_response_code(400);
            echo json_encode(['status' => 'error', 'message' => 'Invalid bouquet order payload.']);
            exit;
        }

        $sanitizedOrder = array_values(array_unique(array_filter(array_map('intval', $orderArray))));

        $db->query('UPDATE `lines` SET `bouquet` = ? WHERE `id` = ?', json_encode($sanitizedOrder), $userId);

        // Invalidate line cache & signal reload
        if (defined('LINES_TMP_PATH')) {
            @unlink(LINES_TMP_PATH . 'line_i_' . $userId);
            if (!empty($rUserInfo['username']) && !empty($rUserInfo['password'])) {
                $key = strtolower($rUserInfo['username']) . '_' . strtolower($rUserInfo['password']);
                @unlink(LINES_TMP_PATH . 'line_c_' . $key);
            }
            if (!empty($rUserInfo['access_token'])) {
                @unlink(LINES_TMP_PATH . 'line_t_' . $rUserInfo['access_token']);
            }
        }

        if (class_exists(LineService::class)) {
            LineService::updateLineSignal($userId, true);
        }

        // Refresh session
        $rUserInfo['bouquet'] = $sanitizedOrder;

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status' => 'success',
            'message' => 'Bouquet order saved and synchronized successfully.'
        ]);
        exit;
    }
}
