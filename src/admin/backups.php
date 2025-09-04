<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Backups';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <form action="#" method="POST">
            <div class="row">
                <div class="col-12">
                    <div class="page-title-box">
                        <div class="page-title-right">
                            <?php include 'topbar.php'; ?>
                        </div>
                        <h4 class="page-title">Backups</h4>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-xl-12">
                    <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
                        <div class="alert alert-success alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            Backup settings successfully updated!
                        </div>
                    <?php else : ?>
                        <div class="alert alert-info alert-dismissible fade show" role="alert">
                            <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                                <span aria-hidden="true">&times;</span>
                            </button>
                            Backups will not contain any logs, restoring a database will therefore clear all of your logs.<br />If you want to keep your logs you should manually create your own backups.
                        </div>
                    <?php endif; ?>
                    <div class="card">
                        <div class="card-body">
                            <div id="basicwizard">
                                <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                    <li class="nav-item">
                                        <a href="#backups" data-toggle="tab" class="nav-link rounded-0 pt-2 pb-2">
                                            <i class="mdi mdi-backup-restore mr-1"></i>
                                            <span class="d-none d-sm-inline">Backups</span>
                                        </a>
                                    </li>
                                </ul>
                                <div class="tab-content b-0 mb-0 pt-0">
                                    <div class="tab-pane" id="backups">
                                        <div class="row">
                                            <div class="col-12">
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="automatic_backups">Automatic Backups <i title="Generate full SQL backups periodically." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <select name="automatic_backups" id="automatic_backups" class="form-control" data-toggle="select2">
                                                            <?php foreach (array('off' => 'Off', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly') as $rType => $rText) : ?>
                                                                <option value="<?php echo $rType; ?>" <?php if ($rSettings['automatic_backups'] == $rType) echo ' selected'; ?>><?php echo $rText; ?></option>
                                                            <?php endforeach; ?>
                                                        </select>
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="backups_to_keep">Local Backups to Keep <i title="Enter 0 for unlimited. Oldest will be deleted." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="backups_to_keep" name="backups_to_keep" value="<?php echo htmlspecialchars($rSettings['backups_to_keep'] ?: 0); ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="dropbox_remote">Dropbox Backups <i title="Once a local backup is generated, upload it to Dropbox for safe remote storage." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input name="dropbox_remote" id="dropbox_remote" type="checkbox" <?php if ($rSettings['dropbox_remote']) echo 'checked '; ?>data-plugin="switchery" class="js-switch" data-color="#039cfd" />
                                                    </div>
                                                    <label class="col-md-4 col-form-label" for="dropbox_keep">Dropbox Backups to Keep <i title="Enter 0 for unlimited. Oldest will be deleted." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-2">
                                                        <input type="text" class="form-control text-center" id="dropbox_keep" name="dropbox_keep" value="<?php echo htmlspecialchars($rSettings['dropbox_keep'] ?: 0); ?>">
                                                    </div>
                                                </div>
                                                <div class="form-group row mb-4">
                                                    <label class="col-md-4 col-form-label" for="dropbox_token">Dropbox Token <i title="Create an application in the Dropbox Developer section, set the expiration to never then generate a token." class="tooltip text-secondary far fa-circle"></i></label>
                                                    <div class="col-md-8">
                                                        <input type="text" class="form-control" id="dropbox_token" name="dropbox_token" value="<?php echo htmlspecialchars($rSettings['dropbox_token']); ?>">
                                                    </div>
                                                </div>
                                                <?php if (strlen($rSettings['dropbox_token']) > 0 && !checkRemote()) : ?>
                                                    <div class="alert alert-danger text-center" role="alert">
                                                        Could not access your Dropbox through the API key provided above. Please generate a new one or check that your key is correct.
                                                    </div>
                                                <?php endif; ?>
                                                <table class="table table-striped table-borderless mb-0" id="datatable-backups">
                                                    <thead>
                                                        <tr>
                                                            <th class="text-center">Date</th>
                                                            <th class="text-center">Filename</th>
                                                            <th class="text-center">Filesize</th>
                                                            <th class="text-center">Local</th>
                                                            <th class="text-center">Dropbox</th>
                                                            <th class="text-center">Actions</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody></tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <ul class="list-inline wizard mb-0" style="margin-top:30px;">
                                            <li class="list-inline-item">
                                                <button id="create_backup" onClick="api('', 'backup')" class="btn btn-info">Create Backup Now</button>
                                            </li>
                                            <li class="list-inline-item float-right">
                                                <input name="submit_settings" type="submit" class="btn btn-primary" value="Save Changes" />
                                            </li>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>
<?php include 'footer.php';
		echo '        ' . "\r\n\t\t" . 'function api(rID, rType, rConfirm=false) {' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete this backup?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "restore") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Restore",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to restore from this backup? This will erase your current database.",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        $.toast("Restoring backup in background, please exit the system until complete.");' . "\r\n" . '                        $(".content-page").fadeOut();' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rType == "backup") {' . "\r\n\t\t\t\t" . '$.toast("Creating backup in background, this may take a few minutes.");' . "\r\n\t\t\t\t" . '$("#create_backup").attr("disabled", true);' . "\r\n\t\t\t" . '}' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=backup&sub=" + rType + "&filename=" + encodeURIComponent(rID), function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            $.toast("Backup successfully deleted.");' . "\r\n" . '                        }' . "\r\n" . '                        if (rType != "backup") {' . "\r\n" . '                            $("#datatable-backups").DataTable().ajax.reload(null, false);' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                        if (rType == "backup") {' . "\r\n" . '                            $("#create_backup").attr("disabled", false);' . "\r\n" . '                        }' . "\r\n" . '                        if (!$(".content-page").is(":visible")) {' . "\r\n" . '                            $(".content-page").fadeIn();' . "\r\n" . '                        }' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . '$("#datatable-backups").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'bInfo: false,' . "\r\n\t\t\t\t" . 'paging: false,' . "\r\n\t\t\t\t" . 'searching: false,' . "\r\n\t\t\t\t" . 'bSort: false,' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'serverSide: true,' . "\r\n\t\t\t\t" . 'ajax: {' . "\r\n\t\t\t\t\t" . 'url: "./table",' . "\r\n\t\t\t\t\t" . '"data": function(d) {' . "\r\n\t\t\t\t\t\t" . 'd.id = "backups"' . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,1,2,3,4,5]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-backups").css("width", "100%");' . "\r\n\t\t\t" . '$("#backups_to_keep").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n\t\t\t" . '$("#dropbox_keep").inputFilter(function(value) { return /^\\d*$/.test(value); });' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>