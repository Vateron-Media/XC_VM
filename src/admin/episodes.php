<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$rAudioCodecs = $rVideoCodecs = array();
$db->query('SELECT DISTINCT(`audio_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `audio_codec` IS NOT NULL AND `type` = 5 ORDER BY `audio_codec` ASC;');

foreach ($db->get_rows() as $rRow) {
	$rAudioCodecs[] = $rRow['audio_codec'];
}
$db->query('SELECT DISTINCT(`video_codec`) FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `video_codec` IS NOT NULL AND `type` = 5 ORDER BY `video_codec` ASC;');

foreach ($db->get_rows() as $rRow) {
	$rVideoCodecs[] = $rRow['video_codec'];
}
$_TITLE = 'Episodes';
include 'header.php';
echo '<div class="wrapper"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n" . '        <div class="row">' . "\n" . '            <div class="col-12">' . "\n" . '                <div class="page-title-box">' . "\n" . '                    <div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n" . '                    <h4 class="page-title">';
echo $_['episodes'];
echo '</h4>' . "\n" . '                </div>' . "\n" . '            </div>' . "\n" . '        </div>     ' . "\n" . '        <div class="row">' . "\n" . '            <div class="col-12">' . "\n" . '                <div class="card">' . "\n" . '                    <div class="card-body" style="overflow-x:auto;">' . "\n" . '                        <div id="collapse_filters" class="';

if (!$rMobile) {
} else {
	echo 'collapse';
}

echo ' form-group row mb-4">' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <input type="text" class="form-control" id="episodes_search" value="';

if (!isset(CoreUtilities::$rRequest['search'])) {
} else {
	echo htmlspecialchars(CoreUtilities::$rRequest['search']);
}

echo '" placeholder="';
echo $_['search_episodes'];
echo '...">' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="episodes_server" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

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
	echo $rServer['id'];
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id'])) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo $rServer['server_name'];
	echo '</option>' . "\n" . '                                    ';
}
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <label class="col-md-1 col-form-label text-center" for="episodes_series">Series &nbsp; <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearSeries();"><i class="mdi mdi-close"></i></button></label>' . "\n" . '                            <div class="col-md-2">' . "\n" . '                                <select id="episodes_series" class="form-control" data-toggle="select2">' . "\n" . '                                    ';

if (!(isset(CoreUtilities::$rRequest['series']) && ($rSeries = getSerie(intval(CoreUtilities::$rRequest['series']))))) {
} else {
	echo '                                    <option value="';
	echo intval($rSeries['id']);
	echo '" selected="selected">';
	echo $rSeries['title'];
	echo '</option>' . "\n" . '                                    ';
}

echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="episodes_filter" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

if (isset(CoreUtilities::$rRequest['filter'])) {
} else {
	echo ' selected';
}

echo '>';
echo $_['no_filter'];
echo '</option>' . "\n" . '                                    <option value="1"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1)) {
} else {
	echo ' selected';
}

echo '>';
echo $_['encoded'];
echo '</option>' . "\n" . '                                    <option value="2"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2)) {
} else {
	echo ' selected';
}

echo '>';
echo $_['encoding'];
echo '</option>' . "\n" . '                                    <option value="3"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3)) {
} else {
	echo ' selected';
}

echo '>';
echo $_['down'];
echo '</option>' . "\n" . '                                    <option value="4"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4)) {
} else {
	echo ' selected';
}

echo '>';
echo $_['ready'];
echo '</option>' . "\n" . '                                    <option value="5"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5)) {
} else {
	echo ' selected';
}

echo '>';
echo $_['direct'];
echo '</option>' . "\n\t\t\t\t\t\t\t\t\t" . '<option value="6"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 6)) {
} else {
	echo ' selected';
}

echo '>Duplicate</option>' . "\n\t\t\t\t\t\t\t\t\t" . '<option value="7"';

if (!(isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 7)) {
} else {
	echo ' selected';
}

echo '>Transcoding</option>' . "\n" . '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="episodes_audio" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

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
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="episodes_video" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

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
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="episodes_resolution" class="form-control" data-toggle="select2">' . "\n" . '                                    <option value=""';

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
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                            <div class="col-md-1">' . "\n" . '                                <select id="episodes_show_entries" class="form-control" data-toggle="select2">' . "\n" . '                                    ';

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
echo '                                </select>' . "\n" . '                            </div>' . "\n" . '                        </div>' . "\n" . '                        <table id="datatable-streampage" class="table table-striped table-borderless dt-responsive nowrap font-normal">' . "\n" . '                            <thead>' . "\n" . '                                <tr>' . "\n" . '                                    <th class="text-center">';
echo $_['id'];
echo '</th>' . "\n" . '                                    <th class="text-center">Image</th>' . "\n" . '                                    <th>';
echo $_['name'];
echo '</th>' . "\n" . '                                    ';

if ($rSettings['streams_grouped'] == 1) {
	echo "\t\t\t\t\t\t\t\t\t" . '<th>';
	echo $_['servers'];
	echo '</th>' . "\n" . '                                    ';
} else {
	echo '                                    <th>';
	echo $_['server'];
	echo '</th>' . "\n\t\t\t\t\t\t\t\t\t";
}

echo '                                    <th class="text-center">';
echo $_['clients'];
echo '</th>' . "\n" . '                                    <th class="text-center">';
echo $_['status'];
echo '</th>' . "\n" . '                                    <th class="text-center">';
echo $_['actions'];
echo '</th>' . "\n" . '                                    <th class="text-center">';
echo $_['player'];
echo '</th>' . "\n" . '                                    <th class="text-center">';
echo $_['stream_info'];
echo '</th>' . "\n" . '                                </tr>' . "\n" . '                            </thead>' . "\n" . '                            <tbody></tbody>' . "\n" . '                        </table>' . "\n" . '                    </div> ' . "\n" . '                </div> ' . "\n" . '            </div>' . "\n" . '        </div>' . "\n" . '    </div>' . "\n" . '</div>' . "\n";
include 'footer.php';
