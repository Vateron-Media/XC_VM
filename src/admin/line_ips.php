<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rRange = (intval(CoreUtilities::$rRequest['range']) ?: 0);
$rLineIPs = (igbinary_unserialize(file_get_contents(CACHE_TMP_PATH . 'lines_per_ip')) ?: array());
$_TITLE = 'Line IP Usage';
include 'header.php';
?>

<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Line IP Usage</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div class="form-group row mb-4">
                            <div class="col-md-7">
                                <input type="text" class="form-control" id="log_search" value="" placeholder="Search Logs...">
                            </div>
                            <div class="col-md-3">
                                <select id="range" class="form-control" data-toggle="select2">
                                    <option value="0" <?= $rRange == 0 ? 'selected' : ''; ?>>All Time</option>
                                    <option value="604800" <?= $rRange == 604800 ? 'selected' : ''; ?>>Last 7 Days</option>
                                    <option value="86400" <?= $rRange == 86400 ? 'selected' : ''; ?>>Last 24 Hours</option>
                                    <option value="3600" <?= $rRange == 3600 ? 'selected' : ''; ?>>Last Hour</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option <?= $rSettings['default_entries'] == $rShow ? 'selected' : ''; ?> value="<?= $rShow; ?>"><?= $rShow; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">User ID</th>
                                    <th>Username</th>
                                    <th class="text-center">IP Count</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rLineIPs[$rRange] as $rRow) {
                                    $rButtons = $rRange == 0 ?
                                        '<a href="line_activity?user_id=' . $rRow['user_id'] . '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs">View Logs</button></a>' :
                                        '<a href="line_activity?user_id=' . $rRow['user_id'] . '&range=' . date($rSettings['date_format'], time() - $rRange) . ' - ' . date($rSettings['date_format'], time()) . '"><button type="button" class="btn btn-light waves-effect waves-light btn-xs">View Logs</button></a>';

                                    if (hasPermissions('adv', 'edit_user')) {
                                        $rID = "<a href='line?id=" . $rRow['user_id'] . "'>" . $rRow['user_id'] . "</a>";
                                        $rUsername = "<a href='line?id=" . $rRow['user_id'] . "'>" . $rRow['username'] . "</a>";
                                    } else {
                                        $rID = $rRow['user_id'];
                                        $rUsername = $rRow['username'];
                                    }
                                ?>
                                    <tr>
                                        <td class="text-center"><?= $rID; ?></td>
                                        <td><?= $rUsername; ?></td>
                                        <td class="text-center"><?= $rRow['ip_count']; ?></td>
                                        <td class="text-center"><?= $rButtons; ?></td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>