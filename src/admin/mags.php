<?php
include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
    exit;
}

$_TITLE = 'MAG Devices';
include 'header.php';
?>
<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo 'style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= $_['mag_devices']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Device has been added / modified.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4 <?php if ($rMobile) echo 'collapse'; ?>">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="mag_search" value="<?php if (isset(CoreUtilities::$rRequest['search'])) echo htmlspecialchars(CoreUtilities::$rRequest['search']); ?>" placeholder="<?= $_['search_devices']; ?>...">
                            </div>
                            <label class="col-md-2 col-form-label text-center" for="mag_reseller">Filter Results &nbsp; <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearOwner();"><i class="mdi mdi-close"></i></button></label>
                            <div class="col-md-3">
                                <select id="mag_reseller" class="form-control" data-toggle="select2">
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="mag_filter" class="form-control" data-toggle="select2">
                                    <?php if (isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner'])))): ?>
                                        <option value="<?= intval($rOwner['id']); ?>" selected="selected"><?= htmlspecialchars($rOwner['username']); ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="mag_filter" class="form-control" data-toggle="select2">
                                    <option value="" <?= !isset(CoreUtilities::$rRequest['filter']) ? 'selected' : ''; ?>><?= $_['no_filter']; ?></option>
                                    <option value="1" <?= (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1) ? 'selected' : ''; ?>><?= $_['active']; ?></option>
                                    <option value="2" <?= (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2) ? 'selected' : ''; ?>><?= $_['disabled']; ?></option>
                                    <option value="3" <?= (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3) ? 'selected' : ''; ?>><?= $_['banned']; ?></option>
                                    <option value="4" <?= (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4) ? 'selected' : ''; ?>><?= $_['expired']; ?></option>
                                    <option value="5" <?= (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5) ? 'selected' : ''; ?>><?= $_['trial']; ?></option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="mag_show_entries"><?= $_['show']; ?></label>
                            <div class="col-md-1">
                                <select id="mag_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                        <option value="<?= $rShow; ?>" <?= (isset(CoreUtilities::$rRequest['entries']) && CoreUtilities::$rRequest['entries'] == $rShow) || (!isset(CoreUtilities::$rRequest['entries']) && $rSettings['default_entries'] == $rShow) ? 'selected' : ''; ?>>
                                            <?= $rShow; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $_['id']; ?></th>
                                    <th><?= $_['username']; ?></th>
                                    <th class="text-center"><?= $_['mac_address']; ?></th>
                                    <th class="text-center">Device</th>
                                    <th><?= $_['owner']; ?></th>
                                    <th class="text-center"><?= $_['status']; ?></th>
                                    <th class="text-center"><?= $_['online']; ?></th>
                                    <th class="text-center"><?= $_['trial']; ?></th>
                                    <th class="text-center"><?= $_['expiration']; ?></th>
                                    <th class="text-center">Last Connection</th>
                                    <th class="text-center"><?= $_['actions']; ?></th>
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