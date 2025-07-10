<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (isset(CoreUtilities::$rRequest['flush'])) {
    flushIPs();
    header('Location: ./ips?status=' . STATUS_FLUSH);
    exit;
}

$_TITLE = "Blocked IP's";
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['blocked_ip_addresses']; ?></h4>
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
                        IP has been added to the block list and propagated across all servers.
                    </div>
                <?php elseif (isset($_STATUS) && $_STATUS == STATUS_FLUSH): ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        All blocked IP's have been flushed from the database.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th class="text-center"><?php echo $_['ip_address']; ?></th>
                                    <th><?php echo $_['notes']; ?></th>
                                    <th class="text-center"><?php echo $_['date']; ?></th>
                                    <th class="text-center"><?php echo $_['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getBlockedIPs() as $rIP): ?>
                                    <tr id="ip-<?php echo $rIP['id']; ?>">
                                        <td class="text-center"><?php echo $rIP['id']; ?></td>
                                        <td class="text-center"><?php echo $rIP['ip']; ?></td>
                                        <td><?php echo $rIP['notes']; ?></td>
                                        <td class="text-center"><?php echo date('Y-m-d H:i:s', $rIP['date']); ?></td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rIP['id']; ?>, 'delete');"><i class="mdi mdi-close"></i></button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>