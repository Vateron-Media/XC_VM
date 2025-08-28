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

		if (!isset(CoreUtilities::$rRequest['multi'])) {
		} else {
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
	if ($rMulti) {
		echo $_['add_multiple'];
	} else {
		echo $_['add_single'];
	}
}

echo '</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>     ' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-xl-12">' . "\n\t\t\t\t";

if (!isset($rEpisode)) {
} else {
	echo "\t\t\t\t";
	$rEncodeErrors = getEncodeErrors($rEpisode['id']);

	foreach ($rEncodeErrors as $rServerID => $rEncodeError) {
		echo "\t\t\t\t" . '<div class="alert alert-warning alert-dismissible fade show" role="alert">' . "\n\t\t\t\t\t" . '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n\t\t\t\t\t\t" . '<span aria-hidden="true">&times;</span>' . "\n\t\t\t\t\t" . '</button>' . "\n\t\t\t\t\t" . '<strong>';
		echo $_['error_on_server'];
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
echo $_['details'];
echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t";

if (isset($rMulti)) {
} else {
	echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#episode-information" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-movie-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
	echo $_['information'];
	echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#advanced-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-folder-alert-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
echo $_['advanced'];
echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-server-network mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">';
echo $_['servers'];
echo '</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\n\t\t\t\t\t\t\t\t\t";

if (!isset($rMulti)) {
	echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="stream-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="series_name">';
	echo $_['series_name'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="series_name" name="series_name" value="';
	echo $rSeriesArr['title'];
	echo '" readonly>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="season_num">';
	echo $_['season_number'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="season_num" name="season_num" placeholder="" value="';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rEpisode['season']);
	}

	echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="episode">';
	echo $_['episode_number'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="episode" name="episode" placeholder="" value="';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rEpisode['episode']);
	}

	echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";

	if (0 >= strlen(CoreUtilities::$rSettings['tmdb_api_key'])) {
	} else {
		echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="tmdb_search">';
		echo $_['tmdb_results'];
		echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="tmdb_search" class="form-control" data-toggle="select2"></select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
	}

	echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="stream_display_name">';
	echo $_['episode_name'];
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
	echo $_['episode_path'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="stream_source" name="stream_source" class="form-control" value="';
	echo $rEpisodeSource;
	echo '" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="#file-browser" id="filebrowser" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="notes">';
	echo $_['notes'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<textarea id="notes" name="notes" class="form-control" rows="3" placeholder="">';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rEpisode['notes']);
	}

	echo '</textarea>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['next'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t";
} else {
	echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="stream-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="series_name">';
	echo $_['series_name'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="series_name" name="series_name" value="';
	echo $rSeriesArr['title'];
	echo '" readonly>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="season_num" name="season_num" placeholder="Season" value="" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4 stream-url">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="season_folder">';
	echo $_['season_folder'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="season_folder" name="season_folder" readonly class="form-control" value="" required data-parsley-trigger="change">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="#file-browser" id="filebrowser" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div id="episode_add"></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="custom-control custom-checkbox">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="custom-control-input" id="addName1" name="addName1" checked>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="custom-control-label" for="addName1">';
	echo $_['add_series_name'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="custom-control custom-checkbox">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="custom-control-input" id="addName2" name="addName2" checked>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="custom-control-label" for="addName2">';
	echo $_['add_episode_number'];
	echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
	echo $_['next'];
	echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="episode-information">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="movie_image">';
echo $_['image_url'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="movie_image" name="movie_image" value="';

if (!isset($rEpisode)) {
} else {
	echo htmlspecialchars($rEpisode['properties']['movie_image']);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript:void(0)" onClick="openImage(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="plot">';
echo $_['plot'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<textarea rows="6" class="form-control" id="plot" name="plot">';

if (!isset($rEpisode)) {
} else {
	echo htmlspecialchars($rEpisode['properties']['plot']);
}

echo '</textarea>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="release_date">';
echo $_['release_date'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="release_date" name="release_date" value="';

if (!isset($rEpisode)) {
} else {
	echo htmlspecialchars($rEpisode['properties']['release_date']);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-2 col-form-label" for="episode_run_time">';
echo $_['runtime'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="';

if (!isset($rEpisode)) {
} else {
	echo intval($rEpisode['properties']['duration_secs'] / 60);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="rating">';
echo $_['rating'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control text-center" id="rating" name="rating" value="';

if (!isset($rEpisode)) {
} else {
	echo htmlspecialchars($rEpisode['properties']['rating']);
}

echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $_['prev'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $_['next'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="advanced-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="direct_source">';
echo $_['direct_source'];
echo ' <i title="';
echo $_['episode_tooltip_1'];
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
echo $_['native_frames'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="read_native" id="read_native" type="checkbox" ';

if (!isset($rEpisode)) {
} else {
	if ($rEpisode['read_native'] != 1) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="movie_symlink">';
echo $_['create_symlink'];
echo ' <i title="';
echo $_['episode_tooltip_2'];
echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="movie_symlink" id="movie_symlink" type="checkbox" ';

if (!isset($rEpisode)) {
} else {
	if ($rEpisode['movie_symlink'] != 1) {
	} else {
		echo 'checked ';
	}
}

echo 'data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <label class="col-md-4 col-form-label" for="remove_subtitles">';
echo $_['remove_existing_subtitles'];
echo ' <i title="';
echo $_['episode_tooltip_3'];
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
	echo $_['target_container'];
	echo ' <i title="';
	echo $_['episode_tooltip_4'];
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
	echo $_['custom_channel_sid'];
	echo ' <i title="';
	echo $_['episode_tooltip_5'];
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
	echo $_['subtitle_location'];
	echo ' <i title="';
	echo $_['episode_tooltip_6'];
	echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="movie_subtitles" name="movie_subtitles" class="form-control" value="';

	if (!isset($rEpisode)) {
	} else {
		echo htmlspecialchars($rSubFile);
	}

	echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="#file-browser" id="filebrowser-sub" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-folder-open-outline"></i></a>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="transcode_profile_id">';
echo $_['transcoding_profile'];
echo ' <i title="';
echo $_['episode_tooltip_7'];
echo '" class="tooltip text-secondary far fa-circle"></i></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="transcode_profile_id" id="transcode_profile_id" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option ';

if (!isset($rEpisode)) {
} else {
	if (intval($rEpisode['transcode_profile_id']) != 0) {
	} else {
		echo 'selected ';
	}
}

echo 'value="0">';
echo $_['transcoding_disabled'];
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
echo $_['prev'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $_['next'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="load-balancing">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="servers">';
echo $_['server_tree'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div id="server_tree"></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="restart_on_edit">';

if (isset($rEpisode)) {
	echo $_['reprocess_on_edit'];
} else {
	echo $_['process_now'];
}

echo '</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">';
echo $_['prev'];
echo '</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_episode" type="submit" class="btn btn-primary" value="';

if (isset($rEpisode)) {
	echo $_['edit'];
} else {
	echo $_['add'];
}

echo '" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t" . '</form>' . "\n\t\t\t\t\t\t" . '<div id="file-browser" class="mfp-hide white-popup-block">' . "\n\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="server_id">';
echo $_['server_name'];
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
echo $_['current_path'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="current_path" name="current_path" class="form-control" value="/">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button class="btn btn-primary waves-effect waves-light" type="button" id="changeDir"><i class="mdi mdi-chevron-right"></i></button>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4"';

if (!isset($rMulti)) {
} else {
	echo "style='display:none;'";
}

echo '>' . "\n\t\t\t\t\t\t\t\t\t" . '<label class="col-md-4 col-form-label" for="search">';
echo $_['search_directory'];
echo '</label>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8 input-group">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<input type="text" id="search" name="search" class="form-control" placeholder="';
echo $_['filter_directory'];
echo '">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button class="btn btn-warning waves-effect waves-light" type="button" onClick="clearSearch()"><i class="mdi mdi-close"></i></button>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<button class="btn btn-primary waves-effect waves-light" type="button" id="doSearch"><i class="mdi mdi-magnify"></i></button>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable" class="table">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th width="20px"></th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
echo $_['directory'];
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-files" class="table">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th width="20px"></th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>';
echo $_['filename'];
echo '</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t";

if (!isset($rMulti)) {
} else {
	echo "\t\t\t\t\t\t\t\t" . '<div class="float-right">' . "\n\t\t\t\t\t\t\t\t\t" . '<input id="select_folder" type="button" class="btn btn-info" value="';
	echo $_['add_this_directory'];
	echo '" />' . "\n\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t";
}



echo "\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div> ' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'var changeTitle = false;' . "\r\n" . '        var rEpisodes = {};' . "\r\n" . '        ' . "\r\n" . '        function pad(n) {' . "\r\n" . '            if (n < 10)' . "\r\n" . '                return "0" + n;' . "\r\n" . '            return n;' . "\r\n" . '        }' . "\r\n" . '        function selectDirectory(elem) {' . "\r\n" . '            window.currentDirectory += elem + "/";' . "\r\n" . '            $("#current_path").val(window.currentDirectory);' . "\r\n" . '            $("#changeDir").click();' . "\r\n" . '        }' . "\r\n" . '        function selectParent() {' . "\r\n" . '            $("#current_path").val(window.currentDirectory.split("/").slice(0,-2).join("/") + "/");' . "\r\n" . '            $("#changeDir").click();' . "\r\n" . '        }' . "\r\n" . '        function selectFile(rFile) {' . "\r\n" . "            if (\$('li.nav-item .active').attr('href') == \"#stream-details\") {" . "\r\n" . '                $("#stream_source").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);' . "\r\n" . "                var rExtension = rFile.substr((rFile.lastIndexOf('.')+1));" . "\r\n" . "                if (\$(\"#target_container option[value='\" + rExtension + \"']\").length > 0) {" . "\r\n" . "                    \$(\"#target_container\").val(rExtension).trigger('change');" . "\r\n" . '                }' . "\r\n" . '            } else {' . "\r\n" . '                $("#movie_subtitles").val("s:" + $("#server_id").val() + ":" + window.currentDirectory + rFile);' . "\r\n" . '            }' . "\r\n" . '            $.magnificPopup.close();' . "\r\n" . '        }' . "\r\n" . '        function openImage(elem) {' . "\r\n" . '            rPath = $(elem).parent().parent().find("input").val();' . "\r\n\t\t\t" . 'if (rPath) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . "                        src: 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(rPath)," . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n\t\t\t" . '}' . "\r\n" . '        }' . "\r\n" . '        function clearSearch() {' . "\r\n" . '            $("#search").val("");' . "\r\n" . '            $("#doSearch").click();' . "\r\n" . '        }' . "\r\n" . '        $(document).ready(function() {' . "\r\n" . "            \$('select').select2({width: '100%'});" . "\r\n" . '            $("#datatable").DataTable({' . "\r\n" . '                responsive: false,' . "\r\n" . '                paging: false,' . "\r\n" . '                bInfo: false,' . "\r\n" . '                searching: false,' . "\r\n" . '                scrollY: "250px",' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0]},' . "\r\n" . '                ],' . "\r\n" . '                "language": {' . "\r\n" . '                    "emptyTable": ""' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#datatable-files").DataTable({' . "\r\n" . '                responsive: false,' . "\r\n" . '                paging: false,' . "\r\n" . '                bInfo: false,' . "\r\n" . '                searching: true,' . "\r\n" . '                scrollY: "250px",' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                },' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0]},' . "\r\n" . '                ],' . "\r\n" . '                "language": {' . "\r\n" . '                    "emptyTable": "';
		echo $_['no_compatible_file'];
		echo '"' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#doSearch").click(function() {' . "\r\n" . "                \$('#datatable-files').DataTable().search(\$(\"#search\").val()).draw();" . "\r\n" . '            })' . "\r\n" . '            $("#direct_source").change(function() {' . "\r\n" . '                evaluateDirectSource();' . "\r\n" . '            });' . "\r\n" . '            $("#direct_proxy").change(function() {' . "\r\n" . '                evaluateDirectSource();' . "\r\n" . '            });' . "\r\n" . '            $("#movie_symlink").change(function() {' . "\r\n" . '                evaluateSymlink();' . "\r\n" . '            });' . "\r\n" . '            $("#stream_source").change(function() {' . "\r\n\t\t\t\t" . 'checkSymlink();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'function evaluateDirectSource() {' . "\r\n\t\t\t\t" . '$(["movie_symlink", "read_native", "transcode_profile_id", "remove_subtitles", "movie_subtitles"]).each(function(rID, rElement) {' . "\r\n\t\t\t\t\t" . 'if ($(rElement)) {' . "\r\n\t\t\t\t\t\t" . 'if ($("#direct_source").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'setSwitch(window.rSwitches[rElement], false);' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].disable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].enable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n" . '                $(["direct_proxy"]).each(function(rID, rElement) {' . "\r\n\t\t\t\t\t" . 'if ($(rElement)) {' . "\r\n\t\t\t\t\t\t" . 'if (!$("#direct_source").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'setSwitch(window.rSwitches[rElement], false);' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].disable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].enable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '}' . "\r\n" . '            function checkSymlink() {' . "\r\n" . '                if (($("#movie_symlink").is(":checked")) && (!$("#stream_source").val().startsWith("s:")) && (!$("#stream_source").val().startsWith("/"))) {' . "\r\n" . '                    $.toast("Please ensure the source is a local file before symlinking.");' . "\r\n" . '                    setSwitch(window.rSwitches["movie_symlink"], false);' . "\r\n" . '                }' . "\r\n" . '            }' . "\r\n\t\t\t" . 'function evaluateSymlink() {' . "\r\n" . '                if ($("#direct_source").is(":checked")) { return; }' . "\r\n" . '                checkSymlink();' . "\r\n" . '                $(["direct_source", "read_native", "remove_subtitles", "target_container", "transcode_profile_id", "movie_subtitles"]).each(function(rID, rElement) {' . "\r\n" . '                    if ($(rElement)) {' . "\r\n" . '                        if ($("#movie_symlink").is(":checked")) {' . "\r\n" . '                            if (window.rSwitches[rElement]) {' . "\r\n" . '                                setSwitch(window.rSwitches[rElement], false);' . "\r\n" . '                                window.rSwitches[rElement].disable();' . "\r\n" . '                            } else {' . "\r\n" . '                                $("#" + rElement).prop("disabled", true);' . "\r\n" . '                            }' . "\r\n" . '                        } else {' . "\r\n" . '                            if (window.rSwitches[rElement]) {' . "\r\n" . '                                window.rSwitches[rElement].enable();' . "\r\n" . '                            } else {' . "\r\n" . '                                $("#" + rElement).prop("disabled", false);' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '            $("#select_folder").click(function() {' . "\r\n" . '                $("#season_folder").val(window.currentDirectory);' . "\r\n" . '                $("#server").val($("#server_id").val());' . "\r\n" . '                rID = 1;' . "\r\n" . '                rNames = {};' . "\r\n" . '                $("#episode_add").html("");' . "\r\n" . '                $("#datatable-files").DataTable().rows().every(function (rowIdx, tableLoop, rowLoop) {' . "\r\n" . '                    var data = this.data();' . "\r\n" . "                    rExt = data[1].split('.').pop().toLowerCase();" . "\r\n" . '                    if (["mp4", "mkv", "mov", "avi", "mpg", "mpeg", "flv", "wmv", "m4v"].includes(rExt)) {' . "\r\n" . "                        \$(\"#episode_add\").append('<div class=\"form-group row mb-4\"><label class=\"col-md-4 col-form-label\" for=\"episode_' + rID + '_name\">";
		echo $_['episode_to_add'];
		echo "</label><div class=\"col-md-6\"><input type=\"text\" class=\"form-control\" id=\"episode_' + rID + '_name\" name=\"episode_' + rID + '_name\" value=\"' + data[1].replace(\"'\", \"\\'\") + '\" readonly></div><div class=\"col-md-2\"><input type=\"text\" class=\"form-control text-center\" id=\"episode_' + rID + '_num\" name=\"episode_' + rID + '_num\" placeholder=\"Episode\" value=\"\"></div></div>');" . "\r\n" . '                        $("#episode_" + rID + "_num").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '                        rNames[rID] = data[1];' . "\r\n" . '                    }' . "\r\n" . '                    rID ++;' . "\r\n" . '                });' . "\r\n" . '                $.getJSON("./api?action=get_episode_ids&data=" + JSON.stringify(rNames), function(data) {' . "\r\n" . '                    $(data.data).each(function(id, item) {' . "\r\n" . '                        $("#episode_" + item[0] + "_num").val(item[1]);' . "\r\n" . '                    });' . "\r\n" . '                });' . "\r\n" . '                $.magnificPopup.close();' . "\r\n" . '            });' . "\r\n" . '            $("#changeDir").click(function() {' . "\r\n" . '                $("#search").val("");' . "\r\n" . '                window.currentDirectory = $("#current_path").val();' . "\r\n" . '                if (window.currentDirectory.substr(-1) != "/") {' . "\r\n" . '                    window.currentDirectory += "/";' . "\r\n" . '                }' . "\r\n" . '                $("#current_path").val(window.currentDirectory);' . "\r\n" . '                $("#datatable").DataTable().clear();' . "\r\n" . '                $("#datatable").DataTable().row.add(["", "';
		echo $_['loading'];
		echo '..."]);' . "\r\n" . '                $("#datatable").DataTable().draw(true);' . "\r\n" . '                $("#datatable-files").DataTable().clear();' . "\r\n" . '                $("#datatable-files").DataTable().row.add(["", "';
		echo $_['please_wait'];
		echo '..."]);' . "\r\n" . '                $("#datatable-files").DataTable().draw(true);' . "\r\n" . "                if (\$('li.nav-item .active').attr('href') == \"#stream-details\") {" . "\r\n" . '                    rFilter = "video";' . "\r\n" . '                } else {' . "\r\n" . '                    rFilter = "subs";' . "\r\n" . '                }' . "\r\n" . '                $.getJSON("./api?action=listdir&dir=" + window.currentDirectory + "&server=" + $("#server_id").val() + "&filter=" + rFilter, function(data) {' . "\r\n" . '                    $("#datatable").DataTable().clear();' . "\r\n" . '                    $("#datatable-files").DataTable().clear();' . "\r\n" . '                    if (window.currentDirectory != "/") {' . "\r\n" . "                        \$(\"#datatable\").DataTable().row.add([\"<i class='mdi mdi-subdirectory-arrow-left'></i>\", \"";
		echo $_['parent_directory'];
		echo '"]);' . "\r\n" . '                    }' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        $(data.data.dirs).each(function(id, dir) {' . "\r\n" . "                            \$(\"#datatable\").DataTable().row.add([\"<i class='mdi mdi-folder-open-outline'></i>\", dir]);" . "\r\n" . '                        });' . "\r\n" . '                        $("#datatable").DataTable().draw(true);' . "\r\n" . '                        $(data.data.files).each(function(id, dir) {' . "\r\n" . "                            \$(\"#datatable-files\").DataTable().row.add([\"<i class='mdi mdi-file-video'></i>\", dir]);" . "\r\n" . '                        });' . "\r\n" . '                        $("#datatable-files").DataTable().draw(true);' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            });' . "\r\n" . "            \$('#datatable').on('click', 'tbody > tr', function() {" . "\r\n" . '                if ($(this).find("td").eq(1).html() == "';
		echo $_['parent_directory'];
		echo '") {' . "\r\n" . '                    selectParent();' . "\r\n" . '                } else if ($(this).find("td").eq(1).html() != "';
		echo $_['loading'];
		echo '...") {' . "\r\n" . '                    selectDirectory($(this).find("td").eq(1).html());' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            ';

		if (isset($rMulti)) {
		} else {
			echo "            \$('#datatable-files').on('click', 'tbody > tr', function() {" . "\r\n" . '                selectFile($(this).find("td").eq(1).html());' . "\r\n" . '            });' . "\r\n" . '            ';
		}

		echo "            \$('#server_tree').on('select_node.jstree', function (e, data) {" . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n" . "                'check_callback': function (op, node, parent, position, more) {" . "\r\n" . '                    switch (op) {' . "\r\n" . "                        case 'move_node':" . "\r\n" . '                            if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id != "offline" && parent.id != "source") { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n" . '                            if (parent.id > 0 && $("#direct_proxy").is(":checked")) { return false; }' . "\r\n" . '                            return true;' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . "                'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo '            }, "plugins" : [ "dnd" ]' . "\r\n" . '            });' . "\r\n" . '            $("#filebrowser").magnificPopup({' . "\r\n" . "                type: 'inline'," . "\r\n" . '                preloader: false,' . "\r\n" . "                focus: '#server_id'," . "\r\n" . '                callbacks: {' . "\r\n" . '                    beforeOpen: function() {' . "\r\n" . '                        if ($(window).width() < 830) {' . "\r\n" . '                            this.st.focus = false;' . "\r\n" . '                        } else {' . "\r\n" . "                            this.st.focus = '#server_id';" . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#filebrowser-sub").magnificPopup({' . "\r\n" . "                type: 'inline'," . "\r\n" . '                preloader: false,' . "\r\n" . "                focus: '#server_id'," . "\r\n" . '                callbacks: {' . "\r\n" . '                    beforeOpen: function() {' . "\r\n" . '                        if ($(window).width() < 830) {' . "\r\n" . '                            this.st.focus = false;' . "\r\n" . '                        } else {' . "\r\n" . "                            this.st.focus = '#server_id';" . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#filebrowser").on("mfpOpen", function() {' . "\r\n" . '                clearSearch();' . "\r\n" . '                $("#changeDir").click();' . "\r\n" . "                \$(\$.fn.dataTable.tables(true)).css('width', '100%');" . "\r\n" . '                $($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();' . "\r\n" . '            });' . "\r\n" . '            $("#filebrowser-sub").on("mfpOpen", function() {' . "\r\n" . '                clearSearch();' . "\r\n" . '                $("#changeDir").click();' . "\r\n" . "                \$(\$.fn.dataTable.tables(true)).css('width', '100%');" . "\r\n" . '                $($.fn.dataTable.tables(true)).DataTable().columns.adjust().draw();' . "\r\n" . '            });' . "\r\n" . '            $("#server_id").change(function() {' . "\r\n" . '                $("#current_path").val("/");' . "\r\n" . '                $("#changeDir").click();' . "\r\n" . '            });' . "\r\n" . '            ';

		if (isset($rMulti)) {
		} else {
			echo '            $("#season_num").change(function() {' . "\r\n" . '                if (!window.changeTitle) {' . "\r\n" . "                    \$(\"#tmdb_search\").empty().trigger('change');" . "\r\n" . '                    if ($("#season_num").val()) {' . "\r\n" . '                        window.rEpisodes = {};' . "\r\n" . '                        $.getJSON("./api?action=tmdb_search&type=episode&term=';
			echo $rSeriesArr['tmdb_id'];
			echo '&season=" + $("#season_num").val() + "&language=" + encodeURIComponent($("#tmdb_language").val()), function(data) {' . "\r\n" . '                            if (data.result == true) {' . "\r\n" . '                                if ((data.data.episodes) && (data.data.episodes.length > 0)) {' . "\r\n" . '                                    newOption = new Option("';
			echo $_['found_episodes'];
			echo '".replace("{num}", data.data.episodes.length), -1, true, true);' . "\r\n" . '                                } else {' . "\r\n" . '                                    newOption = new Option("';
			echo $_['no_episodes_found'];
			echo '", -1, true, true);' . "\r\n" . '                                }' . "\r\n" . "                                \$(\"#tmdb_search\").append(newOption).trigger('change');" . "\r\n" . '                                if ($(data.data.episodes)) {' . "\r\n" . '                                    $(data.data.episodes).each(function(id, item) {' . "\r\n" . '                                        window.rEpisodes[item.id] = item;' . "\r\n" . '                                        rTitle = "';
			echo $_['episode'];
			echo ' " + item.episode_number + " - " + item.name;' . "\r\n" . '                                        newOption = new Option(rTitle, item.id, true, true);' . "\r\n" . '                                        $("#tmdb_search").append(newOption);' . "\r\n" . '                                    });' . "\r\n" . '                                }' . "\r\n" . '                            } else {' . "\r\n" . '                                newOption = new Option("';
			echo $_['no_results_found'];
			echo '", -1, true, true);' . "\r\n" . '                            }' . "\r\n" . "                            \$(\"#tmdb_search\").val(-1).trigger('change');" . "\r\n" . '                        });' . "\r\n" . '                    }' . "\r\n" . '                } else {' . "\r\n" . '                    window.changeTitle = false;' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#tmdb_search").change(function() {' . "\r\n" . '                if (($("#tmdb_search").val()) && ($("#tmdb_search").val() > -1)) {' . "\r\n" . '                    var rEpisode = window.rEpisodes[$("#tmdb_search").val()];' . "\r\n" . '                    var rFormat = "S" + pad(rEpisode.season_number) + "E" + pad(rEpisode.episode_number);' . "\r\n" . '                    $("#stream_display_name").val($("#series_name").val() + " - " + rFormat + " - " + rEpisode.name);' . "\r\n" . '                    $("#movie_image").val("");' . "\r\n" . '                    if (rEpisode.still_path) {' . "\r\n" . '                        $("#movie_image").val("https://image.tmdb.org/t/p/w1280" + rEpisode.still_path);' . "\r\n" . '                    }' . "\r\n" . '                    $("#release_date").val(rEpisode.air_date);' . "\r\n" . "                    \$(\"#episode_run_time\").val('";
			echo $rSeriesArr['episode_run_time'];
			echo "');" . "\r\n" . '                    $("#plot").val(rEpisode.overview);' . "\r\n" . '                    $("#rating").val(rEpisode.vote_average);' . "\r\n" . '                    $("#tmdb_id").val(rEpisode.id);' . "\r\n" . '                    $("#episode").val(rEpisode.episode_number);' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            ';
		}

		if (!isset($rEpisode)) {
		} else {
			echo "            \$(\"#season_num\").trigger('change');" . "\r\n" . '            ';
		}

		echo '            $("#runtime").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#season_num").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("#changeDir").click();' . "\r\n" . '            evaluateDirectSource();' . "\r\n" . '            evaluateSymlink();' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . '                rSubmit = true;' . "\r\n" . '                ';

		if (isset($rMulti)) {
		} else {
			echo '                if (!$("#stream_display_name").val()) {' . "\r\n" . '                    $.toast("';
			echo $_['enter_an_episode_name'];
			echo '");' . "\r\n" . '                    rSubmit = false;' . "\r\n" . '                }' . "\r\n" . '                if ($("#stream_source").val().length == 0) {' . "\r\n" . '                    $.toast("';
			echo $_['enter_an_episode_source'];
			echo '");' . "\r\n" . '                    rSubmit = false;' . "\r\n" . '                }' . "\r\n" . '                ';
		}

		echo "                \$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n" . '                if (rSubmit) {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        });' . "\r\n" . '        ' . "\r\n" . '        ';
				?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>