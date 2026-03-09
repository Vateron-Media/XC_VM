<?php
	echo "\t" . '<section class="section details">' . "\n\t\t" . '<div class="details__bg" data-bg="';
	echo $rCover;
	echo '"></div>' . "\n\t\t" . '<div class="container top-margin">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<h1 class="details__title">';
	echo $rSeries['title'];
	echo '<br/>' . "\n" . '                        <ul class="card__list">' . "\n" . '                            ';

	foreach (json_decode($rSeries['category_id'], true) as $rCategoryID) {
		echo '                            <li>';
		echo CategoryService::getFromDatabase()[$rCategoryID]['category_name'];
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
			$rProperties = json_decode($rRow['movie_properties'], true);
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
			echo '&w=267&h=400" alt="">' . "\n" . '                                    <a href="episodes?id=';
			echo $rItem['id'];
			echo '" class="card__play">' . "\n" . '                                        <i class="icon ion-ios-play"></i>' . "\n" . '                                    </a>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="card__content">' . "\n" . '                                    <h3 class="card__title"><a href="episodes?id=';
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
