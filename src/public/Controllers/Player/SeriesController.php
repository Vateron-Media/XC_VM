<?php

class SeriesController extends BasePlayerController
{
    public function index()
    {
        global $db, $rUserInfo;

        if (isset(RequestManager::getAll()['sort']) && RequestManager::getAll()['sort'] == 'popular') {
            $rPopular = true;
            $rPopular = (igbinary_unserialize(file_get_contents(CONTENT_PATH . 'tmdb_popular'))['series'] ?: array());

            if (0 < count($rPopular) && 0 < count($rUserInfo['series_ids'])) {
                $db->query('SELECT `id`, `title`, `year`, `rating`, `cover`, `backdrop_path` FROM `streams_series` WHERE `id` IN (' . implode(',', $rPopular) . ') AND `id` IN (' . implode(',', $rUserInfo['series_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular) . ') ASC LIMIT 100;');

                $rSeries = array('count' => $db->num_rows(), 'streams' => $db->get_rows());
            } else {
                header('Location: series');
                exit;
            }
        } else {
            $rPopular = false;
            $rPage = (intval(RequestManager::getAll()['page'] ?? 0) ?: 1);
            $rLimit = 48;
            $rSortArray = array('number' => 'Default', 'added' => 'Last Updated', 'release' => 'Air Date', 'name' => 'Title A-Z', 'top' => 'Rating');
            $rSortBy = (isset($rSortArray[RequestManager::getAll()['sort'] ?? '']) ? RequestManager::getAll()['sort'] : 'number');
            $rPicking = array();
            $rYearStart = (intval(RequestManager::getAll()['year_s'] ?? 0) ?: 1900);
            $rYearEnd = (intval(RequestManager::getAll()['year_e'] ?? 0) ?: date('Y'));

            if (!($rYearStart < 1900 || date('Y') < $rYearStart)) {
            } else {
                $rYearStart = 1900;
            }

            if (!($rYearEnd < 1900 || date('Y') < $rYearEnd || $rYearEnd < $rYearStart)) {
            } else {
                $rYearEnd = date('Y');
            }

            if (!(1900 < $rYearStart || $rYearEnd < date('Y'))) {
            } else {
                $rPicking['year_range'] = array($rYearStart, $rYearEnd);
            }

            $rRatingStart = (intval(RequestManager::getAll()['rating_s'] ?? 0) ?: 0);
            $rRatingEnd = (intval(RequestManager::getAll()['rating_e'] ?? 0) ?: 10);

            if (!($rRatingStart < 0 || 10 < $rRatingStart)) {
            } else {
                $rRatingStart = 0;
            }

            if (!($rRatingEnd < 0 || 10 < $rRatingEnd || $rRatingEnd < $rRatingStart)) {
            } else {
                $rRatingEnd = 10;
            }

            if (!(0 < $rRatingStart || $rRatingEnd < 10)) {
            } else {
                $rPicking['rating_range'] = array($rRatingStart, $rRatingEnd);
            }

            $rCategoryID = (intval(RequestManager::getAll()['category'] ?? 0) ?: null);
            $rSearchBy = (RequestManager::getAll()['search'] ?? null);

            if (!$rSearchBy) {
            } else {
                $rPage = 1;
                $rLimit = 100;
            }

            $rSeries = getUserSeries($rUserInfo, $rCategoryID, null, $rSortBy, $rSearchBy, $rPicking, ($rPage - 1) * $rLimit, $rLimit);
        }

        $rCover = '';
        $rShuffle = $rSeries['streams'];
        shuffle($rShuffle);

        foreach ($rShuffle as $rStream) {
            $rBackdrop = json_decode($rStream['backdrop_path'], true);

            if (empty($rBackdrop[0])) {
            } else {
                $rCover = ImageUtils::validateURL($rBackdrop[0]);
                break;
            }
        }

        if ($rPopular || (isset($rSearchBy) && $rSearchBy)) {
        } else {
            $rCount = $rSeries['count'];
            $rPages = ceil($rCount / $rLimit);
            $rPagination = array();

            foreach (range($rPage - 2, $rPage + 2) as $i) {
                if (!(1 <= $i && $i <= $rPages)) {
                } else {
                    $rPagination[] = $i;
                }
            }
        }

        $GLOBALS['_TITLE'] = 'TV Series';
        $GLOBALS['rYearStart'] = isset($rYearStart) ? $rYearStart : 1900;
        $GLOBALS['rYearEnd'] = isset($rYearEnd) ? $rYearEnd : date('Y');
        $GLOBALS['rRatingStart'] = isset($rRatingStart) ? $rRatingStart : 0;
        $GLOBALS['rRatingEnd'] = isset($rRatingEnd) ? $rRatingEnd : 10;

        $this->render('series', [
            'rPopular' => $rPopular,
            'rSeries' => $rSeries,
            'rCover' => $rCover,
            'rSortArray' => isset($rSortArray) ? $rSortArray : [],
            'rSortBy' => isset($rSortBy) ? $rSortBy : null,
            'rCategoryID' => isset($rCategoryID) ? $rCategoryID : null,
            'rSearchBy' => isset($rSearchBy) ? $rSearchBy : null,
            'rPage' => isset($rPage) ? $rPage : 1,
            'rPages' => isset($rPages) ? $rPages : 1,
            'rPagination' => isset($rPagination) ? $rPagination : [],
            'rYearStart' => isset($rYearStart) ? $rYearStart : 1900,
            'rYearEnd' => isset($rYearEnd) ? $rYearEnd : date('Y'),
            'rRatingStart' => isset($rRatingStart) ? $rRatingStart : 0,
            'rRatingEnd' => isset($rRatingEnd) ? $rRatingEnd : 10,
        ]);
    }
}
