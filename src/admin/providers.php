<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Stream Providers';
include 'header.php';
?>
<div class="wrapper" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                            echo ' style="display: none;"';
                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title">Stream Providers</h4>
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
                        Provider has been added to the database and will be periodically scanned.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th class="text-center">Status</th>
                                    <th>Provider</th>
                                    <th class="text-center">Username</th>
                                    <th class="text-center">Connections</th>
                                    <th class="text-center">Streams</th>
                                    <th class="text-center">Movies</th>
                                    <th class="text-center">Series</th>
                                    <th class="text-center">Expires</th>
                                    <th class="text-center">Last Changed</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getStreamProviders() as $rProvider):
                                    $rData = json_decode($rProvider['data'], true);
                                    $rStatus = !$rProvider['enabled'] ? '<i class="text-secondary fas fa-square"></i>' : ($rProvider['enabled'] && $rProvider['status'] ? '<i class="text-success fas fa-square"></i>' : '<i class="text-danger fas fa-square"></i>');
                                ?>
                                    <tr id="provider-<?php echo $rProvider['id']; ?>">
                                        <td class="text-center"><?php echo $rProvider['id']; ?></td>
                                        <td class="text-center"><?php echo $rStatus; ?></td>
                                        <td><?php echo $rProvider['name']; ?><br /><small><?php echo $rProvider['ip']; ?>:<?php echo $rProvider['port']; ?></small></td>
                                        <td class="text-center"><?php echo $rProvider['username']; ?></td>
                                        <td class="text-center">
                                            <?php
                                            if ($rData['max_connections'] > 0) {
                                                $rColour = $rData['max_connections'] * 0.75 < $rData['active_connections'] ? 'danger' : ($rData['max_connections'] * 0.5 < $rData['active_connections'] ? 'warning' : 'success');
                                                echo '<a href="streams?search=' . urlencode(strtolower($rProvider['ip'])) . '&filter=1"><button type="button" class="btn btn-' . $rColour . ' btn-xs waves-effect waves-light">' . number_format($rData['active_connections'], 0) . ' / ' . number_format($rData['max_connections'], 0) . '</button></a>';
                                            } else {
                                                echo '<a href="streams?search=' . urlencode(strtolower($rProvider['ip'])) . '&filter=1"><button type="button" class="btn btn-success btn-xs waves-effect waves-light">' . number_format($rData['active_connections'], 0) . ' / &infin;</button></a>';
                                            }
                                            ?>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-<?php echo $rData['streams'] > 0 ? 'info' : 'secondary'; ?> btn-xs waves-effect waves-light"><?php echo number_format($rData['streams'], 0); ?></button>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-<?php echo $rData['movies'] > 0 ? 'info' : 'secondary'; ?> btn-xs waves-effect waves-light"><?php echo number_format($rData['movies'], 0); ?></button>
                                        </td>
                                        <td class="text-center">
                                            <button type="button" class="btn btn-<?php echo $rData['series'] > 0 ? 'info' : 'secondary'; ?> btn-xs waves-effect waves-light"><?php echo number_format($rData['series'], 0); ?></button>
                                        </td>
                                        <td class="text-center">
                                            <?php echo $rData['exp_date'] == -1 ? 'Unknown' : ($rData['exp_date'] ? date('Y-m-d', $rData['exp_date']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rData['exp_date']) . '</small>' : 'Never'); ?>
                                        </td>
                                        <td class="text-center">
                                            <?php echo $rProvider['last_changed'] ? date('Y-m-d', $rProvider['last_changed']) . "<br/><small class='text-secondary'>" . date('H:i:s', $rProvider['last_changed']) . '</small>' : 'Never'; ?>
                                        </td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <a href="provider?id=<?php echo $rProvider['id']; ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil"></i></button></a>
                                                <button type="button" title="Force Reload" class="tooltip btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rProvider['id']; ?>, 'reload');"><i class="mdi mdi-refresh"></i></button>
                                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rProvider['id']; ?>, 'delete');"><i class="mdi mdi-close"></i></button>
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
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'function api(rID, rType, rConfirm=false) {' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . "                    content: \"Are you sure you want to delete this provider?<br/>This won't affect your streams.\"," . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=provider&sub=" + rType + "&id=" + rID, function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {' . "\r\n" . '                                $("#datatable").DataTable().rows(rRow).remove().draw(false);' . "\r\n" . '                            }' . "\r\n" . '                            $.toast("Provider has been deleted.");' . "\r\n" . '                        } else if (rType == "reload") {' . "\r\n" . '                            $.toast("Updating provider in background...");' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n" . '                order: [[ 9, "desc" ]],' . "\r\n" . '                columnDefs: [' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'responsive: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>