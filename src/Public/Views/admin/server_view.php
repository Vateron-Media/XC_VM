<?php

/**
 * Server detail / monitoring view (Bootstrap 5). Per-server dashboard reached
 * full-page in the new-UI shell via server_view?id=N (from the servers table).
 *
 * Layout: title row (name + ip), optional Certbot status alert, stat tiles
 * (connections / users, plus streams / down for a MAIN server), a health card
 * (install progress textarea while status 3/4, else CPU/Mem/Disk/IO/Network
 * progress bars, else an offline notice), optional GPU cards, optional
 * certificate card, then a main card with nav-tabs: Resources + Network Traffic
 * (ApexCharts area charts seeded from $rStats) and, for a MAIN server, an Online
 * Streams serverSide table plus an Active Connections serverSide table.
 *
 * Both serverSide tables hit ./table unchanged; TableController::handleStreams
 * and ::handleLiveConnections both return CLEAN-JSON objects now, so both tables
 * use columns:[{data,render}] (never positional columnDefs). Row action buttons
 * are rendered client-side (the handlers no longer emit button HTML).
 *
 * Live metrics poll ./api?action=server_view every 1s; install progress polls
 * ./api?action=install_status every 1s while installing. ApexCharts requires the
 * controller to push $GLOBALS['xmNewuiVendors'][] = 'apexcharts'.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;

$rServerId    = intval(RequestManager::get('id'));
$rIsMain      = ($rServer['server_type'] == 0);
$rInstalling  = in_array($rServer['status'], array(3, 4), true);
$rDiskLabel   = (1099511627776 < ($rWatchdog['total_disk_space'] ?? 0))
    ? number_format(($rWatchdog['total_disk_space'] ?? 0) / 1024 / 1024 / 1024 / 1024, 0) . ' TB'
    : number_format(($rWatchdog['total_disk_space'] ?? 0) / 1024 / 1024 / 1024, 0) . ' GB';
?>

<!-- Page header -->
<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0">
        <?= htmlspecialchars((string) $rServer['server_name']) ?>
        <small class="text-body-secondary ms-2"><?= htmlspecialchars((string) $rServer['server_ip']) ?></small>
    </h4>
</div>

<?php if (isset($B4a5f8dc1f8d260c) && defined('STATUS_CERTBOT') && $B4a5f8dc1f8d260c == STATUS_CERTBOT): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <?= $language::get('server_view_certbot_running') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php elseif (isset($B4a5f8dc1f8d260c) && defined('STATUS_CERTBOT_INVALID') && $B4a5f8dc1f8d260c == STATUS_CERTBOT_INVALID): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?= $language::get('server_view_certbot_invalid') ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<!-- Stat tiles -->
<div class="row g-4 mb-4">
    <?php
    $rTiles = array(
        array('open_connections', 'ti tabler-plug-connected', 'primary', $language::get('connections'), Authorization::check('adv', 'live_connections') ? 'live_connections?server_id=' . $rServerId : null),
        array('online_users', 'ti tabler-users', 'success', $language::get('users'), Authorization::check('adv', 'live_connections') ? 'live_connections?server_id=' . $rServerId : null),
    );
    if ($rIsMain) {
        $rTiles[] = array('total_running_streams', 'ti tabler-player-play', 'info', $language::get('streams'), Authorization::check('adv', 'streams') ? 'streams?filter=1&server=' . $rServerId : null);
        $rTiles[] = array('offline_streams', 'ti tabler-alert-triangle', 'danger', $language::get('down'), Authorization::check('adv', 'streams') ? 'streams?filter=2&server=' . $rServerId : null);
    }
    $rTileCol = $rIsMain ? 'col-sm-6 col-xl-3' : 'col-sm-6';
    foreach ($rTiles as list($rId, $rIcon, $rAccent, $rLabel, $rLink)):
    ?>
        <div class="<?= $rTileCol ?>">
            <?php if ($rLink): ?><a href="<?= htmlspecialchars($rLink, ENT_QUOTES) ?>" class="text-body text-decoration-none"><?php endif; ?>
            <div class="card h-100">
                <div class="card-body d-flex justify-content-between align-items-center">
                    <div class="card-title mb-0">
                        <h5 class="mb-1 me-2"><span id="<?= $rId ?>">0</span></h5>
                        <p class="mb-0"><?= htmlspecialchars($rLabel) ?></p>
                    </div>
                    <div class="card-icon">
                        <span class="badge bg-label-<?= $rAccent ?> rounded p-2"><i class="icon-base <?= $rIcon ?> icon-26px"></i></span>
                    </div>
                </div>
            </div>
            <?php if ($rLink): ?></a><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<!-- Health card -->
<div class="card mb-4">
    <div class="card-body">
        <?php if ($rInstalling): ?>
            <div class="text-center py-3">
                <i class="icon-base ti tabler-sparkles icon-32px text-info"></i>
                <h5 class="text-info mt-2"><?= $language::get('installing') ?></h5>
                <textarea readonly id="server_install" class="form-control mt-3 bg-dark text-white" style="height:150px;font-family:monospace;"></textarea>
            </div>
        <?php elseif ($rServer['server_online']): ?>
            <h6 class="mb-1"><?= $language::get('server_view_cpu_usage') ?><small class="text-body-secondary ms-2"><?= $language::get('server_view_of', array(':value' => ($rWatchdog['cpu_cores'] ?? 0) . ' ' . $language::get('server_view_cores'))) ?></small></h6>
            <div id="watchdog_cpu" class="mb-3">
                <div class="d-flex justify-content-between mb-1"><span class="progress-value fw-medium">0%</span></div>
                <div class="progress"><div class="progress-bar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
            </div>
            <h6 class="mb-1"><?= $language::get('server_view_memory_usage') ?><small class="text-body-secondary ms-2"><?= $language::get('server_view_of', array(':value' => round(($rWatchdog['total_mem'] ?? 0) / 1024 / 1024, 0) . ' GB')) ?></small></h6>
            <div id="watchdog_mem" class="mb-3">
                <div class="d-flex justify-content-between mb-1"><span class="progress-value fw-medium">0%</span></div>
                <div class="progress"><div class="progress-bar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
            </div>
            <?php if ($rIsMain): ?>
                <h6 class="mb-1"><?= $language::get('server_view_disk_usage') ?><small class="text-body-secondary ms-2"><?= $language::get('server_view_of', array(':value' => $rDiskLabel)) ?></small></h6>
                <div id="watchdog_disk" class="mb-3">
                    <div class="d-flex justify-content-between mb-1"><span class="progress-value fw-medium">0%</span></div>
                    <div class="progress"><div class="progress-bar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
                </div>
                <h6 class="mb-1"><?= $language::get('server_view_io_wait') ?><small class="text-body-secondary ms-2" id="watchdog_idle">0% <?= $language::get('server_view_idle') ?></small></h6>
                <div id="watchdog_io" class="mb-3">
                    <div class="d-flex justify-content-between mb-1"><span class="progress-value fw-medium">0%</span></div>
                    <div class="progress"><div class="progress-bar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
                </div>
            <?php endif; ?>
            <h6 class="mb-1"><?= $language::get('server_view_network_input') ?><small class="text-body-secondary ms-2"><?= $language::get('server_view_of', array(':value' => number_format($rServer['network_guaranteed_speed'], 0) . ' Mbps')) ?></small></h6>
            <div id="watchdog_input" class="mb-3">
                <div class="d-flex justify-content-between mb-1"><span class="progress-value fw-medium">0 Mbps</span></div>
                <div class="progress"><div class="progress-bar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
            </div>
            <h6 class="mb-1"><?= $language::get('server_view_network_output') ?><small class="text-body-secondary ms-2"><?= $language::get('server_view_of', array(':value' => number_format($rServer['network_guaranteed_speed'], 0) . ' Mbps')) ?></small></h6>
            <div id="watchdog_output" class="mb-0">
                <div class="d-flex justify-content-between mb-1"><span class="progress-value fw-medium">0 Mbps</span></div>
                <div class="progress"><div class="progress-bar" role="progressbar" style="width:0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div></div>
            </div>
        <?php else: ?>
            <div class="text-center py-3">
                <i class="icon-base ti tabler-alert-triangle icon-32px text-danger"></i>
                <h5 class="text-danger mt-2"><?= $language::get('server_view_server_offline') ?></h5>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php if (is_array($rServer['gpu_info']) && !empty($rServer['gpu_info']['gpus'])): ?>
    <?php
    $rGPUNumber = 0;
    foreach ($rServer['gpu_info']['gpus'] as $rGPU):
        $rGpuUtil     = intval(explode(' ', $rGPU['utilisation']['gpu_util'])[0]);
        $rEncUtil     = intval(explode(' ', $rGPU['utilisation']['encoder_util'])[0]);
        $rDecUtil     = intval(explode(' ', $rGPU['utilisation']['decoder_util'])[0]);
        $rMemUsed     = intval(explode(' ', $rGPU['memory_usage']['used'])[0]);
        $rMemTotal    = intval(explode(' ', $rGPU['memory_usage']['total'])[0]);
        $rMemPct      = $rMemTotal > 0 ? number_format($rMemUsed / $rMemTotal * 100, 0) : 0;
    ?>
        <div class="card mb-4">
            <div class="card-body">
                <?php
                $rGpuBars = array(
                    array($language::get('server_view_gpu_usage') . ' ' . $rGPUNumber, htmlspecialchars((string) $rGPU['name']), $rGpuUtil, $rGpuUtil . '%'),
                    array($language::get('server_view_gpu_memory_usage') . ' ' . $rGPUNumber, number_format($rMemUsed, 0) . 'MB / ' . number_format($rMemTotal, 0) . 'MB', (int) $rMemPct, $rMemPct . '%'),
                    array($language::get('server_view_encoder_usage') . ' ' . $rGPUNumber, '', $rEncUtil, $rEncUtil . '%'),
                    array($language::get('server_view_decoder_usage') . ' ' . $rGPUNumber, '', $rDecUtil, $rDecUtil . '%'),
                );
                foreach ($rGpuBars as $rIdx => list($rGpuLabel, $rGpuSub, $rGpuVal, $rGpuText)):
                ?>
                    <h6 class="mb-1<?= $rIdx > 0 ? ' mt-3' : '' ?>"><?= $rGpuLabel ?><?php if ($rGpuSub): ?><small class="text-body-secondary ms-2"><?= $rGpuSub ?></small><?php endif; ?></h6>
                    <div class="mb-0">
                        <div class="d-flex justify-content-between mb-1"><span class="fw-medium"><?= $rGpuText ?></span></div>
                        <div class="progress"><div class="progress-bar <?= AdminHelpers::getBarColour($rGpuVal) ?>" role="progressbar" style="width:<?= $rGpuVal ?>%" aria-valuenow="<?= $rGpuVal ?>" aria-valuemin="0" aria-valuemax="100"></div></div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php
        $rGPUNumber++;
    endforeach; ?>
<?php endif; ?>

<?php if ($rHasCert): ?>
    <div class="card mb-4">
        <div class="card-body">
            <div class="row mb-3">
                <label class="col-md-4 col-form-label" for="expiration_date"><?= $language::get('certificate_expiration_date') ?></label>
                <div class="col-md-8"><input type="text" class="form-control" id="expiration_date" value="<?= htmlspecialchars((string) $rExpiration) ?>" readonly></div>
            </div>
            <?php if ($rCertValid): ?>
                <div class="row mb-3">
                    <label class="col-md-4 col-form-label" for="cert_serial"><?= $language::get('certificate_serial') ?></label>
                    <div class="col-md-8"><input type="text" class="form-control" id="cert_serial" value="<?= htmlspecialchars((string) $rCertificate['serial']) ?>" readonly></div>
                </div>
                <div class="row mb-0">
                    <label class="col-md-4 col-form-label" for="cert_subject"><?= $language::get('certificate_subject') ?></label>
                    <div class="col-md-8"><input type="text" class="form-control" id="cert_subject" value="<?= htmlspecialchars((string) $rCertificate['subject']) ?>" readonly></div>
                </div>
            <?php endif; ?>
        </div>
    </div>
<?php endif; ?>

<!-- Charts + tables -->
<div class="card">
    <div class="card-body">
        <ul class="nav nav-tabs flex-wrap mb-4" role="tablist">
            <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#cpu" role="tab"><i class="icon-base ti tabler-cpu me-1"></i><?= $language::get('server_view_resources') ?></button></li>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#network" role="tab"><i class="icon-base ti tabler-network me-1"></i><?= $language::get('server_view_network_traffic') ?></button></li>
            <?php if ($rIsMain): ?>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#streams" role="tab"><i class="icon-base ti tabler-player-play me-1"></i><?= $language::get('online_streams') ?></button></li>
            <?php endif; ?>
            <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#connections" role="tab"><i class="icon-base ti tabler-plug-connected me-1"></i><?= $language::get('server_view_active_connections') ?></button></li>
        </ul>

        <div class="tab-content p-0">
            <div class="tab-pane fade show active" id="cpu" role="tabpanel">
                <div id="cpu_chart" dir="ltr"></div>
            </div>
            <div class="tab-pane fade" id="network" role="tabpanel">
                <div id="network_chart" dir="ltr"></div>
            </div>
            <?php if ($rIsMain): ?>
                <div class="tab-pane fade" id="streams" role="tabpanel">
                    <div class="table-responsive card-datatable">
                        <table id="datatable_streams" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th><?= $language::get('id') ?></th>
                                    <th><?= $language::get('name') ?></th>
                                    <th class="text-center"><?= $language::get('clients') ?></th>
                                    <th class="text-center"><?= $language::get('uptime') ?></th>
                                    <th class="text-center"><?= $language::get('actions') ?></th>
                                    <th><?= $language::get('stream_info') ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
            <div class="tab-pane fade" id="connections" role="tabpanel">
                <div class="table-responsive card-datatable">
                    <table id="datatable_connections" class="table" style="width:100%">
                        <thead>
                            <tr>
                                <th class="text-center"><?= $language::get('quality') ?></th>
                                <th><?= $language::get('username') ?></th>
                                <th><?= $language::get('stream') ?></th>
                                <th><?= $language::get('server') ?></th>
                                <th><?= $language::get('player') ?></th>
                                <th><?= $language::get('isp') ?></th>
                                <th class="text-center"><?= $language::get('ip') ?></th>
                                <th class="text-center"><?= $language::get('duration') ?></th>
                                <th class="text-center"><?= $language::get('output') ?></th>
                                <th class="text-center"><?= $language::get('restreamer') ?></th>
                                <th class="text-center"><?= $language::get('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Live connections modal (opened from a stream's client-count badge) -->
<div class="modal fade" id="liveModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('live_connections') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table id="datatable-live" class="table" style="width:100%">
                        <thead>
                            <tr>
                                <th class="text-center"><?= $language::get('quality') ?></th>
                                <th><?= $language::get('username') ?></th>
                                <th><?= $language::get('stream') ?></th>
                                <th><?= $language::get('server') ?></th>
                                <th><?= $language::get('player') ?></th>
                                <th><?= $language::get('isp') ?></th>
                                <th class="text-center"><?= $language::get('ip') ?></th>
                                <th class="text-center"><?= $language::get('duration') ?></th>
                                <th class="text-center"><?= $language::get('output') ?></th>
                                <th class="text-center"><?= $language::get('restreamer') ?></th>
                                <th class="text-center"><?= $language::get('actions') ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- PHP-FPM status modal (window.getFPMStatus) -->
<div class="modal fade" id="fpmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('server_view_php_fpm_status') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="fpm-body"></div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        if (!window.jQuery) { return; }
        var $ = window.jQuery;
        var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
        var toast = window.xcToast || function() {};
        var confirmBox = function(text) { return window.xcConfirm ? window.xcConfirm(text) : Promise.resolve(window.confirm(text)); };
        var xhr = { headers: { 'X-Requested-With': 'XMLHttpRequest' } };

        var serverId = <?= $rServerId ?>;
        var netspeed = <?= (int) ($rServer['network_guaranteed_speed'] ?: 1000) ?>;
        var lang = {
            error: <?= json_encode($language::get('error_occured')) ?>,
            start: <?= json_encode($language::get('start')) ?>,
            stop: <?= json_encode($language::get('stop')) ?>,
            restart: <?= json_encode($language::get('restart')) ?>,
            kill: <?= json_encode($language::get('kill')) ?>,
            streamStarted: <?= json_encode($language::get('server_view_stream_started')) ?>,
            streamStopped: <?= json_encode($language::get('server_view_stream_stopped')) ?>,
            streamRestarted: <?= json_encode($language::get('server_view_stream_restarted')) ?>,
            connectionKilled: <?= json_encode($language::get('connection_has_been_killed')) ?>,
            connectionsKilled: <?= json_encode($language::get('server_view_connections_killed')) ?>,
            confirmKill: <?= json_encode($language::get('server_view_confirm_kill')) ?>,
            loadingStreams: <?= json_encode($language::get('server_view_loading_streams')) ?>,
            loadingConnections: <?= json_encode($language::get('server_view_loading_connections')) ?>,
            noStatus: <?= json_encode($language::get('server_view_no_status')) ?>,
            idle: <?= json_encode($language::get('server_view_idle')) ?>,
            noServer: <?= json_encode($language::get('server')) ?>
        };

        var STREAM = { '-1': ['secondary', 'No Server'], '0': ['dark', 'Stopped'], '1': ['success', 'Online'], '2': ['warning', 'Starting'], '3': ['danger', 'Down'], '4': ['info', 'On Demand'], '5': ['primary', 'Direct Source'], '6': ['primary', 'Converting'], '7': ['danger', 'Proxy Down'] };

        var pad = function(n) { return (n < 10 ? '0' : '') + n; };
        var fmtUptime = function(sec) {
            sec = Math.max(0, Math.floor(sec || 0));
            if (sec >= 86400) { return pad(Math.floor(sec / 86400)) + 'd ' + pad(Math.floor(sec / 3600) % 24) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm'; }
            return pad(Math.floor(sec / 3600)) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm ' + pad(sec % 60) + 's';
        };
        var num = function(v) { return Number(v || 0).toLocaleString(); };
        var barClass = function(v) { return v >= 75 ? 'bg-danger' : (v >= 50 ? 'bg-warning' : 'bg-success'); };

        // ----- live metric polling (CPU/Mem/Disk/IO/Network + tiles), 1s cadence -----
        function setBar(id, pct, label) {
            var wrap = document.getElementById(id);
            if (!wrap) { return; }
            var val = wrap.querySelector('.progress-value');
            var bar = wrap.querySelector('.progress-bar');
            if (val) { val.textContent = label; }
            if (bar) {
                bar.className = 'progress-bar ' + barClass(pct);
                bar.style.width = Math.max(0, Math.min(100, pct)) + '%';
                bar.setAttribute('aria-valuenow', Math.round(pct));
            }
        }
        function setText(id, txt) { var el = document.getElementById(id); if (el) { el.textContent = txt; } }

        function getStats(auto) {
            var start = Date.now();
            fetch('./api?action=server_view&server_id=' + serverId, xhr)
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    var d = res.data || {};
                    setText('open_connections', num(d.open_connections));
                    setText('total_running_streams', num(d.total_running_streams));
                    setText('online_users', num(d.online_users));
                    setText('offline_streams', num(d.offline_streams));
                    var w = d.watchdog;
                    if (w) {
                        setBar('watchdog_cpu', w.cpu, (Number(w.cpu) || 0).toFixed(2) + '%');
                        setBar('watchdog_mem', w.total_mem_used_percent, (Number(w.total_mem_used_percent) || 0).toFixed(2) + '%');
                        var disk = w.total_disk_space ? (w.total_disk_space - w.free_disk_space) / w.total_disk_space * 100 : 0;
                        setBar('watchdog_disk', disk, disk.toFixed(2) + '%');
                        if (w.iostat_info && w.iostat_info['avg-cpu']) {
                            var io = Number(w.iostat_info['avg-cpu'].iowait) || 0;
                            setBar('watchdog_io', io, io.toFixed(2) + '%');
                            setText('watchdog_idle', (Math.round(Number(w.iostat_info['avg-cpu'].idle) || 0)) + '% ' + lang.idle);
                        }
                        var spd = res.netspeed || netspeed || 1;
                        var inMbps = (w.bytes_received || 0) / 125000;
                        var outMbps = (w.bytes_sent || 0) / 125000;
                        setBar('watchdog_input', (inMbps / spd) * 100, Math.round(inMbps) + ' Mbps');
                        setBar('watchdog_output', (outMbps / spd) * 100, Math.round(outMbps) + ' Mbps');
                    }
                })
                .catch(function() {})
                .finally(function() {
                    if (auto) { setTimeout(function() { getStats(true); }, Math.max(0, 1000 - (Date.now() - start))); }
                });
        }

        // ----- install progress polling (only while the textarea is present) -----
        function getInstallStatus() {
            var box = document.getElementById('server_install');
            if (!box) { return; }
            fetch('./api?action=install_status&server_id=' + serverId, xhr)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.result === true) {
                        box.innerHTML = data.data;
                        if (data.status == 3) { setTimeout(getInstallStatus, 1000); }
                        else if (data.status == 1) { setTimeout(function() { window.location.href = './server_view?id=' + serverId; }, 3000); }
                    } else {
                        box.innerHTML = lang.noStatus;
                    }
                    box.scrollTop = box.scrollHeight;
                })
                .catch(function() {});
        }

        // ----- PHP-FPM status modal -----
        window.getFPMStatus = function(rServerID) {
            fetch('./api?action=fpm_status&server_id=' + rServerID, xhr)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (data && data.result) {
                        document.getElementById('fpm-body').innerHTML = data.data;
                        bootstrap.Modal.getOrCreateInstance(document.getElementById('fpmModal')).show();
                    }
                })
                .catch(function() {});
        };

        // ----- stream row actions (start/stop/restart/purge) -----
        window.api = function(id, serverID, sub) {
            var run = function() {
                fetch('./api?action=stream&sub=' + encodeURIComponent(sub) + '&stream_id=' + encodeURIComponent(id) + '&server_id=' + encodeURIComponent(serverID), xhr)
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        if (!data || data.result !== true) { toast(lang.error, 'error'); return; }
                        var msg = '';
                        if (sub === 'start') { msg = lang.streamStarted; }
                        else if (sub === 'stop') { msg = lang.streamStopped; }
                        else if (sub === 'restart') { msg = lang.streamRestarted; }
                        else if (sub === 'purge') { msg = lang.connectionsKilled; }
                        toast(msg, 'success');
                        if ($.fn.dataTable.isDataTable('#datatable_streams')) { $('#datatable_streams').DataTable().ajax.reload(null, false); }
                        if ($.fn.dataTable.isDataTable('#datatable_connections')) { $('#datatable_connections').DataTable().ajax.reload(null, false); }
                    })
                    .catch(function() { toast(lang.error, 'error'); });
            };
            if (sub === 'purge') { confirmBox(lang.confirmKill).then(function(ok) { if (ok) { run(); } }); }
            else { run(); }
        };

        // ----- kill a single connection (Active Connections + live modal) -----
        function killConnection(uuid, tableSel) {
            if (!uuid) { return; }
            fetch('./api?action=line_activity&sub=kill&pid=' + encodeURIComponent(uuid), xhr)
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || data.result !== true) { toast(lang.error, 'error'); return; }
                    toast(lang.connectionKilled, 'success');
                    if ($.fn.dataTable.isDataTable(tableSel)) { $(tableSel).DataTable().ajax.reload(null, false); }
                })
                .catch(function() { toast(lang.error, 'error'); });
        }

        // ----- stream table cell renderers (clean-JSON) -----
        function streamIdCell(d, t, row) { return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body">' + esc(d) + '</a>'; }
        function streamTitleCell(d) { return esc(d); }
        function streamClientsCell(d, t, row) {
            if (d > 0) { return '<a href="javascript:void(0);" class="badge bg-label-info" onclick="viewLiveConnections(' + Number(row.id) + ',' + Number(row.server_col_id) + ')">' + num(d) + '</a>'; }
            return '<span class="badge bg-label-secondary">0</span>';
        }
        function streamStatusCell(d, t, row) {
            if (d === 1) { return '<span class="badge bg-label-success">' + esc(fmtUptime(row.uptime)) + '</span>'; }
            if (d === 6) { return '<span class="badge bg-label-primary">' + (row.encode_pct != null ? esc(row.encode_pct) + '%' : 'Converting') + '</span>'; }
            var s = STREAM[String(d)] || ['secondary', ''];
            return '<span class="badge bg-label-' + s[0] + '">' + esc(s[1]) + '</span>';
        }
        function streamActionsCell(d, t, row) {
            var sid = row.server_col_id, id = row.id, items = '';
            var item = function(sub, label, cls) { return '<a class="dropdown-item ' + (cls || '') + ' js-act" href="javascript:void(0);" data-sub="' + esc(sub) + '" data-id="' + esc(id) + '" data-server="' + esc(sid) + '">' + esc(label) + '</a>'; };
            var running = (row.status === 1 || row.status === 2 || row.status === 3 || row.status === 5 || row.on_demand);
            if (running) { items += item('stop', lang.stop); items += item('restart', lang.restart); items += item('purge', lang.kill); }
            else { items += item('start', lang.start); }
            return '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' + items + '</div></div>';
        }
        function streamInfoCell(d) {
            if (!d) { return '<small class="text-body-secondary">&mdash;</small>'; }
            var html = '<div class="d-flex flex-wrap gap-1">';
            html += '<span class="badge bg-label-secondary">' + esc(d.bitrate) + ' Kbps</span>';
            html += '<span class="badge bg-label-primary">' + esc(d.resolution) + '</span>';
            html += '<span class="badge bg-label-info">' + esc(d.video) + '</span>';
            html += '<span class="badge bg-label-success">' + esc(d.audio) + '</span>';
            html += '<span class="badge bg-label-secondary">' + esc(d.speed) + '</span>';
            html += '<span class="badge bg-label-secondary">' + esc(d.fps) + '</span>';
            return html + '</div>';
        }

        // ----- connection table cell renderers (clean-JSON) -----
        var isLocal = function(ip) { return !ip || ip === '127.0.0.1' || ip === '::1'; };
        function connDuration(startTs, isRestreamer) {
            var sec = Math.max(0, Math.floor(Date.now() / 1000) - (startTs || 0));
            var colour = 'success', txt;
            if (sec >= 86400) { txt = pad(Math.floor(sec / 86400)) + 'd ' + pad(Math.floor(sec / 3600) % 24) + 'h'; colour = 'danger'; }
            else if (sec >= 3600) { txt = pad(Math.floor(sec / 3600)) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm'; if (sec > 14400) { colour = 'warning'; } }
            else { txt = pad(Math.floor(sec / 60) % 60) + 'm ' + pad(sec % 60) + 's'; }
            if (isRestreamer) { colour = 'success'; }
            return '<span class="badge bg-label-' + colour + '">' + esc(txt) + '</span>';
        }
        function connColumns() {
            return [
                { data: 'divergence', className: 'text-center', render: function(d) {
                    var pct = 100 - (d || 0);
                    var cls = d <= 50 ? 'text-success' : (d <= 80 ? 'text-warning' : 'text-danger');
                    return '<i class="icon-base ti tabler-square-filled ' + cls + '" title="' + pct + '%"></i>';
                } },
                { data: 'user_label', render: function(d, t, row) { if (!d) { return ''; } return row.user_url ? '<a href="' + esc(row.user_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d); } },
                { data: 'stream_name', render: function(d, t, row) { if (!d) { return ''; } return row.stream_url ? '<a href="' + esc(row.stream_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d); } },
                { data: 'server_name', render: function(d, t, row) {
                    var html = row.server_url ? '<a href="' + esc(row.server_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                    if (row.proxy_via) { html += '<br><small class="text-body-secondary">(via ' + esc(row.proxy_via) + ')</small>'; }
                    return html;
                } },
                { data: 'player' },
                { data: 'isp' },
                { data: 'user_ip', className: 'text-nowrap', render: function(d, t, row) {
                    var flag = row.country ? '<img loading="lazy" class="me-1" src="assets/old/images/countries/' + esc(row.country) + '.png" alt="">' : '';
                    return flag + esc(d || '');
                } },
                { data: 'date_start', className: 'text-center', render: function(d, t, row) { return connDuration(d, row.is_restreamer); } },
                { data: 'container', className: 'text-center' },
                { data: 'is_restreamer', className: 'text-center', render: function(d) {
                    return d ? '<i class="icon-base ti tabler-square-filled text-info"></i>' : '<i class="icon-base ti tabler-square-filled text-body-secondary"></i>';
                } },
                { data: null, orderable: false, searchable: false, className: 'text-center text-nowrap', render: function(d, t, row) {
                    return '<button type="button" class="btn btn-sm btn-icon btn-label-danger js-kill" title="' + esc(lang.kill) + '" data-uuid="' + esc(row.uuid) + '"><i class="icon-base ti tabler-hammer"></i></button>';
                } }
            ];
        }

        // ----- live connections modal (from a stream's client-count badge) -----
        window.viewLiveConnections = function(streamID, serverID) {
            if (typeof serverID === 'undefined') { serverID = -1; }
            $('#datatable-live').DataTable({
                destroy: true,
                processing: true,
                serverSide: true,
                searchDelay: 250,
                ajax: { url: './table', data: function(d) { d.id = 'live_connections'; d.stream_id = streamID; d.server_id = serverID; } },
                columns: connColumns()
            });
            $('#datatable-live tbody').off('click', '.js-kill').on('click', '.js-kill', function() { killConnection(this.getAttribute('data-uuid'), '#datatable-live'); });
            bootstrap.Modal.getOrCreateInstance(document.getElementById('liveModal')).show();
        };

        $(function() {
            getInstallStatus();
            getStats(true);

            if (document.getElementById('datatable_streams')) {
                $('#datatable_streams').DataTable({
                    processing: true,
                    serverSide: true,
                    ordering: true,
                    searching: true,
                    ajax: { url: './table', data: function(d) { d.id = 'streams'; d.server = serverId; d.filter = 1; d.simple = true; } },
                    columns: [
                        { data: 'display_id', render: streamIdCell },
                        { data: 'title', render: streamTitleCell },
                        { data: 'clients', className: 'text-center', render: streamClientsCell },
                        { data: 'status', className: 'text-center text-nowrap', render: streamStatusCell },
                        { data: null, className: 'text-center', orderable: false, render: streamActionsCell },
                        { data: 'info', render: streamInfoCell }
                    ],
                    language: { emptyTable: lang.loadingStreams }
                });
                $('#datatable_streams tbody').on('click', '.js-act', function() {
                    window.api(this.getAttribute('data-id'), this.getAttribute('data-server'), this.getAttribute('data-sub'));
                });
            }

            $('#datatable_connections').DataTable({
                processing: true,
                serverSide: true,
                ordering: true,
                searching: true,
                ajax: { url: './table', data: function(d) { d.id = 'live_connections'; d.server_id = serverId; } },
                columns: connColumns(),
                language: { emptyTable: lang.loadingConnections }
            });
            $('#datatable_connections tbody').on('click', '.js-kill', function() { killConnection(this.getAttribute('data-uuid'), '#datatable_connections'); });

            // Soft-reload the connections table every 5s (paused while a modal/dropdown is open).
            setInterval(function() {
                if (document.querySelector('.modal.show') || document.querySelector('.dropdown-menu.show')) { return; }
                if ($.fn.dataTable.isDataTable('#datatable_connections')) { $('#datatable_connections').DataTable().ajax.reload(null, false); }
                if ($.fn.dataTable.isDataTable('#datatable_streams')) { $('#datatable_streams').DataTable().ajax.reload(null, false); }
            }, 5000);

            // ----- ApexCharts (Resources + Network Traffic) -----
            if (typeof ApexCharts !== 'undefined') {
                var rDates = <?= json_encode($rStats['dates']) ?>;
                var areaOpts = function(sel, series, colors, unit) {
                    return {
                        chart: { height: 380, type: 'area', stacked: false, zoom: { type: 'x', enabled: true, autoScaleYaxis: true }, animations: { enabled: false } },
                        colors: colors,
                        dataLabels: { enabled: false },
                        stroke: { width: 2, curve: 'smooth' },
                        series: series,
                        fill: { type: 'gradient', gradient: { opacityFrom: 0.6, opacityTo: 0.8 } },
                        xaxis: { type: 'datetime', min: rDates[0], max: rDates[1], range: 3600000, labels: { formatter: function(v, ts) { var dt = new Date(ts); return ('0' + dt.getHours()).slice(-2) + ':' + ('0' + dt.getMinutes()).slice(-2); } } },
                        tooltip: { y: { formatter: function(v) { return v + unit; } } }
                    };
                };
                new ApexCharts(document.querySelector('#cpu_chart'), areaOpts('#cpu_chart', [
                    { name: <?= json_encode($language::get('server_view_cpu_usage')) ?>, data: <?= json_encode($rStats['cpu']) ?> },
                    { name: <?= json_encode($language::get('server_view_memory_usage')) ?>, data: <?= json_encode($rStats['memory']) ?> },
                    { name: <?= json_encode($language::get('server_view_io_usage')) ?>, data: <?= json_encode($rStats['io']) ?> }
                ], ['#7367f0', '#00cfe8', '#28c76f'], '%')).render();
                new ApexCharts(document.querySelector('#network_chart'), areaOpts('#network_chart', [
                    { name: <?= json_encode($language::get('input')) ?>, data: <?= json_encode($rStats['input']) ?> },
                    { name: <?= json_encode($language::get('output')) ?>, data: <?= json_encode($rStats['output']) ?> }
                ], ['#7367f0', '#00cfe8'], ' Mbps')).render();
            }
        });
    })();
</script>
</body>

</html>
