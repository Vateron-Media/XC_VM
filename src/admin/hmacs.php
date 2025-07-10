<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'HMAC Keys';
include 'header.php';
?>
<div class="wrapper boxed-layout" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') echo ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">HMAC Keys</h4>
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
                        HMAC Token has been authenticated and can now be used.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th>Description</th>
                                    <th class="text-center">Enabled</th>
                                    <th class="text-center"><?php echo $_['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getHMACTokens() as $rHMAC): ?>
                                    <tr id="hmac-<?php echo $rHMAC['id']; ?>">
                                        <td class="text-center"><?php echo $rHMAC['id']; ?></td>
                                        <td><?php echo $rHMAC['notes']; ?></td>
                                        <td class="text-center">
                                            <?php if ($rHMAC['enabled']): ?>
                                                <i class="text-success fas fa-square"></i>
                                            <?php else: ?>
                                                <i class="text-secondary fas fa-square"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <a href="./hmac?id=<?php echo $rHMAC['id']; ?>">
                                                    <button type="button" data-toggle="tooltip" data-placement="top" title="Edit Key" class="btn btn-light waves-effect waves-light btn-xs">
                                                        <i class="mdi mdi-pencil-outline"></i>
                                                    </button>
                                                </a>
                                                <button type="button" data-toggle="tooltip" data-placement="top" title="Delete Key" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rHMAC['id']; ?>, 'delete');">
                                                    <i class="mdi mdi-close"></i>
                                                </button>
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