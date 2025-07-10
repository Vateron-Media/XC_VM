<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Packages';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['packages']; ?></h4>
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
                        <?php echo $_['package_success']; ?>
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th><?php echo $_['package_name']; ?></th>
                                    <th class="text-center"><?php echo $_['trial']; ?></th>
                                    <th class="text-center"><?php echo $_['official']; ?></th>
                                    <th class="text-center"><?php echo $_['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getPackages() as $rPackage): ?>
                                    <?php if (!$rPackage['is_addon']): ?>
                                        <tr id="package-<?php echo $rPackage['id']; ?>">
                                            <td class="text-center"><?php echo $rPackage['id']; ?></td>
                                            <td><?php echo $rPackage['package_name']; ?></td>
                                            <td class="text-center">
                                                <?php if ($rPackage['is_trial']): ?>
                                                    <i class='text-success mdi mdi-circle'></i>
                                                <?php else: ?>
                                                    <i class='text-secondary mdi mdi-circle'></i>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if ($rPackage['is_official']): ?>
                                                    <i class='text-success mdi mdi-circle'></i>
                                                <?php else: ?>
                                                    <i class='text-secondary mdi mdi-circle'></i>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <?php if (hasPermissions('adv', 'edit_package')): ?>
                                                    <div class="btn-group">
                                                        <a href="./package?id=<?php echo $rPackage['id']; ?>"><button type="button" data-toggle="tooltip" data-placement="top" title="<?php echo $_['edit_package']; ?>" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
                                                        <button type="button" data-toggle="tooltip" data-placement="top" title="<?php echo $_['delete_package']; ?>" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rPackage['id']; ?>, 'delete');"><i class="mdi mdi-close"></i></button>
                                                    </div>
                                                <?php else: ?>
                                                    --
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
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