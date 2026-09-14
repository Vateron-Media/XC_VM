<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Http\RequestManager;

/**
 * PlayerWatchController — Dedicated Cinema Theater Player for Web Player V2.
 *
 * Handles dedicated cinema theater playback for both Movies (VOD) and TV Series Episodes.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class PlayerWatchController extends BasePlayerV2Controller {
	public function index() {
		global $db, $rUserInfo;

		$type = RequestManager::get('type') === 'series' ? 'series' : 'movie';
		$id = (int) RequestManager::get('id');

		$code = $_SERVER['XC_CODE'] ?? '';
		$baseUrl = $code ? '/' . $code . '/' : '/';

		// Support External Xtream Playback
		if (!empty($rUserInfo['is_external_xc'])) {
			$extService = \XcVm\Domain\External\ExternalXtreamService::fromSession();

			if ($type === 'series') {
				$seriesId = (int) RequestManager::get('series_id');
				$seriesInfoData = $extService ? $extService->getSeriesInfo($seriesId) : [];
				$info = $seriesInfoData['info'] ?? [];
				$episodesRaw = $seriesInfoData['episodes'] ?? [];

				$allEpsEnriched = [];
				$seasons = [];
				$targetEp = null;
				$prevEp = null;
				$nextEp = null;

				$allFlatEps = [];
				foreach ($episodesRaw as $sKey => $sEps) {
					$sN = (int) $sKey;
					$seasons[$sN] = true;
					foreach ($sEps as $ep) {
						$eId = (int) ($ep['id'] ?? 0);
						$eN = (int) ($ep['episode_num'] ?? 1);
						$epExt = $ep['container_extension'] ?? 'mp4';
						$epUrl = $extService ? $extService->buildSeriesUrl($eId, $epExt) : '';
						$epItem = [
							'stream_id'           => $eId,
							'season_num'          => $sN,
							'episode_num'         => $eN,
							'stream_display_name' => $ep['title'] ?? ('Episode ' . $eN),
							'stream_icon'         => $ep['info']['movie_image'] ?? ($info['cover'] ?? ''),
							'play_url'            => $epUrl,
							'duration_secs'       => (int) ($ep['info']['duration_secs'] ?? 0),
							'duration_text'       => $ep['info']['duration'] ?? '',
							'container_extension' => $epExt,
						];
						$allEpsEnriched[] = $epItem;
						$allFlatEps[] = $epItem;
					}
				}

				foreach ($allFlatEps as $idx => $epItem) {
					if ($epItem['stream_id'] === $id) {
						$targetEp = $epItem;
						if (isset($allFlatEps[$idx - 1])) {
							$prevEp = $allFlatEps[$idx - 1];
						}
						if (isset($allFlatEps[$idx + 1])) {
							$nextEp = $allFlatEps[$idx + 1];
						}
						break;
					}
				}

				if (!$targetEp && !empty($allFlatEps[0])) {
					$targetEp = $allFlatEps[0];
					$id = $targetEp['stream_id'];
				}

				$seasonNum = $targetEp['season_num'] ?? 1;
				$episodeNum = $targetEp['episode_num'] ?? 1;
				$streamUrl = $targetEp['play_url'] ?? '';
				$seriesTitle = $info['name'] ?? ('Series #' . $seriesId);
				$playbackTitle = $seriesTitle . ' - S' . sprintf('%02d', $seasonNum) . 'E' . sprintf('%02d', $episodeNum) . (!empty($targetEp['stream_display_name']) ? ': ' . $targetEp['stream_display_name'] : '');
				$backUrl = $baseUrl . 'series?id=' . $seriesId;

				$GLOBALS['_TITLE'] = 'Playing: ' . $playbackTitle;
				$GLOBALS['_PAGE'] = 'series';

				$this->render('player', [
					'type'          => 'series',
					'series'        => [
						'id' => $seriesId,
						'stream_display_name' => $seriesTitle,
						'year' => !empty($info['releaseDate']) ? substr((string) $info['releaseDate'], 0, 4) : null,
						'rating' => $info['rating'] ?? '',
						'cover' => $info['cover'] ?? '',
					],
					'stream'        => [
						'id' => $id,
						'stream_display_name' => $targetEp['stream_display_name'] ?? $playbackTitle,
						'stream_icon' => $targetEp['stream_icon'] ?? '',
						'year' => !empty($info['releaseDate']) ? substr((string) $info['releaseDate'], 0, 4) : null,
						'rating' => $info['rating'] ?? '',
					],
					'seriesId'      => $seriesId,
					'seasonNum'     => $seasonNum,
					'episodeNum'    => $episodeNum,
					'allEpisodes'   => $allEpsEnriched,
					'seasons'       => array_keys($seasons),
					'prevEp'        => $prevEp,
					'nextEp'        => $nextEp,
					'playbackTitle' => $playbackTitle,
					'backUrl'       => $backUrl,
					'props'         => [
						'plot' => $info['plot'] ?? '',
						'genre' => $info['genre'] ?? '',
						'cast' => $info['cast'] ?? '',
						'director' => $info['director'] ?? '',
					],
					'streamUrl'     => $streamUrl,
					'qualityBadge'  => 'HD',
					'qualityColor'  => 'primary',
					'categoryName'  => $seriesTitle,
					'baseUrl'       => $baseUrl,
				]);
				return;
			} else {
				// Movie Playback
				$vodInfoData = $extService ? $extService->getVodInfo($id) : [];
				$info = $vodInfoData['info'] ?? [];
				$movieData = $vodInfoData['movie_data'] ?? [];

				$title = $info['name'] ?? ($movieData['name'] ?? ('Movie #' . $id));
				$ext = $movieData['container_extension'] ?? ($info['container_extension'] ?? 'mp4');
				$streamUrl = $extService ? $extService->buildVodUrl($id, $ext) : '';
				$posterUrl = $info['movie_image'] ?? ($info['cover_big'] ?? '');
				$catId = (int) ($movieData['category_id'] ?? ($info['category_id'] ?? 0));
				$catName = PlayerCategoryHelper::resolveCategoryName($catId, $rUserInfo, 'movie');
				$backUrl = $baseUrl . 'movie?id=' . $id;

				$GLOBALS['_TITLE'] = 'Playing: ' . $title;
				$GLOBALS['_PAGE'] = 'movies';

				$this->render('player', [
					'type'          => 'movie',
					'movie'         => [
						'id' => $id,
						'stream_display_name' => $title,
						'stream_icon' => $posterUrl,
						'year' => !empty($info['releasedate']) ? substr((string) $info['releasedate'], 0, 4) : null,
						'rating' => $info['rating'] ?? '',
					],
					'relatedMovies' => [],
					'playbackTitle' => $title,
					'backUrl'       => $backUrl,
					'props'         => [
						'plot' => $info['plot'] ?? ($info['description'] ?? ''),
						'genre' => $info['genre'] ?? '',
						'cast' => $info['cast'] ?? '',
						'director' => $info['director'] ?? '',
						'duration' => !empty($info['duration_secs']) ? (int) ($info['duration_secs'] / 60) . ' min' : ($info['duration'] ?? ''),
					],
					'streamUrl'     => $streamUrl,
					'qualityBadge'  => 'HD',
					'qualityColor'  => 'primary',
					'categoryName'  => $catName,
					'baseUrl'       => $baseUrl,
				]);
				return;
			}
		}

		$domainName = DomainResolver::resolve(
			SERVER_ID,
			(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
		);

		if ($type === 'series') {
			$seriesId = (int) RequestManager::get('series_id');

			// Validate subscriber access
			$hasSeriesAccess = empty($rUserInfo['series_ids']) || (!empty($seriesId) && in_array($seriesId, $rUserInfo['series_ids'], true));
			$hasEpisodeAccess = empty($rUserInfo['episode_ids']) || (!empty($id) && (in_array($id, $rUserInfo['episode_ids'], true) || $hasSeriesAccess));

			if ($id <= 0 || !$hasEpisodeAccess || !($rStream = getStream($id))) {
				header('Location: ' . $baseUrl . 'series');
				exit;
			}

			// Series metadata
			$rSeries = null;
			if ($seriesId > 0) {
				$db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $seriesId);
				$rSeries = $db->get_row();
			}

			// Episode info from streams_episodes
			$db->query('SELECT * FROM `streams_episodes` WHERE `stream_id` = ? LIMIT 1', $id);
			$epMeta = $db->get_row();
			if (!$seriesId && !empty($epMeta['series_id'])) {
				$seriesId = (int) $epMeta['series_id'];
				$db->query('SELECT * FROM `streams_series` WHERE `id` = ?', $seriesId);
				$rSeries = $db->get_row();
			}

			$seasonNum = (int) ($epMeta['season_num'] ?? RequestManager::get('s') ?? 1);
			$episodeNum = (int) ($epMeta['episode_num'] ?? RequestManager::get('e') ?? 1);

			// Collect and enrich all episodes for the interactive Zap Sidebar
			$allEpsEnriched = [];
			$seasons = [];
			if ($seriesId > 0) {
				$db->query('SELECT t1.season_num, t1.episode_num, t1.stream_id, t2.stream_display_name, t2.stream_icon, t2.target_container, t2.movie_properties FROM `streams_episodes` t1 INNER JOIN `streams` t2 ON t2.id = t1.stream_id WHERE t1.series_id = ? ORDER BY t1.season_num ASC, t1.episode_num ASC', $seriesId);
				$rows = $db->get_rows() ?: [];
				foreach ($rows as $idx => $epRow) {
					$sN = (int) ($epRow['season_num'] ?? 1);
					$eN = (int) ($epRow['episode_num'] ?? 1);
					$seasons[$sN] = true;

					$epCont = $epRow['target_container'] ?: 'mp4';
					$epUrl = $domainName . 'series/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $epRow['stream_id'] . '.' . $epCont;
					$epProps = json_decode($epRow['movie_properties'] ?? '', true) ?: [];

					$enriched = [
						'stream_id'           => (int) $epRow['stream_id'],
						'season_num'          => $sN,
						'episode_num'         => $eN,
						'stream_display_name' => $epRow['stream_display_name'],
						'stream_icon'         => $epRow['stream_icon'] ?? '',
						'play_url'            => $epUrl,
						'duration_secs'       => (int) ($epProps['duration_secs'] ?? 0),
						'duration_text'       => $epProps['duration'] ?? '',
					];

					$allEpsEnriched[] = $enriched;

					if ((int) $epRow['stream_id'] === $id) {
						if (isset($rows[$idx - 1])) {
							$prevEp = $rows[$idx - 1];
						}
						if (isset($rows[$idx + 1])) {
							$nextEp = $rows[$idx + 1];
						}
					}
				}
			}

			$container = $rStream['target_container'] ?? 'mp4';
			$streamUrl = $domainName . 'series/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rStream['id'] . '.' . $container;

			$props = json_decode($rStream['movie_properties'] ?? '', true) ?: [];
			$video = $props['video'] ?? null;
			$width = !empty($video['width']) ? (int) $video['width'] : 0;
			$qualityBadge = 'HD';
			$qualityColor = 'primary';
			if ($width >= 3840) {
				$qualityBadge = '4K UHD';
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

			$seriesTitle = $rSeries['title'] ?? 'TV Series';
			$playbackTitle = $seriesTitle . ' — S' . sprintf('%02d', $seasonNum) . 'E' . sprintf('%02d', $episodeNum) . ' — ' . $rStream['stream_display_name'];
			$backUrl = $seriesId > 0 ? $baseUrl . 'series?id=' . $seriesId : $baseUrl . 'series';

			$GLOBALS['_TITLE'] = 'Playing: ' . $playbackTitle;
			$GLOBALS['_PAGE'] = 'series';

			$this->render('player', [
				'type'          => 'series',
				'movie'         => $rStream,
				'series'        => $rSeries,
				'seriesId'      => $seriesId,
				'seasonNum'     => $seasonNum,
				'episodeNum'    => $episodeNum,
				'allEpisodes'   => $allEpsEnriched,
				'seasons'       => array_keys($seasons),
				'prevEp'        => $prevEp,
				'nextEp'        => $nextEp,
				'playbackTitle' => $playbackTitle,
				'backUrl'       => $backUrl,
				'props'         => $props,
				'streamUrl'     => $streamUrl,
				'qualityBadge'  => $qualityBadge,
				'qualityColor'  => $qualityColor,
				'categoryName'  => $seriesTitle,
				'baseUrl'       => $baseUrl,
			]);
			return;
		}

		// Movie Playback
		if ($id <= 0 || !in_array($id, $rUserInfo['vod_ids'] ?? [], true) || !($rStream = getStream($id))) {
			header('Location: ' . $baseUrl . 'movies');
			exit;
		}

		$props = json_decode($rStream['movie_properties'] ?? '', true) ?: [];
		$streamUrl = $domainName . 'movie/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rStream['id'] . '.' . ($rStream['target_container'] ?? 'mp4');

		$video = $props['video'] ?? null;
		$width = !empty($video['width']) ? (int) $video['width'] : 0;
		$qualityBadge = 'HD';
		$qualityColor = 'primary';
		if ($width >= 3840) {
			$qualityBadge = '4K UHD';
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

		// Category Name & Related Movies
		$rawCats = $rStream['category_id'] ?? null;
		$primaryCatId = 0;
		if (is_numeric($rawCats)) {
			$primaryCatId = (int) $rawCats;
		} elseif (is_string($rawCats)) {
			$decoded = json_decode($rawCats, true);
			$primaryCatId = is_array($decoded) && !empty($decoded[0]) ? (int) $decoded[0] : 0;
		} elseif (is_array($rawCats) && !empty($rawCats[0])) {
			$primaryCatId = (int) $rawCats[0];
		}

		$categoryName = $primaryCatId > 0 ? PlayerCategoryHelper::resolveCategoryName($primaryCatId, $rUserInfo, 'movie') : 'Movies';

		// Query related movies in same category for sidebar quick switcher
		$relatedMovies = [];
		if ($primaryCatId > 0) {
			$db->query('SELECT `id`, `stream_display_name`, `stream_icon`, `target_container`, `movie_properties` FROM `streams` WHERE `category_id` = ? AND `type` = 2 AND `id` != ? ORDER BY `id` DESC LIMIT 25', $primaryCatId, $id);
			$relRows = $db->get_rows() ?: [];
			foreach ($relRows as $rm) {
				if (!empty($rUserInfo['vod_ids']) && !in_array((int) $rm['id'], $rUserInfo['vod_ids'], true)) {
					continue;
				}
				$rmCont = $rm['target_container'] ?: 'mp4';
				$rmProps = json_decode($rm['movie_properties'] ?? '', true) ?: [];
				$relatedMovies[] = [
					'id'                  => (int) $rm['id'],
					'stream_display_name' => $rm['stream_display_name'],
					'stream_icon'         => $rm['stream_icon'] ?? '',
					'play_url'            => $domainName . 'movie/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rm['id'] . '.' . $rmCont,
					'rating'              => $rmProps['rating'] ?? '',
					'year'                => $rmProps['year'] ?? '',
					'duration'            => $rmProps['duration'] ?? '',
				];
			}
		}

		$backUrl = $baseUrl . 'movie?id=' . $rStream['id'];

		$GLOBALS['_TITLE'] = 'Playing: ' . $rStream['stream_display_name'];
		$GLOBALS['_PAGE'] = 'movies';

		$this->render('player', [
			'type'          => 'movie',
			'movie'         => $rStream,
			'relatedMovies' => $relatedMovies,
			'playbackTitle' => $rStream['stream_display_name'],
			'backUrl'       => $backUrl,
			'props'         => $props,
			'streamUrl'     => $streamUrl,
			'qualityBadge'  => $qualityBadge,
			'qualityColor'  => $qualityColor,
			'categoryName'  => $categoryName,
			'baseUrl'       => $baseUrl,
		]);
	}
}
