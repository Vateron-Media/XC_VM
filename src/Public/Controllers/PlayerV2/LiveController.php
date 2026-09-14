<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;

/**
 * LiveController — Live TV Controller for Web Player V2.
 *
 * Provides channel listing, category filtering, search, and AJAX channel loading.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class LiveController extends BasePlayerV2Controller
{
    public function index()
    {
        global $db, $rUserInfo;

        // Security / permission check
        if (!in_array(1, $rUserInfo['allowed_outputs'], true) || SettingsManager::getBool('disable_hls')) {
            header('Location: index');
            exit;
        }

        $code = $_SERVER['XC_CODE'] ?? '';
        $baseUrl = $code ? '/' . $code . '/' : '/';

        // AJAX channel retrieval endpoint
        if (RequestManager::get('ajax') === '1' || RequestManager::get('action') === 'channels') {
            header('Content-Type: application/json; charset=utf-8');

            $catId = RequestManager::has('category_id') && RequestManager::get('category_id') !== 'all' && RequestManager::get('category_id') !== ''
                ? (int)RequestManager::get('category_id')
                : null;

            $sortBy = RequestManager::get('sort') ?: 'number';
            $searchBy = RequestManager::get('search') ?: null;

            // Support External Xtream Codes
            if (!empty($rUserInfo['is_external_xc'])) {
                $extService = \XcVm\Domain\External\ExternalXtreamService::fromSession();
                $channels = $extService ? $extService->getLiveStreams($catId) : [];
                if ($searchBy) {
                    $channels = array_filter($channels, fn($c) => stripos($c['name'], $searchBy) !== false);
                    $channels = array_values($channels);
                }
                echo json_encode([
                    'status' => 'success',
                    'count' => count($channels),
                    'channels' => $channels,
                ]);
                exit;
            }

            $rStreams = getUserStreams(
                $rUserInfo,
                ['live', 'created_live'],
                $catId,
                null,
                $sortBy,
                $searchBy,
                [],
                0,
                1000,
                false
            );

            $streamList = isset($rStreams['streams']) ? $rStreams['streams'] : (is_array($rStreams) ? $rStreams : []);

            $domainName = DomainResolver::resolve(
                SERVER_ID,
                (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
            );

            $channels = [];
            foreach ($streamList as $stream) {
                if (!is_array($stream) || empty($stream['id'])) {
                    continue;
                }
                $streamId = (int)$stream['id'];
                $channels[] = [
                    'id' => $streamId,
                    'name' => $stream['stream_display_name'] ?? 'Channel #' . $streamId,
                    'logo' => !empty($stream['stream_icon']) ? $stream['stream_icon'] : '',
                    'category_id' => $stream['category_id'] ?? 0,
                    'archive' => !empty($stream['tv_archive']),
                    'url' => $domainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $streamId . '.m3u8',
                ];
            }

            echo json_encode([
                'status' => 'success',
                'count' => count($channels),
                'channels' => $channels,
            ]);
            exit;
        }

        // Standard Page Load
        $rCategories = PlayerCategoryHelper::getCategories($rUserInfo, 'live');
        $firstCatId = !empty($rCategories[0]['id']) ? (int)$rCategories[0]['id'] : null;

        // Support External Xtream Standard Load
        if (!empty($rUserInfo['is_external_xc'])) {
            $extService = \XcVm\Domain\External\ExternalXtreamService::fromSession();
            $initialChannels = $extService ? $extService->getLiveStreams($firstCatId) : [];

            $GLOBALS['_TITLE'] = 'Live TV';
            $GLOBALS['_PAGE'] = 'live';

            $this->render('live', [
                'rCategories' => $rCategories,
                'initialChannels' => $initialChannels,
                'selectedCategoryId' => $firstCatId,
                'totalLiveCount' => count($initialChannels),
                'baseUrl' => $baseUrl,
            ]);
            return;
        }

        $rStreams = getUserStreams(
            $rUserInfo,
            ['live', 'created_live'],
            $firstCatId,
            null,
            'number',
            null,
            [],
            0,
            500,
            false
        );

        $initialStreams = isset($rStreams['streams']) ? $rStreams['streams'] : (is_array($rStreams) ? $rStreams : []);

        $domainName = DomainResolver::resolve(
            SERVER_ID,
            (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        );

        $initialChannels = [];
        foreach ($initialStreams as $stream) {
            if (!is_array($stream) || empty($stream['id'])) {
                continue;
            }
            $streamId = (int)$stream['id'];
            $initialChannels[] = [
                'id' => $streamId,
                'name' => $stream['stream_display_name'] ?? 'Channel #' . $streamId,
                'logo' => !empty($stream['stream_icon']) ? $stream['stream_icon'] : '',
                'category_id' => $stream['category_id'] ?? 0,
                'archive' => !empty($stream['tv_archive']),
                'url' => $domainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $streamId . '.m3u8',
            ];
        }

        $GLOBALS['_TITLE'] = 'Live TV';
        $GLOBALS['_PAGE'] = 'live';

        $this->render('live', [
            'rCategories' => $rCategories,
            'initialChannels' => $initialChannels,
            'selectedCategoryId' => $firstCatId,
            'totalLiveCount' => count($rUserInfo['live_ids'] ?? []),
            'baseUrl' => $baseUrl,
        ]);
    }
}
