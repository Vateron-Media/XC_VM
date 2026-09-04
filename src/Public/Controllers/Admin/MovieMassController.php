<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Vod\MovieService;

/**
 * MovieMassController — массовое редактирование фильмов.
 *
 * @renders Views/admin/movie_mass.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MovieMassController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $rServers;

        $rCategories = CategoryService::getAllByType('movie');

        if (RequestManager::has('submit_stream')) {
            $rReturn = MovieService::massEdit(RequestManager::getAll());
            $_STATUS = $rReturn['status'];
            $GLOBALS['_STATUS'] = $_STATUS;

            if ($_STATUS == 0) {
                header('Location: ./movies_mass?status=0');
                exit();
            }
        }

        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();
        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<span class='badge bg-success'>Online</span>", 'icon' => 'icon-base ti tabler-player-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<span class='badge bg-secondary'>Offline</span>", 'icon' => 'icon-base ti tabler-player-stop', 'state' => ['opened' => true]],
        ];

        foreach ($rServers as $rServer) {
            $rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'icon-base ti tabler-server', 'state' => ['opened' => true]];
        }

        // The load-balancer server tree on this page is driven by jstree.
        $GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
            (array) ($GLOBALS['xmNewuiVendors'] ?? []),
            ['jstree']
        )));

        $this->setTitle('Mass Edit Movies');
        $this->render('movie_mass', compact('rCategories', 'rTranscodeProfiles', 'rServerTree'));
    }
}
