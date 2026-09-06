<?php

/**
 * Process monitor (Bootstrap 5). Server-side data ($rFS mount points, RAM-disk per-stream
 * usage, $rProcesses) is rendered as new-UI tables; the process list gets a client-side
 * DataTable for search / sort / paging. The server select reloads the page (?server=),
 * mount clears link to ?clear / ?clear_s, and a process is killed via
 * ./api?action=process. Reached full-page in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;

$rServerId = (int) RequestManager::get('server');
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <h4 class="mb-0"><?= $language::get('process_monitor'); ?></h4>
    <a href="process_monitor?server=<?= $rServerId; ?>" class="btn btn-sm btn-label-secondary"><i class="icon-base ti tabler-refresh me-1"></i><?= $language::get('refresh'); ?></a>
</div>

<?php if (!$rMobile && count($rFS) > 0): ?>
    <?php $rSpaceIssue = false; ?>
    <div class="card mb-4">
        <div class="card-datatable table-responsive">
            <table class="table mb-0">
                <thead>
                    <tr>
                        <th><?= $language::get('mount_point'); ?></th>
                        <th class="text-center"><?= $language::get('size'); ?></th>
                        <th class="text-center"><?= $language::get('used'); ?></th>
                        <th class="text-center"><?= $language::get('available'); ?></th>
                        <th class="text-center"><?= $language::get('used'); ?> %</th>
                        <th class="text-center"><?= $language::get('actions'); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rFS as $fs): ?>
                        <?php
                        $rPct = (int) rtrim((string) $fs['percentage'], '%');
                        if ($rPct >= 80) {
                            $rSpaceIssue = true;
                        }
                        ?>
                        <tr>
                            <td class="fw-medium"><?= htmlspecialchars((string) $fs['mount'], ENT_QUOTES); ?></td>
                            <td class="text-center"><?= htmlspecialchars((string) $fs['size'], ENT_QUOTES); ?></td>
                            <td class="text-center"><?= htmlspecialchars((string) $fs['used'], ENT_QUOTES); ?></td>
                            <td class="text-center"><?= htmlspecialchars((string) $fs['avail'], ENT_QUOTES); ?></td>
                            <td class="text-center"><span class="<?= $rPct >= 80 ? 'text-danger fw-medium' : ''; ?>"><?= htmlspecialchars((string) $fs['percentage'], ENT_QUOTES); ?></span></td>
                            <td class="text-center">
                                <?php if (substr((string) $fs['mount'], -3) === 'tmp'): ?>
                                    <a href="./process_monitor?server=<?= $rServerId; ?>&clear" class="btn btn-sm btn-icon btn-label-danger" title="<?= $language::get('clear_temp'); ?>"><i class="icon-base ti tabler-trash"></i></a>
                                <?php elseif (substr((string) $fs['mount'], -7) === 'streams'): ?>
                                    <a href="./process_monitor?server=<?= $rServerId; ?>&clear_s" class="btn btn-sm btn-icon btn-label-danger" title="<?= $language::get('clear_streams'); ?>"><i class="icon-base ti tabler-trash"></i></a>
                                <?php else: ?>
                                    <span class="text-body-secondary">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php if ($rSpaceIssue): ?>
        <div class="alert alert-danger text-center" role="alert"><strong>You are running out of space on one or more of your mount points. You should resolve this before issues occur.</strong></div>
    <?php endif; ?>
    <?php
    $ramdiskUsage = \XcVm\Domain\Server\ServerRepository::getStreamsRamdisk($rServerId);
    $db->query('SELECT `stream_id`, `stream_display_name`, `bitrate` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0;', $rServerId);
    $rStreamNames = $db->get_rows(true, 'stream_id');
    $streamUsage = [];
    foreach ($ramdiskUsage as $rStreamID => $rUsage) {
        if (isset($rStreamNames[$rStreamID])) {
            $streamUsage[$rStreamID] = $rUsage;
        }
    }
    asort($streamUsage);
    $streamUsage = array_reverse($streamUsage, true);
    $rUsageRows = array_slice($streamUsage, 0, 20, true);
    ?>
    <?php if (count($rUsageRows) > 0): ?>
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0"><?= $language::get('mount_usage'); ?></h6>
            </div>
            <div class="card-datatable table-responsive">
                <table class="table mb-0">
                    <thead>
                        <tr>
                            <th class="text-center"><?= $language::get('stream_id'); ?></th>
                            <th><?= $language::get('stream_name'); ?></th>
                            <th class="text-center"><?= $language::get('bitrate'); ?></th>
                            <th class="text-center"><?= $language::get('mount_usage'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rUsageRows as $rStreamID => $rUsage): ?>
                            <tr>
                                <td class="text-center"><a href="stream_view?id=<?= (int) $rStreamID; ?>"><?= (int) $rStreamID; ?></a></td>
                                <td><a href="stream_view?id=<?= (int) $rStreamID; ?>"><?= htmlspecialchars((string) $rStreamNames[$rStreamID]['stream_display_name'], ENT_QUOTES); ?></a></td>
                                <td class="text-center"><span class="badge bg-label-secondary"><?= number_format((int) $rStreamNames[$rStreamID]['bitrate'], 0); ?> Kbps</span></td>
                                <td class="text-center"><span class="badge bg-label-info"><?= number_format($rUsage / 1024 / 1024, 0); ?> MB</span></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="card-title mb-0"><?= $language::get('process'); ?></h6>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 text-nowrap"><?= $language::get('server'); ?></label>
            <select id="live_filter" class="form-select form-select-sm" style="width:auto">
                <?php foreach ($rServers as $rServer): ?>
                    <option value="<?= (int) $rServer['id']; ?>" <?= $rServerId == $rServer['id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <?php if (empty($rProcesses)): ?>
        <div class="card-body">
            <div class="alert alert-warning text-center mb-0"><i class="icon-base ti tabler-alert-circle me-1"></i>Unable to retrieve process list. The server API may be temporarily unavailable. Please try refreshing the page.</div>
        </div>
    <?php endif; ?>
    <div class="card-datatable table-responsive">
        <table id="procs-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th class="text-center"><?= $language::get('pid'); ?></th>
                    <th><?= $language::get('type'); ?></th>
                    <th><?= $language::get('process'); ?></th>
                    <th class="text-center"><?= $language::get('cpu_%'); ?></th>
                    <th class="text-center"><?= $language::get('mem_mb'); ?></th>
                    <th class="text-center"><?= $language::get('runtime'); ?></th>
                    <th class="text-center"><?= $language::get('cpu_time'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rProcesses as $rProcess): ?>
                    <?php
                    $uptime = (int) $rProcess['etime'];
                    $uptime = $uptime >= 86400
                        ? sprintf('%02dd %02dh %02dm', intdiv($uptime, 86400), intdiv($uptime, 3600) % 24, intdiv($uptime, 60) % 60)
                        : sprintf('%02dh %02dm %02ds', intdiv($uptime, 3600) % 24, intdiv($uptime, 60) % 60, $uptime % 60);
                    $cpuTime = (int) $rProcess['time'];
                    $cpuTime = $cpuTime >= 86400
                        ? sprintf('%02dd %02dh %02dm', intdiv($cpuTime, 86400), intdiv($cpuTime, 3600) % 24, intdiv($cpuTime, 60) % 60)
                        : sprintf('%02dh %02dm %02ds', intdiv($cpuTime, 3600) % 24, intdiv($cpuTime, 60) % 60, $cpuTime % 60);
                    $rCli = ['proxy' => 'Live Proxy', 'llod' => 'LLOD', 'loopback' => 'Loopback', 'queue' => 'VOD Queue', 'ondemand' => 'On-Demand Instant Off', 'plex_item' => 'Plex Item Scan', 'watch_item' => 'Watch Item Scan', 'cache_handler' => 'Cache Handler', 'certbot' => 'Certbot SSL Automation', 'closed_cons' => 'Closed Connection Handler', 'signals' => 'Signal Handler', 'watchdog' => 'Server Watchdog'];
                    $rCrons = ['plex' => 'Plex Sync', 'cache_engine' => 'Cache Generator', 'activity' => 'Activity Cron', 'backups' => 'Backup Cron', 'cache' => 'Cache Cron', 'epg' => 'EPG Cron', 'lines_logs' => 'Line Logging Cron', 'root_signals' => 'Root Signal Cron', 'series' => 'Series Cron', 'servers' => 'Servers Cron', 'stats' => 'Stats Cron', 'streams' => 'Streams Cron', 'streams_logs' => 'Stream Logging Cron', 'tmdb' => 'TMDb Refresh Cron', 'tmp' => 'Temp Cron', 'users' => 'Users Cron', 'vod' => 'VOD Cron', 'watch' => 'Watch Folder Cron'];
                    $rCmdKey = basename(explode(' ', trim(explode('#', (string) $rProcess['command'])[0]))[0], '.php');
                    $rCmdKey2 = basename(trim(explode('#', (string) $rProcess['command'])[0]), '.php');
                    if (isset($rCli[$rCmdKey])) {
                        $rProcess['command'] = $rCli[$rCmdKey];
                        $rType = 'XC_VM CLI';
                    } elseif (isset($rCli[$rCmdKey2])) {
                        $rProcess['command'] = $rCli[$rCmdKey2];
                        $rType = 'XC_VM CLI';
                    } elseif (isset($rCrons[$rCmdKey])) {
                        $rProcess['command'] = $rCrons[$rCmdKey];
                        $rType = 'XC_VM Cron';
                    } elseif (stripos((string) $rProcess['command'], 'nginx: master process') !== false) {
                        $rProcess['command'] = 'NGINX Master Process';
                        $rType = 'NGINX Master';
                    } elseif (stripos((string) $rProcess['command'], 'nginx: worker process') !== false) {
                        $rProcess['command'] = 'NGINX Worker Process';
                        $rType = 'NGINX Pool';
                    } elseif (stripos((string) $rProcess['command'], 'php-fpm: master process') !== false) {
                        $rProcess['command'] = 'PHP Master Process';
                        $rType = 'PHP Master';
                    } elseif (stripos((string) $rProcess['command'], 'redis-server') !== false) {
                        $rProcess['command'] = 'Redis Server';
                        $rType = 'Redis';
                    } else {
                        $rProcess['command'] = 'Command: ' . $rProcess['command'];
                        $rPidType = ['pid' => $language::get('main') . ' - ', 'vframes' => $language::get('thumbnail') . ' - ', 'monitor_pid' => $language::get('monitor') . ' - ', 'delay_pid' => $language::get('delayed') . ' - ', 'activity' => $language::get('line_activity') . ' - ', 'timeshift' => $language::get('timeshift') . ' - ', null => ''][$rStreams[$rProcess['pid']]['pid_type'] ?? null];
                        $rStreamType = [1 => $language::get('stream'), 2 => $language::get('movie'), 3 => $language::get('created_channel'), 4 => $language::get('radio'), 5 => $language::get('episode'), null => $language::get('system')][$rStreams[$rProcess['pid']]['type'] ?? null];
                        $rType = $rPidType . $rStreamType;
                    }
                    ?>
                    <tr>
                        <td class="text-center"><?= (int) $rProcess['pid']; ?></td>
                        <td><?= htmlspecialchars((string) $rType, ENT_QUOTES); ?></td>
                        <td class="text-truncate" style="max-width:400px"><?= htmlspecialchars((string) $rProcess['command'], ENT_QUOTES); ?></td>
                        <td class="text-center"><span class="badge bg-label-secondary"><?= number_format($rProcess['cpu'], 2); ?>%</span><br><small class="text-body-secondary">avg: <?= number_format($rProcess['load_average'], 2); ?>%</small></td>
                        <td class="text-center"><span class="badge bg-label-secondary"><?= number_format($rProcess['rss'] / 1024, 0); ?></span></td>
                        <td class="text-center text-nowrap"><?= $uptime; ?></td>
                        <td class="text-center text-nowrap"><?= $cpuTime; ?></td>
                        <td class="text-center">
                            <div class="btn-group">
                                <?php if (isset($rStreams[$rProcess['pid']])): ?>
                                    <a href="stream_view?id=<?= (int) $rStreams[$rProcess['pid']]['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('view'); ?>"><i class="icon-base ti tabler-eye"></i></a>
                                <?php else: ?>
                                    <button disabled type="button" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-eye"></i></button>
                                <?php endif; ?>
                                <button type="button" class="btn btn-sm btn-icon btn-label-danger js-kill" data-pid="<?= (int) $rProcess['pid']; ?>" title="<?= $language::get('kill_process_info'); ?>"><i class="icon-base ti tabler-x"></i></button>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var killedText = <?= json_encode($language::get('process_has_been_killed_wait')); ?>;
        var serverId = <?= $rServerId; ?>;
        var toast = window.xcToast || function() {};

        if ($('#procs-table tbody tr').length) {
            $('#procs-table').DataTable({
                order: [
                    [<?= RequestManager::has('mem') ? 4 : 3; ?>, 'desc']
                ],
                columnDefs: [{
                    orderable: false,
                    targets: [7]
                }],
                pageLength: <?= (int) ($rSettings['default_entries'] ?: 10); ?>,
                layout: {
                    topStart: 'pageLength',
                    topEnd: 'search'
                }
            });
        }
        document.getElementById('live_filter').addEventListener('change', function() {
            window.location.href = './process_monitor?server=' + encodeURIComponent(this.value);
        });
        $('#procs-table tbody').on('click', '.js-kill', function() {
            var pid = this.getAttribute('data-pid');
            (window.xcConfirm ? window.xcConfirm('Kill this process?') : Promise.resolve(confirm('Kill this process?'))).then(function(ok) {
                if (!ok) {
                    return;
                }
                fetch('./api?action=process&pid=' + encodeURIComponent(pid) + '&server=' + encodeURIComponent(serverId), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(d) {
                        toast(d && d.result === true ? killedText : errText, d && d.result === true ? 'success' : 'error');
                    })
                    .catch(function() {
                        toast(errText, 'error');
                    });
            });
        });
    })();
</script>
</body>

</html>