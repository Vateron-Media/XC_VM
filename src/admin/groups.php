<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Groups';
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
                    <h4 class="page-title"><?php echo $_['groups']; ?></h4>
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
                        <?php echo $_['group_success']; ?>
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th><?php echo $_['group_name']; ?></th>
                                    <th class="text-center"><?php echo $_['is_admin']; ?></th>
                                    <th class="text-center"><?php echo $_['is_reseller']; ?></th>
                                    <th class="text-center">Subresellers</th>
                                    <th class="text-center"><?php echo $_['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getMemberGroups() as $rGroup): ?>
                                    <tr id="group-<?php echo $rGroup['group_id']; ?>">
                                        <td class="text-center"><?php echo $rGroup['group_id']; ?></td>
                                        <td><?php echo $rGroup['group_name']; ?></td>
                                        <td class="text-center">
                                            <?php if ($rGroup['is_admin']): ?>
                                                <i class='text-success mdi mdi-circle'></i>
                                            <?php else: ?>
                                                <i class='text-secondary mdi mdi-circle'></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($rGroup['is_reseller']): ?>
                                                <i class='text-success mdi mdi-circle'></i>
                                            <?php else: ?>
                                                <i class='text-secondary mdi mdi-circle'></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if ($rGroup['create_sub_resellers']): ?>
                                                <i class='text-success mdi mdi-circle'></i>
                                            <?php else: ?>
                                                <i class='text-secondary mdi mdi-circle'></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <?php if (hasPermissions('adv', 'edit_group')): ?>
                                                    <a href="./group?id=<?php echo $rGroup['group_id']; ?>">
                                                        <button type="button" data-toggle="tooltip" data-placement="top" title="<?php echo $_['edit_group']; ?>" class="btn btn-light waves-effect waves-light btn-xs">
                                                            <i class="mdi mdi-pencil-outline"></i>
                                                        </button>
                                                    </a>
                                                    <?php if ($rGroup['can_delete']): ?>
                                                        <button type="button" data-toggle="tooltip" data-placement="top" title="<?php echo $_['delete_group']; ?>" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rGroup['group_id']; ?>, 'delete');">
                                                            <i class="mdi mdi-close"></i>
                                                        </button>
                                                    <?php endif; ?>
                                                <?php endif; ?>
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