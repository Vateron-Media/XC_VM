<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$_TITLE = 'Plex Sync';
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
                    <h4 class="page-title">Plex Sync</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (isset($_STATUS) && $_STATUS == STATUS_SUCCESS) : ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        The server is now being synced. It will be scanned during the next Plex Sync run.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center">ID</th>
                                    <th class="text-center">Status</th>
                                    <th class="text-center">Plex IP</th>
                                    <th>Server Name</th>
                                    <th>Library</th>
                                    <th class="text-center">Last Run</th>
                                    <th class="text-center">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getPlexServers() as $rServer) :
                                    $rDate = ($rServer['last_run'] > 0) ? date('Y-m-d H:i:s', $rServer['last_run']) : 'Never';
                                    $rLibraryName = 'Unknown';
                                    foreach (json_decode($rServer['plex_libraries'], true) as $rLibrary) {
                                        if (intval($rLibrary['key']) == intval($rServer['directory'])) {
                                            $rLibraryName = $rLibrary['title'];
                                            break;
                                        }
                                    }
                                    $rServerAdd = is_null($rServer['server_add']) ? 0 : count(json_decode($rServer['server_add'], true));
                                ?>
                                    <tr id="folder-<?= intval($rServer['id']); ?>">
                                        <td class="text-center"><?= intval($rServer['id']); ?></td>
                                        <td class="text-center">
                                            <?php if ($rServer['active']) : ?>
                                                <i class="text-success fas fa-square"></i>
                                            <?php else : ?>
                                                <i class="text-secondary fas fa-square"></i>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-center"><?= $rServer['plex_ip']; ?></td>
                                        <td>
                                            <?= $rServers[$rServer['server_id']]['server_name'] . ($rServerAdd > 0 ? "&nbsp; <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>+ " . $rServerAdd . "</button>" : ""); ?>
                                        </td>
                                        <td><?= $rLibraryName; ?></td>
                                        <td class="text-center"><?= $rDate; ?></td>
                                        <td class="text-center">
                                            <div class="btn-group">
                                                <a href="./plex_add?id=<?= intval($rServer['id']); ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
                                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?= intval($rServer['id']); ?>, 'force');"><i class="mdi mdi-refresh"></i></button>
                                                <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?= intval($rServer['id']); ?>, 'delete');"><i class="mdi mdi-close"></i></button>
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
		echo '        ' . "\r\n" . '        function disableAll(rConfirm=false) {' . "\r\n" . '            if (!rConfirm) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Disable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to disable all libraries?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        disableAll(true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else {' . "\r\n" . '                $.getJSON("./api?action=disable_plex", function(data) {' . "\r\n" . '                    $.toast("Libraries have been disabled.");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function enableAll(rConfirm=false) {' . "\r\n" . '            if (!rConfirm) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Enable",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to enable all libraries?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        enableAll(true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else {' . "\r\n" . '                $.getJSON("./api?action=enable_plex", function(data) {' . "\r\n" . '                    $.toast("Libraries have been enabled.");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        function killPlexSync(rConfirm=false) {' . "\r\n" . '            if (!rConfirm) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Kill",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to kill all processes?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        killPlexSync(true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else {' . "\r\n" . '                $.getJSON("./api?action=kill_plex", function(data) {' . "\r\n" . '                    $.toast("Plex Sync processes have been killed.");' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n\t\t" . 'function api(rID, rType, rConfirm=false) {' . "\r\n" . '            if ((rType == "delete") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to delete this library?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            } else if ((rType == "force") && (!rConfirm)) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Run",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to force this library to run now?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=library&sub=" + rType + "&folder_id=" + rID, function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {' . "\r\n" . '                                $("#datatable").DataTable().rows(rRow).remove().draw(false);' . "\r\n" . '                            }' . "\r\n" . '                            $.toast("Library successfully deleted.");' . "\r\n" . '                        } else if (rType == "force") {' . "\r\n" . '                            $.toast("Library has been forced to sync in the background.");' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("An error occured while processing your request.");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n" . '                order: [[ 5, "desc" ]],' . "\r\n" . '                columnDefs: [' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'responsive: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n" . '            $("form").submit(function(e){' . "\r\n" . '                e.preventDefault();' . "\r\n" . "                \$(':input[type=\"submit\"]').prop('disabled', true);" . "\r\n" . '                submitForm(window.rCurrentPage, new FormData($("form")[0]));' . "\r\n" . '            });' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>