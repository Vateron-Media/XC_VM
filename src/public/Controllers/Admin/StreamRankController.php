<?php
/**
 * StreamRankController — рейтинг стримов (Phase 6.3 — Group A).
 */
class StreamRankController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $db;

        $rStreamTypes = array(1 => 'Live Stream', 2 => 'Movie', 3 => 'Created Channel', 4 => 'Radio Station', 5 => 'Episode');
        $rPeriod = (CoreUtilities::$rRequest['period'] ?: 'all');
        $db->query('SELECT `streams_stats`.*, `streams`.`stream_display_name` FROM `streams_stats` INNER JOIN `streams` ON `streams`.`id` = `streams_stats`.`stream_id` WHERE `streams_stats`.`type` = ? AND `streams`.`type` IN (1,3) GROUP BY `stream_id` ORDER BY `streams_stats`.`rank` ASC LIMIT 500;', $rPeriod);
        $rRows = $db->get_rows();

        $this->setTitle('Stream Rank');
        $this->render('stream_rank', compact('rStreamTypes', 'rPeriod', 'rRows'));
    }
}
