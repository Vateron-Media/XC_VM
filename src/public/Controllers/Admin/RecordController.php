<?php

/**
 * RecordController — Record programme.
 * Complex data-prep: stream/programme/archive loading from multiple sources.
 *
 * @renders Views/admin/record.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RecordController extends BaseAdminController {
    public function index() {
        global $db;

        $this->requirePermission();

        $rAvailableServers = $rServers = array();
        $rStream = $rProgramme = null;

        if (isset(RequestManager::getAll()['id'])) {
            $rStream = StreamRepository::getById(RequestManager::getAll()['id']);
            $rProgramme = EpgService::getProgramme(RequestManager::getAll()['id'], RequestManager::getAll()['programme']);

            if ($rStream && $rStream['type'] == 1 && $rProgramme) {
            } else {
                $this->redirect('record');
                return;
            }
        } else {
            if (isset(RequestManager::getAll()['archive'])) {
                $rArchive = json_decode(base64_decode(RequestManager::getAll()['archive']), true);
                $rStream = StreamRepository::getById($rArchive['stream_id']);
                $rProgramme = array('start' => $rArchive['start'], 'end' => $rArchive['end'], 'title' => $rArchive['title'], 'description' => $rArchive['description'], 'archive' => true);

                if ($rStream && $rStream['type'] == 1 && $rProgramme) {
                } else {
                    $this->redirect('record');
                    return;
                }
            } else {
                if (!isset(RequestManager::getAll()['stream_id'])) {
                } else {
                    $rStream = StreamRepository::getById(RequestManager::getAll()['stream_id']);
                    $rProgramme = array('start' => strtotime(RequestManager::getAll()['start_date']), 'end' => strtotime(RequestManager::getAll()['start_date']) + intval(RequestManager::getAll()['duration']) * 60, 'title' => '', 'description' => '');

                    if (!(!$rStream || $rStream['type'] != 1 || !$rProgramme || $rProgramme['end'] < time())) {
                    } else {
                        header('Location: record');
                    }
                }
            }
        }

        if (!$rStream) {
        } else {
            $rBitrate = null;
            $db->query('SELECT `server_id`, `bitrate` FROM `streams_servers` WHERE `stream_id` = ?;', $rStream['id']);

            foreach ($db->get_rows() as $rRow) {
                $rAvailableServers[] = $rRow['server_id'];
                if (!(!$rBitrate && $rRow['bitrate'] || $rRow['bitrate'] && $rBitrate < $rRow['bitrate'])) {
                } else {
                    $rBitrate = $rRow['bitrate'];
                }
            }
        }

        $this->setTitle('Record');
        $this->render('record', compact('rStream', 'rProgramme', 'rAvailableServers', 'rBitrate'));
    }
}
