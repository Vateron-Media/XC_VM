<?php

include 'functions.php';

if (!in_array(1, $rUserInfo['allowed_outputs']) || CoreUtilities::$rSettings['disable_hls']) {
	header('Location: index.php');
}

$rCategories = CoreUtilities::getOrderedCategories($rUserInfo['category_ids'], 'live');
$rFilterArray = array('all' => 'All Channels', 'timeshift' => 'Timeshift Only', 'epg' => 'Has EPG Only');
$rFilterBy = (isset($rFilterArray[CoreUtilities::$rRequest['filter']]) ? CoreUtilities::$rRequest['filter'] : 'all');
$rPicking = array('filter' => $rFilterBy);
$rSortArray = array('number' => 'Default', 'name' => 'Name A-Z', 'added' => 'Date Added');
$rSortBy = (isset($rSortArray[CoreUtilities::$rRequest['sort']]) ? CoreUtilities::$rRequest['sort'] : 'number');
$rCategoryID = (intval(CoreUtilities::$rRequest['category']) ?: $rCategories[0]['id']);
$rSearchBy = (CoreUtilities::$rRequest['search'] ?: null);
$rStreamIDs = array();
$rStreams = CoreUtilities::getUserStreams($rUserInfo, array('live', 'created_live'), $rCategoryID, null, $rSortBy, $rSearchBy, $rPicking, null, null, true);

foreach ($rStreams as $rStream) {
	$rStreamIDs[] = $rStream['id'];
}

$db->query('SELECT `movie_properties` FROM `streams` WHERE `movie_properties` IS NOT NULL AND `type` = 2 ORDER BY RAND() LIMIT 5;');
$rCover = '';

foreach ($db->get_rows() as $rStream) {
	$rProperties = json_decode($rStream['movie_properties'], true);

	if (!empty($rProperties['backdrop_path'][0])) {
		$rCover = CoreUtilities::validateImage($rProperties['backdrop_path'][0]);

		break;
	}
}
$_TITLE = 'Live TV';
include 'header.php';
echo "\t" . '<section class="section section--first">' . "\n" . '        <div class="details__bg" data-bg="';
echo $rCover;
echo '"></div>' . "\n\t\t" . '<div class="container">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<div class="section__wrap">' . "\n\t\t\t\t\t\t" . '<h2 class="section__title" id="now__playing__title">';
echo (strtoupper(htmlspecialchars($rSearchBy)) ?: 'LIVE TV');
echo '</h2>' . "\n" . '                        <button onClick="closeChannel();" class="close__btn" type="button" style="display: none;">CLOSE</button>' . "\n\t\t\t\t\t" . '</div>' . "\n" . '                    <span id="now__playing__box" style="display: none;">' . "\n" . '                        <h3 class="card__title" id="now__playing__epg"></h3>' . "\n" . '                        <span class="card__rate" id="now__playing__text"></span>' . "\n" . '                        <div id="now__playing__player"></div>' . "\n" . '                    </span>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</section>' . "\n" . '    ';

if (!$rSearchBy) {
	echo "\t" . '<div class="filter">' . "\n\t\t" . '<div class="container">' . "\n\t\t\t" . '<div class="row">' . "\n\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t" . '<div class="filter__content">' . "\n\t\t\t\t\t\t" . '<div class="filter__items">' . "\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__genre">' . "\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">CATEGORY:</span>' . "\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-genre" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\n\t\t\t\t\t\t\t\t\t" . '<input type="button" value="';
	echo (!empty($rCategoryID) ? CoreUtilities::$rCategories[$rCategoryID]['category_name'] : $rCategories[0]['title']);
	echo '">' . "\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-genre">' . "\n" . '                                    ';

	foreach ($rCategories as $rCategory) {
		echo "\t\t\t\t\t\t\t\t\t" . '<li>';
		echo $rCategory['title'];
		echo '</li>' . "\n" . '                                    ';
	}
	echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__filter">' . "\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">FILTER:</span>' . "\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-archive" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\n" . '                                    <input type="button" value="';
	echo (isset($rFilterBy) ? $rFilterArray[$rFilterBy] : 'All Channels');
	echo '">' . "\n\t\t\t\t\t\t\t\t\t" . '<span></span>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<ul class="filter__item-menu dropdown-menu scrollbar-dropdown" aria-labelledby="filter-archive">' . "\n" . '                                    ';

	foreach ($rFilterArray as $rKey => $rValue) {
		echo "\t\t\t\t\t\t\t\t\t" . '<li>';
		echo $rValue;
		echo '</li>' . "\n" . '                                    ';
	}
	echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '<div class="filter__item" id="filter__sort">' . "\n\t\t\t\t\t\t\t\t" . '<span class="filter__item-label">SORT:</span>' . "\n\t\t\t\t\t\t\t\t" . '<div class="filter__item-btn dropdown-toggle" role="navigation" id="filter-quality" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">' . "\n" . '                                    <input type="button" value="';
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

if ($rSearchBy) {
	echo ' top-margin-med';
}

echo '">' . "\n\t\t" . '<div class="container">' . "\n\t\t\t" . '<div class="row">' . "\n" . '                <div class="col-12">' . "\n" . '                    ';

if (0 < count($rStreamIDs)) {
	echo '                    <div class="listings-grid-container">' . "\n" . '                        <a href="#" class="listings-direction-link left day-nav-arrow js-day-nav-arrow" data-direction="prev"><span class="isvg isvg-left-dir"></span></a>' . "\n" . '                        <a href="#" class="listings-direction-link right day-nav-arrow js-day-nav-arrow" data-direction="next"><span class="isvg isvg-right-dir"></span></a>' . "\n" . '                        <div class="listings-day-slider-wrapper">' . "\n" . '                            <div class="listings-day-slider js-listings-day-slider">' . "\n" . '                                <div class="js-listings-day-nav-inner"></div>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n" . '                        <div class="js-billboard-fix-point"></div>' . "\n" . '                        <div class="listings-grid-inner">' . "\n" . '                            <div class="time-nav-bar cf js-time-nav-bar">' . "\n" . '                                <div class="listings-mobile-nav">' . "\n" . '                                    <a class="listings-now-btn js-now-btn" href="#">NOW</a>' . "\n" . '                                </div>' . "\n" . '                                <div class="listings-times-wrapper">' . "\n" . '                                    <a href="#" class="listings-direction-link left js-time-nav-arrow" data-direction="prev"><span class="isvg isvg-left-dir"></span></a>' . "\n" . '                                    <a href="#" class="listings-direction-link right js-time-nav-arrow" data-direction="next"><span class="isvg isvg-right-dir"></span></a>' . "\n" . '                                    <div class="times-slider js-times-slider"></div>' . "\n" . '                                </div>' . "\n" . '                                <div class="listings-loader js-listings-loader"><span class="isvg isvg-loader animate-spin"></span></div>' . "\n" . '                            </div>' . "\n" . '                            <div class="listings-wrapper cf js-listings-wrapper">' . "\n" . '                                <div class="listings-timeline js-listings-timeline"></div>' . "\n" . '                                <div class="js-listings-container"></div>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n" . '                    </div>' . "\n" . '                    ';
} else {
	echo '                    <div class="results_form">' . "\n" . '                        <div class="row">' . "\n" . '                            <div class="col-12">' . "\n" . '                                <h4 class="results__error">No Live Channels or Programmes have been found matching your search terms.</h4>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n" . '                    </div>' . "\n" . '                    ';
}

echo '                </div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n";
include 'footer.php';
