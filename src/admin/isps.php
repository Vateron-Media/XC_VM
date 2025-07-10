<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = "Blocked ISP's";
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') echo ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Blocked ISP's</h4>
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
                        ISP has been blocked on all servers.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th>ISP Name</th>
                                    <th class="text-center">Blocked</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getISPs() as $rISP): ?>
                                    <tr id="isp-<?php echo $rISP['id']; ?>">
                                        <td class="text-center"><?php echo $rISP['id']; ?></td>
                                        <td><?php echo $rISP['isp']; ?></td>
                                        <td class="text-center">
                                            <?php echo $rISP['blocked'] ? '<i class="text-success fas fa-square"></i>' : '<i class="text-secondary fas fa-square"></i>'; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <a href="./isp?id=<?php echo $rISP['id']; ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
                                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rISP['id']; ?>, 'delete');"><i class="mdi mdi-close"></i></button>
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