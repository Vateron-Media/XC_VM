<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Stream\StreamRepository;

/**
 * RecordController — Record programme.
 * Complex data-prep: stream/programme/archive loading from multiple sources.
 *
 * @renders Views/admin/record.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/\XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RecordController extends BaseAdminController {
    public function index() {
        global $db;

        $this->requirePermission();

        $rAvailableServers = $rServers = array();
        $rStream = $rProgramme = $rBitrate = null;
        $rRequestData = RequestManager::getAll();

        if (isset($rRequestData['id'])) {
            $rStream = StreamRepository::getById($rRequestData['id']);
            $rProgramme = EpgService::getProgramme($rRequestData['id'], $rRequestData['programme']);

            if ($rStream && $rStream['type'] == 1 && $rProgramme) {
            } else {
                $this->redirect('record');
                return;
            }
        } else {
            if (isset($rRequestData['archive'])) {
                $rArchive = json_decode(base64_decode($rRequestData['archive']), true);
                $rStream = StreamRepository::getById($rArchive['stream_id']);
                $rProgramme = array('start' => $rArchive['start'], 'end' => $rArchive['end'], 'title' => $rArchive['title'], 'description' => $rArchive['description'], 'archive' => true);

                if ($rStream && $rStream['type'] == 1 && $rProgramme) {
                } else {
                    $this->redirect('record');
                    return;
                }
            } else {
                if (!isset($rRequestData['stream_id'])) {
                } else {
                    $rStream = StreamRepository::getById($rRequestData['stream_id']);
                    $rProgramme = array('start' => strtotime($rRequestData['start_date']), 'end' => strtotime($rRequestData['start_date']) + intval($rRequestData['duration']) * 60, 'title' => '', 'description' => '');

                    if (!(!$rStream || $rStream['type'] != 1 || !$rProgramme || $rProgramme['end'] < time())) {
                    } else {
                        header('Location: record');
                    }
                }
            }
        }

        if ($rStream) {
            $db->query('SELECT `server_id`, `bitrate` FROM `streams_servers` WHERE `stream_id` = ?;', $rStream['id']);

            foreach ($db->get_rows() as $rRow) {
                $rAvailableServers[] = $rRow['server_id'];
                if (!(!$rBitrate && $rRow['bitrate'] || $rRow['bitrate'] && $rBitrate < $rRow['bitrate'])) {
                } else {
                    $rBitrate = $rRow['bitrate'];
                }
            }
        }

        if (is_array($rProgramme) && !isset($rProgramme['archive'])) {
            $rProgramme['archive'] = false;
        }

        $this->setTitle('Record');
        $this->render('record', compact('rStream', 'rProgramme', 'rAvailableServers', 'rBitrate'));
    }
}
