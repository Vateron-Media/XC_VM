<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()): ?>
    <?php goHome(); ?>
<?php endif; ?>

<?php $_TITLE = 'Lines'; ?>
<?php include 'header.php'; ?>

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
                    <h4 class="page-title">Lines</h4>
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
                        Line has been added / modified.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4 <?php if (!$rMobile) {
                                                                                } else {
                                                                                    echo 'collapse';
                                                                                } ?>">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="user_search" value="<?php if (!isset(CoreUtilities::$rRequest['search'])) {
                                                                                                } else {
                                                                                                    echo htmlspecialchars(CoreUtilities::$rRequest['search']);
                                                                                                } ?>" placeholder="Search Lines...">
                            </div>
                            <label class="col-md-2 col-form-label text-center" for="user_reseller">Filter Results &nbsp; <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearOwner();"><i class="mdi mdi-close"></i></button></label>
                            <div class="col-md-3">
                                <select id="user_reseller" class="form-control" data-toggle="select2">
                                    <?php if (!(isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner']))))): ?>
                                    <?php else: ?>
                                        <option value="<?php echo intval($rOwner['id']); ?>" selected="selected"><?php echo $rOwner['username']; ?></option>
                                    <?php endif; ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="user_filter" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])) {
                                                            echo ' selected';
                                                        } ?>>No Filter</option>
                                    <?php
                                    $filters = [
                                        1 => 'Active',
                                        2 => 'Disabled',
                                        3 => 'Banned',
                                        4 => 'Expired',
                                        5 => 'Trial',
                                        6 => 'Restreamer',
                                        7 => 'Ministra',
                                        8 => 'Expiring Soon',
                                    ];
                                    foreach ($filters as $key => $value) {
                                        $selected = (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == $key) ? ' selected' : '';
                                        echo "<option value=\"$key\"$selected>$value</option>\n";
                                    }
                                    ?>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="user_show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="user_show_entries" class="form-control" data-toggle="select2">
                                    <?php
                                    $entriesOptions = [10, 25, 50, 250, 500, 1000];
                                    foreach ($entriesOptions as $rShow) {
                                        $selected = (isset(CoreUtilities::$rRequest['entries']) && CoreUtilities::$rRequest['entries'] == $rShow) || ($rSettings['default_entries'] == $rShow) ? ' selected' : '';
                                        echo "<option value=\"$rShow\"$selected>$rShow</option>\n";
                                    }
                                    ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th>Username</th>
                                    <th>Password</th>
                                    <th>Owner</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Online</th>
                                    <th class="text-center">Trial</th>
                                    <th class="text-center">Restreamer</th>
                                    <th class="text-center">Active</th>
                                    <th class="text-center">Connections</th>
                                    <th class="text-center">Expiration</th>
                                    <th class="text-center">Last Connection</th>
                                    <th class="text-center">Actions</th>
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