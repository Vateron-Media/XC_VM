<?php

/**
 * RTMP monitor (Bootstrap 5). Server-side data ($rRTMPInfo, from the node's stat XML) is
 * rendered as new-UI tables: a summary row (pid / versions / uptime / in-out bandwidth)
 * and the live publisher list (client-side DataTable). The server select reloads the
 * page (?server=); a publisher is dropped via ./api?action=rtmp_kill. Reached full-page
 * in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Server\ServerRepository;

$rServerId = (int) RequestManager::get('server');
$rUptimeFmt = static function (int $rUptime): string {
    return $rUptime >= 86400
        ? sprintf('%02dd %02dh %02dm', intdiv($rUptime, 86400), intdiv($rUptime, 3600) % 24, intdiv($rUptime, 60) % 60)
        : sprintf('%02dh %02dm %02ds', intdiv($rUptime, 3600) % 24, intdiv($rUptime, 60) % 60, $rUptime % 60);
};
$rRtmpBase = htmlspecialchars((string) (ServerRepository::getAll()[$rServerId]['rtmp_server'] ?? ''), ENT_QUOTES);
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <h4 class="mb-0"><?= $language::get('rtmp_monitor'); ?></h4>
    <a href="rtmp_monitor?server=<?= $rServerId; ?>" class="btn btn-sm btn-label-secondary"><i class="icon-base ti tabler-refresh me-1"></i><?= $language::get('refresh'); ?></a>
</div>

<div class="card mb-4">
    <div class="card-datatable table-responsive">
        <table class="table mb-0">
            <thead>
                <tr>
                    <th class="text-center"><?= $language::get('rtmp_pid'); ?></th>
                    <th class="text-center"><?= $language::get('nginx_version'); ?></th>
                    <th class="text-center"><?= $language::get('flv_version'); ?></th>
                    <th class="text-center"><?= $language::get('uptime'); ?></th>
                    <th class="text-center"><?= $language::get('input_mbps'); ?></th>
                    <th class="text-center"><?= $language::get('output_mbps'); ?></th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="text-center"><?= htmlspecialchars((string) $rRTMPInfo['pid'], ENT_QUOTES); ?></td>
                    <td class="text-center"><?= htmlspecialchars((string) $rRTMPInfo['nginx_version'], ENT_QUOTES); ?></td>
                    <td class="text-center"><?= htmlspecialchars((string) $rRTMPInfo['nginx_http_flv_version'], ENT_QUOTES); ?></td>
                    <td class="text-center"><span class="badge bg-label-success"><?= $rUptimeFmt((int) $rRTMPInfo['uptime']); ?></span></td>
                    <td class="text-center"><?= number_format($rRTMPInfo['bw_in'] / 1000 / 1000, 2); ?> Mbps</td>
                    <td class="text-center"><?= number_format($rRTMPInfo['bw_out'] / 1000 / 1000, 2); ?> Mbps</td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h6 class="card-title mb-0">RTMP</h6>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 text-nowrap"><?= $language::get('server'); ?></label>
            <select id="live_filter" class="form-select form-select-sm" style="width:auto">
                <?php foreach ($rServers as $rServer): ?>
                    <option value="<?= (int) $rServer['id']; ?>" <?= $rServerId == $rServer['id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="rtmp-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th class="text-center"><?= $language::get('id'); ?></th>
                    <th><?= $language::get('rtmp_url'); ?></th>
                    <th class="text-center"><?= $language::get('publisher_ip'); ?></th>
                    <th class="text-center"><?= $language::get('uptime'); ?></th>
                    <th class="text-center"><?= $language::get('clients'); ?></th>
                    <th class="text-center"><?= $language::get('stream_info'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php
                $rStreams = $rRTMPInfo['server']['application']['live']['stream'] ?? [];
                if (isset($rStreams['name'])) {
                    $rStreams = [$rStreams];
                }
                foreach ($rStreams as $rStream):
                    if (isset($rStream['client']['id'])) {
                        $rStream['client'] = [$rStream['client']];
                    }
                    $rClientCount = count($rStream['client'] ?? []);
                    $rPublisher = '';
                    foreach (($rStream['client'] ?? []) as $rClient) {
                        if ($rStream['time'] <= $rClient['time']) {
                            $rPublisher = htmlspecialchars((string) $rClient['address'], ENT_QUOTES);
                            $rClientCount--;
                            break;
                        }
                    }
                ?>
                    <tr>
                        <td class="text-center"><?= htmlspecialchars((string) $rStream['name'], ENT_QUOTES); ?></td>
                        <td class="text-truncate" style="max-width:320px"><?= $rRtmpBase . htmlspecialchars((string) $rStream['name'], ENT_QUOTES); ?></td>
                        <td class="text-center"><?= $rPublisher ?: '<span class="text-body-secondary">—</span>'; ?></td>
                        <td class="text-center"><span class="badge bg-label-success"><?= $rUptimeFmt((int) ($rStream['time'] / 1000)); ?></span></td>
                        <td class="text-center"><span class="badge bg-label-<?= $rClientCount > 0 ? 'info' : 'secondary'; ?>"><?= (int) $rClientCount; ?></span></td>
                        <td class="text-center">
                            <div class="d-flex flex-wrap gap-1 justify-content-center">
                                <span class="badge bg-label-secondary"><?= number_format($rStream['bw_in'] / 1000, 0); ?> Kbps</span>
                                <span class="badge bg-label-primary"><?= htmlspecialchars((string) ($rStream['meta']['video']['width'] ?? '?') . '×' . (string) ($rStream['meta']['video']['height'] ?? '?'), ENT_QUOTES); ?></span>
                                <span class="badge bg-label-info"><?= htmlspecialchars((string) ($rStream['meta']['video']['codec'] ?? '?'), ENT_QUOTES); ?></span>
                                <span class="badge bg-label-success"><?= htmlspecialchars((string) ($rStream['meta']['audio']['codec'] ?? '?'), ENT_QUOTES); ?></span>
                                <span class="badge bg-label-secondary"><?= round((float) ($rStream['meta']['video']['frame_rate'] ?? 0), 0); ?> FPS</span>
                            </div>
                        </td>
                        <td class="text-center"><button type="button" class="btn btn-sm btn-icon btn-label-danger js-kill" data-name="<?= htmlspecialchars((string) $rStream['name'], ENT_QUOTES); ?>" title="<?= $language::get('kill_stream'); ?>"><i class="icon-base ti tabler-x"></i></button></td>
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
        var serverId = <?= $rServerId; ?>;
        var toast = window.xcToast || function() {};

        if ($('#rtmp-table tbody tr').length) {
            $('#rtmp-table').DataTable({
                order: [
                    [0, 'asc']
                ],
                columnDefs: [{
                    orderable: false,
                    targets: [5, 6]
                }],
                pageLength: <?= (int) ($rSettings['default_entries'] ?: 10); ?>,
                layout: {
                    topStart: 'pageLength',
                    topEnd: 'search'
                }
            });
        }
        document.getElementById('live_filter').addEventListener('change', function() {
            window.location.href = './rtmp_monitor?server=' + encodeURIComponent(this.value);
        });
        $('#rtmp-table tbody').on('click', '.js-kill', function() {
            var name = this.getAttribute('data-name');
            (window.xcConfirm ? window.xcConfirm('Kill this RTMP stream?') : Promise.resolve(confirm('Kill this RTMP stream?'))).then(function(ok) {
                if (!ok) {
                    return;
                }
                fetch('./api?action=rtmp_kill&name=' + encodeURIComponent(name) + '&server=' + encodeURIComponent(serverId), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(d) {
                        toast(d && d.result === true ? 'Stream killed. It may reconnect unless auth is revoked.' : errText, d && d.result === true ? 'success' : 'error');
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