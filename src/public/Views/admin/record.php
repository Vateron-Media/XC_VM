<?php

if (!isset($__viewMode)):
    include 'session.php';
    include 'functions.php';

    if (!PageAuthorization::checkPermissions()) {
        AdminHelpers::goHome();
    }

    $rAvailableServers = array();
    $rStream = $rProgramme = null;

    if (isset(RequestManager::getAll()['id'])) {
        $rStream = StreamRepository::getById(RequestManager::getAll()['id']);
        $rProgramme = EpgService::getProgramme(RequestManager::getAll()['id'], RequestManager::getAll()['programme']);

        if ($rStream && $rStream['type'] == 1 && $rProgramme) {
        } else {
            AdminHelpers::goHome();
        }
    } else {
        if (isset(RequestManager::getAll()['archive'])) {
            $rArchive = json_decode(base64_decode(RequestManager::getAll()['archive']), true);
            $rStream = StreamRepository::getById($rArchive['stream_id']);
            $rProgramme = array('start' => $rArchive['start'], 'end' => $rArchive['end'], 'title' => $rArchive['title'], 'description' => $rArchive['description'], 'archive' => true);

            if ($rStream && $rStream['type'] == 1 && $rProgramme) {
            } else {
                AdminHelpers::goHome();
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

    $_TITLE = 'Record';

    require_once __DIR__ . '/../layouts/admin.php';
    renderUnifiedLayoutHeader('admin');
endif; // !$__viewMode
include dirname(__DIR__, 3) . '/modules/watch/views/record.php';
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__, 3) . '/modules/watch/views/record_scripts.php';
