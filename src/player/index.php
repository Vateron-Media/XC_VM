<?php

include 'functions.php';

if (!(isset(CoreUtilities::$rRequest['search']) && isset(CoreUtilities::$rRequest['type']))) {
} else {
	if (!in_array(CoreUtilities::$rRequest['type'], array('live', 'movies', 'series'))) {
	} else {
		header('Location: ' . CoreUtilities::$rRequest['type'] . '.php?search=' . urlencode(CoreUtilities::$rRequest['search']));

		exit();
	}
}

$rPopularNow = array();
$rPopular = CoreUtilities::unserialize(file_get_contents(CONTENT_PATH . 'tmdb_popular'));

if (!(0 < count($rPopular['movies']) && 0 < count($rUserInfo['vod_ids']))) {
} else {
	if (CoreUtilities::$rSettings['player_hide_incompatible']) {
		$db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . implode(',', $rPopular['movies']) . ') AND `id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` WHERE `streams_servers`.`stream_id` = `streams`.`id` LIMIT 1) = 1 ORDER BY FIELD(id, ' . implode(',', $rPopular['movies']) . ') ASC LIMIT 50;');
	} else {
		if (PLATFORM == 'xc_vm') {
			$db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . implode(',', $rPopular['movies']) . ') AND `id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular['movies']) . ') ASC LIMIT 50;');
		} else {
			$db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_propeties` FROM `streams` LEFT JOIN `webplayer_data` ON `webplayer_data`.`stream_id` = `streams`.`id` WHERE `id` IN (' . implode(',', $rPopular['movies']) . ') AND `id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular['movies']) . ') ASC LIMIT 50;');
		}
	}

	$rStreams = $db->get_rows();

	foreach ($rStreams as $rStream) {
		$rProperties = json_decode((PLATFORM == 'xc_vm' ? $rStream['movie_properties'] : $rStream['movie_propeties']), true);
		$rPopularNow[] = array('type' => 'movie', 'id' => $rStream['id'], 'title' => $rStream['stream_display_name'], 'year' => ($rStream['year'] ?: null), 'rating' => $rStream['rating'], 'cover' => (CoreUtilities::validateImage($rProperties['movie_image']) ?: ''), 'backdrop' => (CoreUtilities::validateImage($rProperties['backdrop_path'][0]) ?: ''));
	}
}

if (!(0 < count($rPopular['series']) && 0 < count($rUserInfo['series_ids']))) {
} else {
	if (CoreUtilities::$rSettings['player_hide_incompatible']) {
		$db->query('SELECT `id`, `title`, `year`, `rating`, `cover`, `backdrop_path` FROM `streams_series` WHERE `id` IN (' . implode(',', $rPopular['series']) . ') AND `id` IN (' . implode(',', $rUserInfo['series_ids']) . ') AND (SELECT MAX(`compatible`) FROM `streams_servers` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams_servers`.`stream_id` WHERE `streams_episodes`.`series_id` = `streams_series`.`id`) = 1 ORDER BY FIELD(id, ' . implode(',', $rPopular['series']) . ') ASC LIMIT 50;');
	} else {
		if (PLATFORM == 'xc_vm') {
			$db->query('SELECT `id`, `title`, `year`, `rating`, `cover`, `backdrop_path` FROM `streams_series` WHERE `id` IN (' . implode(',', $rPopular['series']) . ') AND `id` IN (' . implode(',', $rUserInfo['series_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular['series']) . ') ASC LIMIT 50;');
		} else {
			$db->query('SELECT `id`, `title`, `releaseDate`, `rating`, `cover`, `backdrop_path` FROM `series` WHERE `id` IN (' . implode(',', $rPopular['series']) . ') AND `id` IN (' . implode(',', $rUserInfo['series_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular['series']) . ') ASC LIMIT 50;');
		}
	}

	$rStreams = $db->get_rows();

	foreach ($rStreams as $rStream) {
		$rBackdrop = json_decode($rStream['backdrop_path'], true);
		$rPopularNow[] = array('type' => 'episodes', 'id' => $rStream['id'], 'title' => $rStream['title'], 'year' => ($rStream['year'] ?: (substr($rStream['releaseDate'], 0, 4) ?: null)), 'rating' => $rStream['rating'], 'cover' => (CoreUtilities::validateImage($rStream['cover']) ?: ''), 'backdrop' => (CoreUtilities::validateImage($rBackdrop[0]) ?: ''));
	}
}

shuffle($rPopularNow);
$rPopularNow = array_slice($rPopularNow, 0, 20);
$rMovies = CoreUtilities::getUserStreams($rUserInfo, array('movie'), null, null, 'added', null, null, 0, 20);
$rSeries = CoreUtilities::getUserSeries($rUserInfo, null, null, 'added', $rSearchBy, null, 0, 20);
$_TITLE = 'Home';
include 'header.php';

if (0 >= count($rPopularNow)) {
} else {
	echo "\t" . '<section class="home">' . "\r\n" . '        <div class="owl-carousel home__bg">' . "\r\n" . '            ';

	foreach ($rPopularNow as $rItem) {
		echo "\t\t\t" . '<div class="item home__cover" data-bg="';
		echo $rItem['backdrop'];
		echo '"></div>' . "\r\n" . '            ';
	}
	echo "\t\t" . '</div>' . "\r\n\t\t" . '<div class="container">' . "\r\n\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t" . '<h1 class="home__title">POPULAR <b>NOW</b></h1>' . "\r\n\t\t\t\t\t" . '<button class="home__nav home__nav--prev" type="button">' . "\r\n\t\t\t\t\t\t" . '<i class="icon ion-ios-arrow-round-back"></i>' . "\r\n\t\t\t\t\t" . '</button>' . "\r\n\t\t\t\t\t" . '<button class="home__nav home__nav--next" type="button">' . "\r\n\t\t\t\t\t\t" . '<i class="icon ion-ios-arrow-round-forward"></i>' . "\r\n\t\t\t\t\t" . '</button>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t" . '<div class="owl-carousel home__carousel">' . "\r\n" . '                        ';

	foreach ($rPopularNow as $rItem) {
		echo "\t\t\t\t\t\t" . '<div class="item">' . "\r\n\t\t\t\t\t\t\t" . '<div class="card card--big">' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="card__cover">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<img loading="lazy" src="resize.php?url=';
		echo urlencode($rItem['cover']);
		echo '&w=267&h=400" alt="">' . "\r\n" . '                                    <a href="';
		echo $rItem['type'];
		echo '.php?id=';
		echo $rItem['id'];
		echo '" class="card__play">' . "\r\n" . '                                        <i class="icon ion-ios-play"></i>' . "\r\n" . '                                    </a>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="card__content">' . "\r\n" . '                                    <h3 class="card__title"><a href="';
		echo $rItem['type'];
		echo '.php?id=';
		echo $rItem['id'];
		echo '">';
		echo htmlspecialchars($rItem['title']);
		echo '</a></h3>' . "\r\n" . '                                    <span class="card__rate">';
		echo ($rItem['year'] ? intval($rItem['year']) . ' &nbsp; ' : '');
		echo '<i class="icon ion-ios-star"></i>';
		echo ($rItem['rating'] ? number_format($rItem['rating'], 1) : 'N/A');
		echo '</span>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n" . '                        ';
	}
	echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</section>' . "\r\n" . '    ';
}

echo "\t" . '<section class="content"';

if (count($rPopularNow) != 0) {
} else {
	echo ' style="margin-top: 10px;"';
}

echo '>' . "\r\n\t\t" . '<div class="content__head">' . "\r\n\t\t\t" . '<div class="container">' . "\r\n\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t\t" . '<h1 class="home__title" style="margin-top:30px;">NEWLY <b>ADDED</b></h1>' . "\r\n\t\t\t\t\t\t" . '<ul class="nav nav-tabs content__tabs" id="content__tabs" role="tablist">' . "\r\n\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t\t" . '<a class="nav-link active" data-toggle="tab" href="#movies" role="tab" aria-controls="movies" aria-selected="true">MOVIES</a>' . "\r\n\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t\t" . '<a class="nav-link" data-toggle="tab" href="#series" role="tab" aria-controls="series" aria-selected="false">TV SERIES</a>' . "\r\n\t\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t" . '<div class="content__mobile-tabs" id="content__mobile-tabs">' . "\r\n\t\t\t\t\t\t\t" . '<div class="content__mobile-tabs-btn dropdown-toggle" role="navigation" id="mobile-tabs" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\r\n\t\t\t\t\t\t\t\t" . '<input type="button" value="Movies">' . "\r\n\t\t\t\t\t\t\t\t" . '<span></span>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '<div class="content__mobile-tabs-menu dropdown-menu" aria-labelledby="mobile-tabs">' . "\r\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-tabs" role="tablist">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item"><a class="nav-link active" id="movies-tab" data-toggle="tab" href="#movies" role="tab" aria-controls="movies" aria-selected="true">MOVIES</a></li>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item"><a class="nav-link" id="series-tab" data-toggle="tab" href="#series" role="tab" aria-controls="series" aria-selected="false">TV SERIES</a></li>' . "\r\n\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t\t" . '<div class="container">' . "\r\n\t\t\t" . '<!-- content tabs -->' . "\r\n\t\t\t" . '<div class="tab-content">' . "\r\n\t\t\t\t" . '<div class="tab-pane fade show active" id="movies" role="tabpanel" aria-labelledby="movies-tab">' . "\r\n\t\t\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t\t\t";

foreach ($rMovies['streams'] as $rStreamID => $rStream) {
	$rProperties = json_decode((PLATFORM == 'xc_vm' ? $rStream['movie_properties'] : $rStream['movie_propeties']), true);
	echo '                            <div class="col-6 col-sm-4 col-lg-3 col-xl-3">' . "\r\n" . '                                <div class="card">' . "\r\n" . '                                    <div class="card__cover">' . "\r\n" . '                                        <img loading="lazy" src="resize.php?url=';
	echo urlencode((CoreUtilities::validateImage($rProperties['movie_image']) ?: ''));
	echo '&w=267&h=400" alt="">' . "\r\n" . '                                        <a href="movie.php?id=';
	echo $rStream['id'];
	echo '" class="card__play">' . "\r\n" . '                                            <i class="icon ion-ios-play"></i>' . "\r\n" . '                                        </a>' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <div class="card__content">' . "\r\n" . '                                        <h3 class="card__title"><a href="movie.php?id=';
	echo $rStream['id'];
	echo '">';
	echo htmlspecialchars($rStream['stream_display_name']);
	echo '</a></h3>' . "\r\n" . '                                        <span class="card__rate">';
	echo ($rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : '');
	echo '<i class="icon ion-ios-star"></i>';
	echo ($rProperties['rating'] ? number_format($rProperties['rating'], 1) : 'N/A');
	echo '</span>' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div>' . "\r\n" . '                            </div>' . "\r\n" . '                        ';
}
echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '<div class="tab-pane fade" id="series" role="tabpanel" aria-labelledby="series-tab">' . "\r\n\t\t\t\t\t" . '<div class="row">' . "\r\n" . '                        ';

foreach ($rSeries['streams'] as $rStreamID => $rStream) {
	echo '                            <div class="col-6 col-sm-4 col-lg-3 col-xl-3">' . "\r\n" . '                                <div class="card">' . "\r\n" . '                                    <div class="card__cover">' . "\r\n" . '                                        <img loading="lazy" src="resize.php?url=';
	echo urlencode((CoreUtilities::validateImage($rStream['cover']) ?: ''));
	echo '&w=267&h=400" alt="">' . "\r\n" . '                                        <a href="episodes.php?id=';
	echo $rStream['id'];
	echo '" class="card__play">' . "\r\n" . '                                            <i class="icon ion-ios-play"></i>' . "\r\n" . '                                        </a>' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <div class="card__content">' . "\r\n" . '                                        <h3 class="card__title"><a href="episodes.php?id=';
	echo $rStream['id'];
	echo '">';
	echo htmlspecialchars($rStream['title']);
	echo '</a></h3>' . "\r\n" . '                                        <span class="card__rate">';
	echo ($rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : ($rStream['releaseDate'] ? intval(substr($rStream['releaseDate'], 0, 4)) . ' &nbsp; ' : ''));
	echo '<i class="icon ion-ios-star"></i>';
	echo ($rStream['rating'] ? number_format($rStream['rating'], 0) : 'N/A');
	echo '</span>' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div>' . "\r\n" . '                            </div>' . "\r\n" . '                        ';
}
echo '                    </div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</section>' . "\r\n";
require 'footer.php';
