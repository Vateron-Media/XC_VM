<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$_TITLE = 'Stream Errors';
include 'header.php';
?>
<div class="wrapper"
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Stream Logs</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div class="form-group row mb-4">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="log_search" value=""
                                    placeholder="Search Logs...">
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="reseller">Server</label>
                            <div class="col-md-3">
                                <select id="reseller" class="form-control" data-toggle="select2">
                                    <option value="" selected>All Servers</option>
                                    <?php foreach (getStreamingServers() as $rServer) { ?>
                                        <option value="<?= intval($rServer['id']); ?>"><?= $rServer['server_name']; ?>
                                        </option>
                                    <?php } ?>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="range">Dates</label>
                            <div class="col-md-2">
                                <input type="text" class="form-control text-center date" id="range" name="range"
                                    data-toggle="date-picker" data-single-date-picker="true" autocomplete="off"
                                    placeholder="All Dates">
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option<?php if ($rSettings['default_entries'] == $rShow) {
                                                    echo ' selected';
                                                } ?>
                                            value="<?= $rShow; ?>"><?= $rShow; ?></option>
                                        <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-activity"
                            class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th>Stream</th>
                                    <th>Server</th>
                                    <th>Error</th>
                                    <th class="text-center">Date</th>
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