<?php
include 'session.php';
include 'functions.php';

// Check permissions
if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Users';
include 'header.php';
?>

<div class="wrapper" <?= (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') ? '' : 'style="display: none;"' ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Users</h4>
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
                        User has been added/modified.
                    </div>
                <?php endif; ?>

                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div id="collapse_filters" class="form-group row mb-4 <?= $rMobile ? 'collapse' : '' ?>">
                            <div class="col-md-3">
                                <input type="text" class="form-control" id="reg_search" 
                                    value="<?= isset(CoreUtilities::$rRequest['search']) ? htmlspecialchars(CoreUtilities::$rRequest['search']) : '' ?>" 
                                    placeholder="Search Users...">
                            </div>

                            <label class="col-md-2 col-form-label text-center" for="reg_reseller">
                                Filter Results &nbsp;
                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="clearOwner();">
                                    <i class="mdi mdi-close"></i>
                                </button>
                            </label>
                            
                            <div class="col-md-3">
                                <select id="reg_reseller" class="form-control" data-toggle="select2">
                                    <?php if (isset(CoreUtilities::$rRequest['owner']) && ($rOwner = getRegisteredUser(intval(CoreUtilities::$rRequest['owner'])))): ?>
                                        <option value="<?= intval($rOwner['id']) ?>" selected>
                                            <?= $rOwner['username'] ?>
                                        </option>
                                    <?php endif; ?>
                                </select>
                            </div>

                            <div class="col-md-2">
                                <select id="reg_filter" class="form-control" data-toggle="select2">
                                    <option value="" <?= !isset(CoreUtilities::$rRequest['filter']) ? 'selected' : '' ?>>No Filter</option>
                                    <option value="-1" <?= (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == -1) ? 'selected' : '' ?>>Active</option>
                                    <option value="-2" <?= (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == -2) ? 'selected' : '' ?>>Disabled</option>
                                    
                                    <?php foreach (getMemberGroups() as $rGroup): ?>
                                        <option value="<?= intval($rGroup['group_id']) ?>" 
                                            <?= (isset(CoreUtilities::$rRequest['filter']) && CoreUtilities::$rRequest['filter'] == intval($rGroup['group_id'])) ? 'selected' : '' ?>>
                                            <?= $rGroup['group_name'] ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <label class="col-md-1 col-form-label text-center" for="reg_show_entries">Show</label>
                            <div class="col-md-1">
                                <select id="reg_show_entries" class="form-control" data-toggle="select2">
                                    <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?>
                                        <option value="<?= $rShow ?>" 
                                            <?= (isset(CoreUtilities::$rRequest['entries']) 
                                                ? (CoreUtilities::$rRequest['entries'] == $rShow ? 'selected' : '') 
                                                : ($rSettings['default_entries'] == $rShow ? 'selected' : '')) ?>>
                                            <?= $rShow ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <table id="datatable-users" class="table table-striped table-borderless dt-responsive nowrap font-normal">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th>Username</th>
                                    <th>Owner</th>
                                    <th class="text-center">IP</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Type</th>
                                    <th class="text-center">Credits</th>
                                    <th class="text-center">Users</th>
                                    <th class="text-center">Lines</th>
                                    <th class="text-center">MAGs</th>
                                    <th class="text-center">Enigmas</th>
                                    <th class="text-center">Last Login</th>
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