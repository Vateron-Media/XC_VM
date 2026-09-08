<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Stream\CategoryService;

/**
 * RadioMassController — массовое редактирование радиостанций.
 *
 * @renders Views/admin/radio_mass.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RadioMassController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $rServers;

        $rCategories = CategoryService::getAllByType('radio');
        $rServerTree = array(
            array('id' => 'source', 'parent' => '#', 'text' => "<span class='badge bg-success'>Online</span>", 'icon' => 'icon-base ti tabler-player-play', 'state' => array('opened' => true)),
            array('id' => 'offline', 'parent' => '#', 'text' => "<span class='badge bg-secondary'>Offline</span>", 'icon' => 'icon-base ti tabler-player-stop', 'state' => array('opened' => true))
        );

        foreach ($rServers as $rServer) {
            $rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'icon-base ti tabler-server', 'state' => array('opened' => true));
        }

        // The load-balancer server tree on this page is driven by jstree.
        $GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
            (array) ($GLOBALS['xmNewuiVendors'] ?? []),
            ['jstree']
        )));

        $this->setTitle('Mass Edit Stations');
        $this->render('radio_mass', compact('rCategories', 'rServerTree'));
    }
}
