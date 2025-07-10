<?php include 'session.php'; ?>
<?php include 'functions.php'; ?>

<?php if (!checkPermissions()): ?>
    <?php goHome(); ?>
<?php endif; ?>

<?php $_TITLE = 'Login Logs'; ?>
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
                    <h4 class="page-title">Login Logs</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['date']; ?></th>
                                    <th class="text-center"><?php echo $_['type']; ?></th>
                                    <th class="text-center"><?php echo $_['status']; ?></th>
                                    <th class="text-center"><?php echo $_['username']; ?></th>
                                    <th class="text-center"><?php echo $_['access_code']; ?></th>
                                    <th class="text-center"><?php echo $_['ip']; ?></th>
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