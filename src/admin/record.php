<?php

include 'session.php';
include 'functions.php';

if (checkPermissions()) {
} else {
    goHome();
}

$rAvailableServers = $rServers = array();
$rStream = $rProgramme = null;

if (isset(CoreUtilities::$rRequest['id'])) {
    $rStream = getStream(CoreUtilities::$rRequest['id']);
    $rProgramme = CoreUtilities::getProgramme(CoreUtilities::$rRequest['id'], CoreUtilities::$rRequest['programme']);

    if ($rStream && $rStream['type'] == 1 && $rProgramme) {
    } else {
        goHome();
    }
} else {
    if (isset(CoreUtilities::$rRequest['archive'])) {
        $rArchive = json_decode(base64_decode(CoreUtilities::$rRequest['archive']), true);
        $rStream = getStream($rArchive['stream_id']);
        $rProgramme = array('start' => $rArchive['start'], 'end' => $rArchive['end'], 'title' => $rArchive['title'], 'description' => $rArchive['description'], 'archive' => true);

        if ($rStream && $rStream['type'] == 1 && $rProgramme) {
        } else {
            goHome();
        }
    } else {
        if (!isset(CoreUtilities::$rRequest['stream_id'])) {
        } else {
            $rStream = getStream(CoreUtilities::$rRequest['stream_id']);
            $rProgramme = array('start' => strtotime(CoreUtilities::$rRequest['start_date']), 'end' => strtotime(CoreUtilities::$rRequest['start_date']) + intval(CoreUtilities::$rRequest['duration']) * 60, 'title' => '', 'description' => '');

            if (!(!$rStream || $rStream['type'] != 1 || !$rProgramme || $rProgramme['end'] < time())) {
            } else {
                header('Location: record');
            }
        }
    }
}

if (!$rStream) {
} else {
    $rBitrate = null;
    $db->query('SELECT `server_id`, `bitrate` FROM `streams_servers` WHERE `stream_id` = ?;', $rStream['id']);

    foreach ($db->get_rows() as $rRow) {
        $rAvailableServers[] = $rRow['server_id'];

        if (!(!$rBitrate && $rRow['bitrate'] || $rRow['bitrate'] && $rBitrate < $rRow['bitrate'])) {
        } else {
            $rBitrate = $rRow['bitrate'];
        }
    }
}

$_TITLE = 'Record';
include 'header.php'; ?>
<div class="wrapper boxed-layout"
    <?php if (empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest') {
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
                    <h4 class="page-title">Record an Event</h4>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <?php if ($rStream) {
                        } else { ?>
                            <form action="record" method="POST" data-parsley-validate="">
                            <?php } ?>
                            <table class="table table-borderless mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>Channel</th>
                                        <th class="text-center">Start</th>
                                        <th class="text-center"><?php echo ($rStream ? 'Finish' : 'Minutes'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr>
                                        <?php if ($rStream) { ?>
                                            <td><?php echo $rStream['stream_display_name']; ?></td>
                                            <td class="text-center">
                                                <?php echo date(CoreUtilities::$rSettings['date_format'], $rProgramme['start']); ?><br /><?php echo date('H:i', $rProgramme['start']); ?>
                                            </td>
                                            <td class="text-center">
                                                <?php echo date(CoreUtilities::$rSettings['date_format'], $rProgramme['end']); ?><br /><?php echo date('H:i', $rProgramme['end']); ?>
                                            </td>
                                        <?php } else { ?>
                                            <td><select id="stream_id" name="stream_id" class="form-control"
                                                    data-toggle="select2"></select></td>
                                            <td style="max-width:120px;" class="text-center"><input type="text"
                                                    class="form-control text-center date" id="start_date" name="start_date"
                                                    value="" data-toggle="date-picker" data-single-date-picker="true"></td>
                                            <td style="max-width:40px;" class="text-center"><input type="text"
                                                    class="form-control text-center" id="duration" name="duration"
                                                    value="0"></td>
                                        <?php } ?>
                                    </tr>
                                </tbody>
                            </table>
                            <?php if ($rStream) {
                            } else { ?>
                                <ul class="list-inline wizard mb-0">
                                    <li class="list-inline-item float-right">
                                        <input type="submit" class="btn btn-primary" value="Continue" />
                                    </li>
                                </ul>
                            </form>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <?php if (!$rStream) {
            } else { ?>
                <div class="col-xl-12">
                    <?php if ($rProgramme['archive'] || $rProgramme['start'] > time()) {
                    } else { ?>
                        <div class="alert alert-warning text-center" role="alert">
                            The programme you are intending to record has already started!
                        </div>
                    <?php } ?>
                    <div class="card">
                        <div class="card-body">
                            <form
                                <?php if (!isset(CoreUtilities::$rRequest['import'])) {
                                } else {
                                    echo ' enctype="multipart/form-data"';
                                } ?>
                                action="#" method="POST" data-parsley-validate="">
                                <input type="hidden" name="stream_id" value="<?php echo intval($rStream['id']); ?>" />
                                <input type="hidden" name="start" value="<?php echo intval($rProgramme['start']); ?>" />
                                <input type="hidden" name="end" value="<?php echo intval($rProgramme['end']); ?>" />
                                <input type="hidden" name="archive"
                                    value="<?php echo (isset($rProgramme['archive']) ? 1 : 0); ?>" />
                                <div id="basicwizard">
                                    <ul class="nav nav-pills bg-light nav-justified form-wizard-header mb-4">
                                        <li class="nav-item">
                                            <a href="#stream-details" data-toggle="tab"
                                                class="nav-link rounded-0 pt-2 pb-2">
                                                <i class="mdi mdi-account-card-details-outline mr-1"></i>
                                                <span class="d-none d-sm-inline">Details</span>
                                            </a>
                                        </li>
                                    </ul>
                                    <div class="tab-content b-0 mb-0 pt-0">
                                        <div class="tab-pane" id="stream-details">
                                            <div class="row">
                                                <div class="col-12">
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="title">Event
                                                            Title</label>
                                                        <div class="col-md-8">
                                                            <input type="text" class="form-control" id="title" name="title"
                                                                value="<?php echo str_replace('"', '&quot;', $rProgramme['title']); ?>"
                                                                required data-parsley-trigger="change">
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="description">Event
                                                            Description</label>
                                                        <div class="col-md-8">
                                                            <textarea rows="6" class="form-control" id="description"
                                                                name="description"><?php echo htmlspecialchars($rProgramme['description']); ?></textarea>
                                                        </div>
                                                    </div>
                                                    <!-- Additional form fields can be added here -->
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="stream_icon">Poster
                                                            URL</label>
                                                        <div class="col-md-8 input-group">
                                                            <input type="text" class="form-control" id="stream_icon"
                                                                name="stream_icon" value="">
                                                            <div class="input-group-append">
                                                                <a href="javascript:void(0)" onClick="openImage(this)"
                                                                    class="btn btn-primary waves-effect waves-light"><i
                                                                        class="mdi mdi-eye"></i></a>
                                                            </div>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label"
                                                            for="category_id">Categories</label>
                                                        <div class="col-md-8">
                                                            <select name="category_id[]" id="category_id"
                                                                class="form-control select2-multiple" data-toggle="select2"
                                                                multiple="multiple" data-placeholder="Choose...">
                                                                <?php foreach (getCategories('movie') as $rCategory) { ?>
                                                                    <option value="<?php echo $rCategory['id']; ?>">
                                                                        <?php echo $rCategory['category_name']; ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label"
                                                            for="bouquets">Bouquets</label>
                                                        <div class="col-md-8">
                                                            <select name="bouquets[]" id="bouquets"
                                                                class="form-control select2-multiple" data-toggle="select2"
                                                                multiple="multiple" data-placeholder="Choose...">
                                                                <?php foreach (getBouquets() as $rBouquet) { ?>
                                                                    <option value="<?php echo $rBouquet['id']; ?>">
                                                                        <?php echo $rBouquet['bouquet_name']; ?></option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>
                                                    <div class="form-group row mb-4">
                                                        <label class="col-md-4 col-form-label" for="source_id">Recording
                                                            Server</label>
                                                        <div class="col-md-8">
                                                            <select name="source_id" id="source_id" class="form-control"
                                                                data-toggle="select2">
                                                                <?php foreach ($rAvailableServers as $rServerID) { ?>
                                                                    <option
                                                                        value="<?php echo CoreUtilities::$rServers[$rServerID]['id']; ?>">
                                                                        <?php echo CoreUtilities::$rServers[$rServerID]['server_name']; ?>
                                                                        - <?php echo CoreUtilities::$rServers[$rServerID]['server_ip']; ?>
                                                                    </option>
                                                                <?php } ?>
                                                            </select>
                                                        </div>
                                                    </div>

                                                    <ul class="list-inline wizard mb-0">
                                                        <li class="list-inline-item float-right">
                                                            <input type="submit" class="btn btn-primary" value="Schedule" />
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
            <?php } ?>
        </div>
    </div>
</div>
<?php include 'footer.php'; ?>