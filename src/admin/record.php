<?php

if (!isset($__viewMode)):
    include 'session.php';
    include 'functions.php';

    if (!checkPermissions()) {
        goHome();
    }

    $rAvailableServers = $rServers = array();
    $rStream = $rProgramme = null;

    if (isset(CoreUtilities::$rRequest['id'])) {
        $rStream = StreamRepository::getById(CoreUtilities::$rRequest['id']);
        $rProgramme = CoreUtilities::getProgramme(CoreUtilities::$rRequest['id'], CoreUtilities::$rRequest['programme']);

        if ($rStream && $rStream['type'] == 1 && $rProgramme) {
        } else {
            goHome();
        }
    } else {
        if (isset(CoreUtilities::$rRequest['archive'])) {
            $rArchive = json_decode(base64_decode(CoreUtilities::$rRequest['archive']), true);
            $rStream = StreamRepository::getById($rArchive['stream_id']);
            $rProgramme = array('start' => $rArchive['start'], 'end' => $rArchive['end'], 'title' => $rArchive['title'], 'description' => $rArchive['description'], 'archive' => true);

            if ($rStream && $rStream['type'] == 1 && $rProgramme) {
            } else {
                goHome();
            }
        } else {
            if (!isset(CoreUtilities::$rRequest['stream_id'])) {
            } else {
                $rStream = StreamRepository::getById(CoreUtilities::$rRequest['stream_id']);
                $rProgramme = array('start' => strtotime(CoreUtilities::$rRequest['start_date']), 'end' => strtotime(CoreUtilities::$rRequest['start_date']) + intval(CoreUtilities::$rRequest['duration']) * 60, 'title' => '', 'description' => '');

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

    require_once __DIR__ . '/../interfaces/Http/Views/layouts/admin.php';
    renderUnifiedLayoutHeader('admin');
endif; // !$__viewMode
include dirname(__DIR__) . '/modules/watch/views/record.php';
require_once __DIR__ . '/../interfaces/Http/Views/layouts/footer.php';
renderUnifiedLayoutFooter('admin');
include dirname(__DIR__) . '/modules/watch/views/record_scripts.php';
