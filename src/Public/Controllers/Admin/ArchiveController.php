<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Stream\StreamService;
use XcVm\Module\Watch\WatchService;

/**
 * ArchiveController — TV Archive / Recordings.
 *
 * @renders Views/admin/archive.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ArchiveController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db;

        $rRecordings = null;
        $rStream = null;
        $rArchive = null;

        if (RequestManager::has('id')) {
            $rStream = StreamRepository::getById(RequestManager::get('id'));

            if (!$rStream || $rStream['type'] != 1 || $rStream['tv_archive_duration'] == 0 || $rStream['tv_archive_server_id'] == 0) {
                $this->redirect('archive');
                return;
            }

            $rArchive = StreamService::getArchive($rStream['id']);
        } else {
            // Recordings are provided by the optional watch module; empty when absent.
            $rRecordings = class_exists(WatchService::class) ? WatchService::getRecordings() : array();
        }

        $rTitle = (!is_null($rRecordings) ? 'Recordings' : 'TV Archive');
        $this->setTitle($rTitle);
        $this->render('archive', compact('rRecordings', 'rStream', 'rArchive'));
    }
}
