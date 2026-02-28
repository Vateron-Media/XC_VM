<?php

/**
 * BouquetSortController — Bouquet Sort / Reorder Content (admin/bouquet_sort.php).
 *
 * Двусторонняя сортировка контента внутри букета (streams, movies, series, radios).
 * Требует параметр id букета.
 *
 * Legacy: admin/bouquet_sort.php (589 строк)
 * Route:  GET /admin/bouquet_sort → index()
 */
class BouquetSortController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $bouquetId = $this->input('id');
        $rBouquet = ($bouquetId && function_exists('getBouquet')) ? getBouquet($bouquetId) : null;

        if (!$rBouquet) {
            if (function_exists('goHome')) {
                goHome();
            }
            exit;
        }

        $this->setTitle('Bouquet Sort');

        global $db;

        $rListings = ['stream' => [], 'movie' => [], 'radio' => [], 'series' => []];
        $rOrdered = ['stream' => [], 'movie' => [], 'radio' => [], 'series' => []];

        $rChannels = array_map('intval', json_decode($rBouquet['bouquet_channels'], true) ?: []);
        $rMovies = array_map('intval', json_decode($rBouquet['bouquet_movies'], true) ?: []);
        $rSeries = array_map('intval', json_decode($rBouquet['bouquet_series'], true) ?: []);
        $rRadios = array_map('intval', json_decode($rBouquet['bouquet_radios'], true) ?: []);

        if (count($rChannels) > 0) {
            $db->query('SELECT `streams`.`id`, `streams`.`type`, `streams`.`category_id`, `streams`.`stream_display_name` FROM `streams` WHERE `streams`.`type` IN (1,3) AND `streams`.`id` IN (' . implode(',', array_map('intval', $rChannels)) . ');');
            foreach ($db->get_rows() as $row) {
                $rListings['stream'][intval($row['id'])] = $row;
            }
        }

        if (count($rMovies) > 0) {
            $db->query('SELECT `streams`.`id`, `streams`.`type`, `streams`.`category_id`, `streams`.`stream_display_name` FROM `streams` WHERE `streams`.`type` = 2 AND `streams`.`id` IN (' . implode(',', array_map('intval', $rMovies)) . ');');
            foreach ($db->get_rows() as $row) {
                $rListings['movie'][intval($row['id'])] = $row;
            }
        }

        if (count($rRadios) > 0) {
            $db->query('SELECT `streams`.`id`, `streams`.`type`, `streams`.`category_id`, `streams`.`stream_display_name` FROM `streams` WHERE `streams`.`type` = 4 AND `streams`.`id` IN (' . implode(',', array_map('intval', $rRadios)) . ');');
            foreach ($db->get_rows() as $row) {
                $rListings['radio'][intval($row['id'])] = $row;
            }
        }

        if (count($rSeries) > 0) {
            $db->query('SELECT `streams_series`.`id`, `streams_series`.`category_id`, `streams_series`.`title` FROM `streams_series` WHERE `streams_series`.`id` IN (' . implode(',', array_map('intval', $rSeries)) . ');');
            foreach ($db->get_rows() as $row) {
                $rListings['series'][intval($row['id'])] = $row;
            }
        }

        // Сохранение порядка из JSON
        foreach ($rChannels as $id) {
            if (isset($rListings['stream'][$id])) {
                $rOrdered['stream'][] = $rListings['stream'][$id];
            }
        }
        foreach ($rMovies as $id) {
            if (isset($rListings['movie'][$id])) {
                $rOrdered['movie'][] = $rListings['movie'][$id];
            }
        }
        foreach ($rRadios as $id) {
            if (isset($rListings['radio'][$id])) {
                $rOrdered['radio'][] = $rListings['radio'][$id];
            }
        }
        foreach ($rSeries as $id) {
            if (isset($rListings['series'][$id])) {
                $rOrdered['series'][] = $rListings['series'][$id];
            }
        }

        $this->render('bouquet_sort', [
            'rBouquet' => $rBouquet,
            'rOrdered' => $rOrdered,
        ]);
    }
}
