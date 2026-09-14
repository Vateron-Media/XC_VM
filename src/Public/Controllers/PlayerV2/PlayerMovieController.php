<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Stream\CategoryService;

/**
 * PlayerMovieController — Movie Details & Player Controller for Web Player V2.
 *
 * Renders movie hero card, metadata, video player, and recommendations.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class PlayerMovieController extends BasePlayerV2Controller {
	public function index() {
		global $db, $rUserInfo;

		$id = (int) RequestManager::get('id');

		$code = $_SERVER['XC_CODE'] ?? '';
		$baseUrl = $code ? '/' . $code . '/' : '/';

		// Support External Xtream Movie View
		if (!empty($rUserInfo['is_external_xc'])) {
			$extService = \XcVm\Domain\External\ExternalXtreamService::fromSession();
			$infoData = $extService ? $extService->getVodInfo($id) : [];
			$info = $infoData['info'] ?? [];
			$movieData = $infoData['movie_data'] ?? [];

			$title = $info['name'] ?? ($movieData['name'] ?? ('Movie #' . $id));
			$posterUrl = $info['movie_image'] ?? ($info['cover_big'] ?? '');
			$backdropUrl = !empty($info['backdrop_path']) ? (is_array($info['backdrop_path']) ? ($info['backdrop_path'][0] ?? '') : $info['backdrop_path']) : $posterUrl;
			$ext = $movieData['container_extension'] ?? ($info['container_extension'] ?? 'mp4');
			$streamUrl = $extService ? $extService->buildVodUrl($id, $ext) : '';
			$catId = (int) ($movieData['category_id'] ?? ($info['category_id'] ?? 0));
			$catName = PlayerCategoryHelper::resolveCategoryName($catId, $rUserInfo, 'movie');

			$GLOBALS['_TITLE'] = $title;
			$GLOBALS['_PAGE']  = 'movies';

			$this->render('movie', [
				'movie'          => [
					'id' => $id,
					'stream_display_name' => $title,
					'year' => !empty($info['releasedate']) ? substr((string) $info['releasedate'], 0, 4) : null,
					'rating' => $info['rating'] ?? 'N/A',
				],
				'props'          => [
					'plot' => $info['plot'] ?? ($info['description'] ?? ''),
					'genre' => $info['genre'] ?? '',
					'cast' => $info['cast'] ?? '',
					'director' => $info['director'] ?? '',
					'duration' => !empty($info['duration_secs']) ? (int) ($info['duration_secs'] / 60) . ' min' : ($info['duration'] ?? ''),
					'release_date' => $info['releasedate'] ?? '',
				],
				'posterUrl'      => $posterUrl,
				'backdropUrl'    => $backdropUrl,
				'backdrops'      => $backdropUrl ? [$backdropUrl] : [],
				'streamUrl'      => $streamUrl,
				'categoryNames'  => [$catName],
				'similarMovies'  => [],
				'baseUrl'        => $baseUrl,
			]);
			return;
		}

		if ($id <= 0 || !in_array($id, $rUserInfo['vod_ids'] ?? [], true) || !($rStream = getStream($id))) {
			header('Location: movies');
			exit;
		}

		$code = $_SERVER['XC_CODE'] ?? '';
		$baseUrl = $code ? '/' . $code . '/' : '/';

		$props = json_decode($rStream['movie_properties'] ?? '', true) ?: [];

		// Backdrops & Posters
		$backdrops = [];
		if (!empty($props['backdrop_path'])) {
			$rawBackdrops = is_array($props['backdrop_path']) ? $props['backdrop_path'] : [$props['backdrop_path']];
			foreach ($rawBackdrops as $bd) {
				if (is_string($bd) && trim($bd) !== '') {
					$valid = ImageUtils::validateURL(trim($bd));
					if ($valid) {
						$backdrops[] = $valid;
					}
				}
			}
		}
		$backdropUrl = !empty($backdrops[0]) ? $backdrops[0] : '';
		$posterUrl = ImageUtils::validateURL($props['cover_big'] ?? '') ?: (ImageUtils::validateURL($props['movie_image'] ?? '') ?: '');

		// Stream URL
		$domainName = DomainResolver::resolve(
			SERVER_ID,
			(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
		);
		$streamUrl = $domainName . 'movie/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rStream['id'] . '.' . ($rStream['target_container'] ?? 'mp4');

		// Resolve Category Names (considering subscriber category template)
		$rawCats = $rStream['category_id'] ?? null;
		$categoryNames = [];
		$categoryIds = [];

		if (is_string($rawCats)) {
			$decoded = json_decode($rawCats, true);
			$categoryIds = is_array($decoded) ? $decoded : [(int) $rawCats];
		} elseif (is_array($rawCats)) {
			$categoryIds = $rawCats;
		} elseif (is_numeric($rawCats)) {
			$categoryIds = [(int) $rawCats];
		}

		foreach ($categoryIds as $catId) {
			$categoryNames[] = PlayerCategoryHelper::resolveCategoryName((int) $catId, $rUserInfo, 'movie');
		}

		// Recommendations / Similar Movies
		$similarMovies = [];
		$similarArray = json_decode($rStream['similar'] ?? '', true);

		if (is_array($similarArray) && !empty($similarArray)) {
			$cleanSimilar = implode(',', array_map('intval', $similarArray));
			$cleanVodIds = implode(',', array_map('intval', $rUserInfo['vod_ids']));
			$db->query('SELECT `id`, `stream_display_name`, `year`, `movie_properties` FROM `streams` WHERE `tmdb_id` IN (' . $cleanSimilar . ') AND `id` IN (' . $cleanVodIds . ') LIMIT 6;');

			foreach ($db->get_rows() as $row) {
				$simProps = json_decode($row['movie_properties'] ?? '', true) ?: [];
				$simCover = ImageUtils::validateURL($simProps['movie_image'] ?? '') ?: (ImageUtils::validateURL($simProps['cover_big'] ?? '') ?: '');
				$similarMovies[] = [
					'id' => (int) $row['id'],
					'title' => $row['stream_display_name'],
					'year' => !empty($row['year']) ? (int) $row['year'] : null,
					'rating' => !empty($simProps['rating']) ? number_format((float) $simProps['rating'], 1) : 'N/A',
					'cover' => $simCover,
				];
			}
		}

		// Fallback recommendations if similar count is low
		if (count($similarMovies) < 6 && !empty($categoryIds[0])) {
			$catFilter = (int) $categoryIds[0];
			$cleanVodIds = implode(',', array_map('intval', $rUserInfo['vod_ids']));
			$db->query("SELECT `id`, `stream_display_name`, `year`, `movie_properties` FROM `streams` WHERE JSON_CONTAINS(`category_id`, ?, '\$') AND `id` != ? AND `id` IN (" . $cleanVodIds . ") ORDER BY RAND() LIMIT " . (6 - count($similarMovies)) . ';', $catFilter, $rStream['id']);

			foreach ($db->get_rows() as $row) {
				$simProps = json_decode($row['movie_properties'] ?? '', true) ?: [];
				$simCover = ImageUtils::validateURL($simProps['movie_image'] ?? '') ?: (ImageUtils::validateURL($simProps['cover_big'] ?? '') ?: '');
				$similarMovies[] = [
					'id' => (int) $row['id'],
					'title' => $row['stream_display_name'],
					'year' => !empty($row['year']) ? (int) $row['year'] : null,
					'rating' => !empty($simProps['rating']) ? number_format((float) $simProps['rating'], 1) : 'N/A',
					'cover' => $simCover,
				];
			}
		}

		// Video & Audio Stream Specifications
		$video = $props['video'] ?? null;
		$audio = $props['audio'] ?? null;
		$width = !empty($video['width']) ? (int) $video['width'] : 0;
		$qualityBadge = 'HD';
		$qualityColor = 'primary';
		if ($width >= 3840) {
			$qualityBadge = '4K';
			$qualityColor = 'warning';
		} elseif ($width >= 1920) {
			$qualityBadge = '1080p FHD';
			$qualityColor = 'primary';
		} elseif ($width >= 1280) {
			$qualityBadge = '720p HD';
			$qualityColor = 'success';
		} elseif ($width > 0) {
			$qualityBadge = 'SD';
			$qualityColor = 'secondary';
		}

		$containerExtension = !empty($rStream['target_container']) ? $rStream['target_container'] : 'mp4';

		// Extract YouTube trailer ID if present
		$trailerId = '';
		if (!empty($props['youtube_trailer'])) {
			$rawTrailer = (string) $props['youtube_trailer'];
			if (preg_match('/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/i', $rawTrailer, $match)) {
				$trailerId = $match[1];
			} elseif (preg_match('/^[a-zA-Z0-9_-]{11}$/', $rawTrailer)) {
				$trailerId = $rawTrailer;
			}
		}

		// Simplify Video FPS
		$fps = null;
		if (!empty($video['r_frame_rate']) && is_string($video['r_frame_rate'])) {
			$parts = explode('/', $video['r_frame_rate']);
			if (count($parts) === 2 && (float) $parts[1] > 0) {
				$fps = round((float) $parts[0] / (float) $parts[1], 2);
			} elseif (is_numeric($video['r_frame_rate'])) {
				$fps = round((float) $video['r_frame_rate'], 2);
			}
		}

		// Cast List
		$castRaw = !empty($props['actors']) ? $props['actors'] : (!empty($props['cast']) ? $props['cast'] : '');
		$castList = [];
		if (is_string($castRaw) && trim($castRaw) !== '') {
			$castList = array_slice(array_filter(array_map('trim', explode(',', $castRaw))), 0, 12);
		}

		$GLOBALS['_TITLE'] = $rStream['stream_display_name'] . ' - Movie Details';
		$GLOBALS['_PAGE'] = 'movies';

		$this->render('movie', [
			'movie'              => $rStream,
			'props'              => $props,
			'video'              => $video,
			'audio'              => $audio,
			'fps'                => $fps,
			'qualityBadge'       => $qualityBadge,
			'qualityColor'       => $qualityColor,
			'containerExtension' => $containerExtension,
			'trailerId'          => $trailerId,
			'castList'           => $castList,
			'backdrops'          => $backdrops,
			'streamUrl'          => $streamUrl,
			'backdropUrl'        => $backdropUrl,
			'posterUrl'          => $posterUrl,
			'categoryNames'      => $categoryNames,
			'similarMovies'      => $similarMovies,
			'baseUrl'            => $baseUrl,
		]);
	}
}
