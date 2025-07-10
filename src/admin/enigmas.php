<?php include 'session.php';
include 'functions.php';
if (!checkPermissions()) {
    goHome();
}
$_TITLE = 'Enigma Devices';
include 'header.php'; ?>
<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo ' style="display: none;"';
                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['enigma_devices']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        Device has been added / modified.
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4 <?php echo $rMobile ? 'collapse' : ''; ?>">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="e2_search" value="<?php echo isset(CoreUtilities::$rRequest['search']) ? htmlspecialchars(CoreUtilities::$rRequest['search']) : ''; ?>" placeholder="<?php echo $_['search_devices']; ?>...">
                            </div>
                            <label class="col-md-2 col-form-label text-center" for="e2_reseller">Filter Results &nbsp; <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearOwner();"><i class="mdi mdi-close"></i></button></label>
                            <div class="col-md-3">
                                <select id="e2_reseller" class="form-control" data-toggle="select2">
                                    <?php if (isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner'])))) { ?>
                                        <option value="<?php echo intval($rOwner['id']); ?>" selected="selected"><?php echo $rOwner['username']; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <select id="e2_filter" class="form-control" data-toggle="select2">
                                    <option value="" <?php if (!isset(CoreUtilities::$rRequest['filter'])) {
                                                            echo 'selected';
                                                        } ?>><?php echo $_['no_filter']; ?></option>
                                    <option value="1" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 1) {
                                                            echo 'selected';
                                                        } ?>><?php echo $_['active']; ?></option>
                                    <option value="2" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 2) {
                                                            echo 'selected';
                                                        } ?>><?php echo $_['disabled']; ?></option>
                                    <option value="3" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 3) {
                                                            echo 'selected';
                                                        } ?>><?php echo $_['banned']; ?></option>
                                    <option value="4" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 4) {
                                                            echo 'selected';
                                                        } ?>><?php echo $_['expired']; ?></option>
                                    <option value="5" <?php if (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == 5) {
                                                            echo 'selected';
                                                        } ?>><?php echo $_['trial']; ?></option>
                                </select>
                            </div>
                            <label class="col-md-1 col-form-label text-center" for="e2_show_entries"><?php echo $_['show']; ?></label>
                            <div class="col-md-1">
                                <select id="e2_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                        <option value="<?php echo $rShow; ?>" <?php if ((isset(CoreUtilities::$rRequest['entries']) && CoreUtilities::$rRequest['entries'] == $rShow) || (!isset(CoreUtilities::$rRequest['entries']) && $rSettings['default_entries'] == $rShow)) {
                                                                                    echo 'selected';
                                                                                } ?>><?php echo $rShow; ?></option>
                                    <?php } ?>
                                </select>
                            </div>
                        </div>
                        <table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th><?php echo $_['username']; ?></th>
                                    <th class="text-center"><?php echo $_['mac_address']; ?></th>
                                    <th class="text-center">Public IP</th>
                                    <th><?php echo $_['owner']; ?></th>
                                    <th class="text-center"><?php echo $_['status']; ?></th>
                                    <th class="text-center"><?php echo $_['online']; ?></th>
                                    <th class="text-center"><?php echo $_['trial']; ?></th>
                                    <th class="text-center"><?php echo $_['expiration']; ?></th>
                                    <th class="text-center">Last Connection</th>
                                    <th class="text-center"><?php echo $_['actions']; ?></th>
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