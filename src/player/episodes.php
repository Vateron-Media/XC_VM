<?php







include 'functions.php';

if (($rSeries = CoreUtilities::getSerie(CoreUtilities::$rRequest['id'])) && in_array(CoreUtilities::$rRequest['id'], $rUserInfo['series_ids'])) {



	$rDomainName = CoreUtilities::getDomainName(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443);
	$rTMDB = null;

	if (!$rSeries['tmdb_id']) {
	} else {
		if (!file_exists(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'])) {
			$rTMDB = CoreUtilities::getSeriesTMDB($rSeries['tmdb_id']);

			if (!$rTMDB) {
			} else {
				file_put_contents(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'], CoreUtilities::serialize($rTMDB));
			}
		} else {
			$rTMDB = CoreUtilities::unserialize(file_get_contents(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id']));
		}
	}

	if ($rTMDB) {
		$rBackdrop = array_rand($rTMDB['images']['backdrops']);
		$rCover = (($rTMDB['images']['backdrops'][$rBackdrop] ? 'https://image.tmdb.org/t/p/w1280' . $rTMDB['images']['backdrops'][$rBackdrop]['file_path'] : CoreUtilities::validateImage(json_decode($rSeries['backdrop_path'], true)[0])) ?: '');
		$rPoster = (($rTMDB['poster_path'] ? 'https://image.tmdb.org/t/p/w600_and_h900_bestv2' . $rTMDB['poster_path'] : CoreUtilities::validateImage($rSeries['cover'])) ?: '');
	} else {
		$rCover = (CoreUtilities::validateImage(json_decode($rSeries['backdrop_path'], true)[0]) ?: '');
		$rPoster = (CoreUtilities::validateImage($rSeries['cover']) ?: '');
	}

	$rSubtitles = $rURLs = $rSeasons = array();

	if (PLATFORM == 'xc_vm') {
		$db->query('SELECT DISTINCT(`season_num`) AS `season_num` FROM `streams_episodes` WHERE `series_id` = ? ORDER BY `season_num` ASC;', $rSeries['id']);
	} else {
		$db->query('SELECT DISTINCT(`season_num`) AS `season_num` FROM `series_episodes` WHERE `series_id` = ? ORDER BY `season_num` ASC;', $rSeries['id']);
	}

	foreach ($db->get_rows() as $rRow) {
		if (CoreUtilities::$rSettings['player_hide_incompatible']) {
			$db->query('SELECT MAX(`compatible`) AS `compatible` FROM `streams_servers` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams_servers`.`stream_id` WHERE `series_id` = ? AND `season_num` = ?;', $rSeries['id'], $rRow['season_num']);

			if (!$db->get_row()['compatible']) {
			} else {
				$rSeasons[] = $rRow['season_num'];
			}
		} else {
			$rSeasons[] = $rRow['season_num'];
		}
	}
	$rSeasonNo = (intval(CoreUtilities::$rRequest['season']) ?: ($rSeasons[0] ?: 1));

	if (CoreUtilities::$rSettings['player_hide_incompatible']) {
		$db->query('SELECT * FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` WHERE `series_id` = ? AND `season_num` = ? AND (SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1 ORDER BY `episode_num` ASC;', $rSeries['id'], $rSeasonNo);
	} else {
		if (PLATFORM == 'xc_vm') {
			$db->query('SELECT * FROM `streams_episodes` LEFT JOIN `streams` ON `streams`.`id` = `streams_episodes`.`stream_id` WHERE `series_id` = ? AND `season_num` = ? ORDER BY `episode_num` ASC;', $rSeries['id'], $rSeasonNo);
		} else {
			$db->query('SELECT * FROM `series_episodes` LEFT JOIN `streams` ON `streams`.`id` = `series_episodes`.`stream_id` WHERE `series_id` = ? AND `season_num` = ? ORDER BY `sort` ASC;', $rSeries['id'], $rSeasonNo);
		}
	}

	$rLegacy = false;
	$rEpisodes = $db->get_rows();

	for ($i = 0; $i < count($rEpisodes); $i++) {
		if (PLATFORM == 'xc_vm') {
		} else {
			$rEpisodes[$i]['target_container'] = (json_decode($rEpisodes[$i]['target_container'], true)[0] ?: 'mp4');
			$rEpisodes[$i]['episode_num'] = $rEpisodes[$i]['sort'];
		}

		$rURLs[$rEpisodes[$i]['id']] = $rDomainName . 'series/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rEpisodes[$i]['id'] . '.' . $rEpisodes[$i]['target_container'];
		$rProperties = json_decode((PLATFORM == 'xc_vm' ? $rEpisodes[$i]['movie_properties'] : $rEpisodes[$i]['movie_propeties']), true);
		$rSubtitles[$rEpisodes[$i]['id']] = (PLATFORM == 'xc_vm' ? CoreUtilities::getSubtitles($rEpisodes[$i]['id'], $rProperties['subtitle']) : array());

		if ($rEpisodes[$i]['target_container'] == 'mp4') {
		} else {
			$rProxySubtitles = array();

			foreach ($rSubtitles[$rEpisodes[$i]['id']] as $rSubtitle) {
				$rSubtitle['file'] = 'proxy.php?url=' . CoreUtilities::encrypt($rSubtitle['file'], CoreUtilities::$rSettings['live_streaming_pass'], 'd8de497ebccf4f4697a1da20219c7c33');
				$rProxySubtitles[] = $rSubtitle;
			}
			$rSubtitles[$rEpisodes[$i]['id']] = $rProxySubtitles;
			$rLegacy = true;
		}
	}
	$rSeason = null;

	if (!$rSeries['tmdb_id']) {
	} else {
		if (!file_exists(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'] . '_' . $rSeasonNo)) {
			$rSeason = CoreUtilities::getSeasonTMDB($rSeries['tmdb_id'], $rSeasonNo);

			if (!$rSeason) {
			} else {
				file_put_contents(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'] . '_' . $rSeasonNo, CoreUtilities::serialize($rSeason));
			}
		} else {
			$rSeason = CoreUtilities::unserialize(file_get_contents(TMP_PATH . 'tmdb_' . $rSeries['tmdb_id'] . '_' . $rSeasonNo));
		}
	}

	if ($rSeason && $rSeason['episodes']) {
		$rSeasonArray = array();

		foreach ($rSeason['episodes'] as $rEpisode) {
			$rSeasonArray[$rEpisode['episode_number']] = array('title' => $rEpisode['name'], 'description' => ($rEpisode['overview'] ?: 'No description is available...'), 'rating' => ($rEpisode['vote_average'] ?: null), 'image' => ($rEpisode['still_path'] ? 'https://image.tmdb.org/t/p/w500' . $rEpisode['still_path'] : ''), 'image_cover' => ($rEpisode['still_path'] ? 'https://image.tmdb.org/t/p/w1280' . $rEpisode['still_path'] : ''));
		}
	} else {
		foreach ($rEpisodes as $rEpisode) {
			$rProperties = json_decode((PLATFORM == 'xc_vm' ? $rEpisode['movie_properties'] : $rEpisode['movie_propeties']), true);
			$rSeasonArray[$rEpisode['episode_num']] = array('title' => 'Episode ' . intval($rEpisode['episode_num']), 'description' => ($rProperties['plot'] ?: 'No description is available...'), 'rating' => ($rProperties['rating'] ?: null), 'image' => (str_replace('w600_and_h900_bestv2', 'w500', CoreUtilities::validateImage($rProperties['movie_image'])) ?: ''), 'image_cover' => str_replace('w600_and_h900_bestv2', 'w500', CoreUtilities::validateImage($rProperties['movie_image'])));
		}
	}

	$rSimilarIDs = array($rSeries['id']);
	$rSimilar = array();
	$rSimilarArray = json_decode($rSeries['similar'], true);

	if (0 >= count($rSimilarArray)) {
	} else {
		if (CoreUtilities::$rSettings['player_hide_incompatible']) {
			$db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` IN (' . implode(',', $rSimilarArray) . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams_servers`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) = 1 LIMIT 6;');
		} else {
			if (PLATFORM == 'xc_vm') {
				$db->query('SELECT * FROM `streams_series` WHERE `tmdb_id` IN (' . implode(',', $rSimilarArray) . ') LIMIT 6;');
			} else {
				$db->query('SELECT * FROM `series` WHERE `tmdb_id` IN (' . implode(',', $rSimilarArray) . ') LIMIT 6;');
			}
		}

		foreach ($db->get_rows() as $rRow) {
			$rSimilar[] = array('type' => 'series', 'id' => $rRow['id'], 'title' => $rRow['title'], 'year' => ($rRow['year'] ?: ($rRow['releaseDate'] ? substr($rRow['releaseDate'], 0, 4) : null)), 'rating' => $rRow['rating'], 'cover' => (CoreUtilities::validateImage($rRow['cover']) ?: ''), 'backdrop' => (CoreUtilities::validateImage(json_decode($rRow['backdrop_path'], true)[0]) ?: ''));
			$rSimilarIDs[] = $rRow['id'];
		}
	}

	$_TITLE = $rSeries['title'];
	include 'header.php';
	echo "\t" . '<section class="section details">' . "\n\t\t" . '<div class="details__bg" data-bg="';
	echo $rCover;
	echo '"></div>' . "\n\t\t" . '<div class="container top-margin">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<h1 class="details__title">';
	echo $rSeries['title'];
	echo '<br/>' . "\n" . '                        <ul class="card__list">' . "\n" . '                            ';

	foreach ((PLATFORM == 'xc_vm' ? json_decode($rSeries['category_id'], true) : array($rSeries['category_id'])) as $rCategoryID) {
		echo '                            <li>';
		echo CoreUtilities::$rCategories[$rCategoryID]['category_name'];
		echo '</li>' . "\n" . '                            ';
	}
	echo '                        </ul>' . "\n" . '                    </h1>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '<div class="col-12 col-xl-12">' . "\n\t\t\t\t\t" . '<div class="card card--details">' . "\n\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t" . '<div class="col-12 col-sm-3 col-md-3 col-lg-3 col-xl-3">' . "\n\t\t\t\t\t\t\t\t" . '<div class="card__cover">' . "\n\t\t\t\t\t\t\t\t\t" . '<img src="';
	echo $rPoster;
	echo '" alt="">' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="col-12 col-sm-9 col-md-9 col-lg-9 col-xl-9">' . "\n\t\t\t\t\t\t\t\t" . '<div class="card__content">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="card__wrap">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<span class="card__rate">';
	echo ($rSeries['year'] ? $rSeries['year'] . ' &nbsp; ' : '');
	echo '<i class="icon ion-ios-star"></i>';
	echo ($rSeries['rating'] ?: 'N/A');
	echo '</span>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="card__meta">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li><span><strong>Duration:</strong></span> ';
	echo intval($rSeries['episode_run_time']);
	echo ' min</li>' . "\n" . '                                        <li>' . "\n" . '                                            <span><strong>Cast:</strong></span>' . "\n" . '                                            ';
	echo implode(', ', array_slice(explode(',', $rSeries['cast']), 0, 5));
	echo '                                        </li>' . "\n\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="card__description card__description--details">' . "\n\t\t\t\t\t\t\t\t\t\t";
	echo $rSeries['plot'];
	echo "\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>' . "\n" . '                        ';

	if ($rLegacy) {
		echo '                        <div class="row top-margin-sml" id="player_row" style="display: none;">' . "\n" . '                            <div class="col-12">' . "\n" . '                                <video controls width="100%" preload="none" id="video__player">' . "\n" . '                                    <source src="" type="video/mp4" />' . "\n" . '                                </video>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n" . '                        ';
	} else {
		echo '                        <div class="row top-margin-sml">' . "\n" . '                            <div class="col-12">' . "\n" . '                                <div id="player_row">' . "\n" . '                                    <div id="now__playing__player"></div>' . "\n" . '                                </div>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n" . '                        ';
	}

	echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</section>' . "\n" . '    <section class="seasons">' . "\n" . '        ';

	if (count($rEpisodes) == 0) {
		echo '        <div class="container">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12">' . "\n" . '                    <div class="alert alert-danger">' . "\n" . '                        No episodes are available for this series. Please check back later.' . "\n" . '                    </div>' . "\n" . '                </div>' . "\n" . '            </div>' . "\n" . '        </div>' . "\n" . '        ';
	} else {
		echo "\t\t" . '<div class="owl-carousel seasons__bg">' . "\n" . '            ';

		foreach ($rEpisodes as $rEpisode) {
			echo "\t\t\t" . '<div class="item seasons__cover" data-bg="';
			echo $rSeasonArray[$rEpisode['episode_num']]['image_cover'];
			echo '"></div>' . "\n" . '            ';
		}
		echo "\t\t" . '</div>' . "\n\t\t" . '<div class="container">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<h1 class="seasons__title">' . "\n" . '                        <select id="season__select">' . "\n" . '                            ';

		foreach ($rSeasons as $i) {
			echo '                            <option';

			if ($rSeasonNo != $i) {
			} else {
				echo ' selected';
			}

			echo '>Season ';
			echo $i;
			echo '</option>' . "\n" . '                            ';
		}
		echo '                        </select>' . "\n" . '                    </h1>' . "\n\t\t\t\t\t" . '<button class="seasons__nav seasons__nav--prev" type="button">' . "\n\t\t\t\t\t\t" . '<i class="icon ion-ios-arrow-round-back"></i>' . "\n\t\t\t\t\t" . '</button>' . "\n\t\t\t\t\t" . '<button class="seasons__nav seasons__nav--next" type="button">' . "\n\t\t\t\t\t\t" . '<i class="icon ion-ios-arrow-round-forward"></i>' . "\n\t\t\t\t\t" . '</button>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<div class="owl-carousel seasons__carousel">' . "\n" . '                        ';
		$i = 0;

		foreach ($rEpisodes as $rRow) {
			$i++;
			$rProperties = json_decode((PLATFORM == 'xc_vm' ? $rRow['movie_properties'] : $rRow['movie_propeties']), true);
			$rEpisodeData = $rSeasonArray[$rRow['episode_num']];
			echo '                            <div class="item" id="episode_';
			echo $rRow['id'];
			echo '" data-index="';
			echo $i - 1;
			echo '">' . "\n" . '                                <div class="card card--big">' . "\n" . '                                    <div class="card__cover">' . "\n" . '                                        <img loading="lazy" src="';
			echo $rEpisodeData['image'];
			echo '" alt="">' . "\n" . '                                        <a href="javascript:void(0)" onClick="openPlayer(';
			echo $rRow['id'];
			echo ');" class="card__play">' . "\n" . '                                            <i class="icon ion-ios-play"></i>' . "\n" . '                                        </a>' . "\n" . '                                    </div>' . "\n" . '                                    <div class="card__content">' . "\n" . '                                        <h3 class="card__title"><a href="javascript:void(0);" onClick="openPlayer(';
			echo $rRow['id'];
			echo ');">';
			echo sprintf('%02d', $rRow['episode_num']);
			echo ' - ';
			echo $rEpisodeData['title'];
			echo '</a></h3>' . "\n" . '                                        <span class="card__episode">' . "\n" . '                                            ';
			echo (500 < strlen($rEpisodeData['description']) ? substr($rEpisodeData['description'], 0, 500) . '...' : $rEpisodeData['description']);
			echo '                                        </span>' . "\n" . '                                        <ul class="card__list card__danger" style="display: none;">' . "\n" . '                                            <li>UNAVAILABLE</li>' . "\n" . '                                        </ul>' . "\n" . '                                        <span class="card__rate"><i class="icon ion-ios-star"></i>';
			echo ($rEpisodeData['rating'] ? number_format($rEpisodeData['rating'], 1) : ($rSeries['rating'] ? number_format($rSeries['rating'], 1) : 'N/A'));
			echo '</span>' . "\n" . '                                    </div>' . "\n" . '                                </div>' . "\n" . '                            </div>' . "\n" . '                        ';
		}
		echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n" . '        ';
	}

	echo "\t" . '</section>' . "\n" . '    ';

	if (0 >= count($rSimilar)) {
	} else {
		echo "\t" . '<section class="content">' . "\n\t\t" . '<div class="container" style="margin-top: 30px;">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12 col-lg-12 col-xl-12">' . "\n\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t" . '<h2 class="section__title section__title--sidebar">Users Also Watched</h2>' . "\n\t\t\t\t\t\t" . '</div>' . "\n" . '                        ';

		foreach (array_slice($rSimilar, 0, 6) as $rItem) {
			echo "\t\t\t\t\t\t" . '<div class="col-4 col-sm-4 col-lg-2">' . "\n\t\t\t\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t\t\t\t" . '<div class="card__cover">' . "\n\t\t\t\t\t\t\t\t\t" . '<img loading="lazy" src="resize.php?url=';
			echo urlencode($rItem['cover']);
			echo '&w=267&h=400" alt="">' . "\n" . '                                    <a href="episodes.php?id=';
			echo $rItem['id'];
			echo '" class="card__play">' . "\n" . '                                        <i class="icon ion-ios-play"></i>' . "\n" . '                                    </a>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="card__content">' . "\n" . '                                    <h3 class="card__title"><a href="episodes.php?id=';
			echo $rItem['id'];
			echo '">';
			echo htmlspecialchars($rItem['title']);
			echo '</a></h3>' . "\n" . '                                    <span class="card__rate">';
			echo ($rItem['year'] ? intval($rItem['year']) . ' &nbsp; ' : '');
			echo '<i class="icon ion-ios-star"></i>';
			echo ($rItem['rating'] ? number_format($rItem['rating'], 1) : 'N/A');
			echo '</span>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>' . "\n" . '                        ';
		}
		echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</section>' . "\n" . '    ';
	}

	include 'footer.php';
} else {
	header('Location: series.php');

	exit();
}
