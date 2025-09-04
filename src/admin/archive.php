<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

$rRecordings = null;

if (isset(CoreUtilities::$rRequest['id'])) {
    $rStream = getStream(CoreUtilities::$rRequest['id']);

    if (!$rStream || $rStream['type'] != 1 || $rStream['tv_archive_duration'] == 0 || $rStream['tv_archive_server_id'] == 0) {
        goHome();
    }

    $rArchive = getArchive($rStream['id']);
} else {
    $rRecordings = getRecordings();
}

$_TITLE = (!is_null($rRecordings) ? 'Recordings' : 'TV Archive');
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
                    <?php if (!is_null($rRecordings)) : ?>
                        <h4 class="page-title">Recordings</h4>
                    <?php else : ?>
                        <h4 class="page-title"><?php echo $rStream['stream_display_name']; ?><small> - TV Archive</small></h4>
                    <?php endif; ?>
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
                        Recording has been scheduled.
                    </div>
                <?php endif; ?>
                <div class="card">
                    <div class="card-body" style="overflow-x:auto;">
                        <div class="table">
                            <table id="datatable" class="table table-striped table-borderless mb-0">
                                <thead>
                                    <tr>
                                        <th class="text-center">ID</th>
                                        <th class="text-center">Date</th>
                                        <th class="text-center">Duration</th>
                                        <th>Title</th>
                                        <th class="text-center">Status</th>
                                        <th class="text-center">Player</th>
                                        <th class="text-center">Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (!is_null($rRecordings)) : ?>
                                        <?php foreach ($rRecordings as $rItem) : ?>
                                            <?php $rDuration = $rItem['end'] - $rItem['start']; ?>
                                            <?php if ($rItem['status'] == 0 && !$rItem['archive'] && $rItem['end'] < time()) $rItem['status'] = 3; ?>
                                            <tr>
                                                <td><?php echo $rItem['id']; ?></td>
                                                <td class="text-center"><?php echo date($rSettings['date_format'] . ' H:i', $rItem['start']); ?></td>
                                                <td class="text-center"><?php echo sprintf('%02dh %02dm', $rDuration / 3600, ($rDuration / 60) % 60); ?></td>
                                                <td><?php echo $rItem['title']; ?></td>
                                                <td class="text-center">
                                                    <?php if ($rItem['status'] == 0) : ?>
                                                        <button type='button' class='btn btn-light btn-xs waves-effect waves-light'>WAITING</button>
                                                    <?php elseif ($rItem['status'] == 1) : ?>
                                                        <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>RECORDING</button>
                                                    <?php elseif ($rItem['status'] == 2) : ?>
                                                        <button type='button' class='btn btn-success btn-xs waves-effect waves-light'>COMPLETE</button>
                                                    <?php else : ?>
                                                        <button type='button' class='btn btn-danger btn-xs waves-effect waves-light'>FAILED</button>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <?php if ($rItem['created_id']) : ?>
                                                        <button type="button" class="btn btn-info waves-effect waves-light btn-xs" onclick="player(<?php echo intval($rItem['created_id']); ?>);"><i class="mdi mdi-play"></i></button>
                                                    <?php else : ?>
                                                        <button disabled type="button" class="btn btn-info waves-effect waves-light btn-xs"><i class="mdi mdi-play"></i></button>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group">
                                                        <?php if ($rItem['created_id']) : ?>
                                                            <a href="stream_view?id=<?php echo intval($rItem['created_id']); ?>"><button title="View Movie" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-movie-outline"></i></button></a>
                                                        <?php else : ?>
                                                            <button disabled type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-movie-outline"></i></button>
                                                        <?php endif; ?>
                                                        <button title="Delete Recording" onClick="deleteRecording(<?php echo intval($rItem['id']); ?>)" type="button" class="btn btn-light waves-effect waves-light btn-xs tooltip"><i class="mdi mdi-close"></i></button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else : ?>
                                        <?php foreach ($rArchive as $rItem) : ?>
                                            <?php
                                            $rDuration = $rItem['end'] - $rItem['start'];
                                            $rItem['stream_id'] = CoreUtilities::$rRequest['id'];
                                            ?>
                                            <tr>
                                                <td><?php echo $rItem['id']; ?></td>
                                                <td class="text-center"><?php echo date($rSettings['date_format'] . ' H:i', $rItem['start']); ?></td>
                                                <td class="text-center"><?php echo sprintf('%02dh %02dm', $rDuration / 3600, ($rDuration / 60) % 60); ?></td>
                                                <td><?php echo $rItem['title']; ?></td>
                                                <td class="text-center">
                                                    <?php if ($rItem['in_progress']) : ?>
                                                        <button type='button' class='btn btn-info btn-xs waves-effect waves-light'>IN PROGRESS</button>
                                                    <?php elseif ($rItem['complete']) : ?>
                                                        <button type='button' class='btn btn-success btn-xs waves-effect waves-light'>COMPLETE</button>
                                                    <?php else : ?>
                                                        <button type='button' class='btn btn-warning btn-xs waves-effect waves-light'>INCOMPLETE</button>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-center"><button type="button" class="btn btn-info waves-effect waves-light btn-xs" onclick="player(<?php echo intval($rStream['id']); ?>, <?php echo intval($rItem['start']); ?>, <?php echo intval($rDuration / 60); ?>);"><i class="mdi mdi-play"></i></button></td>
                                                <td class="text-center">
                                                    <?php if (!$rItem['in_progress']) : ?>
                                                        <a href="record?archive=<?php echo urlencode(base64_encode(json_encode($rItem))); ?>"><button type="button" class="btn btn-danger waves-effect waves-light btn-xs"><i class="mdi mdi-record"></i></button></a>
                                                    <?php else : ?>
                                                        <button disabled type="button" class="btn btn-danger waves-effect waves-light btn-xs"><i class="mdi mdi-record"></i></button>
                                                    <?php endif; ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
<?php include 'footer.php';
		echo '        ' . "\r\n" . '        function player(rID, rStart=null, rDuration=null) {' . "\r\n" . '            if (rStart) {' . "\r\n" . '                rURL = "./player?type=timeshift&id=" + rID + "&start=" + rStart + "&duration=" + rDuration;' . "\r\n" . '            } else {' . "\r\n" . '                rURL = "./player?type=movie&id=" + rID + "&container=mp4";' . "\r\n" . '            }' . "\r\n" . '            $.magnificPopup.open({' . "\r\n" . '                items: {' . "\r\n" . '                    src: rURL,' . "\r\n" . "                    type: 'iframe'" . "\r\n" . '                }' . "\r\n" . '            });' . "\r\n" . '        }' . "\r\n" . '        ' . "\r\n" . '        function deleteRecording(rID, rConfirm=false) {' . "\r\n" . '            if (!rConfirm) {' . "\r\n" . '                new jBox("Confirm", {' . "\r\n" . '                    confirmButton: "Delete",' . "\r\n" . '                    cancelButton: "Cancel",' . "\r\n" . '                    content: "Are you sure you want to cancel and delete this recording?",' . "\r\n" . '                    confirm: function () {' . "\r\n" . '                        deleteRecording(rID, true);' . "\r\n" . '                    }' . "\r\n" . '                }).open();' . "\r\n" . '            }' . "\r\n" . '            if (rConfirm) {' . "\r\n" . '                $.getJSON("./api?action=delete_recording&id=" + rID, function(data) {' . "\r\n" . '                    if (data.result === true) {' . "\r\n" . '                        if (rRow = findRowByID($("#datatable").DataTable(), 0, rID)) {' . "\r\n" . '                            $("#datatable").DataTable().rows(rRow).remove().draw(false);' . "\r\n" . '                        }' . "\r\n" . '                        $.toast("Recording has been deleted.");' . "\r\n" . '                    } else {' . "\r\n" . '                        $.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n" . '                    }' . "\r\n" . '                });' . "\r\n" . '            }' . "\r\n" . '        }' . "\r\n" . '        ' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . '$("#datatable").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '}' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n" . '                columnDefs: [' . "\r\n" . '                    {"visible": false, "targets": [0]}' . "\r\n" . '                ],' . "\r\n" . '                order: [[ 1, "desc" ]]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable").css("width", "100%");' . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n\t\t";
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>