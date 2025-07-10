<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$rRange = (intval(CoreUtilities::$rRequest['range']) ?: 0);
$rTheftDetection = (igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'theft_detection')) ?: array());
$_TITLE = 'VOD Theft Detection';
include 'header.php';
echo '<div class="wrapper boxed-layout-ext"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\r\n" . '    <div class="container-fluid">' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="page-title-box">' . "\r\n" . '                    <div class="page-title-right">' . "\r\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t" . '<h4 class="page-title">VOD Theft Detection</h4>' . "\r\n\t\t\t\t" . '</div>' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>     ' . "\r\n\t\t" . '<div class="row">' . "\r\n\t\t\t" . '<div class="col-12">' . "\r\n\t\t\t\t" . '<div class="card">' . "\r\n\t\t\t\t\t" . '<div class="card-body" style="overflow-x:auto;">' . "\r\n\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\r\n\t\t\t\t\t\t\t" . '<div class="col-md-7">' . "\r\n\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="log_search" value="" placeholder="Search Logs...">' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '<div class="col-md-3">' . "\r\n\t\t\t\t\t\t\t\t" . '<select id="range" class="form-control" data-toggle="select2">' . "\r\n" . '                                    <option value="0"';

if ($rRange != 0) {
} else {
	echo ' selected';
}

echo '>All Time</option>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<option value="604800"';

if ($rRange != 604800) {
} else {
	echo ' selected';
}

echo '>Last 7 Days</option>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<option value="86400"';

if ($rRange != 86400) {
} else {
	echo ' selected';
}

echo '>Last 24 Hours</option>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<option value="3600"';

if ($rRange != 3600) {
} else {
	echo ' selected';
}

echo '>Last Hour</option>' . "\r\n\t\t\t\t\t\t\t\t" . '</select>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\r\n\t\t\t\t\t\t\t\t" . '<select id="show_entries" class="form-control" data-toggle="select2">' . "\r\n\t\t\t\t\t\t\t\t\t";

foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) {
	echo "\t\t\t\t\t\t\t\t\t" . '<option';

	if ($rSettings['default_entries'] != $rShow) {
	} else {
		echo ' selected';
	}

	echo ' value="';
	echo $rShow;
	echo '">';
	echo $rShow;
	echo '</option>' . "\r\n\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t" . '</select>' . "\r\n\t\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '</div>' . "\r\n\t\t\t\t\t\t" . '<table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">' . "\r\n\t\t\t\t\t\t\t" . '<thead>' . "\r\n\t\t\t\t\t\t\t\t" . '<tr>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">User ID</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th>Username</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">View Count</th>' . "\r\n\t\t\t\t\t\t\t\t\t" . '<th class="text-center">Actions</th>' . "\r\n\t\t\t\t\t\t\t\t" . '</tr>' . "\r\n\t\t\t\t\t\t\t" . '</thead>' . "\r\n\t\t\t\t\t\t\t" . '<tbody>' . "\r\n" . '                                ';

foreach ($rTheftDetection[$rRange] as $rRow) {
	if ($rRange == 0) {
		$rButtons = '<a href="line_activity?search=' . $rRow['username'] . '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs">View Logs</button></a>';
	} else {
		$rDates = date($rSettings['date_format'], time() - intval($rRange)) . ' - ' . date($rSettings['date_format'], time());
		$rButtons = '<a href="line_activity?search=' . $rRow['username'] . '&dates=' . $rDates . '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs">View Logs</button></a>';
	}

	if (hasPermissions('adv', 'edit_user')) {
		$rID = "<a href='line?id=" . $rRow['user_id'] . "'>" . $rRow['user_id'] . '</a>';
		$rUsername = "<a href='line?id=" . $rRow['user_id'] . "'>" . $rRow['username'] . '</a>';
	} else {
		$rID = $rRow['user_id'];
		$rUsername = $rRow['username'];
	}

	echo '                                    <tr>' . "\r\n" . '                                        <td class="text-center">';
	echo $rID;
	echo '</td>' . "\r\n" . '                                        <td>';
	echo $rUsername;
	echo '</td>' . "\r\n" . '                                        <td class="text-center">';
	echo $rRow['vod_count'];
	echo '</td>' . "\r\n" . '                                        <td class="text-center">';
	echo $rButtons;
	echo '</td>' . "\r\n" . '                                    </tr>' . "\r\n" . '                                ';
}
echo '                            </tbody>' . "\r\n\t\t\t\t\t\t" . '</table>' . "\r\n\t\t\t\t\t" . '</div> ' . "\r\n\t\t\t\t" . '</div> ' . "\r\n\t\t\t" . '</div>' . "\r\n\t\t" . '</div>' . "\r\n\t" . '</div>' . "\r\n" . '</div>' . "\r\n";
include 'footer.php';
