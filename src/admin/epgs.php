<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
    exit;
}

$_TITLE = 'EPG Files';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php echo empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest' ? '' : ' style="display: none;"'; ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['epgs']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        EPG has been added and will be scanned during the next cron run.
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th><?php echo $_['epg_name']; ?></th>
                                    <th><?php echo $_['source']; ?></th>
                                    <th class="text-center"><?php echo $_['days_to_keep']; ?></th>
                                    <th class="text-center"><?php echo $_['last_updated']; ?></th>
                                    <th class="text-center">Channels</th>
                                    <th class="text-center"><?php echo $_['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getEPGs() as $rEPG) { ?>
                                    <tr id="epg-<?php echo $rEPG['id']; ?>">
                                        <td class="text-center"><?php echo $rEPG['id']; ?></td>
                                        <td><?php echo $rEPG['epg_name']; ?></td>
                                        <td><?php echo parse_url($rEPG['epg_file'])['host']; ?></td>
                                        <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min"><?php echo $rEPG['days_keep']; ?></button></td>
                                        <td class="text-center"><?php echo $rEPG['last_updated'] ? date('Y-m-d H:i', $rEPG['last_updated']) : $_['never']; ?></td>
                                        <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min"><?php echo number_format(count(json_decode($rEPG['data'], true)), 0); ?></button></td>
                                        <td class="text-center">
                                            <?php if (hasPermissions('adv', 'epg_edit')) { ?>
                                                <div class="btn-group">
                                                    <a href="./epg?id=<?php echo $rEPG['id']; ?>"><button type="button" title="<?php echo $_['edit_epg']; ?>" class="tooltip btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
                                                    <button type="button" title="Force Reload" class="tooltip btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rEPG['id']; ?>, 'reload');"><i class="mdi mdi-refresh"></i></button>
                                                    <button type="button" title="<?php echo $_['delete_epg']; ?>" class="tooltip btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rEPG['id']; ?>, 'delete');"><i class="mdi mdi-close"></i></button>
                                                </div>
                                            <?php } else {
                                                echo '--';
                                            } ?>
                                        </td>
                                    </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>