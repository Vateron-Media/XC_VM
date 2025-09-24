<?php

include 'functions.php';

if (isset(CoreUtilities::$rRequest['sort']) && CoreUtilities::$rRequest['sort'] == 'popular') {
	$rPopular = true;
	$rPopular = (CoreUtilities::unserialize(file_get_contents(CONTENT_PATH . 'tmdb_popular'))['series'] ?: array());

	if (0 < count($rPopular) && 0 < count($rUserInfo['series_ids'])) {
		$db->query('SELECT `id`, `title`, `year`, `rating`, `cover`, `backdrop_path` FROM `streams_series` WHERE `id` IN (' . implode(',', $rPopular) . ') AND `id` IN (' . implode(',', $rUserInfo['series_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular) . ') ASC LIMIT 100;');

		$rSeries = array('count' => $db->num_rows(), 'streams' => $db->get_rows());
	} else {
		header('Location: series.php');
	}
} else {
	$rPopular = false;
	$rPage = (intval(CoreUtilities::$rRequest['page']) ?: 1);
	$rLimit = 48;
	$rSortArray = array('number' => 'Default', 'added' => 'Last Updated', 'release' => 'Air Date', 'name' => 'Title A-Z', 'top' => 'Rating');
	$rSortBy = (isset($rSortArray[CoreUtilities::$rRequest['sort']]) ? CoreUtilities::$rRequest['sort'] : 'number');
	$rPicking = array();
	$rYearStart = (intval(CoreUtilities::$rRequest['year_s']) ?: 1900);
	$rYearEnd = (intval(CoreUtilities::$rRequest['year_e']) ?: date('Y'));

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

	$rRatingStart = (intval(CoreUtilities::$rRequest['rating_s']) ?: 0);
	$rRatingEnd = (intval(CoreUtilities::$rRequest['rating_e']) ?: 10);

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

	$rCategoryID = (intval(CoreUtilities::$rRequest['category']) ?: null);
	$rSearchBy = (CoreUtilities::$rRequest['search'] ?: null);

	if (!$rSearchBy) {
	} else {
		$rPage = 1;
		$rLimit = 100;
	}

	$rSeries = CoreUtilities::getUserSeries($rUserInfo, $rCategoryID, null, $rSortBy, $rSearchBy, $rPicking, ($rPage - 1) * $rLimit, $rLimit);
}

$rCover = '';
$rShuffle = $rSeries['streams'];
shuffle($rShuffle);

foreach ($rShuffle as $rStream) {
	$rBackdrop = json_decode($rStream['backdrop_path'], true);

	if (empty($rBackdrop[0])) {
	} else {
		$rCover = CoreUtilities::validateImage($rBackdrop[0]);

		break;
	}
}

if ($rPopular || $rSearchBy) {
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

$_TITLE = 'TV Series';
include 'header.php';
echo "\t" . '<section class="section section--first">' . "\r\n" . '        <div class="details__bg" data-bg="';
echo $rCover;
echo '"></div>' . "\r\n\t\t" . '<div class="container">' . "\r\n\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t" . '<div class="section__wrap">' . "\r\n\t\t\t\t\t\t" . '<h2 class="section__title">';
echo ($rSearchBy ? strtoupper(htmlspecialchars($rSearchBy)) : ($rPopular ? 'POPULAR TV SERIES' : 'TV SERIES'));
echo '</h2>' . "\r\n" . '                        ';

if (!$rSearchBy) {
} else {
	echo '                        <button class="clear__btn wide" type="button">CLEAR</button>' . "\r\n" . '                        ';
}

echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</section>' . "\r\n" . '    ';

if ($rPopular || $rSearchBy) {
} else {
	echo "\t" . '<div class="filter">' . "\r\n\t\t" . '<div class="container">' . "\r\n\t\t\t" . '<div class="row">' . "\r\n\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t" . '<div class="filter__content">' . "\r\n\t\t\t\t\t\t" . '<div class="filter__items">' . "\r\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__genre">' . "\r\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">GENRE:</span>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-genre" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<input type="button" value="';
	echo (isset($rCategoryID) ? CoreUtilities::$rCategories[$rCategoryID]['category_name'] : 'All Genres');
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-genre">' . "\r\n" . '                                    ';

	foreach (CoreUtilities::getOrderedCategories($rUserInfo['category_ids'], 'series') as $rCategory) {
		echo "\t\t\t\t\t\t\t\t\t" . '<li>';
		echo $rCategory['title'];
		echo '</li>' . "\r\n" . '                                    ';
	}
	echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__rate">' . "\r\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">RATING:</span>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="button" id="filter-rate" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<div class="filter__range">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div id="filter__rating-start"></div>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div id="filter__rating-end"></div>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-menu filter__item-menu--range dropdown-menu" aria-labelledby="filter-rate">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<div id="filter__rating"></div>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__year">' . "\r\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">YEAR:</span>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="button" id="filter-year" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<div class="filter__range">' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div id="filter__years-start"></div>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<div id="filter__years-end"></div>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-menu filter__item-menu--range dropdown-menu" aria-labelledby="filter-year">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<div id="filter__years"></div>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__sort">' . "\r\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">SORT:</span>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-quality" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\r\n" . '                                    <input type="button" value="';
	echo (isset($rSortBy) ? $rSortArray[$rSortBy] : 'Date Added');
	echo '">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-quality">' . "\r\n" . '                                    ';

	foreach ($rSortArray as $rKey => $rValue) {
		echo "\t\t\t\t\t\t\t\t\t" . '<li>';
		echo $rValue;
		echo '</li>' . "\r\n" . '                                    ';
	}
	echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n" . '                        <div>' . "\r\n" . '                            <button class="filter__btn" type="button">filter</button>' . "\r\n" . '                            <button class="clear__btn" type="button">X</button>' . "\r\n" . '                        </div>' . "\r\n\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '    ';
}

echo "\t" . '<div class="catalog details';

if (!($rPopular || $rSearchBy)) {
} else {
	echo ' top-margin-med';
}

echo '">' . "\r\n\t\t" . '<div class="container">' . "\r\n\t\t\t" . '<div class="row">' . "\r\n" . '                ';

foreach ($rSeries['streams'] as $rStreamID => $rStream) {
	echo '                    <div class="col-6 col-sm-4 col-lg-3 col-xl-3">' . "\r\n" . '                        <div class="card">' . "\r\n" . '                            <div class="card__cover">' . "\r\n" . '                                <img loading="lazy" src="resize.php?url=';
	echo urlencode((CoreUtilities::validateImage($rStream['cover']) ?: ''));
	echo '&w=267&h=400" alt="">' . "\r\n" . '                                <a href="episodes.php?id=';
	echo $rStream['id'];
	echo '" class="card__play">' . "\r\n" . '                                    <i class="icon ion-ios-play"></i>' . "\r\n" . '                                </a>' . "\r\n" . '                            </div>' . "\r\n" . '                            <div class="card__content">' . "\r\n" . '                                <h3 class="card__title"><a href="episodes.php?id=';
	echo $rStream['id'];
	echo '">';
	echo htmlspecialchars($rStream['title']);
	echo '</a></h3>' . "\r\n" . '                                <span class="card__rate">';
	echo ($rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : ($rStream['releaseDate'] ? intval(substr($rStream['releaseDate'], 0, 4)) . ' &nbsp; ' : ''));
	echo '<i class="icon ion-ios-star"></i>';
	echo ($rStream['rating'] ? number_format($rStream['rating'], 0) : 'N/A');
	echo '</span>' . "\r\n" . '                            </div>' . "\r\n" . '                        </div>' . "\r\n" . '                    </div>' . "\r\n" . '                ';
}

if ($rPopular) {
} else {
	echo "\t\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t\t" . '<ul class="paginator">' . "\r\n" . '                        ';

	if (1 >= $rPage) {
	} else {
		echo '<li class="paginator__item paginator__item--prev">' . "\r\n" . '                                <a href="series.php?page=' . ($rPage - 1) . '"><i class="icon ion-ios-arrow-back"></i></a>' . "\r\n" . '                            </li>';
	}

	if (1 >= $rPagination[0]) {
	} else {
		echo '<li class="paginator__item' . (($rPage == 1 ? ' paginator__item--active' : '')) . '"><a href="series.php?page=1">1</a></li>';

		if (1 >= count($rPagination)) {
		} else {
			echo "<li class='paginator__item'><a href='javascript: void(0);'>...</a></li>";
		}
	}

	foreach ($rPagination as $i) {
		echo '<li class="paginator__item' . (($rPage == $i ? ' paginator__item--active' : '')) . '"><a href="series.php?page=' . $i . '">' . $i . '</a></li>';
	}

	if ($rPagination[count($rPagination) - 1] >= $rPages) {
	} else {
		if (1 >= count($rPagination)) {
		} else {
			echo "<li class='paginator__item'><a href='javascript: void(0);'>...</a></li>";
		}

		echo '<li class="paginator__item' . (($rPage == $rPages ? ' paginator__item--active' : '')) . '"><a href="series.php?page=' . $rPages . '">' . $rPages . '</a></li>';
	}

	if ($rPage >= $rPages) {
	} else {
		echo '<li class="paginator__item paginator__item--next">' . "\r\n" . '                                <a href="series.php?page=' . ($rPage + 1) . '"><i class="icon ion-ios-arrow-forward"></i></a>' . "\r\n" . '                            </li>';
	}

	echo "\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t" . '</div>' . "\r\n" . '                ';
}

echo "\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '    ';

if ($rPopular) {
} else {
	$rPopular = (CoreUtilities::unserialize(file_get_contents(CONTENT_PATH . 'tmdb_popular'))['series'] ?: array());

	if (!(0 < count($rPopular) && 0 < count($rUserInfo['series_ids']))) {
	} else {
		$db->query('SELECT `id`, `title`, `year`, `rating`, `cover`, `backdrop_path` FROM `streams_series` WHERE `id` IN (' . implode(',', $rPopular) . ') AND `id` IN (' . implode(',', $rUserInfo['series_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular) . ') ASC LIMIT 6;');

		$rStreams = $db->get_rows();
		$rShuffle = $rStreams;
		shuffle($rShuffle);

		foreach ($rShuffle as $rStream) {
			$rBackdrop = json_decode($rStream['backdrop_path'], true);

			if (empty($rBackdrop[0])) {
			} else {
				$rCover = CoreUtilities::validateImage($rBackdrop[0]);

				break;
			}
		}
		echo '            <section class="section">' . "\r\n" . '                <div class="details__bg" data-bg="';
		echo $rCover;
		echo '"></div>' . "\r\n" . '                <div class="container">' . "\r\n" . '                    <div class="row">' . "\r\n" . '                        <div class="col-12">' . "\r\n" . '                            <h1 class="home__title bottom-margin-sml">POPULAR <b>THIS WEEK</b></h1>' . "\r\n" . '                        </div>' . "\r\n" . '                        ';

		foreach ($rStreams as $rStream) {
			echo '                            <div class="col-6 col-sm-4 col-lg-3 col-xl-2">' . "\r\n" . '                                <div class="card__cover">' . "\r\n" . '                                    <img loading="lazy" src="resize.php?url=';
			echo urlencode((CoreUtilities::validateImage($rStream['cover']) ?: ''));
			echo '&w=267&h=400" alt="">' . "\r\n" . '                                    <a href="episodes.php?id=';
			echo $rStream['id'];
			echo '" class="card__play">' . "\r\n" . '                                        <i class="icon ion-ios-play"></i>' . "\r\n" . '                                    </a>' . "\r\n" . '                                </div>' . "\r\n" . '                                <div class="card__content">' . "\r\n" . '                                    <h3 class="card__title"><a href="episodes.php?id=';
			echo $rStream['id'];
			echo '">';
			echo htmlspecialchars($rStream['title']);
			echo '</a></h3>' . "\r\n" . '                                    <span class="card__rate">';
			echo ($rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : ($rStream['releaseDate'] ? intval(substr($rStream['releaseDate'], 0, 4)) . ' &nbsp; ' : ''));
			echo '<i class="icon ion-ios-star"></i>';
			echo ($rStream['rating'] ? number_format($rStream['rating'], 0) : 'N/A');
			echo '</span>' . "\r\n" . '                                </div>' . "\r\n" . '                            </div>' . "\r\n" . '                        ';
		}
		echo '                        <div class="col-12">' . "\r\n" . '                            <a href="series.php?sort=popular" class="section__btn">Show more</a>' . "\r\n" . '                        </div>' . "\r\n" . '                    </div>' . "\r\n" . '                </div>' . "\r\n" . '            </section>' . "\r\n" . '        ';
	}
}

include 'footer.php';
