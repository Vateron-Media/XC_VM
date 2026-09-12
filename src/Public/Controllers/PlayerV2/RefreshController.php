<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Domain\User\UserRepository;

/**
 * RefreshController — Data Synchronization & Cache Invalidation for Web Player V2.
 *
 * Flushes line runtime caches, bouquets cache, and reloads fresh database records
 * so all latest streams, movies, series, categories, and bouquets are synchronized immediately.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class RefreshController extends BasePlayerV2Controller
{
    public function index()
    {
        global $rUserInfo;

        $userId = !empty($rUserInfo['id']) ? (int)$rUserInfo['id'] : 0;
        $username = $rUserInfo['username'] ?? '';
        $password = $rUserInfo['password'] ?? '';

        if ($userId > 0) {
            // 1. Clear user line cache files
            if (defined('LINES_TMP_PATH')) {
                @unlink(LINES_TMP_PATH . 'line_i_' . $userId);
                if (!empty($username) && !empty($password)) {
                    @unlink(LINES_TMP_PATH . 'line_c_' . strtolower($username . '_' . $password));
                    @unlink(LINES_TMP_PATH . 'line_c_' . ($username . '_' . $password));
                }
                if (!empty($rUserInfo['access_token'])) {
                    @unlink(LINES_TMP_PATH . 'line_t_' . $rUserInfo['access_token']);
                }
            }

            // 2. Refresh global bouquets cache if needed
            if (defined('CACHE_TMP_PATH')) {
                if (file_exists(CACHE_TMP_PATH . 'bouquets')) {
                    @unlink(CACHE_TMP_PATH . 'bouquets');
                }
                \XcVm\Domain\Bouquet\BouquetService::getAll(true);
            }

            // 3. Re-fetch fresh user info directly from database without cache
            if (!empty($username) && !empty($password)) {
                $fresh = UserRepository::getUserInfo(null, $username, $password, false);
                if (is_array($fresh)) {
                    $rUserInfo = $fresh;
                }
            }
        }

        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'status'  => 'success',
            'message' => 'All catalog categories, channels, series, and bouquets data synchronized successfully.'
        ]);
        exit;
    }
}
