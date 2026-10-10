<?php

namespace XcVm\Public\Controllers\Player;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;

/**
 * MoviesController — movies controller
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MoviesController extends BasePlayerController {
	public function index() {
		global $db, $rUserInfo;

		// The pages of the plain list; a "popular" list has none.
		$rPage = 1;
		$rLimit = 48;

		if (RequestManager::has('sort') && RequestManager::get('sort') == 'popular') {
			$rPopular = (igbinary_unserialize(file_get_contents(CONTENT_PATH . 'tmdb_popular'))['movies'] ?: []);

			if (0 < count($rPopular) && 0 < count($rUserInfo['vod_ids'])) {
				$db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . implode(',', $rPopular) . ') AND `id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular) . ') ASC LIMIT 100;');

				$rStreams = ['count' => $db->num_rows(), 'streams' => $db->get_rows()];
			} else {
				header('Location: movies');
				exit;
			}
		} else {
			$rPopular = false;
			$rPage = (intval(RequestManager::get('page') ?? 0) ?: 1);
			$rLimit = 48;
			$rSortArray = ['number' => 'Default', 'added' => 'Date Added', 'release' => 'Release Date', 'name' => 'Title A-Z', 'top' => 'Rating'];
			$rSortBy = (isset($rSortArray[RequestManager::get('sort') ?? '']) ? RequestManager::get('sort') : 'number');
			$rPicking = [];
			$rYearStart = (intval(RequestManager::get('year_s') ?? 0) ?: 1900);
			$rYearEnd = (intval(RequestManager::get('year_e') ?? 0) ?: date('Y'));

			if ($rYearStart < 1900 || date('Y') < $rYearStart) {
				$rYearStart = 1900;
			}

			if ($rYearEnd < 1900 || date('Y') < $rYearEnd || $rYearEnd < $rYearStart) {
				$rYearEnd = date('Y');
			}

			if (1900 < $rYearStart || $rYearEnd < date('Y')) {
				$rPicking['year_range'] = [$rYearStart, $rYearEnd];
			}

			$rRatingStart = (floatval(RequestManager::get('rating_s') ?? 0) ?: 0);
			$rRatingEnd = (floatval(RequestManager::get('rating_e') ?? 0) ?: 10);

			if ($rRatingStart < 0 || 10 < $rRatingStart) {
				$rRatingStart = 0;
			}

			if ($rRatingEnd < 0 || 10 < $rRatingEnd || $rRatingEnd < $rRatingStart) {
				$rRatingEnd = 10;
			}

			if (0 < $rRatingStart || $rRatingEnd < 10) {
				$rPicking['rating_range'] = [$rRatingStart, $rRatingEnd];
			}

			$rCategoryID = (intval(RequestManager::get('category') ?? 0) ?: null);
			$rSearchBy = (RequestManager::get('search') ?? null);

			if ($rSearchBy) {
				$rPage = 1;
				$rLimit = 100;
			}

			$rStreams = getUserStreams($rUserInfo, ['movie'], $rCategoryID, null, $rSortBy, $rSearchBy, $rPicking, ($rPage - 1) * $rLimit, $rLimit);
		}

		$rCover = '';
		$rShuffle = $rStreams['streams'];
		shuffle($rShuffle);

		foreach ($rShuffle as $rStream) {
			$rProperties = json_decode($rStream['movie_properties'], true);

			if (!empty($rProperties['backdrop_path'][0])) {
				$rCover = ImageUtils::validateURL($rProperties['backdrop_path'][0]);
				break;
			}
		}

		if (!$rPopular && (!isset($rSearchBy) || !$rSearchBy)) {
			$rCount = $rStreams['count'];
			$rPages = ceil($rCount / $rLimit);
			$rPagination = [];
			foreach (range($rPage - 2, $rPage + 2) as $i) {
				if (1 <= $i && $i <= $rPages) {
					$rPagination[] = $i;
				}
			}
		}

		$GLOBALS['_TITLE'] = 'Movies';
		$GLOBALS['rYearStart'] = isset($rYearStart) ? $rYearStart : 1900;
		$GLOBALS['rYearEnd'] = isset($rYearEnd) ? $rYearEnd : date('Y');
		$GLOBALS['rRatingStart'] = isset($rRatingStart) ? $rRatingStart : 0;
		$GLOBALS['rRatingEnd'] = isset($rRatingEnd) ? $rRatingEnd : 10;

		$this->render('movies', [
			'rPopular' => $rPopular,
			'rStreams' => $rStreams,
			'rCover' => $rCover,
			'rSortArray' => isset($rSortArray) ? $rSortArray : [],
			'rSortBy' => isset($rSortBy) ? $rSortBy : null,
			'rCategoryID' => isset($rCategoryID) ? $rCategoryID : null,
			'rSearchBy' => isset($rSearchBy) ? $rSearchBy : null,
			'rPage' => $rPage,
			'rPages' => isset($rPages) ? $rPages : 1,
			'rPagination' => isset($rPagination) ? $rPagination : [],
			'rYearStart' => isset($rYearStart) ? $rYearStart : 1900,
			'rYearEnd' => isset($rYearEnd) ? $rYearEnd : date('Y'),
			'rRatingStart' => isset($rRatingStart) ? $rRatingStart : 0,
			'rRatingEnd' => isset($rRatingEnd) ? $rRatingEnd : 10,
		]);
	}
}
