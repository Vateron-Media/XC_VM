<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$rCategories = getCategories('live');
$rTranscodeProfiles = getTranscodeProfiles();
$rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));

foreach ($rServers as $rServer) {
    $rServerTree[] = array('id' => intval($rServer['id']), 'parent' => 'offline', 'text' => htmlspecialchars($rServer['server_name']), 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
}
$_TITLE = 'Mass Edit Channels';
include 'header.php'; ?>
<div class="wrapper boxed-layout-xl" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                        } else {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Mass Edit Channels <small id="selected_count"></small></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <?php if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
                } else { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Mass edit of channels was successfully executed!
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body">
                        <form action="#" method="POST">
                            <input type="hidden" name="server_tree_data" id="server_tree_data" value="" />
                            <input type="hidden" name="od_tree_data" id="od_tree_data" value="" />
                            <input type="hidden" name="streams" id="streams" value="" />
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#stream-selection" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-play mr-1"></i>
                                            <span class="d-none d-sm-inline">Channels</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#stream-details" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                            <span class="d-none d-sm-inline">Details</span>
                                        </a>
                                    </li>
                                    <li class="nav-item">
                                        <a href="#load-balancing" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-server-network mr-1"></i>
                                            <span class="d-none d-sm-inline">Servers</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="stream-selection">
                                        <div class="row">
                                            <div class="col-md-2 col-6">
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="Search Channels...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="stream_server_id" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Servers</option>
                                                    <option value="-1">No Servers</option>
                                                    <?php foreach (getStreamingServers() as $rServer) { ?>
                                                        <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="category_search" class="form-control" data-toggle="select2">
                                                    <option value="" selected>All Categories</option>
                                                    <option value="-1">No Categories</option>
                                                    <?php foreach ($rCategories as $rCategory) { ?>
                                                        <option value="<?php echo intval($rCategory['id']); ?>" <?php if (isset(CoreUtilities::$rRequest['category']) && CoreUtilities::$rRequest['category'] == $rCategory['id']) {
                                                                                                                    echo ' selected';
                                                                                                                } ?>>
                                                            <?php echo htmlspecialchars($rCategory['category_name']); ?>
                                                        </option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="stream_filter" class="form-control" data-toggle="select2">
                                                    <option value="">No Filter</option>
                                                    <option value="1">Online</option>
                                                    <option value="2">Stopped</option>
                                                    <option value="3">Creating</option>
                                                    <option value="4">Transcoding</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-8">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                                        <option<?php if ($rSettings['default_entries'] == $rShow) {
                                                                    echo ' selected';
                                                                } ?> value="<?php echo $rShow; ?>">
                                                            <?php echo $rShow; ?>
                                                            </option>
                                                        <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-2">
                                                <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleStreams()" style="width: 100%">
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>
                                            <table id="datatable-mass" class="table table-borderless mb-0">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="text-center">ID</th>
                                                        <th class="text-center">Icon</th>
                                                        <th>Stream Name</th>
                                                        <th>Category</th>
                                                        <th>Server</th>
                                                        <th class="text-center">Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody></tbody>
                                            </table>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="stream-details">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="category_id" name="c_category_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="category_id">Select Categories</label>
                                                    <div class="col-md-6">
                                                        <select disabled name="category_id[]" id="category_id" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach ($rCategories as $rCategory) { ?>
                                                                <option value="<?php echo intval($rCategory['id']); ?>"><?php echo htmlspecialchars($rCategory['category_name']); ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="category_id_type" id="category_id_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType) { ?>
                                                                <option value="<?php echo $rType; ?>"><?php echo $rType; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="bouquets" name="c_bouquets">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="bouquets">Select Bouquets</label>
                                                    <div class="col-md-6">
                                                        <select disabled name="bouquets[]" id="bouquets" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach (getBouquets() as $rBouquet) { ?>
                                                                <option value="<?php echo intval($rBouquet['id']); ?>"><?php echo htmlspecialchars($rBouquet['bouquet_name']); ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                    <div class="col-md-2">
                                                        <select disabled name="bouquets_type" id="bouquets_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET', 'ADD', 'DEL') as $rType) { ?>
                                                                <option value="<?php echo $rType; ?>"><?php echo $rType; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="rtmp_output" data-type="switch" name="c_rtmp_output">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="rtmp_output">Output RTMP</label>
                                                    <div class="col-md-2">
                                                        <input name="rtmp_output" id="rtmp_output" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="allow_record">Allow Recording</label>
                                                    <div class="col-md-2">
                                                        <input name="allow_record" id="allow_record" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="allow_record" data-type="switch" name="c_allow_record">
                                                        <label></label>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="transcode_profile_id" name="c_transcode_profile_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="transcode_profile_id">Transcoding Profile</label>
                                                    <div class="col-md-8">
                                                        <select name="transcode_profile_id" disabled id="transcode_profile_id" class="form-control" data-toggle="select2">
                                                            <option selected value="0">Transcoding Disabled</option>
                                                            <?php foreach ($rTranscodeProfiles as $rProfile) { ?>
                                                                <option value="<?php echo intval($rProfile['profile_id']); ?>"><?php echo $rProfile['profile_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                            </li>
                                        </ul>
                                    </div>
                                    <div class="tab-pane" id="load-balancing">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" data-name="server_tree" class="activate" name="c_server_tree" id="c_server_tree">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-3 col-form-label" for="server_tree">Server Tree</label>
                                                    <div class="col-md-8">
                                                        <div id="server_tree"></div>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="server_type">Server Type</label>
                                                    <div class="col-md-2">
                                                        <select disabled name="server_type" id="server_type" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED') as $rValue => $rType) { ?>
                                                                <option value="<?php echo $rValue; ?>"><?php echo $rType; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="on_demand">On-Demand Servers</label>
                                                    <div class="col-md-8">
                                                        <select disabled name="on_demand[]" id="on_demand" class="form-control select2-multiple" data-toggle="select2" multiple="multiple" data-placeholder="Choose...">
                                                            <?php foreach ($rServers as $rServer) { ?>
                                                                <option value="<?php echo $rServer['id']; ?>"><?php echo $rServer['server_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="reencode_on_edit"><?php echo $_['reencode_on_edit']; ?></label>
                                                    <div class="col-md-2">
                                                        <input name="reencode_on_edit" id="reencode_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <div class="col-md-1"></div>
                                                    <label class="col-md-3 col-form-label" for="restart_on_edit">Restart on Edit</label>
                                                    <div class="col-md-2">
                                                        <input name="restart_on_edit" id="restart_on_edit" type="checkbox" data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0">
                                            <li class="prevb list-inline-item">
                                                <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                            </li>
                                            <li class="nextb list-inline-item float-right">
                                                <input name="submit_stream" type="submit" class="btn btn-primary" value="Edit Channels" />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>