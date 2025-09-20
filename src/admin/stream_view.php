<?php







include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

if (isset(CoreUtilities::$rRequest['id']) && ($rStream = getStream(CoreUtilities::$rRequest['id']))) {
} else {
	goHome();
}

$rTypeString = array(1 => 'Stream', 2 => 'Movie', 3 => 'Channel', 4 => 'Station', 5 => 'Episode')[$rStream['type']];
$rEPGData = null;
$rImage = null;

if ($rStream['type'] == 1) {
	$rEPGData = getchannelepg($rStream['id']);

	if (0 >= $rStream['vframes_server_id']) {
	} else {
		$rExpires = time() + 3600;
		$rTokenData = array('session_id' => session_id(), 'expires' => $rExpires, 'stream_id' => intval(CoreUtilities::$rRequest['id']), 'ip' => CoreUtilities::getUserIP());
		$rUIToken = CoreUtilities::encryptData(json_encode($rTokenData), CoreUtilities::$rSettings['live_streaming_pass'], OPENSSL_EXTRA);

		if (issecure()) {
			$rImage = 'https://' . ((CoreUtilities::$rServers[$rStream['vframes_server_id']]['domain_name'] ? CoreUtilities::$rServers[$rStream['vframes_server_id']]['domain_name'] : CoreUtilities::$rServers[$rStream['vframes_server_id']]['server_ip'])) . ':' . intval(CoreUtilities::$rServers[$rStream['vframes_server_id']]['https_broadcast_port']) . '/admin/thumb?uitoken=' . $rUIToken;
		} else {
			$rImage = 'http://' . ((CoreUtilities::$rServers[$rStream['vframes_server_id']]['domain_name'] ? CoreUtilities::$rServers[$rStream['vframes_server_id']]['domain_name'] : CoreUtilities::$rServers[$rStream['vframes_server_id']]['server_ip'])) . ':' . intval(CoreUtilities::$rServers[$rStream['vframes_server_id']]['http_broadcast_port']) . '/admin/thumb?uitoken=' . $rUIToken;
		}
	}

	$rAdaptiveLink = (json_decode($rStream['adaptive_link'], true) ?: array());
} else {
	if ($rStream['type'] == 2 || $rStream['type'] == 5) {
		$rProperties = json_decode($rStream['movie_properties'], true);
		$rImage = (!empty($rProperties['backdrop_path'][0]) ? CoreUtilities::validateImage($rProperties['backdrop_path'][0], (issecure() ? 'https' : 'http')) : CoreUtilities::validateImage($rProperties['movie_image'], (issecure() ? 'https' : 'http')));

		if (empty($rImage)) {
		} else {
			if (@getimagesize($rImage)) {
			} else {
				$rImage = null;
			}
		}
	} else {
		if ($rStream['type'] != 3) {
		} else {
			$rCCInfo = null;
			$db->query('SELECT `streams_servers`.`stream_started`, `streams_servers`.`cc_info` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` AND `streams_servers`.`parent_id` IS NULL WHERE `streams`.`id` = ? GROUP BY `streams`.`id`;', $rStream['id']);

			if (0 >= $db->num_rows()) {
			} else {
				$rServerRow = $db->get_row();
				$rCCInfo = json_decode($rServerRow['cc_info'], true);
				$rSeconds = time() - intval($rServerRow['stream_started']);
			}
		}
	}
}

if ($rStream['type'] != 5) {
} else {
	$rSeries = null;
	$db->query('SELECT * FROM `streams_series` WHERE `id` = (SELECT `series_id` FROM `streams_episodes` WHERE `stream_id` = ?);', $rStream['id']);

	if (0 >= $db->num_rows()) {
	} else {
		$rSeries = $db->get_row();
	}

	$rSeriesID = $rSeries['id'];
}

$rStreamStats = getStreamStats($rStream['id']);
$_TITLE = 'View ' . $rTypeString;
include 'header.php';
echo '<div class="wrapper boxed-layout-ext"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\r\n" . '    <div class="container-fluid">' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="page-title-box">' . "\r\n\t\t\t\t\t" . '<div class="page-title-right">' . "\r\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\r\n" . '                    <h4 class="page-title">';
echo $rStream['stream_display_name'];
echo '</h4>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>     ' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-xl-12">' . "\r\n" . '                ';

if (!$rImage) {
} else {
	echo '                <img class="card-img-top img-fluid" src="';
	echo $rImage;
	echo "\" onerror=\"this.style.display='none'\"/>" . "\r\n" . '                ';
}

if ($rStream['type'] == 1) {
	echo "\t\t\t\t" . '<div class="card-box">' . "\r\n\t\t\t\t\t" . '<ul class="nav nav-tabs nav-bordered nav-justified">' . "\r\n\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#today" data-toggle="tab" aria-expanded="true" class="nav-link active">' . "\r\n\t\t\t\t\t\t\t\t" . 'Today' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#week" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'This Week' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#month" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'This Month' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#all" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'All Time' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t" . '<div class="tab-content">' . "\r\n\t\t\t\t\t\t";

	foreach (array('today', 'week', 'month', 'all') as $rType) {
		echo "\t\t\t\t\t\t" . '<div class="tab-pane';

		if ($rType != 'today') {
		} else {
			echo ' active';
		}

		echo '" id="';
		echo $rType;
		echo '">' . "\r\n\t\t\t\t\t\t\t" . '<div class="row text-center" style="padding-top: 20px;">' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<h4 class="header-title">Stream Rank</h4>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<p class="sub-header" id="s_conns">';

		if (0 < $rStreamStats[$rType]['rank']) {
			echo '#' . $rStreamStats[$rType]['rank'];
		} else {
			echo 'N/A';
		}

		echo '</p>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<h4 class="header-title">Time Played</h4>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<p class="sub-header" id="s_users">';
		echo formatUptime($rStreamStats[$rType]['time']);
		echo '</p>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<h4 class="header-title">Total Streams</h4>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<p class="sub-header" id="s_online">';
		echo number_format($rStreamStats[$rType]['connections'], 0);
		echo '</p>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<h4 class="header-title">Total Users</h4>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<p class="sub-header" id="s_online">';
		echo number_format($rStreamStats[$rType]['users'], 0);
		echo '</p>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t";
	}
	echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n" . '                ';
}

echo "\t\t\t\t" . '<div class="card-box">' . "\r\n\t\t\t\t\t" . '<ul class="nav nav-tabs nav-bordered nav-justified">' . "\r\n\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#servers" data-toggle="tab" aria-expanded="true" class="nav-link active">' . "\r\n\t\t\t\t\t\t\t\t" . 'Active Servers' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';

if ($rStream['type'] == 1) {
	echo "\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#sources" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'Stream Sources' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';

	if (0 >= count($rAdaptiveLink)) {
	} else {
		echo '                        <li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#adaptive" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'Adaptive Link' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';
	}

	if (0 >= count($rEPGData)) {
	} else {
		echo "\t\t\t\t\t\t" . '<li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#guide" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'Programme Guide' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';
	}

	if (!(0 < $rStream['tv_archive_server_id'] && 0 < $rStream['tv_archive_duration'])) {
	} else {
		echo '                        <li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#archive" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'TV Archive' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';
	}

	echo '                        <li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#errors" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'Recent Errors' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';
} else {
	if ($rStream['type'] == 2) {
		echo '                        <li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#information" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'Movie Information' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';
	} else {
		if ($rStream['type'] == 3) {
			echo '                        <li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#sources" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'Channel Guide' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';
		} else {
			if ($rStream['type'] != 5) {
			} else {
				if (!$rSeries) {
				} else {
					echo '                        <li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#s-information" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'Series Information' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';
				}

				echo '                        <li class="nav-item">' . "\r\n\t\t\t\t\t\t\t" . '<a href="#information" data-toggle="tab" aria-expanded="false" class="nav-link">' . "\r\n\t\t\t\t\t\t\t\t" . 'Episode Information' . "\r\n\t\t\t\t\t\t\t" . '</a>' . "\r\n\t\t\t\t\t\t" . '</li>' . "\r\n" . '                        ';
			}
		}
	}
}

echo "\t\t\t\t\t" . '</ul>' . "\r\n\t\t\t\t\t" . '<div class="tab-content">' . "\r\n\t\t\t\t\t\t" . '<div class="tab-pane active" id="servers">' . "\r\n\t\t\t\t\t\t\t" . '<div class="table">' . "\r\n\t\t\t\t\t\t\t\t" . '<table id="datatable" class="table table-striped table-borderless mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th></th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th></th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th></th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th>Source</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th>Clients</th>' . "\r\n" . '                                            ';

if ($rStream['type'] == 2) {
	echo "\t\t\t\t\t\t\t\t\t\t\t" . '<th>Status</th>' . "\r\n" . '                                            ';
} else {
	echo '                                            <th>Uptime</th>' . "\r\n" . '                                            ';
}

echo "\t\t\t\t\t\t\t\t\t\t\t" . '<th>Actions</th>' . "\r\n" . '                                            ';

if ($rStream['type'] == 2) {
	echo '                                            <th>Actions</th>' . "\r\n" . '                                            ';
} else {
	echo '                                            <th>';
	echo $rTypeString;
	echo ' Info</th>' . "\r\n" . '                                            ';
}

echo '                                            <th>';
echo $rTypeString;
echo ' Info</th>' . "\r\n" . '                                            <th>';
echo $rTypeString;
echo ' Info</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<td colspan="8" class="text-center">Loading information...</td>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\r\n\t\t\t\t\t\t\t\t" . '</table>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n" . '                        ';

if ($rStream['type'] == 1) {
	echo "\t\t\t\t\t\t" . '<div class="tab-pane" id="sources">' . "\r\n\t\t\t\t\t\t\t" . '<div class="table">' . "\r\n\t\t\t\t\t\t\t\t" . '<table id="datatable-sources" class="table table-striped table-borderless mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Order</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th>Source</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center" style="width:300px;">Stream Info &nbsp;<button onClick="scanSources();" type="button" class="btn btn-xs btn-outline-secondary waves-effect waves-light"><i class="mdi mdi-refresh"></i></button></th>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\r\n\t\t\t\t\t\t\t\t\t\t";
	$i = 0;

	foreach (json_decode($rStream['stream_source'], true) as $rSource) {
		$i++;
		$rHost = parse_url($rSource)['host'];
		$rNumber = intval(explode('?', explode('.', explode('/', $rSource)[count(explode('/', $rSource)) - 1])[0])[0]);

		if (0 < $rNumber) {
			$rHost .= ' [ID: ' . $rNumber . ']';
		} else {
			if (!in_array(strtolower(pathinfo($rSource)['extension']), array('ts', 'm3u8', 'mp4', 'mkv'))) {
			} else {
				$rHost .= ' [' . explode('?', explode('/', $rSource)[count(explode('/', $rSource)) - 1])[0] . ']';
			}
		}

		echo "\t\t\t\t\t\t\t\t\t\t" . '<tr class="stream_info" data-id="';
		echo $i - 1;
		echo '">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\r\n" . '                                                <button onClick="overrideSource(';
		echo intval(CoreUtilities::$rRequest['id']);
		echo ', ';
		echo $i - 1;
		echo ');" type="button" title="Override Source" class="tooltip btn btn-info btn-xs waves-effect waves-light btn-fixed-xs">';
		echo $i;
		echo '</button>' . "\r\n" . '                                            </td>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<td><span>';
		echo $rHost;
		echo '</span></td>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center" id="stream_info_';
		echo $i - 1;
		echo '" style="width:300px;">' . "\r\n\t\t\t\t\t\t\t\t\t\t\t\t" . "<table tstyle='width: 300px;' class='table-data' align='center'><tbody><tr><td colspan='4'>Not scanned</td></tr></tbody></table>" . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '</td>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t";
	}
	echo "\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\r\n\t\t\t\t\t\t\t\t" . '</table>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n" . '                        ';

	if (0 >= count($rAdaptiveLink)) {
	} else {
		$rAdaptiveLink = array_merge(array($rStream['id']), $rAdaptiveLink);
		$rAdaptiveInfo = $rStreamNames = array();
		$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rAdaptiveLink)) . ');');

		foreach ($db->get_rows() as $rRow) {
			$rStreamNames[$rRow['id']] = $rRow['stream_display_name'];
		}
		$db->query('SELECT `stream_id`, `stream_info`, `progress_info` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rAdaptiveLink)) . ') AND `stream_info` IS NOT NULL AND `pid` IS NOT NULL AND `pid` > 0 GROUP BY `stream_id`;');

		foreach ($db->get_rows() as $rRow) {
			$rAdaptiveInfo[$rRow['stream_id']] = array(json_decode($rRow['stream_info'], true), json_decode($rRow['progress_info'], true));
		}
		echo '                        <div class="tab-pane" id="adaptive">' . "\r\n\t\t\t\t\t\t\t" . '<div class="table">' . "\r\n\t\t\t\t\t\t\t\t" . '<table id="datatable-adaptive" class="table table-striped table-borderless mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th>Stream Name</th>' . "\r\n" . '                                            <th class="text-center">Bandwidth</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center" style="width:300px;">Stream Info</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\r\n\t\t\t\t\t\t\t\t\t\t";

		foreach ($rAdaptiveLink as $rAdaptiveID) {
			list($rStreamInfo, $rProgressInfo) = ($rAdaptiveInfo[$rAdaptiveID] ?: array());

			if (isset($rStreamInfo['codecs']['video'])) {
			} else {
				$rStreamInfo['codecs']['video'] = array('width' => '?', 'height' => '?', 'codec_name' => 'N/A', 'r_frame_rate' => '--');
			}

			if (isset($rStreamInfo['codecs']['audio'])) {
			} else {
				$rStreamInfo['codecs']['audio'] = array('codec_name' => 'N/A');
			}

			if ($rStreamInfo['bitrate'] != 0) {
			} else {
				$rStreamInfo['bitrate'] = '?';
			}

			if (isset($rProgressInfo['speed'])) {
				$rSpeed = floor($rProgressInfo['speed'] * 100) / 100 . 'x';
			} else {
				$rSpeed = '1x';
			}

			$rFPS = null;

			if (isset($rProgressInfo['fps'])) {
				$rFPS = intval($rProgressInfo['fps']);
			} else {
				if (!isset($rStreamInfo['codecs']['video']['r_frame_rate'])) {
				} else {
					$rFPS = intval($rStreamInfo['codecs']['video']['r_frame_rate']);
				}
			}

			if ($rFPS) {
				if (1000 > $rFPS) {
				} else {
					$rFPS = intval($rFPS / 1000);
				}

				$rFPS = $rFPS . ' FPS';
			} else {
				$rFPS = '--';
			}

			$rStreamInfoText = "<table class='table-data nowrap' style='width: 400px;' align='center'><tbody><tr><td class='double'>" . number_format($rStreamInfo['bitrate'] / 1024, 0) . " Kbps</td><td style='color: #20a009;'><i class='mdi mdi-video' data-name='mdi-video'></i></td><td style='color: #20a009;'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td>";

			if ($rCreated) {
			} else {
				$rStreamInfoText .= "<td style='color: #20a009;'><i class='mdi mdi-play-speed' data-name='mdi-play-speed'></i></td>";
			}

			$rStreamInfoText .= "<td style='color: #20a009;'><i class='mdi mdi-layers' data-name='mdi-layers'></i></td></tr><tr><td class='double'>" . $rStreamInfo['codecs']['video']['width'] . ' x ' . $rStreamInfo['codecs']['video']['height'] . '</td><td>' . $rStreamInfo['codecs']['video']['codec_name'] . '</td><td>' . $rStreamInfo['codecs']['audio']['codec_name'] . '</td>';

			if ($rCreated) {
			} else {
				$rStreamInfoText .= '<td>' . $rSpeed . '</td>';
			}

			$rStreamInfoText .= '<td>' . $rFPS . '</td></tr></tbody></table>';
			echo "\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center">' . "\r\n" . '                                                <a href="stream_view?id=';
			echo $rAdaptiveID;
			echo '"><button type="button" class="btn btn-info btn-xs waves-effect waves-light btn-fixed-lg">';
			echo $rAdaptiveID;
			echo '</button></a>' . "\r\n" . '                                            </td>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<td>';
			echo ($rStreamNames[$rAdaptiveID] ?: 'Not Available');
			echo '</td>' . "\r\n" . '                                            <td class="text-center">';
			echo number_format(floatval($rStreamInfo['bitrate']), 0);
			echo '</td>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<td class="text-center" style="width:400px;">';
			echo $rStreamInfoText;
			echo '</td>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t";
		}
		echo "\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\r\n\t\t\t\t\t\t\t\t" . '</table>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n" . '                        ';
	}

	if (0 >= count($rEPGData)) {
	} else {
		$rAvailable = false;
		$db->query('SELECT `server_id`, `direct_source`, `monitor_pid`, `pid`, `stream_status`, `on_demand` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`id` = ? AND `server_id` IS NOT NULL;', $rStream['id']);

		if (0 >= $db->num_rows()) {
		} else {
			foreach ($db->get_rows() as $rStreamRow) {
				if (!$rStreamRow['server_id'] || $rStreamRow['direct_source']) {
				} else {
					$rAvailable = true;

					break;
				}
			}
		}

		echo "\t\t\t\t\t\t" . '<div class="tab-pane" id="guide">' . "\r\n\t\t\t\t\t\t\t" . '<div class="inbox-widget slimscroll" style="min-height: 400px;">' . "\r\n\t\t\t\t\t\t\t\t";
		$rPrevDate = date('Y-m-d');

		foreach ($rEPGData as $rEPGItem) {
			if (date('Y-m-d', $rEPGItem['start']) == $rPrevDate) {
			} else {
				$rPrevDate = date('Y-m-d', $rEPGItem['start']);
				echo '<h4 class="header-title mb-3" style="padding-top: 20px;">' . date('l jS', $rEPGItem['start']) . '</h4>';
			}

			echo "\t\t\t\t\t\t\t\t" . '<div class="inbox-item">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<p class="inbox-item-author">';
			echo $rEPGItem['title'];
			echo '</p>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<p class="inbox-item-text" style="margin-top:10px;">';
			echo $rEPGItem['description'];
			echo '</p>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<p class="inbox-item-date btn-group">' . "\r\n" . '                                        <button class="btn btn-info btn-xs waves-effect waves-light btn-fixed">';
			echo date('H:i', $rEPGItem['start']);
			echo ' - ';
			echo date('H:i', $rEPGItem['end']);
			echo '</button>' . "\r\n" . '                                        ';

			if (!$rAvailable) {
			} else {
				echo '                                        <a href="record?id=';
				echo intval($rStream['id']);
				echo '&programme=';
				echo intval($rEPGItem['id']);
				echo '"><button class="btn btn-danger btn-xs waves-effect waves-light tooltip" title="Record"><i class="mdi mdi-record"></i></button></a>' . "\r\n" . '                                        ';
			}

			echo "\t\t\t\t\t\t\t\t\t" . '</p><br/>' . "\r\n\t\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t\t";
		}
		echo "\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n" . '                        ';
	}

	if (!(0 < $rStream['tv_archive_server_id'] && 0 < $rStream['tv_archive_duration'])) {
	} else {
		$rArchive = getArchive($rStream['id']);
		echo '                        <div class="tab-pane" id="archive">' . "\r\n\t\t\t\t\t\t\t" . '<div class="table">' . "\r\n\t\t\t\t\t\t\t\t" . '<table id="datatable-archive" class="table table-striped table-borderless mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Date</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th>Title</th>' . "\r\n" . '                                            <th class="text-center">Status</th>' . "\r\n" . '                                            <th class="text-center">Player</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\r\n" . '                                        ';

		foreach ($rArchive as $rItem) {
			$rDuration = $rItem['end'] - $rItem['start'];
			$rItem['stream_id'] = CoreUtilities::$rRequest['id'];
			echo "\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n" . '                                            <td class="text-center">';
			echo date($rSettings['date_format'], $rItem['start']);
			echo '<br/>';
			echo date('H:i:s', $rItem['start']);
			echo ' - ';
			echo date('H:i:s', $rItem['end']);
			echo '</td>' . "\r\n" . '                                            <td>';
			echo $rItem['title'];
			echo '</td>' . "\r\n" . '                                            <td class="text-center">' . "\r\n" . '                                                ';

			if ($rItem['in_progress']) {
				echo "                                                <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>IN PROGRESS</button>" . "\r\n" . '                                                ';
			} else {
				if ($rItem['complete']) {
					echo "                                                <button type='button' class='btn btn-success btn-xs waves-effect waves-light'>COMPLETE</button>" . "\r\n" . '                                                ';
				} else {
					echo "                                                <button type='button' class='btn btn-warning btn-xs waves-effect waves-light'>INCOMPLETE</button>" . "\r\n" . '                                                ';
				}
			}

			echo '                                                <a href="record?archive=';
			echo urlencode(base64_encode(json_encode($rItem)));
			echo '"><button class="btn btn-danger btn-xs waves-effect waves-light tooltip" title="Save to VOD"><i class="mdi mdi-record"></i></button></a>' . "\r\n" . '                                            </td>' . "\r\n" . '                                            <td class="text-center"><button type="button" class="btn btn-info waves-effect waves-light btn-xs" onclick="player(';
			echo intval($rStream['id']);
			echo ', ';
			echo intval($rItem['start']);
			echo ', ';
			echo intval($rDuration / 60);
			echo ');"><i class="mdi mdi-play"></i></button></td>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n" . '                                        ';
		}
		echo "\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\r\n\t\t\t\t\t\t\t\t" . '</table>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n" . '                        ';
	}

	echo '                        <div class="tab-pane" id="errors">' . "\r\n\t\t\t\t\t\t\t" . '<div class="table">' . "\r\n\t\t\t\t\t\t\t\t" . '<table id="datatable-errors" class="table table-striped table-borderless mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Date</th>' . "\r\n" . '                                            <th>Message</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\r\n" . '                                        ';

	foreach (getStreamErrors($rStream['id']) as $rItem) {
		echo "\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n" . '                                            <td style="width: 80px;" class="text-center">';
		echo date($rSettings['datetime_format'], $rItem['date']);
		echo '</td>' . "\r\n" . "                                            <td onClick='showError(this);' style='cursor: pointer;'>";
		echo $rItem['error'];
		echo '</td>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n" . '                                        ';
	}
	echo "\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\r\n\t\t\t\t\t\t\t\t" . '</table>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n" . '                        ';
} else {
	if ($rStream['type'] == 2) {
		echo '                        <div class="tab-pane" id="information">' . "\r\n" . '                            <div class="col-12 input-view">' . "\r\n" . '                                <div class="form-group row mb-4">' . "\r\n" . '                                    <label class="col-md-2 col-form-label" for="plot">';
		echo $_['plot'];
		echo '</label>' . "\r\n" . '                                    <div class="col-md-10">' . "\r\n" . '                                        <textarea readonly rows="6" class="form-control" id="plot" name="plot">';
		echo htmlspecialchars($rProperties['plot']);
		echo '</textarea>' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div>' . "\r\n" . '                                <div class="form-group row mb-4">' . "\r\n" . '                                    <label class="col-md-2 col-form-label" for="cast">';
		echo $_['cast'];
		echo '</label>' . "\r\n" . '                                    <div class="col-md-10">' . "\r\n" . '                                        <input readonly type="text" class="form-control" id="cast" name="cast" value="';
		echo htmlspecialchars($rProperties['cast']);
		echo '">' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div>' . "\r\n" . '                                <div class="form-group row mb-4">' . "\r\n" . '                                    <label class="col-md-2 col-form-label" for="director">';
		echo $_['director'];
		echo '</label>' . "\r\n" . '                                    <div class="col-md-4">' . "\r\n" . '                                        <input readonly type="text" class="form-control" id="director" name="director" value="';
		echo htmlspecialchars($rProperties['director']);
		echo '">' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <label class="col-md-2 col-form-label" for="genre">';
		echo $_['genres'];
		echo '</label>' . "\r\n" . '                                    <div class="col-md-4">' . "\r\n" . '                                        <input readonly type="text" class="form-control" id="genre" name="genre" value="';
		echo htmlspecialchars($rProperties['genre']);
		echo '">' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div>' . "\r\n" . '                                <div class="form-group row mb-4">' . "\r\n" . '                                    <label class="col-md-2 col-form-label" for="release_date">';
		echo $_['release_date'];
		echo '</label>' . "\r\n" . '                                    <div class="col-md-4">' . "\r\n" . '                                        <input readonly type="text" class="form-control text-center" id="release_date" name="release_date" value="';
		echo htmlspecialchars($rProperties['release_date']);
		echo '">' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <label class="col-md-2 col-form-label" for="episode_run_time">';
		echo $_['runtime'];
		echo '</label>' . "\r\n" . '                                    <div class="col-md-4">' . "\r\n" . '                                        <input readonly type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="';
		echo CoreUtilities::secondsToTime(intval($rProperties['episode_run_time']) * 60, false);
		echo '">' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div>' . "\r\n" . '                                <div class="form-group row mb-4">' . "\r\n" . '                                    <label class="col-md-2 col-form-label" for="youtube_trailer">';
		echo $_['youtube_trailer'];
		echo '</label>' . "\r\n" . '                                    <div class="col-md-4 input-group">' . "\r\n" . '                                        <input readonly type="text" class="form-control text-center" id="youtube_trailer" name="youtube_trailer" value="';
		echo htmlspecialchars($rProperties['youtube_trailer']);
		echo '">' . "\r\n" . '                                        <div class="input-group-append">' . "\r\n" . '                                            <a href="javascript:void(0)" onClick="openYouTube(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>' . "\r\n" . '                                        </div>' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <label class="col-md-2 col-form-label" for="rating">';
		echo $_['rating'];
		echo '</label>' . "\r\n" . '                                    <div class="col-md-4">' . "\r\n" . '                                        <input readonly type="text" class="form-control text-center" id="rating" name="rating" value="';
		echo htmlspecialchars($rProperties['rating']);
		echo '">' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div>' . "\r\n" . '                                <div class="form-group row mb-4">' . "\r\n" . '                                    <label class="col-md-2 col-form-label" for="country">';
		echo $_['country'];
		echo '</label>' . "\r\n" . '                                    <div class="col-md-10">' . "\r\n" . '                                        <input readonly type="text" class="form-control" id="country" name="country" value="';
		echo htmlspecialchars($rProperties['country']);
		echo '">' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div>' . "\r\n" . '                            </div> ' . "\r\n" . '                        </div>' . "\r\n" . '                        ';
	} else {
		if ($rStream['type'] == 5) {
			if (!$rSeries) {
			} else {
				echo '                        <div class="tab-pane" id="s-information">' . "\r\n" . '                            <div class="row">' . "\r\n" . '                                <div class="col-12 input-view">' . "\r\n" . '                                    <div class="form-group row mb-4">' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="plot">Plot</label>' . "\r\n" . '                                        <div class="col-md-10">' . "\r\n" . '                                            <textarea readonly rows="6" class="form-control" id="plot" name="plot">';
				echo htmlspecialchars($rSeries['plot']);
				echo '</textarea>' . "\r\n" . '                                        </div>' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <div class="form-group row mb-4">' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="cast">Cast</label>' . "\r\n" . '                                        <div class="col-md-10">' . "\r\n" . '                                            <input readonly type="text" class="form-control" id="cast" name="cast" value="';
				echo htmlspecialchars($rSeries['cast']);
				echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <div class="form-group row mb-4">' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="director">Director</label>' . "\r\n" . '                                        <div class="col-md-4">' . "\r\n" . '                                            <input readonly type="text" class="form-control text-center" id="director" name="director" value="';
				echo htmlspecialchars($rSeries['director']);
				echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="genre">Genres</label>' . "\r\n" . '                                        <div class="col-md-4">' . "\r\n" . '                                            <input readonly type="text" class="form-control text-center " id="genre" name="genre" value="';
				echo htmlspecialchars($rSeries['genre']);
				echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <div class="form-group row mb-4">' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="release_date">Release Date</label>' . "\r\n" . '                                        <div class="col-md-4">' . "\r\n" . '                                            <input readonly type="text" class="form-control text-center" id="release_date" name="release_date" value="';
				echo htmlspecialchars($rSeries['release_date']);
				echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="episode_run_time">Runtime</label>' . "\r\n" . '                                        <div class="col-md-4">' . "\r\n" . '                                            <input readonly type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="';
				echo CoreUtilities::secondsToTime(intval($rProperties['episode_run_time']) * 60, false);
				echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <div class="form-group row mb-4">' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="youtube_trailer">Youtube Trailer</label>' . "\r\n" . '                                        <div class="col-md-4">' . "\r\n" . '                                            <input readonly type="text" class="form-control text-center" id="youtube_trailer" name="youtube_trailer" value="';
				echo htmlspecialchars($rSeries['youtube_trailer']);
				echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="rating">Rating</label>' . "\r\n" . '                                        <div class="col-md-4">' . "\r\n" . '                                            <input readonly type="text" class="form-control text-center" id="rating" name="rating" value="';
				echo htmlspecialchars($rSeries['rating']);
				echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div> ' . "\r\n" . '                            </div>' . "\r\n" . '                        </div>' . "\r\n" . '                        ';
			}

			echo '                        <div class="tab-pane" id="information">' . "\r\n" . '                            <div class="row">' . "\r\n" . '                                <div class="col-12 input-view">' . "\r\n" . '                                    <div class="form-group row mb-4">' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="plot">';
			echo $_['plot'];
			echo '</label>' . "\r\n" . '                                        <div class="col-md-10">' . "\r\n" . '                                            <textarea readonly rows="6" class="form-control" id="plot" name="plot">';
			echo htmlspecialchars($rProperties['plot']);
			echo '</textarea>' . "\r\n" . '                                        </div>' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <div class="form-group row mb-4">' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="release_date">';
			echo $_['release_date'];
			echo '</label>' . "\r\n" . '                                        <div class="col-md-4">' . "\r\n" . '                                            <input readonly type="text" class="form-control text-center" id="release_date" name="release_date" value="';
			echo htmlspecialchars($rProperties['release_date']);
			echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="episode_run_time">';
			echo $_['runtime'];
			echo '</label>' . "\r\n" . '                                        <div class="col-md-4">' . "\r\n" . '                                            <input readonly type="text" class="form-control text-center" id="episode_run_time" name="episode_run_time" value="';
			echo CoreUtilities::secondsToTime(intval($rProperties['duration_secs']), false);
			echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                    </div>' . "\r\n" . '                                    <div class="form-group row mb-4">' . "\r\n" . '                                        <label class="col-md-2 col-form-label" for="rating">';
			echo $_['rating'];
			echo '</label>' . "\r\n" . '                                        <div class="col-md-4">' . "\r\n" . '                                            <input readonly type="text" class="form-control text-center" id="rating" name="rating" value="';
			echo htmlspecialchars($rProperties['rating']);
			echo '">' . "\r\n" . '                                        </div>' . "\r\n" . '                                    </div>' . "\r\n" . '                                </div> ' . "\r\n" . '                            </div>' . "\r\n" . '                        </div>' . "\r\n" . '                        ';
		} else {
			if ($rStream['type'] != 3) {
			} else {
				if (!$rCCInfo) {
				} else {
					echo '                        <div class="tab-pane" id="sources">' . "\r\n\t\t\t\t\t\t\t" . '<div class="table">' . "\r\n\t\t\t\t\t\t\t\t" . '<table id="datatable-sources" class="table table-striped table-borderless mb-0">' . "\r\n\t\t\t\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Position</th>' . "\r\n" . '                                            <th>Filename</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Start</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Finish</th>' . "\r\n" . '                                            <th class="text-center">Duration</th>' . "\r\n" . '                                            <th class="text-center">Stream Info</th>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<tbody>' . "\r\n" . '                                        ';

					foreach ($rCCInfo as $rTrack) {
						$rFilename = pathinfo(json_decode($rStream['stream_source'], true)[$rTrack['position']])['filename'];
						$rTrack['seconds'] = intval(explode('.', $rTrack['seconds'])[0]);
						$rOffset = $rTrack['start'] - $rSeconds;
						$rActualStart = time() + $rOffset;
						$rActualFinish = $rActualStart + $rTrack['seconds'];

						if (86400 <= $rTrack['seconds']) {
							$rDuration = sprintf('%02dd %02dh %02dm', $rTrack['seconds'] / 86400, ($rTrack['seconds'] / 3600) % 24, ($rTrack['seconds'] / 60) % 60);
						} else {
							$rDuration = sprintf('%02dh %02dm %02ds', $rTrack['seconds'] / 3600, ($rTrack['seconds'] / 60) % 60, $rTrack['seconds'] % 60);
						}

						$rDuration = "<button type='button' class='btn btn-success btn-xs waves-effect waves-light btn-fixed'>" . $rDuration . '</button>';

						if (isset($rTrack['stream_info']['codecs']['video'])) {
						} else {
							$rTrack['stream_info']['codecs']['video'] = array('width' => '?', 'height' => '?', 'codec_name' => 'N/A', 'r_frame_rate' => '--');
						}

						if (isset($rTrack['stream_info']['codecs']['audio'])) {
						} else {
							$rTrack['stream_info']['codecs']['audio'] = array('codec_name' => 'N/A');
						}

						if ($rTrack['stream_info']['bitrate'] != 0) {
						} else {
							$rTrack['stream_info']['bitrate'] = '?';
						}

						$rFPS = null;

						if (!isset($rTrack['stream_info']['codecs']['video']['r_frame_rate'])) {
						} else {
							$rFPS = intval($rTrack['stream_info']['codecs']['video']['r_frame_rate']);
						}

						if ($rFPS) {
							if (1000 > $rFPS) {
							} else {
								$rFPS = intval($rFPS / 1000);
							}

							$rFPS = $rFPS . ' FPS';
						} else {
							$rFPS = '--';
						}

						$rStreamInfoText = "<table class='table-data nowrap' align='center'><tbody><tr><td class='double'>" . number_format($rTrack['stream_info']['bitrate'] / 1024, 0) . " Kbps</td><td style='color: #20a009;'><i class='mdi mdi-video' data-name='mdi-video'></i></td><td style='color: #20a009;'><i class='mdi mdi-volume-high' data-name='mdi-volume-high'></i></td>";
						$rStreamInfoText .= "<td style='color: #20a009;'><i class='mdi mdi-layers' data-name='mdi-layers'></i></td></tr><tr><td class='double'>" . $rTrack['stream_info']['codecs']['video']['width'] . ' x ' . $rTrack['stream_info']['codecs']['video']['height'] . '</td><td>' . $rTrack['stream_info']['codecs']['video']['codec_name'] . '</td><td>' . $rTrack['stream_info']['codecs']['audio']['codec_name'] . '</td>';
						$rStreamInfoText .= '<td>' . $rFPS . '</td></tr></tbody></table>';

						if ($rTrack['start'] <= $rSeconds && $rSeconds < $rTrack['finish']) {
							$rPosition = '<button type="button" title="Playing Now" class="tooltip btn btn-info btn-xs waves-effect waves-light btn-fixed-xs">' . ($rTrack['position'] + 1) . '</button>';
						} else {
							$rPosition = '<button type="button" class="btn btn-secondary btn-xs waves-effect waves-light btn-fixed-xs">' . ($rTrack['position'] + 1) . '</button>';
						}

						echo "\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n" . '                                            <td class="text-center">';
						echo $rPosition;
						echo '</td>' . "\r\n" . '                                            <td>';
						echo $rFilename;
						echo '</td>' . "\r\n" . '                                            <td class="text-center">';
						echo date('H:i:s', $rActualStart);
						echo '</td>' . "\r\n" . '                                            <td class="text-center">';
						echo date('H:i:s', $rActualFinish);
						echo '</td>' . "\r\n" . '                                            <td class="text-center">';
						echo $rDuration;
						echo '</td>' . "\r\n" . '                                            <td class="text-center">';
						echo $rStreamInfoText;
						echo '</td>' . "\r\n\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n" . '                                        ';
					}
					echo "\t\t\t\t\t\t\t\t\t" . '</tbody>' . "\r\n\t\t\t\t\t\t\t\t" . '</table>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n" . '                        ';
				}
			}
		}
	}
}



echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div> ' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '</div>' . "\r\n";
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
				<?php if (!$rMobile || $rSettings['header_stats']): ?>
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

			<?php if (CoreUtilities::$rSettings['enable_search']): ?>
				$(document).ready(function() {
					initSearch();
				});

			<?php endif; 
		echo '        ' . "\r\n\t\t" . 'function reloadStream() {' . "\r\n" . '            if (!$(".dropdown-menu").is(":visible")) {' . "\r\n" . '                $("#datatable").DataTable().ajax.reload( null, false );' . "\r\n" . '            }' . "\r\n\t\t\t" . 'setTimeout(reloadStream, 5000);' . "\r\n\t\t" . '}' . "\r\n" . '        function overrideSource(rID, rSource) {' . "\r\n" . '            $.getJSON("./api?action=stream&sub=force&stream_id=" + rID + "&force_id=" + rSource, function(data) {' . "\r\n" . '                $.toast("Current source has been changed.");' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        function openYouTube(elem) {' . "\r\n" . '            rPath = $(elem).parent().parent().find("input").val();' . "\r\n" . '            if (rPath) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . "                        src: 'http://www.youtube.com/watch?v=' + rPath," . "\r\n" . "                        type: 'iframe'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function openImage(elem) {' . "\r\n" . '            var rImage = $(elem).data("src");' . "\r\n" . '            if (rImage) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . '                        src: rImage,' . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function viewLiveConnections(rStreamID, rServerID=-1) {' . "\r\n" . '            $("#datatable-live").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: true,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'bInfo: true,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips(false);' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "live_connections";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rStreamID;' . "\r\n" . '                        d.server_id = rServerID;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [1,7,8,9,10,11]},' . "\r\n" . '                    {"visible": false, "targets": [0,3,5,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-live-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n\t\t" . 'function api(rID, rServerID, rType, rConfirm=false) {' . "\r\n" . '            if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rServerID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rServerID == "kill") && (!rConfirm)) {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '                rServerID = -1;' . "\r\n" . '                rType = "kill";' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                ';

		if ($rStream['type'] == 1 || $rStream['type'] == 3 || $rStream['type'] == 4) {
			echo '                $.getJSON("./api?action=stream&sub=" + rType + "&stream_id=" + rID + "&server_id=" + rServerID, function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("Stream successfully started.");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("Stream successfully stopped.");' . "\r\n" . '                        } else if (rType == "restart") {' . "\r\n" . '                            $.toast("Stream successfully restarted.");' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("Connection has been killed.");' . "\r\n" . '                            if ($(".bs-live-modal-center").is(":visible")) {' . "\r\n" . '                                $("#datatable-live").DataTable().ajax.reload( null, false );' . "\r\n" . '                            }' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                        $("#datatable").DataTable().ajax.reload( null, false );' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '                ';
		} else {
			if ($rStream['type'] == 2) {
				echo '                $.getJSON("./api?action=movie&sub=" + rType + "&stream_id=" + rID + "&server_id=" + rServerID, function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("';
				echo $_['movie_encode_started'];
				echo '");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("';
				echo $_['movie_encode_stopped'];
				echo '");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("';
				echo $_['movie_delete_confirmed'];
				echo '");' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("Connection has been killed.");' . "\r\n" . '                            if ($(".bs-live-modal-center").is(":visible")) {' . "\r\n" . '                                $("#datatable-live").DataTable().ajax.reload( null, false );' . "\r\n" . '                            }' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                        $("#datatable").DataTable().ajax.reload( null, false );' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '                ';
			} else {
				if ($rStream['type'] != 5) {
				} else {
					echo '                $.getJSON("./api?action=episode&sub=" + rType + "&stream_id=" + rID + "&server_id=" + rServerID, function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("';
					echo $_['episode_encoding_start'];
					echo '");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("';
					echo $_['episode_encoding_stop'];
					echo '");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("';
					echo $_['episode_deleted'];
					echo '");' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("Connection has been killed.");' . "\r\n" . '                            if ($(".bs-live-modal-center").is(":visible")) {' . "\r\n" . '                                $("#datatable-live").DataTable().ajax.reload( null, false );' . "\r\n" . '                            }' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                        $("#datatable").DataTable().ajax.reload( null, false );' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '                ';
				}
			}
		}

		echo '            }' . "\r\n\t\t" . '}' . "\r\n" . '        ';

		if ($rStream['type'] == 1 || $rStream['type'] == 3) {
			echo "\t\t" . 'function player(rID, rStart=null, rDuration=null) {' . "\r\n" . '            if (rStart && rDuration) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . '                        src: "./player?type=timeshift&id=" + rID + "&start=" + rStart + "&duration=" + rDuration,' . "\r\n" . "                        type: 'iframe'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            } else {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . '                        src: "./player?type=live&id=" + rID,' . "\r\n" . "                        type: 'iframe'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        ';
		} else {
			if ($rStream['type'] == 2) {
				echo '        function player(rID, rContainer) {' . "\r\n" . '            $.magnificPopup.open({' . "\r\n" . '                items: {' . "\r\n" . '                    src: "./player?type=movie&id=" + rID + "&container=" + rContainer,' . "\r\n" . "                    type: 'iframe'" . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        ';
			} else {
				if ($rStream['type'] != 5) {
				} else {
					echo '        function player(rID, rContainer) {' . "\r\n" . '            $.magnificPopup.open({' . "\r\n" . '                items: {' . "\r\n" . '                    src: "./player?type=series&id=" + rID + "&container=" + rContainer,' . "\r\n" . "                    type: 'iframe'" . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        ';
				}
			}
		}

		echo '        function showError(elem) {' . "\r\n" . "            new jBox('Modal', {" . "\r\n" . "                attach: '#errorModal'," . "\r\n" . "                title: 'Stream Error'," . "\r\n" . '                content: $(elem).text()' . "\r\n" . '            }).open();' . "\r\n" . '        }' . "\r\n\t\t" . 'function scanSources() {' . "\r\n\t\t\t" . '$(".stream_info").each(function() {' . "\r\n\t\t\t\t" . 'var rID = $(this).data("id");' . "\r\n\t\t\t\t" . 'var rURL = "./api?action=check_stream&stream=';
		echo intval($rStream['id']);
		echo '&id=" + rID;' . "\r\n\t\t\t\t" . "\$(\"#stream_info_\" + rID).html(\"<table style='width: 300px;' class='table-data' align='center'><tbody><tr><td colspan='4'>Probing source...</td></tr></tbody></table>\");" . "\r\n\t\t\t\t" . '$.get(rURL, function(data) {' . "\r\n\t\t\t\t\t" . '$("#stream_info_" + rID).html(data);' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n" . '            $("#datatable-archive").DataTable({' . "\r\n" . '                ordering: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n" . '                lengthChange: false,' . "\r\n" . '                bInfo: false,' . "\r\n" . '                paging: false,' . "\r\n" . '                columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "ellipsis", "targets": [3]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '            });' . "\r\n" . '            $("#datatable-errors").DataTable({' . "\r\n" . '                ordering: true,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n" . '                lengthChange: true,' . "\r\n" . '                bInfo: true,' . "\r\n" . '                paging: true,' . "\r\n" . '                order: [[ 0, "desc" ]],' . "\r\n" . '                columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "ellipsis", "targets": [1]}' . "\r\n\t\t\t\t" . '],' . "\r\n" . '            });' . "\r\n" . '            $("#datatable-sources").DataTable({' . "\r\n" . '                ordering: true,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n" . '                lengthChange: false,' . "\r\n" . '                bInfo: false,' . "\r\n" . '                paging: true' . "\r\n" . '            });' . "\r\n" . '            $("#datatable-adaptive").DataTable({' . "\r\n" . '                ordering: true,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n" . '                lengthChange: false,' . "\r\n" . '                bInfo: false,' . "\r\n" . '                paging: true,' . "\r\n" . '                order: [[ 2, "desc" ]],' . "\r\n" . '            });' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'ordering: false,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips(false);' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n" . '                        ';

		if ($rStream['type'] == 2) {
			echo '                        d.id = "movies";' . "\r\n" . '                        ';
		} else {
			if ($rStream['type'] == 4) {
				echo '                        d.id = "radios";' . "\r\n" . '                        ';
			} else {
				if ($rStream['type'] == 5) {
					echo '                        d.id = "episodes";' . "\r\n" . '                        ';
				} else {
					echo '                        d.id = "streams";' . "\r\n" . '                        ';
				}
			}
		}

		echo "\t\t\t\t\t\t" . 'd.stream_id = ';
		echo $rStream['id'];
		echo ';' . "\r\n" . '                        d.single = true;' . "\r\n" . '                        ';

		if ($rStream['type'] != 3) {
		} else {
			echo '                        d.created = true;' . "\r\n" . '                        ';
		}

		echo "\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n" . '                    ';

		if ($rStream['type'] == 1) {
			echo "\t\t\t\t\t" . '{"className": "dt-center", "targets": [3,4,5,6,9]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,2,7,8]}' . "\r\n" . '                    ';
		} else {
			if ($rStream['type'] == 2) {
				echo '                    {"className": "dt-center", "targets": [3,4,5,9]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,2,6,8]}' . "\r\n" . '                    ';
			} else {
				if ($rStream['type'] == 3) {
					echo '                    {"className": "dt-center", "targets": [3,4,5,6,8]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,2,7,9]}' . "\r\n" . '                    ';
				} else {
					if ($rStream['type'] == 4) {
						echo '                    {"className": "dt-center", "targets": [3,4,5,6,7]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,2,8,9]}' . "\r\n" . '                    ';
					} else {
						if ($rStream['type'] != 5) {
						} else {
							echo '                    {"className": "dt-center", "targets": [3,4,5,6,8]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,2,7,9]}' . "\r\n" . '                    ';
						}
					}
				}
			}
		}

		echo "\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'setTimeout(reloadStream, 5000);' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
