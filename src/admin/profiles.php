<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$_TITLE = 'Transcoding Profiles';
include 'header.php';
?>
<div class="wrapper boxed-layout-ext" <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
                                        } else {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?php echo $_['transcode_profiles']; ?></h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-12">
                <?php if (!(isset($_STATUS) && $_STATUS == STATUS_SUCCESS)) {
                } else { ?>
                    <div class="alert alert-success alert-dismissible fade show" role="alert">
                        <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                            <span aria-hidden="true">&times;</span>
                        </button>
                        <?php echo $_['profile_success']; ?>
                    </div>
                <?php } ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <table id="datatable" class="table table-striped table-borderless dt-responsive nowrap">
                            <thead>
                                <tr>
                                    <th class="text-center"><?php echo $_['id']; ?></th>
                                    <th><?php echo $_['profile_name']; ?></th>
                                    <th class="text-center">GPU</th>
                                    <th class="text-center">Video</th>
                                    <th class="text-center">Audio</th>
                                    <th class="text-center">Resolution</th>
                                    <th class="text-center">Logo</th>
                                    <th class="text-center"><?php echo $_['actions']; ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (getTranscodeProfiles() as $rProfile) {
                                    $rProfileOptions = json_decode($rProfile['profile_options'], true); ?>
                                    <tr id="profile-<?php echo $rProfile['profile_id']; ?>">
                                        <td class="text-center"><?php echo $rProfile['profile_id']; ?></td>
                                        <td><?php echo $rProfile['profile_name']; ?></td>
                                        <td class="text-center">
                                            <?php if (isset($rProfileOptions['gpu'])) {
                                                echo '<i class="text-success fas fa-square"></i>';
                                            } else {
                                                echo '<i class="text-secondary fas fa-square"></i>';
                                            } ?>
                                        </td>
                                        <td class="text-center"><?php echo ($rProfileOptions['-vcodec'] ?: 'None'); ?></td>
                                        <td class="text-center"><?php echo ($rProfileOptions['-acodec'] ?: 'None'); ?></td>
                                        <td class="text-center">
                                            <?php if (isset($rProfileOptions['gpu'])) {
                                                echo ($rProfileOptions['gpu']['resize'] ?: str_replace(':', 'x', $rProfileOptions[9]['val']));
                                            } else {
                                                echo str_replace(':', 'x', $rProfileOptions[9]['val']);
                                            } ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (isset($rProfileOptions[16])) {
                                                echo '<i class="text-success fas fa-square"></i>';
                                            } else {
                                                echo '<i class="text-secondary fas fa-square"></i>';
                                            } ?>
                                        </td>
                                        <td class="text-center">
                                            <?php if (hasPermissions('adv', 'edit_tprofile')) { ?>
                                                <div class="btn-group">
                                                    <a href="./profile?id=<?php echo $rProfile['profile_id']; ?>"><button type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-pencil-outline"></i></button></a>
                                                    <button type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="api(<?php echo $rProfile['profile_id']; ?>, 'delete');"><i class="mdi mdi-close"></i></button>
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
		echo $_['profile_delete_confirm'];
		echo '",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        api(rID, rType, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n\t\t\t" . '} else {' . "\r\n" . '                rConfirm = true;' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=profile&sub=" + rType + "&profile_id=" + rID, function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rType == "delete") {' . "\r\n" . '                            if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {' . "\r\n" . '                                $("#datatable").DataTable().rows(rRow).remove().draw(false);' . "\r\n" . '                            }' . "\r\n" . '                            $.toast("';
		echo $_['profile_deleted'];
		echo '");' . "\r\n" . '                        }' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n\t\t" . '}' . "\r\n\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n" . '                    bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n" . '                order: [[ 1, "asc" ]],' . "\r\n" . '                columnDefs: [' . "\r\n\t\t\t\t\t" . '{"visible": false, "targets": [0]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t" . 'responsive: false' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>