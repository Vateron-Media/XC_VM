<?php

include 'session.php';
include 'functions.php';

if (!checkPermissions()) {
    goHome();
}

if (!isset(CoreUtilities::$rRequest['server']) || !isset($rServers[CoreUtilities::$rRequest['server']])) {
    CoreUtilities::$rRequest['server'] = SERVER_ID;
}

if (!isset(CoreUtilities::$rRequest['clear'])) {
    if (!isset(CoreUtilities::$rRequest['clear_s'])) {
        $rStreams = getStreamPIDs(CoreUtilities::$rRequest['server']);
        $rFS = getFreeSpace(CoreUtilities::$rRequest['server']);
        $rProcesses = getPIDs(CoreUtilities::$rRequest['server']);
        $rStatus = array('D' => 'Uninterruptible Sleep', 'I' => 'Idle', 'R' => 'Running', 'S' => 'Interruptible Sleep', 'T' => 'Stopped', 'W' => 'Paging', 'X' => 'Dead', 'Z' => 'Zombie');
        $_TITLE = 'Process Monitor';
        include 'header.php'; ?>
        <div class="wrapper" <?= empty($_SERVER['HTTP_X_REQUESTED_WITH']) || strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) != 'xmlhttprequest' ? '' : ' style="display: none;"' ?>>
            <div class="container-fluid">
                <div class="row">
                    <div class="col-12">
                        <div class="page-title-box">
                            <div class="page-title-right">
                                <ol class="breadcrumb m-0">
                                    <li>
                                        <a href="process_monitor?server=<?= intval(CoreUtilities::$rRequest['server']) ?>" style="margin-right:10px;">
                                            <button type="button" class="btn btn-dark waves-effect waves-light btn-sm">
                                                <i class="mdi mdi-refresh"></i> <?= $_['refresh'] ?>
                                            </button>
                                        </a>
                                    </li>
                                </ol>
                            </div>
                            <h4 class="page-title"><?= $_['process_monitor'] ?></h4>
                        </div>
                    </div>
                </div>
                <div class="row">
                    <div class="col-12">
                        <?php if (!$rMobile) {
                            if (count($rFS) > 0) { ?>
                                <div class="card">
                                    <div class="card-body" style="overflow-x:auto;">
                                        <table class="table table-borderless mb-0">
                                            <thead class="thead-light">
                                                <tr>
                                                    <th><?= $_['mount_point'] ?></th>
                                                    <th class="text-center"><?= $_['size'] ?></th>
                                                    <th class="text-center"><?= $_['used'] ?></th>
                                                    <th class="text-center"><?= $_['available'] ?></th>
                                                    <th class="text-center"><?= $_['used'] ?> %</th>
                                                    <th class="text-center"><?= $_['actions'] ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rFS as $fs) {
                                                    if ($fs['percentage'] >= 80) {
                                                        $_STATUS = STATUS_SPACE_ISSUE;
                                                    } ?>
                                                    <tr>
                                                        <td><?= $fs['mount'] ?></td>
                                                        <td class="text-center"><?= $fs['size'] ?></td>
                                                        <td class="text-center"><?= $fs['used'] ?></td>
                                                        <td class="text-center"><?= $fs['avail'] ?></td>
                                                        <td class="text-center">
                                                            <?php if (intval(rtrim($fs['percentage'], '%')) >= 80) {
                                                                echo "<span class='text-danger'>" . $fs['percentage'] . '</span>';
                                                            } else {
                                                                echo $fs['percentage'];
                                                            } ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <div class="btn-group">
                                                                <?php if (substr($fs['mount'], -3) == 'tmp') { ?>
                                                                    <a href="./process_monitor?server=<?= intval(CoreUtilities::$rRequest['server']) ?>&clear">
                                                                        <button data-toggle="tooltip" data-placement="top" title="<?= $_['clear_temp'] ?>" type="button" class="btn btn-light waves-effect waves-light btn-xs">
                                                                            <i class="mdi mdi-close"></i>
                                                                        </button>
                                                                    </a>
                                                                <?php } elseif (substr($fs['mount'], -7) == 'streams') { ?>
                                                                    <a href="./process_monitor?server=<?= intval(CoreUtilities::$rRequest['server']) ?>&clear_s">
                                                                        <button data-toggle="tooltip" data-placement="top" title="<?= $_['clear_streams'] ?>" type="button" class="btn btn-light waves-effect waves-light btn-xs">
                                                                            <i class="mdi mdi-close"></i>
                                                                        </button>
                                                                    </a>
                                                                <?php } ?>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                                <?php if (isset($_STATUS) && $_STATUS == STATUS_SPACE_ISSUE) { ?>
                                    <div class="alert alert-danger text-center" role="alert">
                                        <strong>You are running out of space on one or more of your mount points. You should resolve this before issues occur.</strong>
                                    </div>
                                <?php }
                                $ramdiskUsage = getStreamsRamdisk(CoreUtilities::$rRequest['server']);
                                $db->query('SELECT `stream_id`, `stream_display_name`, `bitrate` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0;', CoreUtilities::$rRequest['server']);
                                $rStreamNames = $db->get_rows(true, 'stream_id');
                                $streamUsage = array();
                                foreach ($ramdiskUsage as $rStreamID => $rUsage) {
                                    if (isset($rStreamNames[$rStreamID])) {
                                        $streamUsage[$rStreamID] = $rUsage;
                                    }
                                }
                                asort($streamUsage);
                                $streamUsage = array_reverse($streamUsage, true);
                                $halfCount = ceil(count($streamUsage) / 2);
                                if ($halfCount > 10) {
                                    $halfCount = 10;
                                }
                                $topUsage = array_slice($streamUsage, 0, $halfCount, true);
                                $bottomUsage = array_slice($streamUsage, $halfCount, $halfCount, true);
                                if ($halfCount > 0) { ?>
                                    <div class="row" style="overflow-x:auto;">
                                        <div class="col-md-6">
                                            <div class="card-box">
                                                <table class="table table-borderless mb-0">
                                                    <thead class="thead-light">
                                                        <tr>
                                                            <th class="text-center">Stream ID</th>
                                                            <th>Stream Name</th>
                                                            <th class="text-center">Bitrate</th>
                                                            <th class="text-center">Mount Usage</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($topUsage as $rStreamID => $rUsage) { ?>
                                                            <tr>
                                                                <td class="text-center"><a class="text-dark" href="stream_view?id=<?= $rStreamID ?>"><?= $rStreamID ?></a></td>
                                                                <td><a class="text-dark" href="stream_view?id=<?= $rStreamID ?>"><?= $rStreamNames[$rStreamID]['stream_display_name'] ?></a></td>
                                                                <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min"><?= number_format($rStreamNames[$rStreamID]['bitrate'], 0) ?> Kbps</button></td>
                                                                <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min"><?= number_format($rUsage / 1024 / 1024, 0) ?> MB</button></td>
                                                            </tr>
                                                        <?php } ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                        <div class="col-md-6">
                                            <div class="card-box">
                                                <table class="table table-borderless mb-0">
                                                    <thead class="thead-light">
                                                        <tr>
                                                            <th class="text-center">Stream ID</th>
                                                            <th>Stream Name</th>
                                                            <th class="text-center">Bitrate</th>
                                                            <th class="text-center">Mount Usage</th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($bottomUsage as $rStreamID => $rUsage) { ?>
                                                            <tr>
                                                                <td class="text-center"><a class="text-dark" href="stream_view?id=<?= $rStreamID ?>"><?= $rStreamID ?></a></td>
                                                                <td><a class="text-dark" href="stream_view?id=<?= $rStreamID ?>"><?= $rStreamNames[$rStreamID]['stream_display_name'] ?></a></td>
                                                                <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min"><?= number_format($rStreamNames[$rStreamID]['bitrate'], 0) ?> Kbps</button></td>
                                                                <td class="text-center"><button type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed-min"><?= number_format($rUsage / 1024 / 1024, 0) ?> MB</button></td>
                                                            </tr>
                                                        <?php } ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                <?php } ?>
                                <div class="card">
                                    <div class="card-body" style="overflow-x:auto;">
                                        <form id="line_activity_search">
                                            <div class="form-group row mb-4">
                                                <div class="col-md-6">
                                                    <input type="text" class="form-control" id="live_search" value="" placeholder="<?= $_['search_processes'] ?>...">
                                                </div>
                                                <label class="col-md-1 col-form-label text-center" for="live_filter"><?= $_['server'] ?></label>
                                                <div class="col-md-3">
                                                    <select id="live_filter" class="form-control" data-toggle="select2">
                                                        <?php foreach ($rServers as $rServer) { ?>
                                                            <option value="<?= $rServer['id'] ?>" <?= CoreUtilities::$rRequest['server'] == $rServer['id'] ? ' selected' : '' ?>><?= $rServer['server_name'] ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                                <label class="col-md-1 col-form-label text-center" for="live_show_entries"><?= $_['show'] ?></label>
                                                <div class="col-md-1">
                                                    <select id="live_show_entries" class="form-control" data-toggle="select2">
                                                        <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow) { ?>
                                                            <option value="<?= $rShow ?>" <?= $rSettings['default_entries'] == $rShow ? ' selected' : '' ?>><?= $rShow ?></option>
                                                        <?php } ?>
                                                    </select>
                                                </div>
                                            </div>
                                        </form>
                                        <table id="datatable-activity" class="table table-striped table-borderless dt-responsive nowrap">
                                            <thead>
                                                <tr>
                                                    <th><?= $_['pid'] ?></th>
                                                    <th><?= $_['type'] ?></th>
                                                    <th style="max-width: 100px !important;"><?= $_['process'] ?></th>
                                                    <th><?= $_['cpu_%'] ?></th>
                                                    <th><?= $_['mem_mb'] ?></th>
                                                    <th>Runtime</th>
                                                    <th>CPU Time</th>
                                                    <th><?= $_['actions'] ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rProcesses as $rProcess) {
                                                    $uptime = $rProcess['etime'];
                                                    $uptime = $uptime >= 86400 ? sprintf('%02dd %02dh %02dm', $uptime / 86400, ($uptime / 3600) % 24, ($uptime / 60) % 60) : sprintf('%02dh %02dm %02ds', $uptime / 3600, ($uptime / 60) % 60, $uptime % 60);
                                                    $cpuTime = $rProcess['time'];
                                                    $cpuTime = $cpuTime >= 86400 ? sprintf('%02dd %02dh %02dm', $cpuTime / 86400, ($cpuTime / 3600) % 24, ($cpuTime / 60) % 60) : sprintf('%02dh %02dm %02ds', $cpuTime / 3600, ($cpuTime / 60) % 60, $cpuTime % 60);

                                                    $A134afcd6d59abf6 = array('proxy' => 'Live Proxy', 'llod' => 'LLOD', 'loopback' => 'Loopback', 'queue' => 'VOD Queue', 'ondemand' => 'On-Demand Instant Off', 'plex_item' => 'Plex Item Scan', 'watch_item' => 'Watch Item Scan', 'cache_handler' => 'Cache Handler', 'certbot' => 'Certbot SSL Automation', 'closed_cons' => 'Closed Connection Handler', 'signals' => 'Signal Handler', 'watchdog' => 'Server Watchdog');
                                                    $rCrons = array('plex' => 'Plex Sync', 'cache_engine' => 'Cache Generator', 'activity' => 'Activity Cron', 'backups' => 'Backup Cron', 'cache' => 'Cache Cron', 'epg' => 'EPG Cron', 'lines_logs' => 'Line Logging Cron', 'root_signals' => 'Root Signal Cron', 'series' => 'Series Cron', 'servers' => 'Servers Cron', 'stats' => 'Stats Cron', 'streams' => 'Streams Cron', 'streams_logs' => 'Stream Logging Cron', 'tmdb' => 'TMDb Refresh Cron', 'tmp' => 'Temp Cron', 'users' => 'Users Cron', 'vod' => 'VOD Cron', 'watch' => 'Watch Folder Cron');

                                                    if (isset($A134afcd6d59abf6[basename(explode(' ', trim(explode('#', $rProcess['command'])[0]))[0], '.php')])) {
                                                        $rProcess['command'] = $A134afcd6d59abf6[basename(explode(' ', trim(explode('#', $rProcess['command'])[0]))[0], '.php')];
                                                        $rType = 'XC_VM CLI';
                                                    } else {
                                                        if (isset($A134afcd6d59abf6[basename(trim(explode('#', $rProcess['command'])[0]), '.php')])) {
                                                            $rProcess['command'] = $A134afcd6d59abf6[basename(trim(explode('#', $rProcess['command'])[0]), '.php')];
                                                            $rType = 'XC_VM CLI';
                                                        } else {
                                                            if (isset($rCrons[basename(explode(' ', trim(explode('#', $rProcess['command'])[0]))[0], '.php')])) {
                                                                $rProcess['command'] = $rCrons[basename(explode(' ', trim(explode('#', $rProcess['command'])[0]))[0], '.php')];
                                                                $rType = 'XC_VM Cron';
                                                            } else {
                                                                if (stripos($rProcess['command'], 'nginx: master process') !== false) {
                                                                    $rProcess['command'] = 'NGINX Master Process';
                                                                    $rType = 'NGINX Master';
                                                                } else {
                                                                    if (stripos($rProcess['command'], 'nginx: worker process') !== false) {
                                                                        $rProcess['command'] = 'NGINX Worker Process';
                                                                        $rType = 'NGINX Pool';
                                                                    } else {
                                                                        if (stripos($rProcess['command'], 'php-fpm: master process') !== false) {
                                                                            $rProcess['command'] = 'PHP Master Process';
                                                                            $rType = 'PHP Master';
                                                                        } else {
                                                                            if (stripos($rProcess['command'], 'redis-server') !== false) {
                                                                                $rProcess['command'] = 'Redis Server';
                                                                                $rType = 'Redis';
                                                                            } else {
                                                                                $rProcess['command'] = 'Command: ' . $rProcess['command'];
                                                                                $rType = array('pid' => $_['main'] . ' - ', 'vframes' => $_['thumbnail'] . ' - ', 'monitor_pid' => $_['monitor'] . ' - ', 'delay_pid' => $_['delayed'] . ' - ', 'activity' => $_['line_activity'] . ' - ', 'timeshift' => $_['timeshift'] . ' - ', null => '')[$rStreams[$rProcess['pid']]['pid_type']] . array(1 => $_['stream'], 2 => $_['movie'], 3 => $_['created_channel'], 4 => $_['radio'], 5 => $_['episode'], null => $_['system'])[$rStreams[$rProcess['pid']]['type']];
                                                                            }
                                                                        }
                                                                    }
                                                                }
                                                            }
                                                        }
                                                    }
                                                ?>

                                                    <tr>
                                                        <td><?= $rProcess['pid'] ?></td>
                                                        <td><?= $rType ?></td>
                                                        <td style="max-width: 700px !important; overflow: hidden;"><?= $rProcess['command'] ?></td>
                                                        <td><button type="button" class="btn btn-light btn-xs waves-effect waves-light"><?= number_format($rProcess['cpu'], 2) ?>%</button><br /><small>avg: <?= number_format($rProcess['load_average'], 2) ?>%</small></td>
                                                        <td><button type="button" class="btn btn-light btn-xs waves-effect waves-light"><?= number_format($rProcess['rss'] / 1024, 0) ?></button></td>
                                                        <td><button type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed"><?= $uptime ?></button></td>
                                                        <td><button type="button" class="btn btn-light btn-xs waves-effect waves-light btn-fixed"><?= $cpuTime ?></button></td>
                                                        <td>
                                                            <div class="btn-group">
                                                                <?php if (isset($rStreams[$rProcess['pid']])) { ?>
                                                                    <a href="<?= array(1 => 'stream_view', 2 => 'stream_view', 3 => 'stream_view', 4 => 'stream_view', 5 => 'stream_view')[$rStreams[$rProcess['pid']]['type']] . '?id=' . $rStreams[$rProcess['pid']]['id'] ?>">
                                                                        <button data-toggle="tooltip" data-placement="top" title="<?= $_['view'] ?>" type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-eye"></i></button>
                                                                    </a>
                                                                <?php } else { ?>
                                                                    <button disabled type="button" class="btn btn-light waves-effect waves-light btn-xs"><i class="mdi mdi-eye"></i></button>
                                                                <?php } ?>
                                                                <button data-toggle="tooltip" data-placement="top" title="<?= $_['kill_process_info'] ?>" type="button" class="btn btn-light waves-effect waves-light btn-xs" onClick="kill(<?= intval(CoreUtilities::$rRequest['server']) ?>, <?= $rProcess['pid'] ?>);"><i class="mdi mdi-close"></i></button>
                                                            </div>
                                                        </td>
                                                    </tr>
                                                <?php } ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php } ?>
                        <?php } ?>
                    </div>
                </div>
            </div>
        </div>
        <?php include 'footer.php'; ?>
<script id="scripts">
	<?php
		echo '        ' . "\r\n\t\t" . 'function kill(rServerID, rID) {' . "\r\n\t\t\t" . '$.getJSON("./api?action=process&pid=" + rID + "&server=" + rServerID, function(data) {' . "\r\n\t\t\t\t" . 'if (data.result === true) {' . "\r\n\t\t\t\t\t" . '$.toast("';
		echo $_['process_has_been_killed_wait'];
		echo '");' . "\r\n\t\t\t\t" . '} else {' . "\r\n\t\t\t\t\t" . '$.toast("';
		echo $_['error_occured'];
		echo '");' . "\r\n\t\t\t\t" . '}' . "\r\n\t\t\t" . '});' . "\r\n\t\t" . '}' . "\r\n\t\t" . '$(document).ready(function() {' . "\r\n\t\t\t" . "\$('select').select2({width: '100%'});" . "\r\n\t\t\t" . '$("#datatable-activity").DataTable({' . "\r\n\t\t\t\t" . 'language: {' . "\r\n\t\t\t\t\t" . 'paginate: {' . "\r\n\t\t\t\t\t\t" . "previous: \"<i class='mdi mdi-chevron-left'>\"," . "\r\n\t\t\t\t\t\t" . "next: \"<i class='mdi mdi-chevron-right'>\"" . "\r\n\t\t\t\t\t" . '},' . "\r\n\t\t\t\t\t" . 'infoFiltered: ""' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'drawCallback: function() {' . "\r\n\t\t\t\t\t" . 'bindHref(); refreshTooltips();' . "\r\n\t\t\t\t" . '},' . "\r\n\t\t\t\t" . 'responsive: false,' . "\r\n\t\t\t\t" . 'processing: true,' . "\r\n\t\t\t\t" . 'columnDefs: [' . "\r\n\t\t\t\t\t" . '{"className": "dt-center", "targets": [0,3,4,5,6]}' . "\r\n\t\t\t\t" . '],' . "\r\n\t\t\t\t\r\n\t\t\t\t";

		if (isset(CoreUtilities::$rRequest['mem'])) {
			echo "\t\t\t\t" . 'order: [[ 4, "desc" ]],' . "\r\n\t\t\t\t";
		} else {
			echo "\t\t\t\t" . 'order: [[ 3, "desc" ]],' . "\r\n\t\t\t\t";
		}

		echo "\t\t\t\t" . 'pageLength: ';
		echo (intval($rSettings['default_entries']) ?: 10);
		echo ',' . "\r\n\t\t\t\t" . 'lengthMenu: [10, 25, 50, 250, 500, 1000]' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . '$("#datatable-activity").css("width", "100%");' . "\r\n\t\t\t" . "\$('#live_search').keyup(function(){" . "\r\n\t\t\t\t" . "\$('#datatable-activity').DataTable().search(\$(this).val()).draw();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#live_show_entries').change(function(){" . "\r\n\t\t\t\t" . "\$('#datatable-activity').DataTable().page.len(\$(this).val()).draw();" . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#live_filter').change(function(){" . "\r\n\t\t\t\t" . 'navigate("./process_monitor?server=" + $(this).val());' . "\r\n\t\t\t" . '});' . "\r\n\t\t\t" . "\$('#datatable-activity').DataTable().search(\$('#live_search').val()).draw();" . "\r\n\t\t" . '});' . "\r\n" . '        ' . "\r\n" . '        ';
		?>
</script>
<script src="assets/js/listings.js"></script>
</body>

</html>
<?php } else {
        c00aB7dA6173ebB1(CoreUtilities::$rRequest['server']);
        header('Location: ./process_monitor?server=' . CoreUtilities::$rRequest['server']);

        exit();
    }
} else {
    b8E493b5dbDB3D7A(CoreUtilities::$rRequest['server']);
    header('Location: ./process_monitor?server=' . CoreUtilities::$rRequest['server']);

    exit();
}
?>