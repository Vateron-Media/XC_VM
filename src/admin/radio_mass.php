<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$rCategories = getCategories('radio');
$rServerTree = array(array('id' => 'source', 'parent' => '#', 'text' => "<strong class='btn btn-success waves-effect waves-light btn-xs'>Active</strong>", 'icon' => 'mdi mdi-play', 'state' => array('opened' => true)), array('id' => 'offline', 'parent' => '#', 'text' => "<strong class='btn btn-secondary waves-effect waves-light btn-xs'>Offline</strong>", 'icon' => 'mdi mdi-stop', 'state' => array('opened' => true)));

foreach ($rServers as $rServer) {
    $rServerTree[] = array('id' => $rServer['id'], 'parent' => 'offline', 'text' => $rServer['server_name'], 'icon' => 'mdi mdi-server-network', 'state' => array('opened' => true));
}
$_TITLE = 'Mass Edit Stations';
include 'header.php';
?>
<div class="wrapper boxed-layout-xl" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                        } else { ?> style="display: none;" <?php } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Mass Edit Stations <small id="selected_count"></small></h4>
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
                        Mass edit of stations was successfully executed!
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
                                            <span class="d-none d-sm-inline">Stations</span>
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
                                                <input type="text" class="form-control" id="stream_search" value="" placeholder="Search Stations...">
                                            </div>
                                            <div class="col-md-3 col-6">
                                                <select id="station_server_id" class="form-control" data-toggle="select2">
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
                                                        <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                    <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-2 col-6">
                                                <select id="station_filter" class="form-control" data-toggle="select2">
                                                    <option value=""><?php echo $_['no_filter']; ?></option>
                                                    <option value="1">Online</option>
                                                    <option value="2">Down</option>
                                                    <option value="3">Stopped</option>
                                                    <option value="4">Starting</option>
                                                    <option value="5">On Demand</option>
                                                    <option value="6">Direct</option>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <select id="show_entries" class="form-control" data-toggle="select2">
                                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                                        <option<?php if ($rSettings['default_entries'] != $rShow) {
                                                                } else { ?> selected<?php } ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                                        <?php } ?>
                                                </select>
                                            </div>
                                            <div class="col-md-1 col-6">
                                                <button type="button" class="btn btn-info waves-effect waves-light" onClick="toggleStreams()" style="width: 100%">
                                                    <i class="mdi mdi-selection"></i>
                                                </button>
                                            </div>
                                            <table id="datatable-mass" class="table table-borderless mb-0">
                                                <thead class="bg-light">
                                                    <tr>
                                                        <th class="text-center">ID</th>
                                                        <th class="text-center">Icon</th>
                                                        <th>Station Name</th>
                                                        <th>Category</th>
                                                        <th><?php echo $_['servers']; ?></th>
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
                                                <p class="sub-header">
                                                    To mass edit any of the below options, tick the checkbox next to it and change the input value.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="category_id" name="c_category_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Category</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control select2" multiple="multiple" data-toggle="select2" id="c_category_id" name="c_category_id[]" data-placeholder="Choose Categories">
                                                            <?php foreach ($rCategories as $rCategory) { ?>
                                                                <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="station_server_id" name="c_server_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Server</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control select2" multiple="multiple" data-toggle="select2" id="c_server_id" name="c_server_id[]" data-placeholder="Choose Servers">
                                                            <?php foreach (getStreamingServers() as $rServer) { ?>
                                                                <option value="<?php echo intval($rServer['id']); ?>"><?php echo $rServer['server_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="allow_comments" name="c_allow_comments">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Allow Comments</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control" data-toggle="select2" id="c_allow_comments" name="c_allow_comments">
                                                            <option value="1">Yes</option>
                                                            <option value="0">No</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="autoplay" name="c_autoplay">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Autoplay</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control" data-toggle="select2" id="c_autoplay" name="c_autoplay">
                                                            <option value="1">Yes</option>
                                                            <option value="0">No</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="email_on_new_comment" name="c_email_on_new_comment">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Email on New Comment</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control" data-toggle="select2" id="c_email_on_new_comment" name="c_email_on_new_comment">
                                                            <option value="1">Yes</option>
                                                            <option value="0">No</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="tab-pane" id="load-balancing">
                                        <div class="row">
                                            <div class="col-12">
                                                <p class="sub-header">
                                                    To mass edit any of the below options, tick the checkbox next to it and change the input value.
                                                </p>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="load_balancing" name="c_load_balancing">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Load Balancing</label>
                                                    <div class="col-md-9">
                                                        <select class="form-control" data-toggle="select2" id="c_load_balancing" name="c_load_balancing">
                                                            <option value="1">Yes</option>
                                                            <option value="0">No</option>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="total_listeners" name="c_total_listeners">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Total Listeners</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_total_listeners" name="c_total_listeners">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="current_listeners" name="c_current_listeners">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Current Listeners</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_current_listeners" name="c_current_listeners">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="bitrate" name="c_bitrate">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example-email-input">Bitrate</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_bitrate" name="c_bitrate">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="max_listeners" name="c_max_listeners">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label" for="example
                                                    </div>
                                                        <label class=" col-md-2 col-form-label">Category</label>
                                                    <div class="col-md-9">
                                                        <select id="c_category_id" class="form-control" data-toggle="select2" multiple>
                                                            <?php foreach ($rCategories as $rCategory) { ?>
                                                                <option value="<?php echo $rCategory['id']; ?>"><?php echo $rCategory['category_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="server_id" name="c_server_id">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label">Server</label>
                                                    <div class="col-md-9">
                                                        <select id="c_server_id" class="form-control" data-toggle="select2" multiple>
                                                            <?php foreach (getStreamingServers() as $rServer) { ?>
                                                                <option value="<?php echo $rServer['id']; ?>"><?php echo $rServer['server_name']; ?></option>
                                                            <?php } ?>
                                                        </select>
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="stream_username" name="c_stream_username">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label">Username</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_stream_username" name="c_stream_username" value="" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="stream_password" name="c_stream_password">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label">Password</label>
                                                    <div class="col-md-9">
                                                        <input type="text" class="form-control" id="c_stream_password" name="c_stream_password" value="" />
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                        <input type="checkbox" class="activate" data-name="stream_allow_transcode" name="c_stream_allow_transcode">
                                                        <label></label>
                                                    </div>
                                                    <label class="col-md-2 col-form-label">Transcode</label>
                                                    <div class="col-md-9">
                                                        <select id="c_stream_allow_transcode" class="form-control" data-toggle="select2">
                                                            <option value="1">Enabled</option>
                                                            <option value="0">Disabled</option>
                                                        </select>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="tab-pane" id="load-balancing">
                                    <div class="row">
                                        <div class="col-12">
                                            <p class="sub-header">
                                                To mass edit any of the below options, tick the checkbox next to it and change the input value.
                                            </p>
                                            <div class="form-group row mb-4">
                                                <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                    <input type="checkbox" class="activate" data-name="load_balancer_enabled" name="c_load_balancer_enabled">
                                                    <label></label>
                                                </div>
                                                <label class="col-md-2 col-form-label">Load Balancer</label>
                                                <div class="col-md-9">
                                                    <select id="c_load_balancer_enabled" class="form-control" data-toggle="select2">
                                                        <option value="1">Enabled</option>
                                                        <option value="0">Disabled</option>
                                                    </select>
                                                </div>
                                            </div>
                                            <div class="form-group row mb-4">
                                                <div class="checkbox checkbox-single col-md-1 checkbox-offset checkbox-primary text-center">
                                                    <input type="checkbox" class="activate" data-name="load_balancer_server_id" name="c_load_balancer_server_id">
                                                    <label></label>
                                                </div>
                                                <label class="col-md-2 col-form-label">Load Balancer Server</label>
                                                <div class="col-md-9">
                                                    <select id="c_load_balancer_server_id" class="form-control" data-toggle="select2">
                                                        <option value="">No Server</option>
                                                        <?php foreach (getStreamingServers() as $rServer) { ?>
                                                            <option value="<?php echo $rServer['id']; ?>"><?php echo $rServer['server_name']; ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <ul class="list-inline mb-0 wizard">
                                <li class="previous list-inline-item">
                                    <a href="javascript: void(0);" class="btn btn-secondary">Previous</a>
                                </li>
                                <li class="next list-inline-item float-right">
                                    <a href="javascript: void(0);" class="btn btn-secondary">Next</a>
                                </li>
                            </ul>
                    </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>
</div>
<?php include 'footer.php'; ?>