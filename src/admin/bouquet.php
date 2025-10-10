<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

if (!isset(CoreUtilities::$rRequest['id'])) {
} else {
	$rBouquetArr = getBouquet(CoreUtilities::$rRequest['id']);
}

if (!isset(CoreUtilities::$rRequest['duplicate'])) {
} else {
	$rBouquetArr = getBouquet(CoreUtilities::$rRequest['duplicate']);
	$rBouquetArr['bouquet_name'] .= ' - Copy';
	unset($rBouquetArr['id']);
}

$rSeriesNames = $rNames = array();

if (!isset($rBouquetArr)) {
} else {
	$rBouquetChannels = json_decode($rBouquetArr['bouquet_channels'], true);
	$rBouquetMovies = json_decode($rBouquetArr['bouquet_movies'], true);
	$rBouquetRadios = json_decode($rBouquetArr['bouquet_radios'], true);
	$rBouquetSeries = json_decode($rBouquetArr['bouquet_series'], true);
	$rRequiredIDs = confirmIDs(array_merge($rBouquetChannels, $rBouquetMovies, $rBouquetRadios));

	if (0 >= count($rRequiredIDs)) {
	} else {
		$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rRequiredIDs) . ');');

		foreach ($db->get_rows() as $rRow) {
			$rNames[$rRow['id']] = $rRow['stream_display_name'];
		}
	}

	if (0 >= count($rBouquetSeries)) {
	} else {
		$db->query('SELECT `id`, `title` FROM `streams_series` WHERE `id` IN (' . implode(',', $rBouquetSeries) . ');');

		foreach ($db->get_rows() as $rRow) {
			$rSeriesNames[$rRow['id']] = $rRow['title'];
		}
	}
}

$_TITLE = 'Bouquets';
include 'header.php';
echo '<div class="wrapper boxed-layout"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n\t\t\t\t\t\t";
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">';

if (isset($rBouquetArr['id'])) {
	echo $_['edit_bouquet'];
} else {
	echo $_['add_bouquet'];
}

echo '</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-xl-12">' . "\n\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t" . '<form onSubmit="return false;" action="#" method="POST" id="bouquet_form" data-parsley-validate="">' . "\n\t\t\t\t\t\t\t";

if (!isset($rBouquetArr['id'])) {
} else {
	echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="edit" value="';
	echo intval($rBouquetArr['id']);
	echo '" />' . "\n\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t" . '<input type="hidden" id="bouquet_data" name="bouquet_data" value="" />' . "\n\t\t\t\t\t\t\t" . '<div id="basicwizard">' . "\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#bouquet-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-card-details-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
echo $_['details'];
echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t";

if (!isset($rBouquetArr)) {
} else {
	echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#channels" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-play mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
	echo $_['streams'];
	echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#movies" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-movie mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
	echo $_['movies'];
	echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#series" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-youtube-tv mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
	echo $_['series'];
	echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#radios" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-radio mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
	echo $_['radio'];
	echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#review" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-book-open-variant mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
	echo $_['review'];
	echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="bouquet-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="bouquet_name">';
echo $_['bouquet_name'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="bouquet_name" name="bouquet_name" value="';

if (!isset($rBouquetArr)) {
} else {
	echo htmlspecialchars($rBouquetArr['bouquet_name']);
}

echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";

if (isset($rBouquetArr)) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['next'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
} else {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_bouquet" type="submit" class="btn btn-primary" value="';
	echo $_['add'];
	echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t";

if (!isset($rBouquetArr)) {
} else {
	echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="channels">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="category_name">';
	echo $_['category_name'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="category_id" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="" selected>';
	echo $_['all_categories'];
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

	foreach (getCategories('live') as $rCategory) {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
		echo intval($rCategory['id']);
		echo '">';
		echo htmlspecialchars($rCategory['category_name']);
		echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
	}
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="stream_search">';
	echo $_['search'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="stream_search" value="">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-stream" class="table nowrap">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['id'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['stream_name'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['category'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['actions'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['prev'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<a href=\"javascript: void(0);\" onClick=\"toggleBouquets('datatable-stream')\" class=\"btn btn-primary\">";
	echo $_['toggle_page'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['next'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="movies">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="category_name">';
	echo $_['category_name'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="category_idv" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="" selected>';
	echo $_['all_categories'];
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

	foreach (getCategories('movie') as $rCategory) {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
		echo intval($rCategory['id']);
		echo '">';
		echo htmlspecialchars($rCategory['category_name']);
		echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
	}
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="vod_search">';
	echo $_['search'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="vod_search" value="">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-movies" class="table nowrap">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['id'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['vod_name'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['category'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['actions'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['prev'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<a href=\"javascript: void(0);\" onClick=\"toggleBouquets('datatable-movies')\" class=\"btn btn-primary\">";
	echo $_['toggle_page'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['next'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="series">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="category_name">';
	echo $_['category_name'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="category_ids" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="" selected>';
	echo $_['all_categories'];
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

	foreach (getCategories('series') as $rCategory) {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
		echo intval($rCategory['id']);
		echo '">';
		echo htmlspecialchars($rCategory['category_name']);
		echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
	}
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="series_search">';
	echo $_['search'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="series_search" value="">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-series" class="table nowrap">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['id'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['series_name'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['category'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['actions'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['prev'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<a href=\"javascript: void(0);\" onClick=\"toggleBouquets('datatable-series')\" class=\"btn btn-primary\">";
	echo $_['toggle_page'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['next'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="radios">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="category_idr">';
	echo $_['category_name'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="category_idr" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="" selected>';
	echo $_['all_categories'];
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

	foreach (getCategories('radio') as $rCategory) {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
		echo intval($rCategory['id']);
		echo '">';
		echo htmlspecialchars($rCategory['category_name']);
		echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
	}
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="radios_search">';
	echo $_['search'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="radios_search" value="">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-radios" class="table nowrap">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['id'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['station_name'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['category'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['actions'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['prev'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . "<a href=\"javascript: void(0);\" onClick=\"toggleBouquets('datatable-series')\" class=\"btn btn-primary\">";
	echo $_['toggle_page'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['next'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="review">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-review" class="table nowrap">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['id'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['type'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['display_name'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">';
	echo $_['actions'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\n" . '                                                            ';

	foreach ($rBouquetChannels as $rChannel) {
		echo '                                                            <tr id="stream-';
		echo $rChannel;
		echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
		echo $rChannel;
		echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>Stream</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
		echo $rNames[$rChannel];
		echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n" . '                                                                    <button type="button" class="btn-remove btn btn-warning waves-effect waves-warning btn-xs" onClick="toggleBouquet(';
		echo $rChannel;
		echo ", 'stream');\"><i class=\"mdi mdi-minus\"></i></button>" . "\n" . '                                                                </td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n" . '                                                            ';
	}

	foreach ($rBouquetMovies as $rChannel) {
		echo '                                                            <tr id="movies-';
		echo $rChannel;
		echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
		echo $rChannel;
		echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>Movies</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
		echo $rNames[$rChannel];
		echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n" . '                                                                    <button type="button" class="btn-remove btn btn-warning waves-effect waves-warning btn-xs" onClick="toggleBouquet(';
		echo $rChannel;
		echo ", 'movies');\"><i class=\"mdi mdi-minus\"></i></button>" . "\n" . '                                                                </td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n" . '                                                            ';
	}

	foreach ($rBouquetRadios as $rChannel) {
		echo '                                                            <tr id="radios-';
		echo $rChannel;
		echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
		echo $rChannel;
		echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>Radios</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
		echo $rNames[$rChannel];
		echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n" . '                                                                    <button type="button" class="btn-remove btn btn-warning waves-effect waves-warning btn-xs" onClick="toggleBouquet(';
		echo $rChannel;
		echo ", 'radios');\"><i class=\"mdi mdi-minus\"></i></button>" . "\n" . '                                                                </td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n" . '                                                            ';
	}

	foreach ($rBouquetSeries as $rChannel) {
		echo '                                                            <tr id="series-';
		echo $rChannel;
		echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">';
		echo $rChannel;
		echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>Series</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
		echo $rSeriesNames[$rChannel];
		echo '</td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\n" . '                                                                    <button type="button" class="btn-remove btn btn-warning waves-effect waves-warning btn-xs" onClick="toggleBouquet(';
		echo $rChannel;
		echo ", 'series');\"><i class=\"mdi mdi-minus\"></i></button>" . "\n" . '                                                                </td>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n" . '                                                            ';
	}
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['prev'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_bouquet" type="submit" class="btn btn-primary" value="';

	if (isset($rBouquetArr['id'])) {
		echo $_['edit'];
	} else {
		echo $_['add'];
	}

	echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t" . '</form>' . "\n\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php'; ?>
<script id="scripts">
			var resizeObserver = new ResizeObserver(entries => $(window).scroll());
			$(document).ready(function() {
				resizeObserver.observe(document.body)
				$("form").attr('autocomplete', 'off');
				$(document).keypress(function(event) {
					if (event.which == 13 && event.target.nodeName != "TEXTAREA") return false;
				});
				$.fn.dataTable.ext.errMode = 'none';
				var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));
				elems.forEach(function(html) {
					var switchery = new Switchery(html, {
						'color': '#414d5f'
					});
					window.rSwitches[$(html).attr("id")] = switchery;
				});
				setTimeout(pingSession, 30000);
				<?php if (!$rMobile && $rSettings['header_stats']): ?>
					headerStats();
				<?php endif; ?>
				bindHref();
				refreshTooltips();
				$(window).scroll(function() {
					if ($(this).scrollTop() > 200) {
						if ($(document).height() > $(window).height()) {
							$('#scrollToBottom').fadeOut();
						}
						$('#scrollToTop').fadeIn();
					} else {
						$('#scrollToTop').fadeOut();
						if ($(document).height() > $(window).height()) {
							$('#scrollToBottom').fadeIn();
						} else {
							$('#scrollToBottom').hide();
						}
					}
				});
				$("#scrollToTop").unbind("click");
				$('#scrollToTop').click(function() {
					$('html, body').animate({
						scrollTop: 0
					}, 800);
					return false;
				});
				$("#scrollToBottom").unbind("click");
				$('#scrollToBottom').click(function() {
					$('html, body').animate({
						scrollTop: $(document).height()
					}, 800);
					return false;
				});
				$(window).scroll();
				$(".nextb").unbind("click");
				$(".nextb").click(function() {
					var rPos = 0;
					var rActive = null;
					$(".nav .nav-item").each(function() {
						if ($(this).find(".nav-link").hasClass("active")) {
							rActive = rPos;
						}
						if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
							$(this).find(".nav-link").trigger("click");
							return false;
						}
						rPos += 1;
					});
				});
				$(".prevb").unbind("click");
				$(".prevb").click(function() {
					var rPos = 0;
					var rActive = null;
					$($(".nav .nav-item").get().reverse()).each(function() {
						if ($(this).find(".nav-link").hasClass("active")) {
							rActive = rPos;
						}
						if (rActive !== null && rPos > rActive && !$(this).find("a").hasClass("disabled") && $(this).is(":visible")) {
							$(this).find(".nav-link").trigger("click");
							return false;
						}
						rPos += 1;
					});
				});
				(function($) {
					$.fn.inputFilter = function(inputFilter) {
						return this.on("input keydown keyup mousedown mouseup select contextmenu drop", function() {
							if (inputFilter(this.value)) {
								this.oldValue = this.value;
								this.oldSelectionStart = this.selectionStart;
								this.oldSelectionEnd = this.selectionEnd;
							} else if (this.hasOwnProperty("oldValue")) {
								this.value = this.oldValue;
								this.setSelectionRange(this.oldSelectionStart, this.oldSelectionEnd);
							}
						});
					};
				}(jQuery));
				<?php if ($rSettings['js_navigate']): ?>
					$(".navigation-menu li").mouseenter(function() {
						$(this).find(".submenu").show();
					});
					delParam("status");
					$(window).on("popstate", function() {
						if (window.rRealURL) {
							if (window.rRealURL.split("/").reverse()[0].split("?")[0].split(".")[0] != window.location.href.split("/").reverse()[0].split("?")[0].split(".")[0]) {
								navigate(window.location.href.split("/").reverse()[0]);
							}
						}
					});
				<?php endif; ?>
				$(document).keydown(function(e) {
					if (e.keyCode == 16) {
						window.rShiftHeld = true;
					}
				});
				$(document).keyup(function(e) {
					if (e.keyCode == 16) {
						window.rShiftHeld = false;
					}
				});
				document.onselectstart = function() {
					if (window.rShiftHeld) {
						return false;
					}
				}
			});

			<?php if (isset($rBouquetArr)) {
			if (is_array(json_decode($rBouquetArr['bouquet_series'], true))) {
			} else {
				$rBouquetArr['bouquet_series'] = '[]';
			}

			if (is_array(json_decode($rBouquetArr['bouquet_channels'], true))) {
			} else {
				$rBouquetArr['bouquet_channels'] = '[]';
			}

			if (is_array(json_decode($rBouquetArr['bouquet_movies'], true))) {
			} else {
				$rBouquetArr['bouquet_movies'] = '[]';
			}

			if (is_array(json_decode($rBouquetArr['bouquet_radios'], true))) {
			} else {
				$rBouquetArr['bouquet_radios'] = '[]';
			}

			echo '            var rBouquet = {"stream": $.parseJSON(';
			echo json_encode($rBouquetArr['bouquet_channels']);
			echo '), "series": $.parseJSON(';
			echo json_encode($rBouquetArr['bouquet_series']);
			echo '), "movies": $.parseJSON(';
			echo json_encode($rBouquetArr['bouquet_movies']);
			echo '), "radios": $.parseJSON(';
			echo json_encode($rBouquetArr['bouquet_radios']);
			echo ')};' . "\r\n" . '        ';
		}

		echo '        function toggleBouquet(rID, rType, rDraw=true) {' . "\r\n" . '            var rIndex = rBouquet[rType].indexOf(parseInt(rID));' . "\r\n" . '            if (rIndex > -1) {' . "\r\n" . '                rBouquet[rType] = jQuery.grep(rBouquet[rType], function(rValue) {' . "\r\n" . '                    return parseInt(rValue) != parseInt(rID);' . "\r\n" . '                });' . "\r\n" . "                \$('#' + rType + '-' + rID).find(\".btn-add\").show(); \$('#' + rType + '-' + rID).find(\".btn-remove\").hide();" . "\r\n" . '                delRow(rID, rType);' . "\r\n" . '            } else {' . "\r\n" . '                rBouquet[rType].push(parseInt(rID));' . "\r\n" . "                \$('#' + rType + '-' + rID).find(\".btn-remove\").show(); \$('#' + rType + '-' + rID).find(\".btn-add\").hide();" . "\r\n" . '                addRow(rID, rType);' . "\r\n" . '            }' . "\r\n" . '            if (rDraw) {' . "\r\n" . "                \$('#datatable-review').DataTable().draw(false);" . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function ucwords(str) {' . "\r\n" . "            return (str + '').replace(/^([a-z])|\\s+([a-z])/g, function (\$1) {" . "\r\n" . '                return $1.toUpperCase();' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        function addRow(rID, rType) {' . "\r\n" . "            var rTable = \$('#datatable-review').DataTable();" . "\r\n" . "            rTable.row.add([rID, ucwords(rType), \$(\$(\"#\" + rType + \"-\" + rID).find(\"td\")[1]).text(), '<button type=\"button\" class=\"btn-remove btn btn-warning waves-effect waves-warning btn-xs\" onClick=\"toggleBouquet(' + rID + ', \\'' + rType + '\\');\"><i class=\"mdi mdi-minus\"></i></button>']).node().id = rType + '-' + rID;" . "\r\n" . '        }' . "\r\n" . '        function delRow(rID, rType) {' . "\r\n" . "            var rTable = \$('#datatable-review').DataTable();" . "\r\n" . "            rTable.row('#' + rType + '-' + rID).remove();" . "\r\n" . '        }' . "\r\n" . '        function toggleBouquets(_PAGE) {' . "\r\n" . '            $("#" + _PAGE + " tr").each(function() {' . "\r\n" . "                \$(this).find(\"td:last-child button\").filter(':visible').each(function() {" . "\r\n" . '                    toggleBouquet($(this).data("id"), $(this).data("type"), false);' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . "            \$('#datatable-review').DataTable().draw(false);" . "\r\n" . '        }' . "\r\n" . '        $(document).ready(function() {' . "\r\n" . '            $("#datatable-stream").DataTable({' . "\r\n" . '                language: {' . "\r\n" . '                    paginate: {' . "\r\n" . "                        previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n" . "                        next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n" . '                createdRow: function(row, data, index) {' . "\r\n" . "                    \$(row).attr('id', 'stream-' + data[0]);" . "\r\n" . '                    var rIndex = rBouquet["stream"].indexOf(parseInt(data[0]));' . "\r\n" . '                    if (rIndex > -1) {' . "\r\n" . '                        $(row).find(".btn-remove").show();' . "\r\n" . '                    } else {' . "\r\n" . '                        $(row).find(".btn-add").show();' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                bInfo: false,' . "\r\n" . '                bAutoWidth: false,' . "\r\n" . '                searching: true,' . "\r\n" . '                pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                lengthChange: false,' . "\r\n" . '                processing: true,' . "\r\n" . '                serverSide: true,' . "\r\n" . '                ajax: {' . "\r\n" . '                    url: "./table",' . "\r\n" . '                    "data": function(d) {' . "\r\n" . '                        d.id = "bouquets_streams";' . "\r\n" . '                        d.category_id = $("#category_id").val();' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0,3]}' . "\r\n" . '                ],' . "\r\n" . '            });' . "\r\n" . '            $("#datatable-movies").DataTable({' . "\r\n" . '                language: {' . "\r\n" . '                    paginate: {' . "\r\n" . "                        previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n" . "                        next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n" . '                createdRow: function(row, data, index) {' . "\r\n" . "                    \$(row).attr('id', 'movies-' + data[0]);" . "\r\n" . '                    var rIndex = rBouquet["movies"].indexOf(parseInt(data[0]));' . "\r\n" . '                    if (rIndex > -1) {' . "\r\n" . '                        $(row).find(".btn-remove").show();' . "\r\n" . '                    } else {' . "\r\n" . '                        $(row).find(".btn-add").show();' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                bInfo: false,' . "\r\n" . '                bAutoWidth: false,' . "\r\n" . '                searching: true,' . "\r\n" . '                pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                lengthChange: false,' . "\r\n" . '                processing: true,' . "\r\n" . '                serverSide: true,' . "\r\n" . '                ajax: {' . "\r\n" . '                    url: "./table",' . "\r\n" . '                    "data": function(d) {' . "\r\n" . '                        d.id = "bouquets_vod";' . "\r\n" . '                        d.category_id = $("#category_idv").val();' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0,3]}' . "\r\n" . '                ],' . "\r\n" . '            });' . "\r\n" . '            $("#datatable-series").DataTable({' . "\r\n" . '                language: {' . "\r\n" . '                    paginate: {' . "\r\n" . "                        previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n" . "                        next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n" . '                createdRow: function(row, data, index) {' . "\r\n" . "                    \$(row).attr('id', 'series-' + data[0]);" . "\r\n" . '                    var rIndex = rBouquet["series"].indexOf(parseInt(data[0]));' . "\r\n" . '                    if (rIndex > -1) {' . "\r\n" . '                        $(row).find(".btn-remove").show();' . "\r\n" . '                    } else {' . "\r\n" . '                        $(row).find(".btn-add").show();' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                bInfo: false,' . "\r\n" . '                bAutoWidth: false,' . "\r\n" . '                searching: true,' . "\r\n" . '                pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                lengthChange: false,' . "\r\n" . '                processing: true,' . "\r\n" . '                serverSide: true,' . "\r\n" . '                ajax: {' . "\r\n" . '                    url: "./table",' . "\r\n" . '                    "data": function(d) {' . "\r\n" . '                        d.id = "bouquets_series";' . "\r\n" . '                        d.category_id = $("#category_ids").val();' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0,3]}' . "\r\n" . '                ],' . "\r\n" . '            });' . "\r\n" . '            $("#datatable-radios").DataTable({' . "\r\n" . '                language: {' . "\r\n" . '                    paginate: {' . "\r\n" . "                        previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n" . "                        next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n" . '                createdRow: function(row, data, index) {' . "\r\n" . "                    \$(row).attr('id', 'radios-' + data[0]);" . "\r\n" . '                    var rIndex = rBouquet["radios"].indexOf(parseInt(data[0]));' . "\r\n" . '                    if (rIndex > -1) {' . "\r\n" . '                        $(row).find(".btn-remove").show();' . "\r\n" . '                    } else {' . "\r\n" . '                        $(row).find(".btn-add").show();' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                bInfo: false,' . "\r\n" . '                bAutoWidth: false,' . "\r\n" . '                searching: true,' . "\r\n" . '                pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                lengthChange: false,' . "\r\n" . '                processing: true,' . "\r\n" . '                serverSide: true,' . "\r\n" . '                ajax: {' . "\r\n" . '                    url: "./table",' . "\r\n" . '                    "data": function(d) {' . "\r\n" . '                        d.id = "bouquets_radios";' . "\r\n" . '                        d.category_id = $("#category_idr").val();' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0,3]}' . "\r\n" . '                ],' . "\r\n" . '            });' . "\r\n" . '            $("#datatable-review").DataTable({' . "\r\n" . '                language: {' . "\r\n" . '                    paginate: {' . "\r\n" . "                        previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n" . "                        next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n" . '                bInfo: false,' . "\r\n" . '                bAutoWidth: false,' . "\r\n" . '                searching: true,' . "\r\n" . '                pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                lengthChange: false,' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0,1,3]}' . "\r\n" . '                ],' . "\r\n" . '            });' . "\r\n" . "            \$('select').select2({width: '100%'});" . "\r\n" . '            $("#category_id").on("select2:select", function(e) { ' . "\r\n" . '                $("#datatable-stream").DataTable().ajax.reload(null, false);' . "\r\n" . "                \$(\"#datatable-stream\").DataTable().page(0).draw('page');" . "\r\n" . '            });' . "\r\n" . "            \$('#stream_search').keyup(function(){" . "\r\n" . "                \$('#datatable-stream').DataTable().search(\$(this).val()).draw();" . "\r\n" . '            })' . "\r\n" . '            $("#category_idv").on("select2:select", function(e) { ' . "\r\n" . '                $("#datatable-movies").DataTable().ajax.reload(null, false);' . "\r\n" . "                \$(\"#datatable-movies\").DataTable().page(0).draw('page');" . "\r\n" . '            });' . "\r\n" . "            \$('#vod_search').keyup(function(){" . "\r\n" . "                \$('#datatable-movies').DataTable().search(\$(this).val()).draw();" . "\r\n" . '            })' . "\r\n" . '            $("#category_ids").on("select2:select", function(e) { ' . "\r\n" . '                $("#datatable-series").DataTable().ajax.reload(null, false);' . "\r\n" . "                \$(\"#datatable-series\").DataTable().page(0).draw('page');" . "\r\n" . '            });' . "\r\n" . "            \$('#series_search').keyup(function(){" . "\r\n" . "                \$('#datatable-series').DataTable().search(\$(this).val()).draw();" . "\r\n" . '            });' . "\r\n" . '            $("#category_idr").on("select2:select", function(e) { ' . "\r\n" . '                $("#datatable-radios").DataTable().ajax.reload(null, false);' . "\r\n" . "                \$(\"#datatable-radios\").DataTable().page(0).draw('page');" . "\r\n" . '            });' . "\r\n" . "            \$('#radios_search').keyup(function(){" . "\r\n" . "                \$('#datatable-radios').DataTable().search(\$(this).val()).draw();" . "\r\n" . '            });' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . '                if (typeof(rBouquet) != "undefined") {' . "\r\n" . '                    $("#bouquet_data").val(JSON.stringify(rBouquet));' . "\r\n" . '                }' . "\r\n" . '                if ($("#bouquet_name").val().length == 0) {' . "\r\n" . '                    $.toast("';
		echo $_['enter_a_bouquet_name'];
		echo '");' . "\r\n" . '                } else {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        });' . "\r\n" . '        ' . "\r\n\t\t";
	?>
	    <?php if (CoreUtilities::$rSettings['enable_search']): ?>
        $(document).ready(function() {
            initSearch();
        });
    <?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>