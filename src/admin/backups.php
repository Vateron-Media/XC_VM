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
<?php include 'footer.php'; ?>