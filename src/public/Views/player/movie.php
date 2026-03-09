<?php
echo "\t" . '<section class="section details">' . "\n\t\t" . '<div class="details__bg" data-bg="';
echo $rCover;
echo '"></div>' . "\n\t\t" . '<div class="container top-margin">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<h1 class="details__title">';
echo $rStream['stream_display_name'];
echo '<br/>' . "\n" . '                        <ul class="card__list">' . "\n" . '                            ';

foreach (json_decode($rStream['category_id'], true) as $rCategoryID) {
	echo '                            <li>';
	echo CategoryService::getFromDatabase()[$rCategoryID]['category_name'];
	echo '</li>' . "\n" . '                            ';
}
echo '                        </ul>' . "\n" . '                    </h1>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '<div class="col-12 col-xl-12">' . "\n\t\t\t\t\t" . '<div class="card card--details">' . "\n\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t" . '<div class="col-12 col-sm-3 col-md-3 col-lg-3 col-xl-3">' . "\n\t\t\t\t\t\t\t\t" . '<div class="card__cover">' . "\n\t\t\t\t\t\t\t\t\t" . '<img src="';
echo $rPoster;
echo '" alt="">' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="col-12 col-sm-9 col-md-9 col-lg-9 col-xl-9">' . "\n\t\t\t\t\t\t\t\t" . '<div class="card__content">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="card__wrap">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<span class="card__rate">';
echo ($rStream['year'] ? $rStream['year'] . ' &nbsp; ' : '');
echo '<i class="icon ion-ios-star"></i>';
echo ($rProperties['rating'] ?: 'N/A');
echo '</span>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<ul class="card__meta">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li><span><strong>Duration:</strong></span> ';
echo intval($rProperties['duration_secs'] / 60);
echo ' min</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<li><span><strong>Country:</strong></span> <a href="#">';
echo $rProperties['country'];
echo '</a> </li>' . "\n" . '                                        <li>' . "\n" . '                                            <span><strong>Cast:</strong></span>' . "\n" . '                                            ';
echo implode(', ', array_slice(explode(',', $rProperties['cast']), 0, 5));
echo '                                        </li>' . "\n\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="card__description card__description--details">' . "\n\t\t\t\t\t\t\t\t\t\t";
echo $rProperties['description'];
echo "\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>' . "\n" . '                        <div class="row top-margin-sml">' . "\n" . '                            <div class="col-12">' . "\n" . '                                <div class="alert alert-danger" id="player__error" style="display: none;"></div>' . "\n" . '                                <div id="player_row">' . "\n" . '                                    ';

if ($rLegacy) {
	echo '                                    <video controls width="100%" autoplay>' . "\n" . '                                        <source src="';
	echo $rURLs[0];
	echo '" type="video/mp4" />' . "\n" . '                                        ';

	foreach ($rSubtitles[0] as $rSubtitle) {
		echo '                                        <track label="';
		echo $rSubtitle['label'];
		echo '" kind="subtitles" src="proxy.php?url=';
		echo Encryption::encrypt($rSubtitle['file'], SettingsManager::getAll()['live_streaming_pass'], 'd8de497ebccf4f4697a1da20219c7c33');
		echo '">' . "\n" . '                                        ';
	}
	echo '                                    </video>' . "\n" . '                                    ';
} else {
	echo '                                    <div id="now__playing__player"></div>' . "\n" . '                                    ';
}

echo '                                </div>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</section>' . "\n" . '    ';

if (0 >= count($rSimilar)) {
} else {
	echo "\t" . '<section class="content">' . "\n\t\t" . '<div class="container" style="margin-top: 30px;">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12 col-lg-12 col-xl-12">' . "\n\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t" . '<h2 class="section__title section__title--sidebar">Users Also Watched</h2>' . "\n\t\t\t\t\t\t" . '</div>' . "\n" . '                        ';

	foreach (array_slice($rSimilar, 0, 6) as $rItem) {
		echo "\t\t\t\t\t\t" . '<div class="col-4 col-sm-4 col-lg-2">' . "\n\t\t\t\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t\t\t\t" . '<div class="card__cover">' . "\n\t\t\t\t\t\t\t\t\t" . '<img loading="lazy" src="resize.php?url=';
		echo urlencode($rItem['cover']);
		echo '&w=267&h=400" alt="">' . "\n" . '                                    <a href="movie.php?id=';
		echo $rItem['id'];
		echo '" class="card__play">' . "\n" . '                                        <i class="icon ion-ios-play"></i>' . "\n" . '                                    </a>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="card__content">' . "\n" . '                                    <h3 class="card__title"><a href="movie.php?id=';
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
