<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;

/**
 * MoviesController — Movies (VOD) Catalog Controller for Web Player V2.
 *
 * Handles movies catalog, category filtering, search, sorting, and AJAX pagination.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class MoviesController extends BasePlayerV2Controller {
	public function index() {
		global $db, $rUserInfo;

		// Initialize vod_ids if not set
		if (!isset($rUserInfo['vod_ids']) || !is_array($rUserInfo['vod_ids'])) {
			$rUserInfo['vod_ids'] = [];
		}

		$code = $_SERVER['XC_CODE'] ?? '';
		$baseUrl = $code ? '/' . $code . '/' : '/';

		// AJAX movies retrieval endpoint
		if (RequestManager::get('ajax') === '1' || RequestManager::get('action') === 'movies') {
			header('Content-Type: application/json; charset=utf-8');

			$catId = RequestManager::has('category_id') && RequestManager::get('category_id') !== 'all' && RequestManager::get('category_id') !== ''
				? (int) RequestManager::get('category_id')
				: null;

			$sortBy = RequestManager::get('sort') ?: 'number';
			$searchBy = RequestManager::get('search') ?: null;
			$isPopular = RequestManager::get('filter') === 'popular' || $sortBy === 'popular';

			// Support External Xtream Codes
			if (!empty($rUserInfo['is_external_xc'])) {
				$extService = ExternalXtreamService::fromSession();
				$extVod = $extService instanceof ExternalXtreamService ? $extService->getVodStreams($catId) : [];
				if ($searchBy) {
					$extVod = array_filter($extVod, fn($m) => stripos($m['title'], $searchBy) !== false);
					$extVod = array_values($extVod);
				}
				$movies = [];
				foreach ($extVod as $m) {
					$movies[] = [
						'id' => (int) $m['id'],
						'title' => $m['title'],
						'year' => $m['year'],
						'rating' => $m['rating'] ?: 'N/A',
						'cover' => $m['poster'],
						'category_id' => (int) $m['category_id'],
						'duration' => 0,
					];
				}
				echo json_encode([
					'status' => 'success',
					'count' => count($movies),
					'movies' => $movies,
				]);
				exit;
			}

			if ($isPopular && file_exists(CONTENT_PATH . 'tmdb_popular')) {
				$popularData = @igbinary_unserialize(file_get_contents(CONTENT_PATH . 'tmdb_popular'));
				$popularIds = is_array($popularData) && !empty($popularData['movies']) ? $popularData['movies'] : [];
				$validVodIds = array_intersect(array_map('intval', $popularIds), array_map('intval', $rUserInfo['vod_ids']));

				if ($validVodIds !== []) {
					$cleanIds = implode(',', $validVodIds);
					$db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . $cleanIds . ') ORDER BY FIELD(id, ' . $cleanIds . ') LIMIT 100;');
					$streamList = $db->get_rows();
				} else {
					$streamList = [];
				}
			} else {
				if (empty($rUserInfo['vod_ids'])) {
					$where = ['`type` = 2'];
					$whereV = [];
					if (!empty($catId)) {
						$where[] = '`category_id` = ?';
						$whereV[] = $catId;
					}
					if (!empty($searchBy)) {
						$where[] = '`stream_display_name` LIKE ?';
						$whereV[] = '%' . $searchBy . '%';
					}
					$whereStr = implode(' AND ', $where);
					$db->query("SELECT * FROM `streams` WHERE {$whereStr} ORDER BY `id` DESC LIMIT 1000", ...$whereV);
					$streamList = $db->get_rows() ?: [];
				} else {
					$rStreams = getUserStreams(
						$rUserInfo,
						['movie'],
						$catId,
						null,
						$sortBy,
						$searchBy,
						[],
						0,
						1000,
						false
					);
					$streamList = isset($rStreams['streams']) ? $rStreams['streams'] : (is_array($rStreams) ? $rStreams : []);
				}
			}

			$movies = [];
			foreach ($streamList as $stream) {
				if (!is_array($stream) || empty($stream['id'])) {
					continue;
				}
				$props = json_decode($stream['movie_properties'] ?? '', true) ?: [];
				$coverUrl = !empty($props['movie_image']) ? ImageUtils::validateURL($props['movie_image']) : '';
				if (!$coverUrl && !empty($props['cover_big'])) {
					$coverUrl = ImageUtils::validateURL($props['cover_big']);
				}

				$rating = !empty($props['rating']) ? (float) $props['rating'] : (!empty($stream['rating']) ? (float) $stream['rating'] : 0);

				$movies[] = [
					'id' => (int) $stream['id'],
					'title' => $stream['stream_display_name'] ?? 'Movie #' . $stream['id'],
					'year' => !empty($stream['year']) ? (int) $stream['year'] : null,
					'rating' => $rating > 0 ? number_format($rating, 1) : 'N/A',
					'cover' => $coverUrl ?: '',
					'category_id' => $stream['category_id'] ?? 0,
					'duration' => !empty($props['duration_secs']) ? (int) ($props['duration_secs'] / 60) : 0,
				];
			}

			echo json_encode([
				'status' => 'success',
				'count' => count($movies),
				'movies' => $movies,
			]);
			exit;
		}

		// Standard Page Load
		$rCategories = PlayerCategoryHelper::getCategories($rUserInfo, 'movie');
		$firstCatId = !empty($rCategories[0]['id']) ? (int) $rCategories[0]['id'] : null;

		// Support External Xtream Standard Load
		if (!empty($rUserInfo['is_external_xc'])) {
			$extService = ExternalXtreamService::fromSession();
			$extVod = $extService instanceof ExternalXtreamService ? $extService->getVodStreams($firstCatId) : [];

			$initialMovies = [];
			foreach ($extVod as $m) {
				$initialMovies[] = [
					'id' => (int) $m['id'],
					'title' => $m['title'],
					'year' => $m['year'],
					'rating' => $m['rating'] ?: 'N/A',
					'cover' => $m['poster'],
					'category_id' => (int) $m['category_id'],
					'duration' => 0,
				];
			}

			$GLOBALS['_TITLE'] = 'Movies';
			$GLOBALS['_PAGE'] = 'movies';

			$this->render('movies', [
				'rCategories' => $rCategories,
				'initialMovies' => $initialMovies,
				'selectedCategoryId' => $firstCatId,
				'totalMoviesCount' => count($initialMovies),
				'baseUrl' => $baseUrl,
			]);
			return;
		}

		if (empty($rUserInfo['vod_ids'])) {
			$where = ['`type` = 2'];
			$whereV = [];
			if (!empty($firstCatId)) {
				$where[] = '`category_id` = ?';
				$whereV[] = $firstCatId;
			}
			$whereStr = implode(' AND ', $where);
			$db->query("SELECT * FROM `streams` WHERE {$whereStr} ORDER BY `id` DESC LIMIT 100", ...$whereV);
			$initialStreams = $db->get_rows() ?: [];
			$db->query("SELECT count(*) as c FROM `streams` WHERE `type` = 2;");
			$totalCount = (int) ($db->get_row()['c'] ?? 0);
		} else {
			$rStreams = getUserStreams(
				$rUserInfo,
				['movie'],
				$firstCatId,
				null,
				'number',
				null,
				[],
				0,
				100,
				false
			);

			$initialStreams = isset($rStreams['streams']) ? $rStreams['streams'] : (is_array($rStreams) ? $rStreams : []);
			$totalCount = count($rUserInfo['vod_ids']);
		}

		$initialMovies = [];
		foreach ($initialStreams as $stream) {
			if (!is_array($stream) || empty($stream['id'])) {
				continue;
			}
			$props = json_decode($stream['movie_properties'] ?? '', true) ?: [];
			$coverUrl = !empty($props['movie_image']) ? ImageUtils::validateURL($props['movie_image']) : '';
			if (!$coverUrl && !empty($props['cover_big'])) {
				$coverUrl = ImageUtils::validateURL($props['cover_big']);
			}

			$rating = !empty($props['rating']) ? (float) $props['rating'] : (!empty($stream['rating']) ? (float) $stream['rating'] : 0);

			$initialMovies[] = [
				'id' => (int) $stream['id'],
				'title' => $stream['stream_display_name'] ?? 'Movie #' . $stream['id'],
				'year' => !empty($stream['year']) ? (int) $stream['year'] : null,
				'rating' => $rating > 0 ? number_format($rating, 1) : 'N/A',
				'cover' => $coverUrl ?: '',
				'category_id' => $stream['category_id'] ?? 0,
				'duration' => !empty($props['duration_secs']) ? (int) ($props['duration_secs'] / 60) : 0,
			];
		}

		$GLOBALS['_TITLE'] = 'Movies (VOD)';
		$GLOBALS['_PAGE'] = 'movies';

		$this->render('movies', [
			'rCategories' => $rCategories,
			'initialMovies' => $initialMovies,
			'selectedCategoryId' => $firstCatId,
			'totalMoviesCount' => count($rUserInfo['vod_ids'] ?? []),
			'baseUrl' => $baseUrl,
		]);
	}
}
