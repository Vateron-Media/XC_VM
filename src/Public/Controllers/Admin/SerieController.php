<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Vod\SeriesService;

/**
 * SerieController — редактирование/добавление сериала.
 *
 * @renders Views/admin/serie.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SerieController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $rServers;

        $rSeriesArr = null;
        if (RequestManager::has('id') && !($rSeriesArr = SeriesService::getById(RequestManager::get('id')))) {
            $this->redirect('series');
            return;
        }

        if (isset($rSeriesArr) && RequestManager::has('import')) {
            unset(RequestManager::getAll()['import']);
        }

        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<span class='badge bg-success'>Active</span>", 'icon' => 'icon-base ti tabler-player-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<span class='badge bg-secondary'>Offline</span>", 'icon' => 'icon-base ti tabler-player-stop', 'state' => ['opened' => true]],
        ];

        foreach ($rServers as $rServer) {
            $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'icon-base ti tabler-server', 'state' => ['opened' => true]];
        }

        // The import flow's server tree is driven by jstree.
        if (RequestManager::has('import')) {
            $GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
                (array) ($GLOBALS['xmNewuiVendors'] ?? []),
                ['jstree']
            )));
        }

        $this->setTitle('TV Series');
        $this->render('serie', compact('rSeriesArr', 'rTranscodeProfiles', 'rServerTree'));
    }
}
