<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = "RTMP IP's";
include 'header.php';
?>

<div class="wrapper boxed-layout-ext"
    <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">RTMP IP Addresses</h4>
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
                        <?php echo $_['rtmp_success']; ?>
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">IP Address</th>
                                    <th class="text-center">Password</th>
                                    <th class="text-center">Push</th>
                                    <th class="text-center">Pull</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getRTMPIPs() as $rIP): ?>
                                    <tr id="ip-<?php echo $rIP['id']; ?>">
                                        <td class="text-center"><?php echo $rIP['id']; ?></td>
                                        <td class="text-center"><?php echo $rIP['ip']; ?></td>
                                        <td class="text-center"><?php echo $rIP['password']; ?></td>
                                        <td class="text-center">
                                            <?php if ($rIP['push']): ?>
                                                <i class="text-success fas fa-square"></i>
                                            <?php else: ?>
                                                <i class="text-secondary fas fa-square"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($rIP['pull']): ?>
                                                <i class="text-success fas fa-square"></i>
                                            <?php else: ?>
                                                <i class="text-secondary fas fa-square"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <?php if (hasPermissions('adv', 'add_rtmp')): ?>
                                                    <a href="./rtmp_ip?id=<?php echo $rIP['id']; ?>"><button type="button"
                                                            class="btn btn-light waves-effect waves-light btn-xs"><i
                                                                class="mdi mdi-pencil-outline"></i></button></a>
                                                <?php endif; ?>
                                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs"
                                                    onClick="api(<?php echo $rIP['id']; ?>, 'delete');"><i
                                                        class="mdi mdi-close"></i></button>
                                            </div>
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