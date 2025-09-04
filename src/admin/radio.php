<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (isset(CoreUtilities::$rRequest['id'])) {
    $rStation = getStream(CoreUtilities::$rRequest['id']);
    if (!$rStation || $rStation['type'] != 4) {
        goHome();
    }
}

$rOnDemand = array();
$rStationArguments = getStreamArguments();
$rServerTree = array(
    array(
        'id' => 'source',
        'parent' => '#',
        'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Online</strong>",
        'icon' => 'mdi mdi-play',
        'state' => array('opened' => true)
    ),
    array(
        'id' => 'offline',
        'parent' => '#',
        'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>",
        'icon' => 'mdi mdi-stop',
        'state' => array('opened' => true)
    )
);

if (isset($rStation)) {
    $rStationOptions = getStreamOptions(CoreUtilities::$rRequest['id']);
    $rStationSys = getStreamSys(CoreUtilities::$rRequest['id']);

    foreach ($rServers as $rServer) {
        if (isset($rStationSys[intval($rServer['id'])])) {
            $rParent = ($rStationSys[intval($rServer['id'])]['parent_id'] != 0) ? intval($rStationSys[intval($rServer['id'])]['parent_id']) : 'source';
            if ($rStationSys[intval($rServer['id'])]['on_demand']) {
                $rOnDemand[] = intval($rServer['id']);
            }
        } else {
            $rParent = 'offline';
        }

        $rServerTree[] = array(
            'id' => $rServer['id'],
            'parent' => $rParent,
            'text' => $rServer['server_name'],
            'icon' => 'mdi mdi-server-network',
            'state' => array('opened' => true)
        );
    }
} else {
    foreach ($rServers as $rServer) {
        $rServerTree[] = array(
            'id' => $rServer['id'],
            'parent' => 'offline',
            'text' => $rServer['server_name'],
            'icon' => 'mdi mdi-server-network',
            'state' => array('opened' => true)
        );
    }
}

$_TITLE = 'Radio Stations';
include 'header.php';
?>

<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                        echo ' style="display: none;"';
                                    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <ol class="breadcrumb m-0">
                            <li>
                                <a href="./radios">
                                    <button type="button" class="btn btn-primary waves-effect waves-light btn-sm">
                                        <?= $_['view_stations']; ?>
                                    </button>
                                </a>
                            </li>
                        </ol>
                    </div>
                    <h4 class="page-title"><?= isset($rStation['id']) ? $rStation['stream_display_name'] : $_['add_radio_station']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (isset($_STATUS)) { ?>
                    <div class="alert alert-<?= $_STATUS == 0 ? "success" : "danger"; ?> alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <?= $_STATUS == 0 ? $_['radio_success'] : ($_STATUS == 1 ? $_['radio_info_1'] : $_['radio_info_2']); ?>
                    </div>
                <?php } ?>

                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST" data-parsley-validate="">
                            <?php if (isset($rStation['id'])) { ?>
                                <input type="hidden" name="edit" value="<?= $rStation['id']; ?>" />
                            <?php } ?>
                            <input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
                            <input type="hidden" name="od_tree_data" id="od_tree_data" value="" />
                            <input type="hidden" name="bouquet_create_list" id="bouquet_create_list" value="" />
                            <input type="hidden" name="category_create_list" id="category_create_list" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $_['details']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#advanced-options" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-folder-alert-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $_['advanced']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#auto-restart" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-clock-outline mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $_['auto_restart']; ?></span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-server-network mr-1"></i>
                                            <span class="d-none d-sm-inline"><?= $_['servers']; ?></span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="stream_display_name"><?= $_['station_name']; ?></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="stream_display_name" name="stream_display_name" value="<?= isset($rStation) ? htmlspecialchars($rStation['stream_display_name']) : ''; ?>" required data-parsley-trigger="change">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="stream_icon">Station Logo</label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" class="form-control" id="stream_icon" name="stream_icon" value="<?= isset($rStation) ? htmlspecialchars($rStation['stream_icon']) : ''; ?>">
                                                        <div class="input-group-append">
                                                            <a href="javascript:void(0)" onclick="openImage(this)" class="btn btn-primary waves-effect waves-light"><i class="mdi mdi-eye"></i></a>
                                                        </div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4 stream-url">
                                                    <label class="col-md-4 col-form-label" for="stream_source"><?= $_['station_url']; ?></label>
                                                    <div class="col-md-8 input-group">
                                                        <input type="text" id="stream_source" name="stream_source[]" class="form-control" value="<?= isset($rStation) ? htmlspecialchars(json_decode($rStation['stream_source'], true)[0]) : ''; ?>">
                                                    </div>
                                                </div>
                                                <!-- Additional fields for categories, bouquets, notes, etc. go here -->
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="category_id">Categories</label>
                                                    <div class="col-md-8">
                                                        <select name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getCategories('radio') as $rCategory): ?>
                                                                <option <?php if (isset($rStation) && in_array(intval($rCategory['id']), json_decode($rStation['category_id'], true))) {
                                                                            echo 'selected';
                                                                        } ?> value="<?= $rCategory['id']; ?>"><?= $rCategory['category_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div id="category_create" class="alert bg-dark text-white border-0 mt-2 mb-0" role="alert" style="display: none;">
                                                            <strong>New Categories:</strong> <span id="category_new"></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="bouquets"><?= $_['bouquets']; ?></label>
                                                    <div class="col-md-8">
                                                        <select name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getBouquets() as $rBouquet): ?>
                                                                <option <?php if (isset($rStation) && in_array($rStation['id'], json_decode($rBouquet['bouquet_radios'], true))) {
                                                                            echo 'selected';
                                                                        } ?> value="<?= $rBouquet['id']; ?>"><?= htmlspecialchars($rBouquet['bouquet_name']); ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                        <div id="bouquet_create" class="alert bg-dark text-white border-0 mt-2 mb-0" role="alert" style="display: none;">
                                                            <strong>New Bouquets:</strong> <span id="bouquet_new"></span>
                                                        </div>
                                                    </div>
                                                </div>

                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="notes"><?= $_['notes']; ?></label>
                                                    <div class="col-md-8">
                                                        <textarea id="notes" name="notes" class="form-control" rows="3" placeholder=""><?php if (isset($rStation)) {
                                                                                                                                            echo htmlspecialchars($rStation['notes']);
                                                                                                                                        } ?></textarea>
                                                    </div>
                                                </div>
                                                <ul class="list-inline wizard mb-0">
                                                    <li class="nextb list-inline-item float-right">
                                                        <a href="javascript: void(0);" class="btn btn-secondary"><?= $_['next']; ?></a>
                                                    </li>
                                                </ul>
                                            </div>
                                        </div>
                                        <!-- Pagination and submission buttons go here -->
                                    </div>

                                    <!-- Additional tabs content (advanced-options, auto-restart, load-balancing) go here -->
                                    <div class="tab-pane" id="advanced-options">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="direct_source"><?= $_['direct_source']; ?> <i title="Don't run source through XC_VM, just redirect instead." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="direct_source" id="direct_source" type="checkbox" <?php if (isset($rStation) && $rStation['direct_source'] == 1) {
                                                                                                                            echo 'checked';
                                                                                                                        } ?> data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="probesize_ondemand"><?= $_['on_demand_probesize']; ?> <i title="Adjustable probesize for ondemand streams. Adjust this setting if you experience issues with no audio." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control" id="probesize_ondemand" name="probesize_ondemand" value="<?php if (isset($rStation)) {
                                                                                                                                                                echo htmlspecialchars($rStation['probesize_ondemand']);
                                                                                                                                                            } else {
                                                                                                                                                                echo '128000';
                                                                                                                                                            } ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="custom_sid"><?= $_['custom_channel_sid']; ?> <i title="Here you can specify the SID of the channel in order to work with the epg on the enigma2 devices. You have to specify the code with the ':' but without the first number, 1 or 4097. Example: if we have this code: '1:0:1:13f:157c:13e:820000:0:0:0:2097' then you have to add on this field: ':0:1:13f:157c:13e:820000:0:0:0:'" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="custom_sid" name="custom_sid" value="<?php if (isset($rStation)) {
                                                                                                                                                echo htmlspecialchars($rStation['custom_sid']);
                                                                                                                                            } ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="custom_ffmpeg"><?= $_['custom_ffmpeg_command']; ?> <i title="In this field you can write your own custom FFmpeg command. Please note that this command will be placed after the input and before the output. If the command you will specify here is about to do changes in the output video or audio, it may require to transcode the stream. In this case, you have to use and change at least the Video/Audio Codecs using the transcoding attributes below. The custom FFmpeg command will only be used by the server(s) that take the stream from the Source." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="custom_ffmpeg" name="custom_ffmpeg" value="<?php if (isset($rStation)) {
                                                                                                                                                    echo htmlspecialchars($rStation['custom_ffmpeg']);
                                                                                                                                                } ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="user_agent"><?= $_['user_agent']; ?></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="user_agent" name="user_agent" value="<?php if (isset($rStationOptions[1])) {
                                                                                                                                                echo htmlspecialchars($rStationOptions[1]['value']);
                                                                                                                                            } else {
                                                                                                                                                echo htmlspecialchars($rStationArguments['user_agent']['argument_default_value']);
                                                                                                                                            } ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="http_proxy"><?= $_['http_proxy']; ?> <i title="Format: ip:port" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="http_proxy" name="http_proxy" value="<?php if (isset($rStationOptions[2])) {
                                                                                                                                                echo htmlspecialchars($rStationOptions[2]['value']);
                                                                                                                                            } else {
                                                                                                                                                echo htmlspecialchars($rStationArguments['proxy']['argument_default_value']);
                                                                                                                                            } ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="cookie"><?= $_['cookie']; ?> <i title="Format: key=value;" class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="cookie" name="cookie" value="<?php if (isset($rStationOptions[17])) {
                                                                                                                                        echo htmlspecialchars($rStationOptions[17]['value']);
                                                                                                                                    } else {
                                                                                                                                        echo htmlspecialchars($rStationArguments['cookie']['argument_default_value']);
                                                                                                                                    } ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="headers"><?= $_['headers']; ?> <i title="FFmpeg -headers command." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="headers" name="headers" value="<?php if (isset($rStreamOptions[19])) {
                                                                                                                                        echo htmlspecialchars($rStreamOptions[19]['value']);
                                                                                                                                    } else {
                                                                                                                                        echo htmlspecialchars($rStationArguments['headers']['argument_default_value']);
                                                                                                                                    } ?>">
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $_['prev']; ?></a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary"><?= $_['next']; ?></a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="auto-restart">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="days_to_restart"><?= $_['days_to_restart']; ?></label>
                                                    <div class="col-md-8">
                                                        <select id="days_to_restart" name="days_to_restart[]" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="<?= $_['choose']; ?>...">
                                                            <?php
                                                            $daysMap = array($_['monday'] => 'Monday', $_['tuesday'] => 'Tuesday', $_['wednesday'] => 'Wednesday', $_['thursday'] => 'Thursday', $_['friday'] => 'Friday', $_['saturday'] => 'Saturday', $_['sunday'] => 'Sunday');
                                                            foreach ($daysMap as $dayName => $dayValue): ?>
                                                                <option value="<?= $dayValue; ?>" <?php if (isset($rAutoRestart['days']) && in_array($dayValue, $rAutoRestart['days'])) {
                                                                                                        echo ' selected';
                                                                                                    } ?>><?= $dayName; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="time_to_restart"><?= $_['time_to_restart']; ?></label>
                                                    <div class="col-md-8">
                                                        <div class="input-group clockpicker" data-placement="top" data-align="top" data-autoclose="true">
                                                            <input id="time_to_restart" name="time_to_restart" type="text" class="form-control" value="<?= isset($rAutoRestart['at']) ? $rAutoRestart['at'] : '06:00'; ?>">
                                                            <div class="input-group-append">
                                                                <span class="input-group-text"><i class="mdi mdi-clock-outline"></i></span>
                                                            </div>
                                                        </div>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="load-balancing">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="servers"><?= $_['server_tree']; ?></label>
                                                    <div class="col-md-8">
                                                        <div id="server_tree"></div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="on_demand">On-Demand Servers</label>
                                                    <div class="col-md-8">
                                                        <select name="on_demand[]" id="on_demand" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach ($rServers as $rServer): ?>
                                                                <option value="<?= $rServer['id']; ?>" <?php if (isset($rStation) && in_array($rServer['id'], $rOnDemand)) {
                                                                                                            echo ' selected';
                                                                                                        } ?>><?= $rServer['server_name']; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="restart_on_edit"><?= isset($rStation['id']) ? $_['restart_on_edit'] : $_['start_stream_now']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <ul class="list-inline wizard mb-0">
                                        <li class="prevb list-inline-item">
                                            <a href="javascript: void(0);" class="btn btn-secondary"><?= $_['prev']; ?></a>
                                        </li>
                                        <li class="nextb list-inline-item float-right">
                                            <input name="submit_radio" type="submit" class="btn btn-primary" value="<?= isset($rStation['id']) ? $_['edit'] : $_['add']; ?>" />
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php';
		echo '        ' . "\r\n" . '        function openImage(elem) {' . "\r\n" . '            var rImage = $(elem).parent().parent().find("input").val();' . "\r\n" . '            if (rImage) {' . "\r\n" . '                $.magnificPopup.open({' . "\r\n" . '                    items: {' . "\r\n" . "                        src: 'resize?maxw=512&maxh=512&url=' + encodeURIComponent(rImage)," . "\r\n" . "                        type: 'image'" . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function evaluateServers() {' . "\r\n" . '            var rOVal = $("#on_demand").val();' . "\r\n" . '            $("#on_demand").empty();' . "\r\n" . "            \$(\$('#server_tree').jstree(true).get_json('source', {flat:true})).each(function(index, value) {" . "\r\n" . '                if (value.parent != "#") {' . "\r\n" . '                    $("#on_demand").append(new Option(value.text, value.id));' . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '            $("#on_demand").val(rOVal).trigger("change");' . "\r\n" . '            if (!$("#on_demand").val()) {' . "\r\n" . '                $("#on_demand").val(0).trigger("change");' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n" . '            $("#category_id").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#category_id').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#category_create").show();' . "\r\n" . "                    \$(\"#category_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#category_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#category_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n" . '            $("#bouquets").select2({' . "\r\n" . "                width: '100%'," . "\r\n" . '                tags: true' . "\r\n" . '            }).on("change", function(e) {' . "\r\n" . "                rData = \$('#bouquets').select2('data');" . "\r\n" . '                rAdded = [];' . "\r\n" . '                for (i = 0; i < rData.length; i++) {' . "\r\n" . '                    if (!rData[i].selected) {' . "\r\n" . '                        rAdded.push(rData[i].text);' . "\r\n" . '                    }' . "\r\n" . '                }' . "\r\n" . '                if (rAdded.length > 0) {' . "\r\n" . '                    $("#bouquet_create").show();' . "\r\n" . "                    \$(\"#bouquet_new\").html(rAdded.join(', '));" . "\r\n" . '                } else {' . "\r\n" . '                    $("#bouquet_create").hide();' . "\r\n" . '                }' . "\r\n" . '                $("#bouquet_create_list").val(JSON.stringify(rAdded));' . "\r\n" . '            });' . "\r\n\t\t\t" . '$(".clockpicker").clockpicker();' . "\r\n\t\t\t" . "\$('#server_tree').on('redraw.jstree', function (e, data) {" . "\r\n" . '                evaluateServers();' . "\r\n" . "            }).on('select_node.jstree', function (e, data) {" . "\r\n" . '                if (data.node.parent == "offline") {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#source\", \"last\");" . "\r\n" . '                } else {' . "\r\n" . "                    \$('#server_tree').jstree(\"move_node\", data.node.id, \"#offline\", \"first\");" . "\r\n" . '                }' . "\r\n" . "            }).jstree({ 'core' : {" . "\r\n\t\t\t\t" . "'check_callback': function (op, node, parent, position, more) {" . "\r\n\t\t\t\t\t" . 'switch (op) {' . "\r\n\t\t\t\t\t\t" . "case 'move_node':" . "\r\n\t\t\t\t\t\t\t" . 'if ((node.id == "offline") || (node.id == "source")) { return false; }' . "\r\n" . '                            if (parent.id == "#") { return false; }' . "\r\n\t\t\t\t\t\t\t" . 'return true;' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . "'data' : ";
		echo json_encode(($rServerTree ?: array()));
		echo "\t\t\t" . '}, "plugins" : [ "dnd" ]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#direct_source").change(function() {' . "\r\n\t\t\t\t" . 'evaluateDirectSource();' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . 'function evaluateDirectSource() {' . "\r\n\t\t\t\t" . '$(["custom_ffmpeg", "probesize_ondemand", "user_agent", "http_proxy", "cookie", "headers", "days_to_restart", "time_to_restart", "on_demand", "restart_on_edit"]).each(function(rID, rElement) {' . "\r\n\t\t\t\t\t" . 'if ($(rElement)) {' . "\r\n\t\t\t\t\t\t" . 'if ($("#direct_source").is(":checked")) {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'setSwitch(window.rSwitches[rElement], false);' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].disable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", true);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t" . 'if (window.rSwitches[rElement]) {' . "\r\n\t\t\t\t\t\t\t\t" . 'window.rSwitches[rElement].enable();' . "\r\n\t\t\t\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t\t\t\t" . '$("#" + rElement).prop("disabled", false);' . "\r\n\t\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t\t" . '}' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '});' . "\r\n\t\t\t" . '}' . "\r\n\t\t\t" . '$("#probesize_ondemand").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#delay_minutes").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#tv_archive_duration").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . 'evaluateDirectSource();' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(\"#server_tree_data\").val(JSON.stringify(\$('#server_tree').jstree(true).get_json('source', {flat:true})));" . "\r\n\t\t\t\t" . 'if ($("#stream_display_name").val().length == 0) {' . "\r\n\t\t\t\t\t" . '$.toast("';
		echo $_['enter_a_radio_station_name'];
		echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n" . "                    \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                    submitForm(window.rCurrentPage, new FormData($("form")[0]), window.rReferer);' . "\r\n" . '                }' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>