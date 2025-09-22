<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
	goHome();
}

$rCategories = getCategories('live');
$rStreamArguments = getStreamArguments();
$rTranscodeProfiles = getTranscodeProfiles();
$rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));

foreach ($rServers as $rServer) {
	$rServerTree[] = array('id' => intval($rServer['id']), 'parent' => 'offline', 'text' => htmlspecialchars($rServer['server_name']), 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
}
$_TITLE = 'Mass Edit Streams';
include 'header.php';
echo '<div class="wrapper boxed-layout-xl"';

if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
} else {
	echo ' style="display: none;"';
}

echo '>' . "\n" . '    <div class="container-fluid">' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t" . '<div class="page-title-box">' . "\n\t\t\t\t\t" . '<div class="page-title-right">' . "\n" . '                        ';
include 'topbar.php';
echo "\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t" . '<h4 class="page-title">Mass Edit Streams <small id="selected_count"></small></h4>' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t" . '</div>' . "\n\t\t" . '</div>     ' . "\n\t\t" . '<div class="row">' . "\n\t\t\t" . '<div class="col-xl-12">' . "\n\t\t\t\t";

if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
} else {
	echo "\t\t\t\t" . '<div class="alert alert-success alert-dismissible fade show" role="alert">' . "\n\t\t\t\t\t" . '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' . "\n\t\t\t\t\t\t" . '<span aria-hidden="true">&times;</span>' . "\n\t\t\t\t\t" . '</button>' . "\n\t\t\t\t\t" . 'Mass edit of streams was successfully executed!' . "\n\t\t\t\t" . '</div>' . "\n\t\t\t\t";
}

echo "\t\t\t\t" . '<div class="card">' . "\n\t\t\t\t\t" . '<div class="card-body">' . "\n\t\t\t\t\t\t" . '<form action="#" method="POST">' . "\n\t\t\t\t\t\t\t" . '<input type="hidden" name="server_tree_data" id="server_tree_data" value="" />' . "\n" . '                            <input type="hidden" name="od_tree_data" id="od_tree_data" value="" />' . "\n\t\t\t\t\t\t\t" . '<input type="hidden" name="streams" id="streams" value="" />' . "\n\t\t\t\t\t\t\t" . '<div id="basicwizard">' . "\n\t\t\t\t\t\t\t\t" . '<ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#stream-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-play mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Streams</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2"> ' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-account-card-details-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Details</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#auto-restart" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-clock-outline mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Auto Restart</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t" . '<li class="nav-item">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-server-network mr-1"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<span class="d-none d-sm-inline">Servers</span>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</a>' . "\n\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t" . '<div class="tab-content b-0 mb-0 pt-0">' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="stream-selection">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2 col-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" class="form-control" id="stream_search" value="" placeholder="Search Streams...">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                            <div class="col-md-3 col-6">' . "\n" . '                                                <select id="stream_server_id" class="form-control" data-toggle="select2">' . "\n" . '                                                    <option value="" selected>All Servers</option>' . "\n" . '                                                    <option value="-1">No Servers</option>' . "\n" . '                                                    ';

foreach (getStreamingServers() as $rServer) {
	echo '                                                    <option value="';
	echo intval($rServer['id']);
	echo '">';
	echo $rServer['server_name'];
	echo '</option>' . "\n" . '                                                    ';
}
echo '                                                </select>' . "\n" . '                                            </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-3 col-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="category_search" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="" selected>All Categories</option>' . "\n" . '                                                    <option value="-1">No Categories</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach ($rCategories as $rCategory) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo intval($rCategory['id']);
	echo '"';

	if (!(isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id'])) {
	} else {
		echo ' selected';
	}

	echo '>';
	echo htmlspecialchars($rCategory['category_name']);
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                            <div class="col-md-2 col-6">' . "\n" . '                                                <select id="stream_filter" class="form-control" data-toggle="select2">' . "\n" . '                                                    <option value="" selected>No Filter</option>' . "\n" . '                                                    <option value="1">Online</option>' . "\n" . '                                                    <option value="2">Down</option>' . "\n" . '                                                    <option value="3">Stopped</option>' . "\n" . '                                                    <option value="4">Starting</option>' . "\n" . '                                                    <option value="5">On Demand</option>' . "\n" . '                                                    <option value="6">Direct</option>' . "\n" . '                                                    <option value="7">Timeshift</option>' . "\n" . '                                                    <option value="8">Looping</option>' . "\n" . '                                                    <option value="9">Has EPG</option>' . "\n" . '                                                    <option value="10">No EPG</option>' . "\n" . '                                                    <option value="11">Adaptive Link</option>' . "\n" . '                                                    <option value="12">Title Sync</option>' . "\n" . '                                                    <option value="13">Transcoding</option>' . "\n" . '                                                </select>' . "\n" . '                                            </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-1 col-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<select id="show_entries" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option';

	if ($rSettings['default_entries'] != $rShow) {
	} else {
		echo ' selected';
	}

	echo ' value="';
	echo $rShow;
	echo '">';
	echo $rShow;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-1 col-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleStreams()" style="width: 100%">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<i class="mdi mdi-selection"></i>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</button>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<table id="datatable-mass" class="table table-borderless mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<thead class="bg-light">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th class="text-center">ID</th>' . "\n" . '                                                        <th class="text-center">Icon</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>Stream Name</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<th>Category</th>' . "\n" . '                                                        <th>Server</th>' . "\n" . '                                                        <th class="text-center">Status</th>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</tr>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</thead>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<tbody></tbody>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</table>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="stream-details">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="category_id" name="c_category_id">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="category_id">Select Categories</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select disabled name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach ($rCategories as $rCategory) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo intval($rCategory['id']);
	echo '">';
	echo htmlspecialchars($rCategory['category_name']);
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <div class="col-md-2">' . "\n" . '                                                        <select disabled name="category_id_type" id="category_id_type" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (array('SET', 'ADD', 'DEL') as $rType) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rType;
	echo '">';
	echo $rType;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n" . '                                                    </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="bouquets" name="c_bouquets">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="bouquets">Select Bouquets</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-6">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select disabled name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (getBouquets() as $rBouquet) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo intval($rBouquet['id']);
	echo '">';
	echo htmlspecialchars($rBouquet['bouquet_name']);
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <div class="col-md-2">' . "\n" . '                                                        <select disabled name="bouquets_type" id="bouquets_type" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (array('SET', 'ADD', 'DEL') as $rType) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rType;
	echo '">';
	echo $rType;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n" . '                                                    </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="gen_timestamps" data-type="switch" name="c_gen_timestamps">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="gen_timestamps">Generate PTS</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="gen_timestamps" id="gen_timestamps" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="read_native">Native Frames</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="read_native" id="read_native" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="read_native" data-type="switch" name="c_read_native">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="stream_all" data-type="switch" name="c_stream_all">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="stream_all">Stream All Codecs</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="stream_all" id="stream_all" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="allow_record">Allow Recording</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="allow_record" id="allow_record" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="allow_record" data-type="switch" name="c_allow_record">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="direct_source" data-type="switch" name="c_direct_source">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="direct_source">Direct Source</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="direct_source" id="direct_source" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="direct_proxy">Direct Stream</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="direct_proxy" id="direct_proxy" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="direct_proxy" data-type="switch" name="c_direct_proxy">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="delay_minutes" name="c_delay_minutes">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="delay_minutes">Minute Delay</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" disabled class="form-control text-center" id="delay_minutes" name="delay_minutes" value="0">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="probesize_ondemand">On Demand Probesize</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" disabled class="form-control text-center" id="probesize_ondemand" name="probesize_ondemand" value="128000">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="probesize_ondemand" name="c_probesize_ondemand">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="fps_restart" data-type="switch" name="c_fps_restart">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="fps_restart">Restart on FPS Drop</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="fps_restart" id="fps_restart" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="fps_threshold">FPS Threshold %</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" disabled class="form-control text-center" id="fps_threshold" name="fps_threshold" value="90">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="fps_threshold" name="c_fps_threshold">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n" . '                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="rtmp_output" data-type="switch" name="c_rtmp_output">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="rtmp_output">Output RTMP</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="rtmp_output" id="rtmp_output" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd"/>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="custom_sid">Custom Channel SID</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" disabled class="form-control" id="custom_sid" name="custom_sid" value="">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="custom_sid" name="c_custom_sid">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="user_agent" name="c_user_agent">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="user_agent">User Agent</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" disabled class="form-control" id="user_agent" name="user_agent" value="';
echo htmlspecialchars($rStreamArguments['user_agent']['argument_default_value']);
echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="http_proxy" name="c_http_proxy">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="http_proxy">HTTP Proxy</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" disabled class="form-control" id="http_proxy" name="http_proxy" value="';
echo htmlspecialchars($rStreamArguments['proxy']['argument_default_value']);
echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="cookie" name="c_cookie">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="cookie">Cookie</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" disabled class="form-control" id="cookie" name="cookie" value="';
echo htmlspecialchars($rStreamArguments['cookie']['argument_default_value']);
echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="headers" name="c_headers">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="headers">Headers</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="text" disabled class="form-control" id="headers" name="headers" value="';
echo htmlspecialchars($rStreamArguments['headers']['argument_default_value']);
echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="transcode_profile_id" name="c_transcode_profile_id">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="transcode_profile_id">Transcoding Profile</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="transcode_profile_id" disabled id="transcode_profile_id" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option selected value="0">Transcoding Disabled</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach ($rTranscodeProfiles as $rProfile) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo intval($rProfile['profile_id']);
	echo '">';
	echo $rProfile['profile_name'];
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="auto-restart">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="days_to_restart" name="c_days_to_restart">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="days_to_restart">Days to Restart</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
$rAutoRestart = array('days' => array(), 'at' => '06:00');
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select disabled id="days_to_restart" name="days_to_restart[]" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose ...">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (array('Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday') as $rDay) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rDay;
	echo '">';
	echo $rDay;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-1"></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="time_to_restart">Time to Restart</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group clockpicker" data-placement="top" data-align="top" data-autoclose="true">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input disabled id="time_to_restart" name="time_to_restart" type="text" class="form-control" value="';
echo $rAutoRestart['at'];
echo '">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="input-group-append">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<span class="input-group-text"><i class="mdi mdi-clock-outline"></i></span>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Next</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t" . '<div class="tab-pane" id="load-balancing">' . "\n\t\t\t\t\t\t\t\t\t\t" . '<div class="row">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-12">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" data-name="server_tree" class="activate" name="c_server_tree" id="c_server_tree">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="server_tree">Server Tree</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div id="server_tree"></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <div class="col-md-1"></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="server_type">Server Type</label>' . "\n" . '                                                    <div class="col-md-2">' . "\n" . '                                                        <select disabled name="server_type" id="server_type" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (array('SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED') as $rValue => $rType) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rValue;
	echo '">';
	echo $rType;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n" . '                                                    </div>' . "\n" . '                                                </div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <div class="col-md-1"></div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="on_demand">On-Demand Servers</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n" . '                                                        <select disabled name="on_demand[]" id="on_demand" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach ($rServers as $rServer) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rServer['id'];
	echo '">';
	echo $rServer['server_name'];
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="tv_archive_server_id" name="c_tv_archive_server_id">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="tv_archive_server_id">Timeshift Server</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select disabled name="tv_archive_server_id" id="tv_archive_server_id" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="">Timeshift Disabled</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach ($rServers as $rServer) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo intval($rServer['id']);
	echo '">';
	echo htmlspecialchars($rServer['server_name']);
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="vframes_server_id" name="c_vframes_server_id">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label class="col-md-3 col-form-label" for="vframes_server_id">Thumbnail Server</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-8">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select disabled name="vframes_server_id" id="vframes_server_id" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="">Thumbnails Disabled</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach ($rServers as $rServer) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo intval($rServer['id']);
	echo '">';
	echo htmlspecialchars($rServer['server_name']);
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="form-group row mb-4">' . "\n" . '                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="llod" data-type="switch" name="c_llod">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <label class="col-md-3 col-form-label" for="llod">Low Latency On-Demand</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<select name="llod" id="llod" class="form-control" data-toggle="select2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";

foreach (array('Disabled', 'FFMPEG', 'PHP') as $rValue => $rText) {
	echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<option value="';
	echo $rValue;
	echo '">';
	echo $rText;
	echo '</option>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t\t";
}
echo "\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</select>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <label class="col-md-3 col-form-label" for="tv_archive_duration">Timeshift Days</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input disabled type="text" class="form-control text-center" id="tv_archive_duration" name="tv_archive_duration" value="0" />' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input type="checkbox" class="activate" data-name="tv_archive_duration" name="c_tv_archive_duration">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<label></label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                <div class="form-group row mb-4">' . "\n" . '                                                    <div class="col-md-1"></div>' . "\n" . '                                                    <label class="col-md-3 col-form-label" for="restart_on_edit">Restart on Edit</label>' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<div class="col-md-2">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />' . "\n\t\t\t\t\t\t\t\t\t\t\t\t\t" . '</div>' . "\n" . '                                                </div>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t\t\t\t" . '<ul class="list-inline wizard mb-0">' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="prevb list-inline-item">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<a href="javascript: void(0);" class="btn btn-secondary">Previous</a>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '<li class="nextb list-inline-item float-right">' . "\n\t\t\t\t\t\t\t\t\t\t\t\t" . '<input name="submit_stream" type="submit" class="btn btn-primary" value="Edit Streams" />' . "\n\t\t\t\t\t\t\t\t\t\t\t" . '</li>' . "\n\t\t\t\t\t\t\t\t\t\t" . '</ul>' . "\n\t\t\t\t\t\t\t\t\t" . '</div>' . "\n\t\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t\t\t" . '</form>' . "\n\t\t\t\t\t" . '</div> ' . "\n\t\t\t\t" . '</div> ' . "\n\t\t\t" . '</div> ' . "\n\t\t" . '</div>' . "\n\t" . '</div>' . "\n" . '</div>' . "\n";
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

			<?php if (CoreUtilities::$rSettings['enable_search']): ?>
				$(document).ready(function() {
					initSearch();
				});

			<?php endif; 
		echo '        ' . "\r\n\t\t" . 'var rSelected = [];' . "\r\n\r\n\t\t" . 'function getCategory() {' . "\r\n\t\t\t" . 'return $("#category_search").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getServer() {' . "\r\n" . '            return $("#stream_server_id").val();' . "\r\n" . '        }' . "\r\n" . '        function getFilter() {' . "\r\n\t\t\t" . 'return $("#stream_filter").val();' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function toggleStreams() {' . "\r\n\t\t\t" . '$("#datatable-mass tr").each(function() {' . "\r\n\t\t\t\t" . "if (\$(this).hasClass('selected')) {" . "\r\n\t\t\t\t\t" . "\$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t" . "\$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . 'if ($(this).find("td:eq(0)").text()) {' . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t" . '}' . "\r\n" . '        function evaluateServers() {' . "\r\n" . '            var rOVal = $("#on_demand").val();' . "\r\n" . '            $("#on_demand").empty();' . "\r\n" . "            \$(\$('#server_tree').jstree(true).get_json('source', {flat:true})).each(function(index, value) {" . "\r\n" . '                if (value.parent != "#") {' . "\r\n" . '                    $("#on_demand").append(new Option(value.text, value.id));' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#on_demand").val(rOVal).trigger("change");' . "\r\n" . '            if (!$("#on_demand").val()) {' . "\r\n" . '                $("#on_demand").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'})" . "\r\n\t\t\t" . "var elems = Array.prototype.slice.call(document.querySelectorAll('.js-switch'));" . "\r\n\t\t\t" . 'elems.forEach(function(html) {' . "\r\n\t\t\t\t" . 'if ($(html).attr("id") != "restart_on_edit") {' . "\r\n\t\t\t\t\t" . 'window.rSwitches[$(html).attr("id")].disable();' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#server_tree').on('redraw.jstree', function (e, data) {" . "\r\n" . '                evaluateServers();' . "\r\n" . "            }).on('select_node.jstree', function (e, data) {" . "\r\n" . '                $("#c_server_tree").prop("checked", true);' . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("input[type=checkbox].activate").change(function() {' . "\r\n\t\t\t\t" . 'if ($(this).is(":checked")) {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].enable();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", false);' . "\r\n\t\t\t\t\t\t" . 'if ($(this).data("name") == "days_to_restart") {' . "\r\n\t\t\t\t\t\t\t" . '$("#time_to_restart").prop("disabled", false);' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n" . '                        if ($(this).data("name") == "server_tree") {' . "\r\n" . '                            $("#on_demand").prop("disabled", false);' . "\r\n" . '                            $("#server_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "category_id") {' . "\r\n" . '                            $("#category_id_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "bouquets") {' . "\r\n" . '                            $("#bouquets_type").prop("disabled", false);' . "\r\n" . '                        }' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . 'if ($(this).data("type") == "switch") {' . "\r\n\t\t\t\t\t\t" . 'window.rSwitches[$(this).data("name")].disable();' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . '$("#" + $(this).data("name")).prop("disabled", true);' . "\r\n\t\t\t\t\t\t" . 'if ($(this).data("name") == "days_to_restart") {' . "\r\n\t\t\t\t\t\t\t" . '$("#time_to_restart").prop("disabled", true);' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n" . '                        if ($(this).data("name") == "server_tree") {' . "\r\n" . '                            $("#on_demand").prop("disabled", true);' . "\r\n" . '                            $("#server_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "category_id") {' . "\r\n" . '                            $("#category_id_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n" . '                        if ($(this).data("name") == "bouquets") {' . "\r\n" . '                            $("#bouquets_type").prop("disabled", true);' . "\r\n" . '                        }' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$(".clockpicker").clockpicker();' . "\r\n\t\t\t" . '$("#probesize_ondemand").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#delay_minutes").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#tv_archive_duration").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . 'rTable = $("#datatable-mass").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    $("#datatable-mass a").removeAttr("href");' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "stream_list",' . "\r\n\t\t\t\t\t\t" . 'd.category = getCategory(),' . "\r\n" . '                        d.filter = getFilter(),' . "\r\n" . '                        d.server = getServer()' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,5]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . '"rowCallback": function(row, data) {' . "\r\n\t\t\t\t\t" . 'if ($.inArray(data[0], window.rSelected) !== -1) {' . "\r\n\t\t\t\t\t\t" . "\$(row).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n" . '                order: [[ 0, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#stream_search').keyup(function(){" . "\r\n\t\t\t\t" . 'rTable.search($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#show_entries').change(function(){" . "\r\n\t\t\t\t" . 'rTable.page.len($(this).val()).draw();' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#stream_filter').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#stream_server_id').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . "\$('#category_search').change(function(){" . "\r\n\t\t\t\t" . 'rTable.ajax.reload(null, false);' . "\r\n\t\t\t" . '})' . "\r\n\t\t\t" . '$("#datatable-mass").selectable({' . "\r\n\t\t\t\t" . "filter: 'tr'," . "\r\n\t\t\t\t" . 'selected: function (event, ui) {' . "\r\n\t\t\t\t\t" . "if (\$(ui.selected).hasClass('selectedfilter')) {" . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.splice($.inArray($(ui.selected).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n\t\t\t\t\t" . '} else {            ' . "\r\n\t\t\t\t\t\t" . "\$(ui.selected).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n\t\t\t\t\t\t" . 'window.rSelected.push($(ui.selected).find("td:eq(0)").text());' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '$("#selected_count").html(" - " + window.rSelected.length + " selected")' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n\t\t\t\t" . "\$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n\t\t\t\t" . 'rPass = false;' . "\r\n" . '                rSubmit = true;' . "\r\n\t\t\t\t" . "\$.each(\$('#server_tree').jstree(true).get_json('#', {flat:true}), function(k,v) {" . "\r\n\t\t\t\t\t" . 'if (v.parent == "source") {' . "\r\n\t\t\t\t\t\t" . 'rPass = true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t\t" . '$("#streams").val(JSON.stringify(window.rSelected));' . "\r\n\t\t\t\t" . 'if (window.rSelected.length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("Select at least one stream to edit.");' . "\r\n" . '                    rSubmit = false;' . "\r\n\t\t\t\t" . '}' . "\r\n" . '                if (rSubmit) {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n\t\t\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
