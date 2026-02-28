<?php
/**
 * StreamListController — список стримов (Phase 6.3 — Group A).
 */
class StreamListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        $rAudioCodecs = $rVideoCodecs = [];

        $db->query('SELECT DISTINCT(`audio_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `audio_codec` IS NOT NULL AND `type` = 1 ORDER BY `audio_codec` ASC;');
        foreach ($db->get_rows() as $rRow) {
            $rAudioCodecs[] = $rRow['audio_codec'];
        }

        $db->query('SELECT DISTINCT(`video_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `video_codec` IS NOT NULL AND `type` = 1 ORDER BY `video_codec` ASC;');
        foreach ($db->get_rows() as $rRow) {
            $rVideoCodecs[] = $rRow['video_codec'];
        }

        $this->setTitle('Streams');
        $this->render('streams', compact('rAudioCodecs', 'rVideoCodecs'));
    }
}
