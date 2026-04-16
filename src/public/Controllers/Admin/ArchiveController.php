<?php

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

        if (isset(RequestManager::getAll()['id'])) {
            $rStream = StreamRepository::getById(RequestManager::getAll()['id']);

            if (!$rStream || $rStream['type'] != 1 || $rStream['tv_archive_duration'] == 0 || $rStream['tv_archive_server_id'] == 0) {
                $this->redirect('archive');
                return;
            }

            $rArchive = StreamService::getArchive($rStream['id']);
        } else {
            $rRecordings = WatchService::getRecordings();
        }

        $rTitle = (!is_null($rRecordings) ? 'Recordings' : 'TV Archive');
        $this->setTitle($rTitle);
        $this->render('archive', compact('rRecordings', 'rStream', 'rArchive'));
    }
}
