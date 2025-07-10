<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['user_id'])) {
} else {
    $rSearchUser = getUser(CoreUtilities::$rRequest['user_id']);
}

if (!isset(CoreUtilities::$rRequest['stream_id'])) {
} else {
    $rSearchStream = getStream(CoreUtilities::$rRequest['stream_id']);
}

$_TITLE = 'Activity Logs';
include 'header.php';
?>

<div class="wrapper" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Activity Logs</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4<?php if (!$rMobile) {
                                                                                } else {
                                                                                    echo ' collapse';
                                                                                } ?>">
                            <div class="col-md-2">
                                <input type="text" class="form-control" id="act_search" value="<?php if (!isset(CoreUtilities::$rRequest['search'])) {
                                                                                                } else {
                                                                                                    echo htmlspecialchars(CoreUtilities::$rRequest['search']);
                                                                                                } ?>" placeholder="<?php echo $_['search_logs']; ?>...">
                            </div>
                            <div class="col-md-2">
                                <select id="act_server" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (isset(CoreUtilities::$rRequest['server'])) {
                                                        } else {
                                                            echo ' selected';
                                                        } ?>><?php echo $_['all_servers']; ?></option>
                                    <?php foreach (CoreUtilities::$rServers as $rServer) { ?>
                                        <option value="<?php echo $rServer['id']; ?>" <?php if (isset(CoreUtilities::$rRequest['server']) && CoreUtilities::$rRequest['server'] == $rServer['id']) {
                                                                                            echo ' selected';
                                                                                        } ?>><?php echo $rServer['server_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="act_stream" class="form-control" data-toggle="select2">
                                    <?php if (!isset($rSearchStream)) {
                                    } else { ?>
                                        <option value="<?php echo intval($rSearchStream['id']); ?>" selected="selected"><?php echo $rSearchStream['stream_display_name']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="act_line" class="form-control" data-toggle="select2">
                                    <?php if (!isset($rSearchUser)) {
                                    } else { ?>
                                        <option value="<?php echo intval($rSearchUser['id']); ?>" selected="selected"><?php echo $rSearchUser['username']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <input type="text" class="form-control text-center date" id="act_range" name="range" value="<?php if (!isset(CoreUtilities::$rRequest['range'])) {
                                                                                                                            } else {
                                                                                                                                echo htmlspecialchars(CoreUtilities::$rRequest['range']);
                                                                                                                            } ?>" data-toggle="date-picker" data-single-date-picker="true" placeholder="All Dates">
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="act_show_entries"><?php echo $_['show']; ?></label>
                            <div class="col-md-1">
                                <select id="act_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option<?php if ($rSettings['default_entries'] == $rShow) {
                                                    echo ' selected';
                                                } ?> value="<?php echo $rShow; ?>"><?php echo $rShow; ?></option>
                                        <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th>Line</th>
                                    <th>Stream</th>
                                    <th>Server</th>
                                    <th>Player</th>
                                    <th>ISP</th>
                                    <th class="text-center">IP</th>
                                    <th class="text-center">Start</th>
                                    <th class="text-center">Stop</th>
                                    <th class="text-center">Duration</th>
                                    <th class="text-center">Output</th>
                                    <th class="text-center">Restreamer</th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>