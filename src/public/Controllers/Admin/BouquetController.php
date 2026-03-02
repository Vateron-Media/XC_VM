<?php

/**
 * BouquetController — Add/Edit Bouquet (admin/bouquet.php).
 *
 * Форма добавления/редактирования букета с вкладками:
 * details, streams, movies, series, radios, review.
 * Server-side DataTables для каждого типа контента.
 *
 * Legacy: admin/bouquet.php (495 строк)
 * Route:  GET /admin/bouquet → index()
 */
class BouquetController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Bouquets');

        $rBouquetArr = null;
        $rNames = [];
        $rSeriesNames = [];
        $rBouquetChannels = [];
        $rBouquetMovies = [];
        $rBouquetRadios = [];
        $rBouquetSeries = [];

        // Загрузка букета для редактирования
        $editId = $this->input('id');
        $duplicateId = $this->input('duplicate');

        if ($editId !== null) {
            $rBouquetArr = function_exists('getBouquet') ? getBouquet($editId) : null;
        } elseif ($duplicateId !== null) {
            $rBouquetArr = function_exists('getBouquet') ? getBouquet($duplicateId) : null;
            if ($rBouquetArr) {
                $rBouquetArr['bouquet_name'] .= ' - Copy';
                unset($rBouquetArr['id']);
            }
        }

        // Разбор связанного контента
        if ($rBouquetArr) {
            $rBouquetChannels = json_decode($rBouquetArr['bouquet_channels'], true) ?: [];
            $rBouquetMovies = json_decode($rBouquetArr['bouquet_movies'], true) ?: [];
            $rBouquetRadios = json_decode($rBouquetArr['bouquet_radios'], true) ?: [];
            $rBouquetSeries = json_decode($rBouquetArr['bouquet_series'], true) ?: [];

            // Имена потоков/фильмов/радио
            $rRequiredIDs = function_exists('confirmIDs')
                ? confirmIDs(array_merge($rBouquetChannels, $rBouquetMovies, $rBouquetRadios))
                : array_map('intval', array_merge($rBouquetChannels, $rBouquetMovies, $rBouquetRadios));

            if (count($rRequiredIDs) > 0) {
                global $db;
                $db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rRequiredIDs) . ');');
                foreach ($db->get_rows() as $rRow) {
                    $rNames[$rRow['id']] = $rRow['stream_display_name'];
                }
            }

            // Имена сериалов
            if (count($rBouquetSeries) > 0) {
                global $db;
                $db->query('SELECT `id`, `title` FROM `streams_series` WHERE `id` IN (' . implode(',', $rBouquetSeries) . ');');
                foreach ($db->get_rows() as $rRow) {
                    $rSeriesNames[$rRow['id']] = $rRow['title'];
                }
            }
        }

        // Категории для вкладок
        $liveCategories = function_exists('getCategories') ? getCategories('live') : [];
        $movieCategories = function_exists('getCategories') ? getCategories('movie') : [];
        $seriesCategories = function_exists('getCategories') ? getCategories('series') : [];
        $radioCategories = function_exists('getCategories') ? getCategories('radio') : [];

        $this->render('bouquet', [
            'rBouquetArr'       => $rBouquetArr,
            'rNames'            => $rNames,
            'rSeriesNames'      => $rSeriesNames,
            'rBouquetChannels'  => $rBouquetChannels,
            'rBouquetMovies'    => $rBouquetMovies,
            'rBouquetRadios'    => $rBouquetRadios,
            'rBouquetSeries'    => $rBouquetSeries,
            'liveCategories'    => $liveCategories,
            'movieCategories'   => $movieCategories,
            'seriesCategories'  => $seriesCategories,
            'radioCategories'   => $radioCategories,
        ]);
    }
}
