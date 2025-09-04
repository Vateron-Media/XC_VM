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
		echo '        var rClearing = false;' . "\r\n" . '        var rSelected = [];' . "\r\n\r\n" . '        function openImage(elem) {' . "\r\n" . '            var rImage = $(elem).data("src");' . "\r\n" . '            if (rImage) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . '                        src: rImage,' . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function viewSources(rTitle, rID) {' . "\r\n" . '            $("#datatable-sources").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "episodes";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rID;' . "\r\n" . '                        d.single = true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [4,5,6,9]},' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0,1,2,7,8]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-streams-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n\t\t" . 'function viewDuplicates(rTitle, rSource) {' . "\r\n" . '            $("#datatable-sources").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "episodes";' . "\r\n\t\t\t\t\t\t" . 'd.source_id = rSource;' . "\r\n" . '                        d.grouped = true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,5,6,8]},' . "\t\r\n\t\t\t\t\t" . '{"visible": false, "targets": [1,4,7,9]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-streams-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n" . '        function viewLiveConnections(rStreamID, rServerID=-1) {' . "\r\n" . '            $("#datatable-live").DataTable({' . "\r\n" . '                destroy: true,' . "\r\n\t\t\t\t" . 'ordering: true,' . "\r\n\t\t\t\t" . 'paging: true,' . "\r\n\t\t\t\t" . 'searching: true,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n\t\t\t\t" . 'bInfo: true,' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "live_connections";' . "\r\n\t\t\t\t\t\t" . 'd.stream_id = rStreamID;' . "\r\n" . '                        d.server_id = rServerID;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [1,7,8,9,10,11]},' . "\r\n" . '                    {"visible": false, "targets": [0,3,5,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n" . '            $(".bs-live-modal-center").modal("show");' . "\r\n" . '        }' . "\r\n" . '        function getStreamIDs() {' . "\r\n" . '            var rStreamIDs = [];' . "\r\n" . '            var rIndexes = [];' . "\r\n" . '            $("#datatable-streampage").DataTable().rows().every(function (rowIdx, tableLoop, rowLoop) {' . "\r\n" . '                rStreamIDs.push($($("#datatable-streampage").DataTable().row(rowIdx).data()[0]).text());' . "\r\n" . '                rIndexes.push(rowIdx);' . "\r\n" . '            });' . "\r\n" . '            return [rStreamIDs, rIndexes];' . "\r\n" . '        }' . "\r\n" . '        function refreshInformation() {' . "\r\n" . '            if (!window.rProcessing) {' . "\r\n" . '                var rUpdateColumns = [4,5,6,7,8];' . "\r\n" . '                var rStreamIDs = getStreamIDs();' . "\r\n" . '                if (rStreamIDs[0].length > 0) {' . "\r\n" . '                    $.getJSON("./table?" + $.param($("#datatable-streampage").DataTable().ajax.params()) + "&refresh=" + rStreamIDs[0].join(","), function(rTable) {' . "\r\n" . '                        if (!window.rProcessing) {' . "\r\n" . '                            $(rTable.data).each(function(rIndex, rItem) {' . "\r\n" . '                                for (i in rUpdateColumns) {' . "\r\n" . '                                    var rIndex = rStreamIDs[0].indexOf($(rItem[0]).text());' . "\r\n" . '                                    if (rIndex >= 0) {' . "\r\n" . "                                        if (\$('#datatable-streampage').DataTable().cell(rStreamIDs[1][rIndex], rUpdateColumns[i]).data() != rItem[rUpdateColumns[i]]) {" . "\r\n" . "                                            \$('#datatable-streampage').DataTable().cell(rStreamIDs[1][rIndex], rUpdateColumns[i]).data(rItem[rUpdateColumns[i]]);" . "\r\n" . '                                        }' . "\r\n" . '                                    }' . "\r\n" . '                                }' . "\r\n" . '                            });' . "\r\n" . '                            bindHref(); refreshTooltips(false);' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                }' . "\r\n" . '            }' . "\r\n" . '            clearTimeout(window.rRefresh);' . "\r\n" . '            window.rRefresh = setTimeout(refreshInformation, 5000);' . "\r\n" . '        }' . "\r\n" . '        function api(rID, rServerID, rType, rConfirm=false) {' . "\r\n" . '            if ((window.rSelected) && (window.rSelected.length > 0)) {' . "\r\n" . '                $.toast("Individual actions disabled in multi-select mode.");' . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "';
		echo $_['episode_delete_confirm'];
		echo '",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rServerID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rServerID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rServerID == "kill") && (!rConfirm)) {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '                rServerID = -1;' . "\r\n" . '                rType = "kill";' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=episode&sub=" + rType + "&stream_id=" + rID + "&server_id=" + rServerID, function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("';
		echo $_['episode_encoding_start'];
		echo '");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("';
		echo $_['episode_encoding_stop'];
		echo '");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("';
		echo $_['episode_deleted'];
		echo '");' . "\r\n" . '                            refreshTable();' . "\r\n" . '                        } else if (rType == "kill") {' . "\r\n" . '                            $.toast("Connection has been killed.");' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                        if ($(".bs-streams-modal-center").is(":visible")) {' . "\r\n" . '                            $("#datatable-sources").DataTable().ajax.reload( null, false );' . "\r\n" . '                        }' . "\r\n" . '                        if ($(".bs-live-modal-center").is(":visible")) {' . "\r\n" . '                            $("#datatable-live").DataTable().ajax.reload( null, false );' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function multiAPI(rType, rConfirm=false) {' . "\r\n" . '            if (rType == "clear") {' . "\r\n" . '                if ("#header_stats") {' . "\r\n" . '                    $("#header_stats").show();' . "\r\n" . '                }' . "\r\n" . '                window.rSelected = [];' . "\r\n" . '                $(".multiselect").hide();' . "\r\n" . "                \$(\"#datatable-streampage tr\").removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                return;' . "\r\n" . '            }' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete these episodes?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "purge") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all connections?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        multiAPI(rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=multi&type=episode&sub=" + rType + "&ids=" + JSON.stringify(window.rSelected), function(data) {' . "\r\n" . '                    if (data.result == true) {' . "\r\n" . '                        if (rType == "start") {' . "\r\n" . '                            $.toast("Episodes have started endoding.");' . "\r\n" . '                        } else if (rType == "stop") {' . "\r\n" . '                            $.toast("Episodes have stopped encoding.");' . "\r\n" . '                        } else if (rType == "restart") {' . "\r\n" . '                            $.toast("Episodes have been queued for re-encoding.");' . "\r\n" . '                        } else if (rType == "delete") {' . "\r\n" . '                            $.toast("Episodes have been deleted.");' . "\r\n" . '                            refreshTable();' . "\r\n" . '                        } else if (rType == "purge") {' . "\r\n" . '                            $.toast("Connections have been killed.");' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                }).fail(function() {' . "\r\n" . '                    $.toast("An error occured while processing your request.");' . "\r\n" . '                });' . "\r\n" . '                multiAPI("clear");' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n" . '        function player(rID, rContainer) {' . "\r\n" . '            $.magnificPopup.open({' . "\r\n" . '                items: {' . "\r\n" . '                    src: "./player?type=series&id=" + rID + "&container=" + rContainer,' . "\r\n" . "                    type: 'iframe'" . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        function refreshTable() {' . "\r\n\t\t\t" . '$("#datatable-streampage").DataTable().ajax.reload( null, false );' . "\r\n\t\t" . '}' . "\r\n" . '        function getSeries() {' . "\r\n" . '            return $("#episodes_series").val();' . "\r\n" . '        }' . "\r\n" . '        function getFilter() {' . "\r\n" . '            return $("#episodes_filter").val();' . "\r\n" . '        }' . "\r\n" . '        function getServer() {' . "\r\n" . '            return $("#episodes_server").val();' . "\r\n" . '        }' . "\r\n" . '        function getVideo() {' . "\r\n\t\t\t" . 'return $("#episodes_video").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getAudio() {' . "\r\n\t\t\t" . 'return $("#episodes_audio").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function getResolution() {' . "\r\n\t\t\t" . 'return $("#episodes_resolution").val();' . "\r\n\t\t" . '}' . "\r\n" . '        function clearFilters() {' . "\r\n" . '            window.rClearing = true;' . "\r\n" . "            \$(\"#episodes_search\").val(\"\").trigger('change');" . "\r\n" . "            \$('#episodes_filter').val(\"\").trigger('change');" . "\r\n" . "            \$('#episodes_server').val(\"\").trigger('change');" . "\r\n" . "            \$('#episodes_series').val(\"\").trigger('change');" . "\r\n" . "            \$('#episodes_audio').val(\"\").trigger('change');" . "\r\n" . "            \$('#episodes_video').val(\"\").trigger('change');" . "\r\n" . "            \$('#episodes_resolution').val(\"\").trigger('change');" . "\r\n" . "            \$('#episodes_show_entries').val(\"";
		echo (intval($rSettings['default_entries']) ?: 10);
		echo "\").trigger('change');" . "\r\n" . '            window.rClearing = false;' . "\r\n" . "            \$('#datatable-streampage').DataTable().search(\$(\"#episodes_search\").val());" . "\r\n" . "            \$('#datatable-streampage').DataTable().page.len(\$('#episodes_show_entries').val());" . "\r\n" . "            \$(\"#datatable-streampage\").DataTable().page(0).draw('page');" . "\r\n" . '            $("#datatable-streampage").DataTable().ajax.reload( null, false );' . "\r\n" . '            delParams(["search", "server", "filter", "series", "page", "entries", "video", "audio", "resolution"]);' . "\r\n\t\t\t" . 'checkClear();' . "\r\n" . '        }' . "\r\n" . '        function checkClear() {' . "\r\n\t\t\t" . 'if (!hasParams(["search", "server", "filter", "series", "video", "audio", "resolution"])) {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", true);' . "\r\n\t\t\t" . '} else {' . "\r\n\t\t\t\t" . '$("#clearFilters").prop("disabled", false);' . "\r\n\t\t\t" . '}' . "\r\n\t\t" . '}' . "\r\n" . '        function clearSeries() {' . "\r\n" . "            \$('#episodes_series').val(\"\").trigger('change');" . "\r\n" . '        }' . "\r\n" . '        function showModal() {' . "\r\n" . '            if ($("#episodes_series").val()) {' . "\r\n" . "                rOption = \$(\"<option selected='selected'></option>\").val(\$(\"#episodes_series\").val()).text(\$(\"#episodes_series\").text());" . "\r\n" . "                \$(\"#add_series_id\").append(rOption).trigger('change');" . "\r\n" . '            } else {' . "\r\n" . '                $("#add_series_id").val("").trigger("change");' . "\r\n" . '            }' . "\r\n" . "            \$('.addModal').modal('show');" . "\r\n" . '        }' . "\r\n" . '        function addEpisode() {' . "\r\n" . '            if ($("#add_series_id").val()) {' . "\r\n" . '                navigate("./episode?sid=" + $("#add_series_id").val());' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function addEpisodes() {' . "\r\n" . '            if ($("#add_series_id").val()) {' . "\r\n" . '                navigate("./episode?sid=" + $("#add_series_id").val() + "&multi");' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        var rSearch;' . "\r\n" . '        $(document).ready(function() {' . "\r\n" . "            \$('select').select2({width: '100%'});" . "\r\n" . "            \$('#episodes_series').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'serieslist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for a series...'" . "\r\n\t\t\t" . '});' . "\r\n" . "            \$('#add_series_id').select2({" . "\r\n\t\t\t" . '  ajax: {' . "\r\n\t\t\t\t" . "url: './api'," . "\r\n\t\t\t\t" . "dataType: 'json'," . "\r\n\t\t\t\t" . 'data: function (params) {' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'search: params.term,' . "\r\n\t\t\t\t\t" . "action: 'serieslist'," . "\r\n\t\t\t\t\t" . 'page: params.page' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'processResults: function (data, params) {' . "\r\n\t\t\t\t" . '  params.page = params.page || 1;' . "\r\n\t\t\t\t" . '  return {' . "\r\n\t\t\t\t\t" . 'results: data.items,' . "\r\n\t\t\t\t\t" . 'pagination: {' . "\r\n\t\t\t\t\t\t" . 'more: (params.page * 100) < data.total_count' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '  };' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'cache: true,' . "\r\n\t\t\t\t" . 'width: "100%"' . "\r\n\t\t\t" . '  },' . "\r\n\t\t\t" . "  placeholder: 'Search for a series...'" . "\r\n\t\t\t" . '});' . "\r\n" . '            var rPage = getParam("page");' . "\r\n" . '            if (!rPage) { rPage = 1; }' . "\r\n" . '            var rEntries = getParam("entries");' . "\r\n" . '            if (!rEntries) { rEntries = ';
		echo intval($rSettings['default_entries']);
		echo '; }' . "\r\n\t\t\t" . 'var rTable = $("#datatable-streampage").DataTable({' . "\r\n" . '                language: {' . "\r\n" . '                    paginate: {' . "\r\n" . "                        previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n" . "                        next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                drawCallback: function() {' . "\r\n" . '                    window.rProcessing = false;' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n" . '                    if ($("#datatable-streampage").DataTable().page.info().page > 0) {' . "\r\n" . '                        setParam("page", $("#datatable-streampage").DataTable().page.info().page+1);' . "\r\n" . '                    } else {' . "\r\n" . '                        delParam("page");' . "\r\n" . '                    }' . "\r\n" . '                    var rOrder = $("#datatable-streampage").DataTable().order()[0];' . "\r\n" . '                    setParam("order", rOrder[0]); setParam("dir", rOrder[1]);' . "\r\n" . '                    clearTimeout(window.rRefresh);' . "\r\n" . '                    if ($("#datatable-streampage").DataTable().rows().count() <= 50) {' . "\r\n" . '                        setTimeout(refreshInformation, 5000);' . "\r\n" . '                    }' . "\r\n" . '                    ';

		if (!hasPermissions('adv', 'edit_episode')) {
		} else {
			echo '                    // Multi Actions' . "\r\n" . '                    multiAPI("clear");' . "\r\n" . '                    $("#datatable-streampage tr").click(function() {' . "\r\n" . '                        if (window.rShiftHeld) {' . "\r\n" . "                            if (\$(this).hasClass('selectedfilter')) {" . "\r\n" . "                                \$(this).removeClass('selectedfilter').removeClass('ui-selected').removeClass(\"selected\");" . "\r\n" . '                                window.rSelected.splice($.inArray($(this).find("td:eq(0)").text(), window.rSelected), 1);' . "\r\n" . '                            } else {            ' . "\r\n" . "                                \$(this).addClass('selectedfilter').addClass('ui-selected').addClass(\"selected\");" . "\r\n" . '                                window.rSelected.push($(this).find("td:eq(0)").text());' . "\r\n" . '                            }' . "\r\n" . '                        }' . "\r\n" . '                        $("#multi_streams_selected").html(window.rSelected.length + " episodes");' . "\r\n" . '                        if (window.rSelected.length > 0) {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").hide();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_streams").show();' . "\r\n" . '                        } else {' . "\r\n" . '                            if ("#header_stats") {' . "\r\n" . '                                $("#header_stats").show();' . "\r\n" . '                            }' . "\r\n" . '                            $("#multiselect_streams").hide();' . "\r\n" . '                        }' . "\r\n" . '                    });' . "\r\n" . '                    ';
		}

		echo '                },' . "\r\n" . '                responsive: false,' . "\r\n" . '                processing: true,' . "\r\n" . '                serverSide: true,' . "\r\n" . '                searchDelay: 250,' . "\r\n" . '                ajax: {' . "\r\n" . '                    url: "./table",' . "\r\n" . '                    "data": function(d) {' . "\r\n" . '                        d.id = "episodes";' . "\r\n" . '                        d.series = getSeries();' . "\r\n" . '                        d.server = getServer();' . "\r\n" . '                        d.filter = getFilter();' . "\r\n" . '                        d.audio = getAudio();' . "\r\n" . '                        d.video = getVideo();' . "\r\n" . '                        d.resolution = getResolution();' . "\r\n" . '                    }' . "\r\n" . '                },' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"className": "dt-center", "targets": [0,1,4,5,6,7,8]},' . "\r\n" . '                    ';

		if (CoreUtilities::$rSettings['redis_handler']) {
			echo "\t\t\t\t\t" . '{"orderable": false, "targets": [1,4,6,7]},' . "\r\n\t\t\t\t\t";
		} else {
			echo "\t\t\t\t\t" . '{"orderable": false, "targets": [1,6,7]},' . "\r\n\t\t\t\t\t";
		}

		echo '                    ';

		if ($rSettings['show_images']) {
		} else {
			echo '                    {"visible": false, "targets": [1]}' . "\r\n" . '                    ';
		}

		echo '                ],' . "\r\n" . '                ';

		if (!$rMobile) {
		} else {
			echo 'scrollX: true,';
		}

		echo '                order: [[ ';
		echo (isset(CoreUtilities::$rRequest['order']) ? intval(CoreUtilities::$rRequest['order']) : 0);
		echo ', "';
		echo (in_array(strtolower(CoreUtilities::$rRequest['dir']), array('asc', 'desc')) ? strtolower(CoreUtilities::$rRequest['dir']) : 'desc');
		echo '" ]],' . "\r\n" . '                pageLength: parseInt(rEntries),' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000],' . "\r\n" . '                displayStart: (parseInt(rPage)-1) * parseInt(rEntries)' . "\r\n" . "            }).on('processing.dt', function (e, settings, processing) {" . "\r\n" . '                window.rProcessing = processing;' . "\r\n" . '            });' . "\r\n" . '            function doSearch(rValue) {' . "\r\n" . '                clearTimeout(window.rSearch); window.rSearch = setTimeout(function(){ rTable.search(rValue).draw(); }, 500);' . "\r\n" . '            }' . "\r\n" . '            $("#datatable-streampage").css("width", "100%");' . "\r\n" . "            \$('#episodes_search').keyup(function(){" . "\r\n" . '                if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#episodes_search").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("search", $("#episodes_search").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("search");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n" . '                    doSearch($(this).val());' . "\r\n" . '                }' . "\r\n" . '            })' . "\r\n" . "            \$('#episodes_show_entries').change(function(){" . "\r\n" . '                if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#episodes_show_entries").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("entries", $("#episodes_show_entries").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("entries");' . "\r\n\t\t\t\t\t" . '}' . "\r\n" . '                    rTable.page.len($(this).val()).draw();' . "\r\n" . '                }' . "\r\n" . '            })' . "\r\n" . "            \$('#episodes_series').change(function(){" . "\r\n" . '                if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#episodes_series").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("series", $("#episodes_series").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("series");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n" . '                    rTable.ajax.reload( null, false );' . "\r\n" . '                }' . "\r\n" . '            })' . "\r\n" . "            \$('#episodes_server').change(function(){" . "\r\n" . '                if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#episodes_server").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("server", $("#episodes_server").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("server");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n" . '                    rTable.ajax.reload( null, false );' . "\r\n" . '                }' . "\r\n" . '            })' . "\r\n" . "            \$('#episodes_filter').change(function(){" . "\r\n" . '                if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#episodes_filter").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("filter", $("#episodes_filter").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("filter");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n" . '                    rTable.ajax.reload( null, false );' . "\r\n" . '                }' . "\r\n" . '            })' . "\r\n" . "            \$('#episodes_audio').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#episodes_audio").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("audio", $("#episodes_audio").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("audio");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#episodes_video').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#episodes_video").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("video", $("#episodes_video").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("video");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n" . "            \$('#episodes_resolution').change(function(){" . "\r\n\t\t\t\t" . 'if (!window.rClearing) {' . "\r\n" . '                    delParam("page");' . "\r\n" . '                    rTable.page(0);' . "\r\n" . '                    if ($("#episodes_resolution").val()) {' . "\r\n\t\t\t\t\t\t" . 'setParam("resolution", $("#episodes_resolution").val());' . "\r\n\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t" . 'delParam("resolution");' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . 'checkClear();' . "\r\n\t\t\t\t\t" . 'rTable.ajax.reload( null, false );' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '})' . "\r\n" . "            if (\$('#episodes_search').val()) {" . "\r\n" . "                rTable.search(\$('#episodes_search').val()).draw();" . "\r\n" . '            }' . "\r\n" . '            $("#btn-export-csv").click(function() {' . "\r\n" . '                $.toast("Generating CSV report...");' . "\r\n" . '                window.location.href = "api?action=report&params=" + encodeURIComponent(JSON.stringify($("#datatable-streampage").DataTable().ajax.params()));' . "\r\n\t\t\t" . '});' . "\r\n" . '            checkClear();' . "\r\n" . '        });' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
