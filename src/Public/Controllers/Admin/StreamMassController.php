<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\StreamConfigRepository;

/**
 * StreamMassController — массовое редактирование стримов.
 *
 * @renders Views/admin/stream_mass.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamMassController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $rServers;

        $rCategories = CategoryService::getAllByType('live');
        $rStreamArguments = StreamConfigRepository::getStreamArguments();
        $rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

        $rServerTree = [
            ['id' => 'source', 'parent' => '#', 'text' => "<span class='badge bg-success'>Online</span>", 'icon' => 'icon-base ti tabler-player-play', 'state' => ['opened' => true]],
            ['id' => 'offline', 'parent' => '#', 'text' => "<span class='badge bg-secondary'>Offline</span>", 'icon' => 'icon-base ti tabler-player-stop', 'state' => ['opened' => true]],
        ];

        foreach ($rServers as $rServer) {
            $rServerTree[] = ['id' => intval($rServer['id']), 'parent' => 'offline', 'text' => htmlspecialchars($rServer['server_name']), 'icon' => 'icon-base ti tabler-server', 'state' => ['opened' => true]];
        }

        // The load-balancer server tree on this form is driven by jstree.
        $GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
            (array) ($GLOBALS['xmNewuiVendors'] ?? []),
            ['jstree']
        )));

        $this->setTitle('Mass Edit Streams');
        $this->render('stream_mass', compact('rCategories', 'rStreamArguments', 'rTranscodeProfiles', 'rServerTree'));
    }
}
