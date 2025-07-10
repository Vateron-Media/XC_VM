<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$rAudioCodecs = $rVideoCodecs = array();
$db->query('SELECT DISTINCT(`audio_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `audio_codec` IS NOT NULL AND `type` = 1 ORDER BY `audio_codec` ASC;');

foreach ($db->get_rows() as $rRow) {
	$rAudioCodecs[] = $rRow['audio_codec'];
}
$db->query('SELECT DISTINCT(`video_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `video_codec` IS NOT NULL AND `type` = 1 ORDER BY `video_codec` ASC;');

foreach ($db->get_rows() as $rRow) {
	$rVideoCodecs[] = $rRow['video_codec'];
}
$_TITLE = 'Streams';
include 'header.php';
echo '<div class="wrapper"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">Streams</h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n" . '                ';

if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
} else {
	echo '                <div class="alert alert-success alert-dismissible fade show" role="alert">' . "\n" . '                    <button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n" . '                        <span aria-hidden="true">&times;</span>' . "\n" . '                    </button>' . "\n" . '                    ';
	echo $_['stream_success'];
	echo '                </div>' . "\n" . '                ';
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\n" . '                        <div id="collapse_filters" class="';

if (!$rMobile) {
} else {
	echo 'collapse';
}

echo ' form-group row mb-4">' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <input type="text" class="form-control" id="stream_search" value="';

if (!isset(CoreUtilities::$rRequest['search'])) {
} else {
	echo htmlspecialchars(CoreUtilities::$rRequest['search']);
}

echo '" placeholder="Search Streams...">' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="stream_server_id" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['server'])) {
} else {
	echo ' selected';
}

echo '>';
echo $_['all_servers'];
echo '</option>' . "\n" . '                                    <option value="-1"';

if (!(isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == -1)) {
} else {
	echo ' selected';
}

echo '>No Servers</option>' . "\n" . '                                    ';

foreach (getStreamingServers() as $rServer) {
	echo '                                    <option value="';
	echo intval($rServer['id']);
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id'])) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rServer['server_name'];
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="stream_category_id" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['category'])) {
} else {
	echo ' selected';
}

echo '>';
echo $_['all_categories'];
echo '</option>' . "\n" . '                                    <option value="-1"';

if (!(isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == -1)) {
} else {
	echo ' selected';
}

echo '>No Categories</option>' . "\n" . '                                    ';

foreach (getCategories('live') as $rCategory) {
	echo '                                    <option value="';
	echo intval($rCategory['id']);
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id'])) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rCategory['category_name'];
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="stream_filter" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['filter'])) {
} else {
	echo ' selected';
}

echo '>No Filter</option>' . "\n" . '                                    <option value="1"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1)) {
} else {
	echo ' selected';
}

echo '>Online</option>' . "\n" . '                                    <option value="2"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2)) {
} else {
	echo ' selected';
}

echo '>Down</option>' . "\n" . '                                    <option value="3"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3)) {
} else {
	echo ' selected';
}

echo '>Stopped</option>' . "\n" . '                                    <option value="4"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4)) {
} else {
	echo ' selected';
}

echo '>Starting</option>' . "\n" . '                                    <option value="5"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5)) {
} else {
	echo ' selected';
}

echo '>On Demand</option>' . "\n" . '                                    <option value="6"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 6)) {
} else {
	echo ' selected';
}

echo '>Direct</option>' . "\n" . '                                    <option value="7"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 7)) {
} else {
	echo ' selected';
}

echo '>Timeshift</option>' . "\n" . '                                    <option value="8"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 8)) {
} else {
	echo ' selected';
}

echo '>Looping</option>' . "\n" . '                                    <option value="9"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 9)) {
} else {
	echo ' selected';
}

echo '>Has EPG</option>' . "\n" . '                                    <option value="10"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 10)) {
} else {
	echo ' selected';
}

echo '>No EPG</option>' . "\n" . '                                    <option value="11"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 11)) {
} else {
	echo ' selected';
}

echo '>Adaptive Link</option>' . "\n\t\t\t\t\t\t\t\t\t" . '<option value="12"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 12)) {
} else {
	echo ' selected';
}

echo '>Title Sync</option>' . "\n\t\t\t\t\t\t\t\t\t" . '<option value="13"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 13)) {
} else {
	echo ' selected';
}

echo '>Transcoding</option>' . "\n" . '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="stream_audio" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['audio'])) {
} else {
	echo ' selected';
}

echo '>Audio</option>' . "\n" . '                                    <option value="-1"';

if (!(isset(CoreUtilities::$rRequest['audio']) && CoreUtilities::$rRequest['audio'] == '-1')) {
} else {
	echo ' selected';
}

echo '>None</option>' . "\n" . '                                    ';

foreach ($rAudioCodecs as $rCodec) {
	echo '                                    <option value="';
	echo $rCodec;
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['audio']) && CoreUtilities::$rRequest['audio'] == $rCodec)) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rCodec;
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="stream_video" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['video'])) {
} else {
	echo ' selected';
}

echo '>Video</option>' . "\n" . '                                    <option value="-1"';

if (!(isset(CoreUtilities::$rRequest['video']) && CoreUtilities::$rRequest['video'] == '-1')) {
} else {
	echo ' selected';
}

echo '>None</option>' . "\n" . '                                    ';

foreach ($rVideoCodecs as $rCodec) {
	echo '                                    <option value="';
	echo $rCodec;
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['video']) && CoreUtilities::$rRequest['video'] == $rCodec)) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rCodec;
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="stream_resolution" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['resolution'])) {
} else {
	echo ' selected';
}

echo '>Quality</option>' . "\n" . '                                    ';

foreach (array(240, 360, 480, 576, 720, 1080, 1440, 2160) as $rResolution) {
	echo '                                    <option value="';
	echo $rResolution;
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['resolution']) && CoreUtilities::$rRequest['resolution'] == $rResolution)) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rResolution;
	echo 'p</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="stream_show_entries" class="form-control" data-toggle="select2">' . "\n" . '                                    ';

foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) {
	echo '                                    <option';

	if (isset(CoreUtilities::$rRequest['entries'])) {
		if (CoreUtilities::$rRequest['entries'] != $rShow) {
		} else {
			echo ' selected';
		}
	} else {
		if ($rSettings['default_entries'] != $rShow) {
		} else {
			echo ' selected';
		}
	}

	echo ' value="';
	echo $rShow;
	echo '">';
	echo $rShow;
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n\t\t\t\t\t\t" . '<table id="datatable-streampage" class="table table-borderless table-striped dt-responsive nowrap font-normal">' . "\n\t\t\t\t\t\t\t" . '<thead>' . "\n\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Icon</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th>Name</th>' . "\n" . '                                    ';

if ($rSettings['streams_grouped'] == 1) {
	echo "\t\t\t\t\t\t\t\t\t" . '<th>Servers</th>' . "\n" . '                                    ';
} else {
	echo '                                    <th>Source</th>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo "\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Clients</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Uptime</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Actions</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Player</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">EPG</th>' . "\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Stream Info</th>' . "\n\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t" . '</div>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
include 'footer.php';
