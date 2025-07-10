<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()) {
    goHome();
} ?>

<?php $_TITLE = 'Restream Detection Logs'; ?>
<?php include 'header.php'; ?>

<div class="wrapper boxed-layout-ext"
    <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        echo ' style="display: none;"';
    } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <h4 class="page-title">Restream Detection Logs</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="alert alert-info" role="alert">
                    <?php
                    if (CoreUtilities::$rSettings['detect_restream_block_user'] && CoreUtilities::$rSettings['detect_restream_block_ip']) {
                        echo 'Your service is set up to automatically block the lines and IP addresses of those detected restreaming without permission.';
                    } elseif (!CoreUtilities::$rSettings['detect_restream_block_user'] && CoreUtilities::$rSettings['detect_restream_block_ip']) {
                        echo 'Your service is set up to automatically block the IP addresses of those detected restreaming without permission. Lines will remain active.';
                    } elseif (CoreUtilities::$rSettings['detect_restream_block_user'] && !CoreUtilities::$rSettings['detect_restream_block_ip']) {
                        echo "Your service is set up to automatically block the lines of those detected restreaming without permission. IP's will not be automatically blocked.";
                    } else {
                        echo "Your service is set up to detect restreaming without permission, however it won't automatically block IP addresses or lines.";
                    }
                    ?>
                </div>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th><?php echo $_['username']; ?></th>
                                    <th><?php echo $_['stream']; ?></th>
                                    <th class="text-center"><?php echo $_['ip']; ?></th>
                                    <th class="text-center"><?php echo $_['date']; ?></th>
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