<?php

/**
 * MovieListController — список фильмов.
 *
 * @renders Views/admin/movies.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MovieListController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rCategories = CategoryService::getAllByType('movie');
        $rAudioCodecs = $rVideoCodecs = [];

        global $db;
        $db->query('SELECT DISTINCT(`audio_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `audio_codec` IS NOT NULL AND `type` = 2 ORDER BY `audio_codec` ASC;');
        foreach ($db->get_rows() as $rRow) {
            $rAudioCodecs[] = $rRow['audio_codec'];
        }

        $db->query('SELECT DISTINCT(`video_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `video_codec` IS NOT NULL AND `type` = 2 ORDER BY `video_codec` ASC;');
        foreach ($db->get_rows() as $rRow) {
            $rVideoCodecs[] = $rRow['video_codec'];
        }

        $this->setTitle('Movies');
        $this->render('movies', compact('rCategories', 'rAudioCodecs', 'rVideoCodecs'));
    }
}
