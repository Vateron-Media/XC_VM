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
<?php include 'footer.php'; ?>