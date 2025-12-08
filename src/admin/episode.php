<?php







include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

if (!empty(CoreUtilities::$rRequest['sid']) || empty(CoreUtilities::$rRequest['id'])) {
} else {
	$db->query('SELECT `series_id` FROM `streams_episodes` WHERE `stream_id` = ?;', intval(CoreUtilities::$rRequest['id']));

	if (0 >= $db->num_rows()) {
	} else {
		CoreUtilities::$rRequest['sid'] = intval($db->get_row()['series_id']);
	}
}

if ($rSeriesArr = getSerie(CoreUtilities::$rRequest['sid'])) {
} else {
	goHome();
}

if (!isset(CoreUtilities::$rRequest['id'])) {
} else {
	$rEpisode = getStream(CoreUtilities::$rRequest['id']);

	if ($rEpisode && $rEpisode['type'] == 5) {
	} else {
		goHome();
	}
}

$rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));

if (isset($rEpisode)) {
	$db->query('SELECT `season_num`, `episode_num` FROM `streams_episodes` WHERE `stream_id` = ?;', $rEpisode['id']);

	if (0 < $db->num_rows()) {
		$rRow = $db->get_row();
		$rEpisode['episode'] = intval($rRow['episode_num']);
		$rEpisode['season'] = intval($rRow['season_num']);
	} else {
		$rEpisode['episode'] = 0;
		$rEpisode['season'] = 0;
	}

	$rEpisode['properties'] = json_decode($rEpisode['movie_properties'], true);
	$rStreamSys = getStreamSys(CoreUtilities::$rRequest['id']);

	foreach ($rServers as $rServer) {
		if (isset($rStreamSys[intval($rServer['id'])])) {
			$rParent = 'source';
		} else {
			$rParent = 'offline';
		}

		$rServerTree[] = array('id' => $rServer['id'], 'parent' => $rParent, 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
	}
} else {
	if (hasPermissions('adv', 'add_episode')) {
		foreach ($rServers as $rServer) {
			$rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
		}

		if (isset(CoreUtilities::$rRequest['multi'])) {
			if (hasPermissions('adv', 'import_episodes')) {
				$rMulti = true;
			} else {
				exit();
			}
		}
	} else {
		exit();
	}
}

$_TITLE = 'Episode';
include 'header.php';
echo '<div class="wrapper boxed-layout"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">';

if (isset($rEpisode)) {
	echo $rEpisode['stream_display_name'];
} else {
	if (isset($rMulti) && $rMulti) {
		echo $language::get('add_multiple');
	} else {
		echo $language::get('add_single');
	}
}

echo '</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>     ' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-xl-12">' . "\n\t\t\t\t";

if (!isset($rEpisode)) {
} else {
	echo "\t\t\t\t";
	$rEncodeErrors = getEncodeErrors($rEpisode['id']);

	foreach ($rEncodeErrors as $rServerID => $rEncodeError) {
		echo "\t\t\t\t" . '<div class="alert alert-warning alert-dismissible fade show" role="alert">' . "\n\t\t\t\t\t" . '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n\t\t\t\t\t\t" . '<span aria-hidden="true">&times;</span>' . "\n\t\t\t\t\t" . '</button>' . "\n\t\t\t\t\t" . '<strong>';
		echo $language::get('error_on_server');
		echo ' - ';
		echo $rServers[$rServerID]['server_name'];
		echo '</strong><br/>' . "\n\t\t\t\t\t";
		echo str_replace("\n", '<br/>', $rEncodeError);
		echo "\t\t\t\t" . '</div>' . "\n\t\t\t\t";
	}
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t" . '<form action="#" method="POST" data-parsley-validate="">' . "\n\t\t\t\t\t\t\t";

if (!isset($rEpisode)) {
} else {
	echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="edit" value="';
	echo $rEpisode['id'];
	echo '" />' . "\n\t\t\t\t\t\t\t";
}

if (!isset($rMulti)) {
	echo "\t\t\t\t\t\t\t" . '<input type="hidden" id="tmdb_id" name="tmdb_id" value="';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rEpisode['properties']['tmdb_id']);
	}

	echo '" />' . "\n\t\t\t\t\t\t\t";
} else {
	echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="multi" id="multi" value="" />' . "\n\t\t\t\t\t\t\t" . '<input type="hidden" name="server" id="server" value="" />' . "\n\t\t\t\t\t\t\t" . '<input type="hidden" id="tmdb_id" name="tmdb_id" value="';
	echo htmlspecialchars($rSeriesArr['tmdb_id']);
	echo '" />' . "\n\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t" . '<input type="hidden" name="series" value="';
echo $rSeriesArr['id'];
echo '" />' . "\n\t\t\t\t\t\t\t" . '<input type="hidden" name="server_tree_data" id="server_tree_data" value="" />' . "\n" . '                            <input type="hidden" id="tmdb_language" value="';
echo $rSeriesArr['tmdb_language'];
echo '" />' . "\n\t\t\t\t\t\t\t" . '<div id="basicwizard">' . "\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-card-details-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
echo $language::get('details');
echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t";

if (isset($rMulti)) {
} else {
	echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#episode-information" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-movie-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
	echo $language::get('information');
	echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#advanced-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-folder-alert-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
echo $language::get('advanced');
echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-server-network mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
echo $language::get('servers');
echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\n\t\t\t\t\t\t\t\t\t";

if (!isset($rMulti)) {
	echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="stream-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="series_name">';
	echo $language::get('series_name');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="series_name" name="series_name" value="';
	echo $rSeriesArr['title'];
	echo '" readonly>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="season_num">';
	echo $language::get('season_number');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="season_num" name="season_num" placeholder="" value="';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rEpisode['season']);
	}

	echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="episode">';
	echo $language::get('episode_number');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="episode" name="episode" placeholder="" value="';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rEpisode['episode']);
	}

	echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";

	if (0 >= strlen(CoreUtilities::$rSettings['tmdb_api_key'])) {
	} else {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="tmdb_search">';
		echo $language::get('tmdb_results');
		echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="tmdb_search" class="form-control" data-toggle="select2"></select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
	}

	echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="stream_display_name">';
	echo $language::get('episode_name');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="stream_display_name" name="stream_display_name" value="';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rEpisode['stream_display_name']);
	}

	echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";

	if (isset($rEpisode)) {
		list($rEpisodeSource) = json_decode($rEpisode['stream_source'], true);
	} else {
		$rEpisodeSource = '';
	}

	echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4 stream-url">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="stream_source">';
	echo $language::get('episode_path');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="stream_source" name="stream_source" class="form-control" value="';
	echo $rEpisodeSource;
	echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="#file-browser" id="filebrowser" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="notes">';
	echo $language::get('notes');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<textarea id="notes" name="notes" class="form-control" rows="3" placeholder="">';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rEpisode['notes']);
	}

	echo '</textarea>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $language::get('next');
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t";
} else {
	echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="stream-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="series_name">';
	echo $language::get('series_name');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="series_name" name="series_name" value="';
	echo $rSeriesArr['title'];
	echo '" readonly>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="season_num" name="season_num" placeholder="Season" value="" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4 stream-url">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="season_folder">';
	echo $language::get('season_folder');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="season_folder" name="season_folder" readonly class="form-control" value="" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="#file-browser" id="filebrowser" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div id="episode_add"></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="custom-control custom-checkbox">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="custom-control-input" id="addName1" name="addName1" checked>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="custom-control-label" for="addName1">';
	echo $language::get('add_series_name');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="custom-control custom-checkbox">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="custom-control-input" id="addName2" name="addName2" checked>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="custom-control-label" for="addName2">';
	echo $language::get('add_episode_number');
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $language::get('next');
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="episode-information">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="movie_image">';
echo $language::get('image_url');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="movie_image" name="movie_image" value="';

if (!isset($rEpisode)) {
} else {
	echo htmlspecialchars($rEpisode['properties']['movie_image']);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript:void(0)" onClick="openImage(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="plot">';
echo $language::get('plot');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<textarea rows="6" class="form-control" id="plot" name="plot">';

if (!isset($rEpisode)) {
} else {
	echo htmlspecialchars($rEpisode['properties']['plot']);
}

echo '</textarea>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="release_date">';
echo $language::get('release_date');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="release_date" name="release_date" value="';

if (!isset($rEpisode)) {
} else {
	echo htmlspecialchars($rEpisode['properties']['release_date']);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-2 col-form-label" for="episode_run_time">';
echo $language::get('runtime');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="';

if (!isset($rEpisode)) {
} else {
	echo intval($rEpisode['properties']['duration_secs'] / 60);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="rating">';
echo $language::get('rating');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="rating" name="rating" value="';

if (!isset($rEpisode)) {
} else {
	echo htmlspecialchars($rEpisode['properties']['rating']);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $language::get('prev');
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $language::get('next');
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="advanced-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="direct_source">';
echo $language::get('direct_source');
echo ' <i title="';
echo $language::get('episode_tooltip_1');
echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="direct_source" id="direct_source" type="checkbox" ';

if (!isset($rEpisode)) {
} else {
	if ($rEpisode['direct_source'] != 1) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . "                                                    <label class=\"col-md-4 col-form-label\" for=\"direct_proxy\">Direct Stream <i title=\"When using direct source, hide the original URL by proxying the movie through your servers. This will consume bandwidth but won't require the movie to be saved to your servers permanently. Make sure to set the correct target container.\" class=\"tooltip text-secondary far fa-circle\"></i></label>" . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="direct_proxy" id="direct_proxy" type="checkbox" ';

if (!isset($rEpisode)) {
} else {
	if ($rEpisode['direct_proxy'] != 1) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="read_native">';
echo $language::get('native_frames');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="read_native" id="read_native" type="checkbox" ';

if (!isset($rEpisode)) {
} else {
	if ($rEpisode['read_native'] != 1) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="movie_symlink">';
echo $language::get('create_symlink');
echo ' <i title="';
echo $language::get('episode_tooltip_2');
echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="movie_symlink" id="movie_symlink" type="checkbox" ';

if (!isset($rEpisode)) {
} else {
	if ($rEpisode['movie_symlink'] != 1) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="remove_subtitles">';
echo $language::get('remove_existing_subtitles');
echo ' <i title="';
echo $language::get('episode_tooltip_3');
echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="remove_subtitles" id="remove_subtitles" type="checkbox" ';

if (!isset($rEpisode)) {
} else {
	if ($rEpisode['remove_subtitles'] != 1) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";

if (isset($rMulti)) {
} else {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="target_container">';
	echo $language::get('target_container');
	echo ' <i title="';
	echo $language::get('episode_tooltip_4');
	echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="target_container" id="target_container" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

	foreach (array('mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts') as $rContainer) {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option ';

		if (!isset($rEpisode)) {
		} else {
			if ($rEpisode['target_container'] != $rContainer) {
			} else {
				echo 'selected ';
			}
		}

		echo 'value="';
		echo $rContainer;
		echo '">';
		echo $rContainer;
		echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
	}
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="custom_sid">';
	echo $language::get('custom_channel_sid');
	echo ' <i title="';
	echo $language::get('episode_tooltip_5');
	echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="custom_sid" name="custom_sid" value="';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rEpisode['custom_sid']);
	}

	echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
}

$rSubFile = '';

if (!isset($rEpisode)) {
} else {
	$rSubData = json_decode($rEpisode['movie_subtitles'], true);

	if (!isset($rSubData['location'])) {
	} else {
		$rSubFile = 's:' . $rSubData['location'] . ':' . $rSubData['files'][0];
	}
}

echo "\t\t\t\t\t\t\t\t\t\t\t\t";

if (isset($rMulti)) {
} else {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="movie_subtitles">';
	echo $language::get('subtitle_location');
	echo ' <i title="';
	echo $language::get('episode_tooltip_6');
	echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="movie_subtitles" name="movie_subtitles" class="form-control" value="';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rSubFile);
	}

	echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="#file-browser" id="filebrowser-sub" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="transcode_profile_id">';
echo $language::get('transcoding_profile');
echo ' <i title="';
echo $language::get('episode_tooltip_7');
echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="transcode_profile_id" id="transcode_profile_id" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option ';

if (!isset($rEpisode)) {
} else {
	if (intval($rEpisode['transcode_profile_id']) != 0) {
	} else {
		echo 'selected ';
	}
}

echo 'value="0">';
echo $language::get('transcoding_disabled');
echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (getTranscodeProfiles() as $rProfile) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option ';

	if (!isset($rEpisode)) {
	} else {
		if (intval($rEpisode['transcode_profile_id']) != intval($rProfile['profile_id'])) {
		} else {
			echo 'selected ';
		}
	}

	echo 'value="';
	echo $rProfile['profile_id'];
	echo '">';
	echo $rProfile['profile_name'];
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $language::get('prev');
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $language::get('next');
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="load-balancing">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="servers">';
echo $language::get('server_tree');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div id="server_tree"></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="restart_on_edit">';

if (isset($rEpisode)) {
	echo $language::get('reprocess_on_edit');
} else {
	echo $language::get('process_now');
}

echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $language::get('prev');
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_episode" type="submit" class="btn btn-primary" value="';

if (isset($rEpisode)) {
	echo $language::get('edit');
} else {
	echo $language::get('add');
}

echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t" . '</form>' . "\n\t\t\t\t\t\t" . '<div id="file-browser" class="mfp-hide white-popup-block">' . "\n\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="server_id">';
echo $language::get('server_name');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<select id="server_id" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t";

foreach (getStreamingServers() as $rServer) {
	echo "\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rServer['id'];
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id'])) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo htmlspecialchars($rServer['server_name']);
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="current_path">';
echo $language::get('current_path');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="current_path" name="current_path" class="form-control" value="/">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button class="btn btn-primary waves-effect waves-light" type="button" id="changeDir"><i class="mdi mdi-chevron-right"></i></button>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4"';

if (isset($rMulti)) {
	echo "style='display:none;'";
}

echo '>' . "\n\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="search">';
echo $language::get('search_directory');
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="search" name="search" class="form-control" placeholder="';
echo $language::get('filter_directory');
echo '">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button class="btn btn-warning waves-effect waves-light" type="button" onClick="clearSearch()"><i class="mdi mdi-close"></i></button>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button class="btn btn-primary waves-effect waves-light" type="button" id="doSearch"><i class="mdi mdi-magnify"></i></button>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable" class="table">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th width="20px"></th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
echo $language::get('directory');
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-files" class="table">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th width="20px"></th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
echo $language::get('filename');
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t";

if (isset($rMulti)) {
	echo "\t\t\t\t\t\t\t\t" . '<div class="float-right">' . "\n\t\t\t\t\t\t\t\t\t" . '<input id="select_folder" type="button" class="btn btn-info" value="';
	echo $language::get('add_this_directory');
	echo '" />' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t";
}



echo "\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div> ' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
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

	var changeTitle = false;
	var rEpisodes = {};

	function pad(n) {
		if (n < 10)
			return "0" + n;
		return n;
	}

	function selectDirectory(elem) {
		window.currentDirectory += elem + "/";
		$("#current_path").val(window.currentDirectory);
		$("#changeDir").click();
	}

	function selectParent() {
		$("#current_path").val(window.currentDirectory.split("/").slice(0, -2).join("/") + "/");
		$("#changeDir").click();
	}

	function selectFile(rFile) {
		if ($('li.nav-item .active').attr('href') == "#stream-details") {
			$("#stream_source").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);
			var rExtension = rFile.substr((rFile.lastIndexOf('.') + 1));
			if ($("#target_container option[value='" + rExtension + "']").length > 0) {
				$("#target_container").val(rExtension).trigger('change');
			}
		} else {
			$("#movie_subtitles").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);
		}
		$.magnificPopup.close();
	}

	function openImage(elem) {
		rPath = $(elem).parent().parent().find("input").val();
		if (rPath) {
			$.magnificPopup.open({
				items: {
					src: 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(rPath),
					type: 'image'
				}
			});
		}
	}

	function clearSearch() {
		$("#search").val("");
		$("#doSearch").click();
	}
	$(document).ready(function() {
		$('select').select2({
			width: '100%'
		});
		$("#datatable").DataTable({
			responsive: false,
			paging: false,
			bInfo: false,
			searching: false,
			scrollY: "250px",
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			columnDefs: [{
				"className": "dt-center",
				"targets": [0]
			}, ],
			"language": {
				"emptyTable": ""
			}
		});
		$("#datatable-files").DataTable({
			responsive: false,
			paging: false,
			bInfo: false,
			searching: true,
			scrollY: "250px",
			drawCallback: function() {
				bindHref();
				refreshTooltips();
			},
			columnDefs: [{
				"className": "dt-center",
				"targets": [0]
			}, ],
			"language": {
				"emptyTable": "<?= $language::get('no_compatible_file') ?>"
			}
		});
		$("#doSearch").click(function() {
			$('#datatable-files').DataTable().search($("#search").val()).draw();
		})
		$("#direct_source").change(function() {
			evaluateDirectSource();
		});
		$("#direct_proxy").change(function() {
			evaluateDirectSource();
		});
		$("#movie_symlink").change(function() {
			evaluateSymlink();
		});
		$("#stream_source").change(function() {
			checkSymlink();
		});

		function evaluateDirectSource() {
			$(["movie_symlink", "read_native", "transcode_profile_id", "remove_subtitles", "movie_subtitles"]).each(function(rID, rElement) {
				if ($(rElement)) {
					if ($("#direct_source").is(":checked")) {
						if (window.rSwitches[rElement]) {
							setSwitch(window.rSwitches[rElement], false);
							window.rSwitches[rElement].disable();
						} else {
							$("#" + rElement).prop("disabled", true);
						}
					} else {
						if (window.rSwitches[rElement]) {
							window.rSwitches[rElement].enable();
						} else {
							$("#" + rElement).prop("disabled", false);
						}
					}
				}
			});
			$(["direct_proxy"]).each(function(rID, rElement) {
				if ($(rElement)) {
					if (!$("#direct_source").is(":checked")) {
						if (window.rSwitches[rElement]) {
							setSwitch(window.rSwitches[rElement], false);
							window.rSwitches[rElement].disable();
						} else {
							$("#" + rElement).prop("disabled", true);
						}
					} else {
						if (window.rSwitches[rElement]) {
							window.rSwitches[rElement].enable();
						} else {
							$("#" + rElement).prop("disabled", false);
						}
					}
				}
			});
		}

		function checkSymlink() {
			if (($("#movie_symlink").is(":checked")) && (!$("#stream_source").val().startsWith("s:")) && (!$("#stream_source").val().startsWith("/"))) {
				$.toast("Please ensure the source is a local file before symlinking.");
				setSwitch(window.rSwitches["movie_symlink"], false);
			}
		}

		function evaluateSymlink() {
			if ($("#direct_source").is(":checked")) {
				return;
			}
			checkSymlink();
			$(["direct_source", "read_native", "remove_subtitles", "target_container", "transcode_profile_id", "movie_subtitles"]).each(function(rID, rElement) {
				if ($(rElement)) {
					if ($("#movie_symlink").is(":checked")) {
						if (window.rSwitches[rElement]) {
							setSwitch(window.rSwitches[rElement], false);
							window.rSwitches[rElement].disable();
						} else {
							$("#" + rElement).prop("disabled", true);
						}
					} else {
						if (window.rSwitches[rElement]) {
							window.rSwitches[rElement].enable();
						} else {
							$("#" + rElement).prop("disabled", false);
						}
					}
				}
			});
		}
		$("#select_folder").click(function() {
			$("#season_folder").val(window.currentDirectory);
			$("#server").val($("#server_id").val());
			rID = 1;
			rNames = {};
			$("#episode_add").html("");
			$("#datatable-files").DataTable().rows().every(function(rowIdx, tableLoop, rowLoop) {
				var data = this.data();
				rExt = data[1].split('.').pop().toLowerCase();
				if (["mp4", "mkv", "mov", "avi", "mpg", "mpeg", "flv", "wmv", "m4v"].includes(rExt)) {
					$("#episode_add").append('<div class="form-group row mb-4"><label class="col-md-4 col-form-label" for="episode_' + rID + '_name"><?= $language::get('episode_to_add') ?></label><div class="col-md-6"><input type="text" class="form-control" id="episode_' + rID + '_name" name="episode_' + rID + '_name" value="' + data[1].replace("'", "\'") + '" readonly></div><div class="col-md-2"><input type="text" class="form-control text-center" id="episode_' + rID + '_num" name="episode_' + rID + '_num" placeholder="Episode" value=""></div></div>');
					$("#episode_" + rID + "_num").inputFilter(function(value) {
						return /^\d*$/.test(value);
					});
					rNames[rID] = data[1];
				}
				rID++;
			});
			$.post("./api?action=get_episode_ids",JSON.stringify({data: rNames}),
				function(data) {
					$(data.data).each(function(id, item) {
						$("#episode_" + item[0] + "_num").val(item[1]);
					});

					var nextEpisode = 1;
					$("[id^=episode_][id$=_num]").each(function() {
						if (!$(this).val()) {
							$(this).val(nextEpisode);
						}
						nextEpisode++;
					});
				},
				"json"
			);






			$.magnificPopup.close();
		});
		$("#changeDir").click(function() {
			$("#search").val("");
			window.currentDirectory = $("#current_path").val();
			if (window.currentDirectory.substr(-1) != "/") {
				window.currentDirectory += "/";
			}
			$("#current_path").val(window.currentDirectory);
			$("#datatable").DataTable().clear();
			$("#datatable").DataTable().row.add(["", "<?= $language::get('loading') ?>..."]);
			$("#datatable").DataTable().draw(true);
			$("#datatable-files").DataTable().clear();
			$("#datatable-files").DataTable().row.add(["", "<?= $language::get('please_wait') ?>..."]);
			$("#datatable-files").DataTable().draw(true);
			if ($('li.nav-item .active').attr('href') == "#stream-details") {
				rFilter = "video";
			} else {
				rFilter = "subs";
			}
			$.getJSON("./api?action=listdir&dir=" + window.currentDirectory + "&server=" + $("#server_id").val() + "&filter=" + rFilter, function(data) {
				$("#datatable").DataTable().clear();
				$("#datatable-files").DataTable().clear();
				if (window.currentDirectory != "/") {
					$("#datatable").DataTable().row.add(["<i class='mdi mdi-subdirectory-arrow-left'></i>", "<?= $language::get('parent_directory') ?>"]);
				}
				if (data.result == true) {
					$(data.data.dirs).each(function(id, dir) {
						$("#datatable").DataTable().row.add(["<i class='mdi mdi-folder-open-outline'></i>", dir]);
					});
					$("#datatable").DataTable().draw(true);
					$(data.data.files).each(function(id, dir) {
						$("#datatable-files").DataTable().row.add(["<i class='mdi mdi-file-video'></i>", dir]);
					});
					$("#datatable-files").DataTable().draw(true);
				}
			});
		});
		$('#datatable').on('click', 'tbody > tr', function() {
			if ($(this).find("td").eq(1).html() == "<?= $language::get('parent_directory') ?>") {
				selectParent();
			} else if ($(this).find("td").eq(1).html() != "<?= $language::get('loading') ?>...") {
				selectDirectory($(this).find("td").eq(1).html());
			}
		});
		<?php if (!isset($rMulti)): ?>
			$('#datatable-files').on('click', 'tbody > tr', function() {
				selectFile($(this).find("td").eq(1).html());
			});
		<?php endif; ?>
		$('#server_tree').on('select_node.jstree', function(e, data) {
			if (data.node.parent == "offline") {
				$('#server_tree').jstree("move_node", data.node.id, "#source", "last");
			} else {
				$('#server_tree').jstree("move_node", data.node.id, "#offline", "first");
			}
		}).jstree({
			'core': {
				'check_callback': function(op, node, parent, position, more) {
					switch (op) {
						case 'move_node':
							if ((node.id == "offline") || (node.id == "source")) {
								return false;
							}
							if (parent.id != "offline" && parent.id != "source") {
								return false;
							}
							if (parent.id == "#") {
								return false;
							}
							if (parent.id > 0 && $("#direct_proxy").is(":checked")) {
								return false;
							}
							return true;
					}
				},
				'data': <?= json_encode(($rServerTree ?: array())) ?>
			},
			"plugins": ["dnd"]
		});
		$("#filebrowser").magnificPopup({
			type: 'inline',
			preloader: false,
			focus: '#server_id',
			callbacks: {
				beforeOpen: function() {
					if ($(window).width() < 830) {
						this.st.focus = false;
					} else {
						this.st.focus = '#server_id';
					}
				}
			}
		});
		$("#filebrowser-sub").magnificPopup({
			type: 'inline',
			preloader: false,
			focus: '#server_id',
			callbacks: {
				beforeOpen: function() {
					if ($(window).width() < 830) {
						this.st.focus = false;
					} else {
						this.st.focus = '#server_id';
					}
				}
			}
		});
		$("#filebrowser").on("mfpOpen", function() {
			clearSearch();
			$("#changeDir").click();
			$($.fn.dataTable.tables(true)).css('width', '100%');
			$($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();
		});
		$("#filebrowser-sub").on("mfpOpen", function() {
			clearSearch();
			$("#changeDir").click();
			$($.fn.dataTable.tables(true)).css('width', '100%');
			$($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();
		});
		$("#server_id").change(function() {
			$("#current_path").val("/");
			$("#changeDir").click();
		});
		<?php if (!isset($rMulti)): ?>
			$("#season_num").change(function() {
				if (!window.changeTitle) {
					$("#tmdb_search").empty().trigger('change');
					if ($("#season_num").val()) {
						window.rEpisodes = {};
						$.getJSON("./api?action=tmdb_search&type=episode&term=<?= $rSeriesArr['tmdb_id'] ?>&season=" + $("#season_num").val() + "&language=" + encodeURIComponent($("#tmdb_language").val()), function(data) {
							if (data.result == true) {
								if ((data.data.episodes) && (data.data.episodes.length > 0)) {
									newOption = new Option("<?= $language::get('found_episodes') ?>".replace("{num}", data.data.episodes.length), -1, true, true);
								} else {
									newOption = new Option("<?= $language::get('no_episodes_found') ?>", -1, true, true);
								}
								$("#tmdb_search").append(newOption).trigger('change');
								if ($(data.data.episodes)) {
									$(data.data.episodes).each(function(id, item) {
										window.rEpisodes[item.id] = item;
										rTitle = "<?= $language::get('episode') ?> " + item.episode_number + " - " + item.name;
										newOption = new Option(rTitle, item.id, true, true);
										$("#tmdb_search").append(newOption);
									});
								}
							} else {
								newOption = new Option("<?= $language::get('no_results_found') ?>", -1, true, true);
							}
							$("#tmdb_search").val(-1).trigger('change');
						});
					}
				} else {
					window.changeTitle = false;
				}
			});
			$("#tmdb_search").change(function() {
				if (($("#tmdb_search").val()) && ($("#tmdb_search").val() > -1)) {
					var rEpisode = window.rEpisodes[$("#tmdb_search").val()];
					var rFormat = "S" + pad(rEpisode.season_number) + "E" + pad(rEpisode.episode_number);
					$("#stream_display_name").val($("#series_name").val() + " - " + rFormat + " - " + rEpisode.name);
					$("#movie_image").val("");
					if (rEpisode.still_path) {
						$("#movie_image").val("https://image.tmdb.org/t/p/w1280" + rEpisode.still_path);
					}
					$("#release_date").val(rEpisode.air_date);
					$("#episode_run_time").val('<?= $rSeriesArr['episode_run_time'] ?>');
					$("#plot").val(rEpisode.overview);
					$("#rating").val(rEpisode.vote_average);
					$("#tmdb_id").val(rEpisode.id);
					$("#episode").val(rEpisode.episode_number);
				}
			});
		<?php endif; ?>
		<?php if (isset($rEpisode)): ?>
			$("#season_num").trigger('change');
		<?php endif; ?>
		$("#runtime").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#season_num").inputFilter(function(value) {
			return /^\d*$/.test(value);
		});
		$("#changeDir").click();
		evaluateDirectSource();
		evaluateSymlink();
		$("form").submit(function(e) {
			e.preventDefault();
			rSubmit = true;
			<?php if (!isset($rMulti)): ?>
				if (!$("#stream_display_name").val()) {
					$.toast("<?= $language::get('enter_an_episode_name') ?>");
					rSubmit = false;
				}
				if ($("#stream_source").val().length == 0) {
					$.toast("<?= $language::get('enter_an_episode_source') ?>");
					rSubmit = false;
				}
			<?php endif; ?>
			$("#server_tree_data").val(JSON.stringify($('#server_tree').jstree(true).get_json('source', {
				flat: true
			})));
			if (rSubmit) {
				$(':input[type="submit"]').prop('disabled', true);
				submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);
			}
		});
	});



	<?php if (CoreUtilities::$rSettings['enable_search']): ?>
		$(document).ready(function() {
			initSearch();
		});
	<?php endif; ?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>