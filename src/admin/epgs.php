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
                                        <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min"><?php echo number_format(count(json_decode($rEPG['data'] ?? '[]', true) ?? []), 0); ?></button></td>
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
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'function api(rID, rType, rConfirm=false) {' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "';
		echo $_['epg_confirm'];
		echo '",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=epg&sub=" + rType + "&epg_id=" + rID, function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {' . "\r\n" . '                                $("#datatable").DataTable().rows(rRow).remove().draw(false);' . "\r\n" . '                            }' . "\r\n" . '                            $.toast("';
		echo $_['epg_deleted'];
		echo '");' . "\r\n" . '                        } else if (rType == "reload") {' . "\r\n" . '                            $.toast("';
		echo $_['updating_epg'];
		echo '");' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\t\t" . 'function forceUpdate() {' . "\r\n\t\t\t" . '$("#force_update").attr("disabled", true);' . "\r\n\t\t\t" . '$.toast("';
		echo $_['updating_epg'];
		echo '");' . "\r\n\t\t\t" . '$.getJSON("./api?action=force_epg", function(data) {' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n" . '                order: [[ 4, "desc" ]],' . "\r\n" . '                columnDefs: [' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'responsive: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>