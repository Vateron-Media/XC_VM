<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Module\Plex\PlexRepository;

/**
 * AdminPlexController — Plex Sync listing (admin wrapper).
 *
 * @renders Views/admin/plex.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AdminPlexController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        // plex is an optional module (fetched from its own repo); empty list when absent.
        $rPlexServers = class_exists(PlexRepository::class) ? PlexRepository::getPlexServers() : [];
        if (!is_array($rPlexServers)) {
            $rPlexServers = [];
        }

        $this->setTitle('Plex Sync');
        $this->render('plex', compact('rPlexServers'));
    }
}
