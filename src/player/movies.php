<?php







include 'functions.php';

if (isset(CoreUtilities::$rRequest['sort']) && CoreUtilities::$rRequest['sort'] == 'popular') {
	$rPopular = true;
	$rPopular = (CoreUtilities::unserialize(file_get_contents(CONTENT_PATH . 'tmdb_popular'))['movies'] ?: array());

	if (0 < count($rPopular) && 0 < count($rUserInfo['vod_ids'])) {
			$db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . implode(',', $rPopular) . ') AND `id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular) . ') ASC LIMIT 100;');

		$rStreams = array('count' => $db->num_rows(), 'streams' => $db->get_rows());
	} else {
		header('Location: movies.php');
	}
} else {
	$rPopular = false;
	$rPage = (intval(CoreUtilities::$rRequest['page']) ?: 1);
	$rLimit = 48;
	$rSortArray = array('number' => 'Default', 'added' => 'Date Added', 'release' => 'Release Date', 'name' => 'Title A-Z', 'top' => 'Rating');
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

	$rRatingStart = (floatval(CoreUtilities::$rRequest['rating_s']) ?: 0);
	$rRatingEnd = (floatval(CoreUtilities::$rRequest['rating_e']) ?: 10);

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

	$rStreams = CoreUtilities::getUserStreams($rUserInfo, array('movie'), $rCategoryID, null, $rSortBy, $rSearchBy, $rPicking, ($rPage - 1) * $rLimit, $rLimit);
}

$rCover = '';
$rShuffle = $rStreams['streams'];
shuffle($rShuffle);

foreach ($rShuffle as $rStream) {
	$rProperties = json_decode($rStream['movie_properties'], true);

	if (empty($rProperties['backdrop_path'][0])) {
	} else {
		$rCover = CoreUtilities::validateImage($rProperties['backdrop_path'][0]);

		break;
	}
}

if ($rPopular || $rSearchBy) {
} else {
	$rCount = $rStreams['count'];
	$rPages = ceil($rCount / $rLimit);
	$rPagination = array();

	foreach (range($rPage - 2, $rPage + 2) as $i) {
		if (!(1 <= $i && $i <= $rPages)) {
		} else {
			$rPagination[] = $i;
		}
	}
}

$_TITLE = 'Movies';
include 'header.php';
echo "\t" . '<section class="section section--first">' . "\n" . '        <div class="details__bg" data-bg="';
echo $rCover;
echo '"></div>' . "\n\t\t" . '<div class="container">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<div class="section__wrap">' . "\n" . '                        <h2 class="section__title">';
echo ($rSearchBy ? strtoupper(htmlspecialchars($rSearchBy)) : ($rPopular ? 'POPULAR MOVIES' : 'MOVIES'));
echo '</h2>' . "\n" . '                        ';

if (!$rSearchBy) {
} else {
	echo '                        <button class="clear__btn wide" type="button">CLEAR</button>' . "\n" . '                        ';
}

echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</section>' . "\n" . '    ';

if ($rPopular || $rSearchBy) {
} else {
	echo "\t" . '<div class="filter">' . "\n\t\t" . '<div class="container">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<div class="filter__content">' . "\n\t\t\t\t\t\t" . '<div class="filter__items">' . "\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__genre">' . "\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">GENRE:</span>' . "\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-genre" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\n\t\t\t\t\t\t\t\t\t" . '<input type="button" value="';
	echo (isset($rCategoryID) ? CoreUtilities::$rCategories[$rCategoryID]['category_name'] : 'All Genres');
	echo '">' . "\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-genre">' . "\n" . '                                    ';

	foreach (CoreUtilities::getOrderedCategories($rUserInfo['category_ids']) as $rCategory) {
		echo "\t\t\t\t\t\t\t\t\t" . '<li>';
		echo $rCategory['title'];
		echo '</li>' . "\n" . '                                    ';
	}
	echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__rate">' . "\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">RATING:</span>' . "\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="button" id="filter-rate" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="filter__range">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div id="filter__rating-start"></div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div id="filter__rating-end"></div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-menu filter__item-menu--range dropdown-menu" aria-labelledby="filter-rate">' . "\n\t\t\t\t\t\t\t\t\t" . '<div id="filter__rating"></div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__year">' . "\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">YEAR:</span>' . "\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="button" id="filter-year" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="filter__range">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div id="filter__years-start"></div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div id="filter__years-end"></div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-menu filter__item-menu--range dropdown-menu" aria-labelledby="filter-year">' . "\n\t\t\t\t\t\t\t\t\t" . '<div id="filter__years"></div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__sort">' . "\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">SORT:</span>' . "\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-quality" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\n" . '                                    <input type="button" value="';
	echo (isset($rSortBy) ? $rSortArray[$rSortBy] : 'Date Added');
	echo '">' . "\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-quality">' . "\n" . '                                    ';

	foreach ($rSortArray as $rKey => $rValue) {
		echo "\t\t\t\t\t\t\t\t\t" . '<li>';
		echo $rValue;
		echo '</li>' . "\n" . '                                    ';
	}
	echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</div>' . "\n" . '                        <div>' . "\n" . '                            <button class="filter__btn" type="button">filter</button>' . "\n" . '                            <button class="clear__btn" type="button">X</button>' . "\n" . '                        </div>' . "\n\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '    ';
}

echo "\t" . '<div class="catalog details';

if (!($rPopular || $rSearchBy)) {
} else {
	echo ' top-margin-med';
}

echo '">' . "\n\t\t" . '<div class="container">' . "\n\t\t\t" . '<div class="row">' . "\n" . '                ';

foreach ($rStreams['streams'] as $rStreamID => $rStream) {
	$rProperties = json_decode($rStream['movie_properties'], true);
	echo '                    <div class="col-6 col-sm-4 col-lg-3 col-xl-3">' . "\n" . '                        <div class="card">' . "\n" . '                            <div class="card__cover">' . "\n" . '                                <img loading="lazy" src="resize.php?url=';
	echo urlencode((CoreUtilities::validateImage($rProperties['movie_image']) ?: ''));
	echo '&w=267&h=400" alt="">' . "\n" . '                                <a href="movie.php?id=';
	echo $rStream['id'];
	echo '" class="card__play">' . "\n" . '                                    <i class="icon ion-ios-play"></i>' . "\n" . '                                </a>' . "\n" . '                            </div>' . "\n" . '                            <div class="card__content">' . "\n" . '                                <h3 class="card__title"><a href="movie.php?id=';
	echo $rStream['id'];
	echo '">';
	echo htmlspecialchars(($rStream['title'] ?: $rStream['stream_display_name']));
	echo '</a></h3>' . "\n" . '                                <span class="card__rate">';
	echo ($rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : '');
	echo '<i class="icon ion-ios-star"></i>';
	echo ($rProperties['rating'] ? number_format($rProperties['rating'], 1) : 'N/A');
	echo '</span>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n" . '                    </div>' . "\n" . '                ';
}

if ($rPopular) {
} else {
	echo "\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<ul class="paginator">' . "\n" . '                        ';

	if (1 >= $rPage) {
	} else {
		echo '<li class="paginator__item paginator__item--prev">' . "\n" . '                                <a href="movies.php?page=' . ($rPage - 1) . '"><i class="icon ion-ios-arrow-back"></i></a>' . "\n" . '                            </li>';
	}

	if (1 >= $rPagination[0]) {
	} else {
		echo '<li class="paginator__item' . (($rPage == 1 ? ' paginator__item--active' : '')) . '"><a href="movies.php?page=1">1</a></li>';

		if (1 >= count($rPagination)) {
		} else {
			echo "<li class='paginator__item'><a href='javascript: void(0);'>...</a></li>";
		}
	}

	foreach ($rPagination as $i) {
		echo '<li class="paginator__item' . (($rPage == $i ? ' paginator__item--active' : '')) . '"><a href="movies.php?page=' . $i . '">' . $i . '</a></li>';
	}

	if ($rPagination[count($rPagination) - 1] >= $rPages) {
	} else {
		if (1 >= count($rPagination)) {
		} else {
			echo "<li class='paginator__item'><a href='javascript: void(0);'>...</a></li>";
		}

		echo '<li class="paginator__item' . (($rPage == $rPages ? ' paginator__item--active' : '')) . '"><a href="movies.php?page=' . $rPages . '">' . $rPages . '</a></li>';
	}

	if ($rPage >= $rPages) {
	} else {
		echo '<li class="paginator__item paginator__item--next">' . "\n" . '                                <a href="movies.php?page=' . ($rPage + 1) . '"><i class="icon ion-ios-arrow-forward"></i></a>' . "\n" . '                            </li>';
	}

	echo "\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t" . '</div>' . "\n" . '                ';
}

echo "\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '    ';

if ($rPopular) {
} else {
	$rPopular = (CoreUtilities::unserialize(file_get_contents(CONTENT_PATH . 'tmdb_popular'))['movies'] ?: array());

	if (!(0 < count($rPopular) && 0 < count($rUserInfo['vod_ids']))) {
	} else {
			$db->query('SELECT `id`, `stream_display_name`, `year`, `rating`, `movie_properties` FROM `streams` WHERE `id` IN (' . implode(',', $rPopular) . ') AND `id` IN (' . implode(',', $rUserInfo['vod_ids']) . ') ORDER BY FIELD(id, ' . implode(',', $rPopular) . ') ASC LIMIT 6;');

		$rStreams = $db->get_rows();
		$rShuffle = $rStreams;
		shuffle($rShuffle);

		foreach ($rShuffle as $rStream) {
			$rProperties = json_decode($rStream['movie_properties'], true);

			if (empty($rProperties['backdrop_path'][0])) {
			} else {
				$rCover = CoreUtilities::validateImage($rProperties['backdrop_path'][0]);

				break;
			}
		}
		echo '            <section class="section">' . "\n" . '                <div class="details__bg" data-bg="';
		echo $rCover;
		echo '"></div>' . "\n" . '                <div class="container">' . "\n" . '                    <div class="row">' . "\n" . '                        <div class="col-12">' . "\n" . '                            <h1 class="home__title bottom-margin-sml">POPULAR <b>THIS WEEK</b></h1>' . "\n" . '                        </div>' . "\n" . '                        ';

		foreach ($rStreams as $rStream) {
			$rProperties = json_decode($rStream['movie_properties'], true);
			echo '                            <div class="col-6 col-sm-4 col-lg-3 col-xl-2">' . "\n" . '                                <div class="card">' . "\n" . '                                    <div class="card__cover">' . "\n" . '                                        <img loading="lazy" src="resize.php?url=';
			echo urlencode((CoreUtilities::validateImage($rProperties['movie_image']) ?: ''));
			echo '&w=267&h=400" alt="">' . "\n" . '                                        <a href="movie.php?id=';
			echo $rStream['id'];
			echo '" class="card__play">' . "\n" . '                                            <i class="icon ion-ios-play"></i>' . "\n" . '                                        </a>' . "\n" . '                                    </div>' . "\n" . '                                    <div class="card__content">' . "\n" . '                                        <h3 class="card__title"><a href="movie.php?id=';
			echo $rStream['id'];
			echo '">';
			echo htmlspecialchars(($rStream['title'] ?: $rStream['stream_display_name']));
			echo '</a></h3>' . "\n" . '                                        <span class="card__rate">';
			echo ($rStream['year'] ? intval($rStream['year']) . ' &nbsp; ' : '');
			echo '<i class="icon ion-ios-star"></i>';
			echo ($rProperties['rating'] ? number_format($rProperties['rating'], 1) : 'N/A');
			echo '</span>' . "\n" . '                                    </div>' . "\n" . '                                </div>' . "\n" . '                            </div>' . "\n" . '                        ';
		}
		echo '                        <div class="col-12">' . "\n" . '                            <a href="movies.php?sort=popular" class="section__btn">Show more</a>' . "\n" . '                        </div>' . "\n" . '                    </div>' . "\n" . '                </div>' . "\n" . '            </section>' . "\n" . '        ';
	}
}

include 'footer.php';
