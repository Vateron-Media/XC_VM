<?php

/**
 * Admin Dashboard (Vuexy). Rebuilt clean on the current backend:
 *  - Service-Status probes are prepared by DashboardController ($rStatusItems).
 *  - Live data keeps the legacy contract: ./api?action=stats (1s) and
 *    ./api?action=graph_stats (60s); DOM ids/classes are unchanged so the
 *    endpoints need no edits.
 *  - No jVectorMap / jQuery Knob / Peity / Switchery: locations and per-server
 *    stats are progress bars; CPU history is an ApexCharts sparkline.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Server\ServerRepository;

if (!Authorization::check('adv', 'index')):
    ?>
    <div class="alert alert-danger text-center" role="alert">
        <?= $language::get('dashboard_no_permissions'); ?><br>
        <?= $language::get('dashboard_nav_top'); ?>
    </div>
    <?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$xmServerId = RequestManager::has('server_id') ? intval(RequestManager::get('server_id')) : null;
$xmAccents  = ['primary', 'success', 'info', 'warning', 'danger'];

// Stat tiles: [wrapClass, icon, accent, label, link|null, showSecondary, unit].
$xmTiles = [
    ['active-connections', 'ti tabler-plug-connected', 'primary', $language::get('dashboard_online_connections'), Authorization::check('adv', 'live_connections') ? 'live_connections' : null, true, ''],
    ['online-users',       'ti tabler-users',          'success', $language::get('dashboard_active_lines'),        Authorization::check('adv', 'live_connections') ? 'live_connections' : null, true, ''],
    ['active-streams',     'ti tabler-player-play',    'info',    $language::get('dashboard_live_streams'),        Authorization::check('adv', 'streams') ? 'streams?filter=1' : null, true, ''],
    ['offline-streams',    'ti tabler-alert-triangle', 'danger',  $language::get('dashboard_down_streams'),        Authorization::check('adv', 'streams') ? 'streams?filter=2' : null, false, ''],
    ['output-flow',        'ti tabler-arrow-up-right', 'primary', $language::get('dashboard_network_output'),      null, false, 'Mbps'],
    ['input-flow',         'ti tabler-arrow-down-left','warning', $language::get('dashboard_network_input'),       null, false, 'Mbps'],
];

// CPU-history seed for the per-server sparklines (id => number[]).
$xmSparklines = [];
foreach ($rServerStats as $rSid => $rHistory) {
    $xmSparklines[(int) $rSid] = array_values(array_map('floatval', (array) $rHistory));
}

// World-map region fills for jsvectormap: ISO2 country code => colour hex, the
// SAME per-country colour the top list uses (colour[0] hex == colour[1] bg class).
// Skip non-country GeoIP codes (A1/A2/O1/AP/EU/…) that are not map regions.
$xmMapValues = [];
if ($rSettings['save_closed_connection'] && $rSettings['dashboard_map']) {
    foreach ($rConnectionMap as $rCountry) {
        $rCode = strtoupper((string) ($rCountry['geoip_country_code'] ?? ''));
        if (preg_match('/^[A-Z]{2}$/', $rCode) && !in_array($rCode, ['A1', 'A2', 'O1', 'AP', 'EU', 'AN'], true) && isset($rCountry['colour'][0])) {
            $xmMapValues[$rCode] = $rCountry['colour'][0];
        }
    }
}
?>

<!-- Page header -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <h4 class="mb-0">
        <?php if ($xmServerId !== null): ?>
            <?= htmlspecialchars(ServerRepository::getAll()[$xmServerId]['server_name'] ?? 'Dashboard'); ?>
            <a href="server_view?id=<?= $xmServerId; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= htmlspecialchars($language::get('dashboard_view_server'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-chart-line"></i></a>
            <a href="process_monitor?server=<?= $xmServerId; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= htmlspecialchars($language::get('dashboard_process_monitor'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-chart-bar"></i></a>
        <?php else: ?>
            <?= $language::get('dashboard') ?: 'Dashboard'; ?>
        <?php endif; ?>
    </h4>
    <div class="dashboard-server-select">
        <select id="server_id" class="form-select">
            <option value="" <?= $xmServerId === null ? 'selected' : ''; ?>><?= $language::get('all_servers'); ?></option>
            <?php foreach ($rServers as $rServerItem): ?>
                <?php if ($rServerItem['enabled']): ?>
                    <option value="<?= (int) $rServerItem['id']; ?>" <?= $xmServerId === (int) $rServerItem['id'] ? 'selected' : ''; ?>>
                        <?= htmlspecialchars($rServerItem['server_name']); ?>
                    </option>
                <?php endif; ?>
            <?php endforeach; ?>
        </select>
    </div>
</div>

<!-- Stat tiles -->
<div class="row g-4 mb-4">
    <?php foreach ($xmTiles as [$rWrap, $rIcon, $rAccent, $rLabel, $rLink, $rSub, $rUnit]): ?>
        <div class="col-sm-6 col-xl-4">
            <?php if ($rLink): ?><a href="<?= htmlspecialchars($rLink, ENT_QUOTES); ?>" class="text-body text-decoration-none"><?php endif; ?>
            <div class="card h-100">
                <div class="card-body d-flex justify-content-between align-items-center <?= $rWrap; ?>">
                    <div class="card-title mb-0">
                        <h5 class="mb-1 me-2"><span class="entry">0</span><?php if ($rUnit): ?> <small class="text-body-secondary"><?= $rUnit; ?></small><?php endif; ?></h5>
                        <p class="mb-0"><?= htmlspecialchars($rLabel); ?></p>
                    </div>
                    <div class="card-icon">
                        <span class="badge bg-label-<?= $rAccent; ?> rounded p-2">
                            <i class="icon-base <?= $rIcon; ?> icon-26px"></i>
                        </span>
                    </div>
                </div>
            </div>
            <?php if ($rLink): ?></a><?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>

<div class="row g-4 mb-4">
    <?php if ($rSettings['dashboard_status']): ?>
        <!-- Service Status -->
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0"><?= $language::get('dashboard_service_status'); ?></h5></div>
                <div class="card-body dashboard-status-scroll">
                    <ul class="timeline mb-0">
                        <?php if (empty($rStatusItems)): ?>
                            <li class="timeline-item timeline-item-transparent">
                                <span class="timeline-point timeline-point-success"></span>
                                <div class="timeline-event">
                                    <div class="timeline-header"><h6 class="mb-0"><?= $language::get('dashboard_no_issues'); ?></h6></div>
                                </div>
                            </li>
                        <?php else: foreach ($rStatusItems as $rItem): ?>
                            <li class="timeline-item timeline-item-transparent">
                                <span class="timeline-point timeline-point-<?= htmlspecialchars($rItem['state'], ENT_QUOTES); ?>"></span>
                                <div class="timeline-event">
                                    <div class="timeline-header"><h6 class="mb-1"><?= htmlspecialchars($rItem['title']); ?></h6></div>
                                    <small class="text-body-secondary"><?= $rItem['text']; ?></small>
                                </div>
                            </li>
                        <?php endforeach; endif; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$rMobile && $rSettings['dashboard_stats']): ?>
        <!-- CPU & Memory -->
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0"><?= $language::get('dashboard_cpu_memory'); ?></h5></div>
                <div class="card-body"><div id="cpu_chart"></div></div>
            </div>
        </div>
        <!-- Network -->
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header"><h5 class="card-title mb-0"><?= $language::get('dashboard_network_traffic'); ?></h5></div>
                <div class="card-body"><div id="network_chart"></div></div>
            </div>
        </div>
        <?php if ($rSettings['save_closed_connection']): ?>
            <!-- Connections -->
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header"><h5 class="card-title mb-0"><?= $language::get('dashboard_connections'); ?></h5></div>
                    <div class="card-body"><div id="connections_chart"></div></div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($rSettings['save_closed_connection'] && $rSettings['dashboard_map'] && $rConnectionCount > 0): ?>
            <!-- Connections by Location -->
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-header"><h5 class="card-title mb-0"><?= $language::get('dashboard_connections_by_location'); ?></h5></div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-lg-8 mb-4 mb-lg-0">
                                <div id="map" class="dashboard-map"></div>
                            </div>
                            <div class="col-lg-4 align-self-center">
                                <?php foreach (array_slice($rConnectionMap, 0, 6) as $rCountry):
                                    $rPct = (int) round($rCountry['count'] / $rConnectionCount * 100);
                                    $rBar = $rCountry['colour'][1] ?? 'bg-primary';
                                    ?>
                                    <div class="d-flex justify-content-between mb-1">
                                        <span class="fw-medium"><?= htmlspecialchars($rCountry['name']); ?></span>
                                        <span class="text-body-secondary"><?= number_format($rCountry['count'], 0); ?> · <?= $rPct; ?>%</span>
                                    </div>
                                    <div class="progress mb-3 dashboard-loc-progress">
                                        <div class="progress-bar <?= htmlspecialchars($rBar, ENT_QUOTES); ?>" role="progressbar" data-width="<?= $rPct; ?>" aria-valuenow="<?= $rPct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php if ($xmServerId === null): ?>
    <!-- Per-server cards -->
    <div class="row g-4">
        <?php $i = 0; foreach ($rOrderedServers as $rServer): ?>
            <?php
            if (!$rServer['enabled']) {
                continue;
            }
            $i = ($i % 5) + 1;
            $rAccent = $xmAccents[($i - 1) % count($xmAccents)];
            $rId = (int) $rServer['id'];

            if ($rServer['server_type'] == 0) {
                $rServerType = $rServer['is_main'] ? $language::get('dashboard_main_server') : $language::get('dashboard_load_balancer');
                if ($rServer['enable_proxy']) {
                    $rServerType .= ' ' . $language::get('dashboard_proxied');
                }
            } else {
                $rServerType = $language::get('dashboard_proxy_server');
            }
            $rIsMain = ($rServer['server_type'] == 0);
            ?>
            <div class="col-xl-4 col-md-6">
                <div class="card h-100">
                    <div class="card-header d-flex justify-content-between align-items-center border-bottom">
                        <div>
                            <h6 class="mb-0"><a href="server_view?id=<?= $rId; ?>" class="text-body"><?= htmlspecialchars($rServer['server_name']); ?></a></h6>
                            <small class="text-body-secondary"><?= htmlspecialchars($rServerType); ?></small>
                        </div>
                        <span class="badge rounded-pill bg-label-<?= $rServer['server_online'] ? 'success' : 'danger'; ?>">
                            <?= $rServer['server_online'] ? $language::get('dashboard_online') : $language::get('dashboard_offline'); ?>
                        </span>
                    </div>

                    <?php if (!$rServer['server_online']): ?>
                        <div class="card-body text-center py-5">
                            <?php
                            $rOffText  = $language::get('dashboard_server_offline');
                            $rOffState = 'danger';
                            if ($rServer['status'] == 3) { $rOffText = $language::get('dashboard_installing'); $rOffState = 'info'; }
                            elseif ($rServer['status'] == 4) { $rOffText = $language::get('dashboard_install_failed'); $rOffState = 'warning'; }
                            ?>
                            <i class="icon-base ti tabler-alert-triangle icon-36px text-<?= $rOffState; ?> mb-2"></i>
                            <h6 class="text-<?= $rOffState; ?> mb-0"><?= $rOffText; ?></h6>
                        </div>
                    <?php else: ?>
                        <div class="card-body">
                            <div class="row text-center g-3 mb-3">
                                <div class="col-4"><small class="text-body-secondary d-block"><?= $language::get('conns'); ?></small><span class="fw-medium" id="s_<?= $rId; ?>_conns">0</span></div>
                                <div class="col-4"><small class="text-body-secondary d-block"><?= $language::get('users'); ?></small><span class="fw-medium" id="s_<?= $rId; ?>_users">0</span></div>
                                <div class="col-4"><small class="text-body-secondary d-block"><?= $language::get('online'); ?></small><span class="fw-medium" id="s_<?= $rId; ?>_online">0</span></div>
                                <div class="col-4"><small class="text-body-secondary d-block"><?= $language::get('input'); ?></small><span class="fw-medium"><span id="s_<?= $rId; ?>_input">0</span> <small>Mbps</small></span></div>
                                <div class="col-4"><small class="text-body-secondary d-block"><?= $language::get('output'); ?></small><span class="fw-medium"><span id="s_<?= $rId; ?>_output">0</span> <small>Mbps</small></span></div>
                                <div class="col-4"><small class="text-body-secondary d-block"><?= $language::get('uptime'); ?></small><span class="fw-medium" id="s_<?= $rId; ?>_uptime">0d 0h</span></div>
                            </div>

                            <?php
                            $rMetrics = [['cpu', 'CPU'], ['mem', 'MEM']];
                            if ($rIsMain) {
                                $rMetrics[] = ['io', 'IO'];
                                $rMetrics[] = ['fs', 'DISK'];
                            }
                            foreach ($rMetrics as [$rMk, $rMl]): ?>
                                <div class="d-flex justify-content-between mb-1"><small class="fw-medium"><?= $rMl; ?></small></div>
                                <div class="progress mb-2 dashboard-metric-progress">
                                    <div class="progress-bar bg-success" role="progressbar" id="s_<?= $rId; ?>_<?= $rMk; ?>" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            <?php endforeach; ?>
                            <span class="d-none" id="s_<?= $rId; ?>_requests">0</span>
                            <div class="dashboard-spark mt-2" id="s_<?= $rId; ?>_spark" data-accent="<?= $rAccent; ?>"></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function () {
        var nf = new Intl.NumberFormat('en-US');
        var num = function (n) { return nf.format(n || 0); };
        var hasServerId = <?= $xmServerId !== null ? 'true' : 'false'; ?>;
        var serverParam = <?= $xmServerId !== null ? intval($xmServerId) : 'null'; ?>;
        var doGraphs = <?= (!$rMobile && $rSettings['dashboard_stats']) ? 'true' : 'false'; ?>;
        var doConnections = <?= (!$rMobile && $rSettings['dashboard_stats'] && $rSettings['save_closed_connection']) ? 'true' : 'false'; ?>;

        function pctOf(a, b) {
            var p = Math.ceil((a / b) * 100);
            if (!isFinite(p) || isNaN(p)) return 0;
            return Math.max(0, Math.min(100, p));
        }
        function setTile(cls, value, secondary, pct) {
            var root = document.querySelector('.' + cls);
            if (!root) return;
            var e = root.querySelector('.entry'); if (e) e.textContent = num(value);
            if (secondary !== null) { var s = root.querySelector('.stat-sub'); if (s) s.textContent = num(secondary); }
            var bar = root.querySelector('.progress-bar');
            if (bar && pct !== null) { bar.style.width = pct + '%'; bar.setAttribute('aria-valuenow', pct); }
        }
        function barClass(v) { return v > 75 ? 'bg-danger' : (v > 50 ? 'bg-warning' : 'bg-success'); }
        function setServerBar(id, key, v) {
            var el = document.getElementById('s_' + id + '_' + key);
            if (!el) return;
            el.className = 'progress-bar ' + barClass(v);
            el.style.width = v + '%';
            el.setAttribute('aria-valuenow', v);
        }
        function setText(id, txt) { var el = document.getElementById(id); if (el) el.textContent = txt; }

        function pollStats(auto) {
            var url = './api?action=stats' + (hasServerId ? '&server_id=' + serverParam : '');
            var start = Date.now();
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    setTile('active-connections', d.open_connections, d.total_connections, pctOf(d.open_connections, d.total_connections));
                    setTile('online-users', d.online_users, d.total_users, pctOf(d.online_users, d.total_users));
                    setTile('active-streams', d.total_running_streams, d.offline_streams, pctOf(d.total_running_streams, d.offline_streams + d.total_running_streams));
                    setTile('offline-streams', d.offline_streams, null, null);
                    var out = Math.floor((d.bytes_sent || 0) / 125000);
                    var inp = Math.floor((d.bytes_received || 0) / 125000);
                    setTile('output-flow', out, null, pctOf(out, d.network_guaranteed_speed));
                    setTile('input-flow', inp, null, pctOf(inp, d.network_guaranteed_speed));

                    if (!hasServerId && Array.isArray(d.servers)) {
                        d.servers.forEach(function (s) {
                            setText('s_' + s.server_id + '_conns', num(s.open_connections));
                            setText('s_' + s.server_id + '_users', num(s.online_users));
                            setText('s_' + s.server_id + '_online', num(s.total_running_streams));
                            setText('s_' + s.server_id + '_input', num(Math.floor(s.bytes_received / 125000)));
                            setText('s_' + s.server_id + '_output', num(Math.floor(s.bytes_sent / 125000)));
                            if (s.uptime) setText('s_' + s.server_id + '_uptime', s.uptime.split(' ').slice(0, 2).join(' '));
                            setServerBar(s.server_id, 'cpu', s.cpu);
                            setServerBar(s.server_id, 'mem', s.mem);
                            if (s.server_type == 0) { setServerBar(s.server_id, 'io', s.io); setServerBar(s.server_id, 'fs', s.fs); }
                        });
                    }
                })
                .catch(function () { /* keep last values */ })
                .finally(function () {
                    if (auto) { var wait = Math.max(0, 1000 - (Date.now() - start)); setTimeout(function () { pollStats(true); }, wait); }
                });
        }

        function areaOptions(el, series, colors, unit) {
            return {
                chart: { height: 300, type: 'area', stacked: false, zoom: { enabled: false }, toolbar: { show: false }, animations: { enabled: false } },
                colors: colors,
                dataLabels: { enabled: false },
                stroke: { width: 2, curve: 'smooth' },
                series: series,
                fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.1 } },
                xaxis: { type: 'datetime', labels: { formatter: function (v, ts) { var dt = new Date(ts); return ('0' + dt.getHours()).slice(-2) + ':' + ('0' + dt.getMinutes()).slice(-2); } } },
                tooltip: { y: { formatter: function (v) { return unit ? v + unit : v; } } }
            };
        }
        var charts = {};
        function drawChart(key, sel, opts) {
            var node = document.querySelector(sel);
            if (!node) return;
            if (charts[key]) { charts[key].destroy(); }
            charts[key] = new ApexCharts(node, opts);
            charts[key].render();
        }
        function pollGraphs(auto) {
            var url = './api?action=graph_stats' + (hasServerId ? '&server_id=' + serverParam : '');
            var start = Date.now();
            fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    drawChart('cpu', '#cpu_chart', areaOptions('#cpu_chart',
                        [{ name: 'CPU Usage', data: d.cpu }, { name: 'Memory Usage', data: d.memory }, { name: 'IO Usage', data: d.io }],
                        ['#7367f0', '#00cfe8', '#28c76f'], '%'));
                    drawChart('net', '#network_chart', areaOptions('#network_chart',
                        [{ name: 'Input', data: d.input }, { name: 'Output', data: d.output }],
                        ['#7367f0', '#00cfe8'], ' Mbps'));
                    if (doConnections) {
                        drawChart('conn', '#connections_chart', areaOptions('#connections_chart',
                            [{ name: 'Online Streams', data: d.streams }, { name: 'Unique Users', data: d.users }, { name: 'Total Connections', data: d.connections }],
                            ['#7367f0', '#00cfe8', '#28c76f'], ''));
                    }
                })
                .catch(function () { /* keep last chart */ })
                .finally(function () {
                    if (auto) { setTimeout(function () { pollGraphs(true); }, Math.max(0, 60000 - (Date.now() - start))); }
                });
        }

        function renderSparklines() {
            var seed = <?= json_encode($xmSparklines, JSON_UNESCAPED_SLASHES); ?>;
            document.querySelectorAll('.dashboard-spark').forEach(function (node) {
                var id = node.id.replace('s_', '').replace('_spark', '');
                var data = seed[id] || [];
                if (!data.length) return;
                new ApexCharts(node, {
                    chart: { type: 'area', height: 50, sparkline: { enabled: true }, animations: { enabled: false } },
                    stroke: { width: 2, curve: 'smooth' },
                    fill: { type: 'gradient', gradient: { opacityFrom: 0.4, opacityTo: 0.1 } },
                    series: [{ data: data }],
                    tooltip: { y: { formatter: function (v) { return v + '%'; } } }
                }).render();
            });
        }

        function renderMap() {
            var el = document.getElementById('map');
            if (!el || typeof jsVectorMap === 'undefined') return;
            var values = <?= json_encode($xmMapValues, JSON_UNESCAPED_SLASHES); ?>;
            var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            var low = dark ? '#3b4253' : '#e7eaec';
            var map = new jsVectorMap({
                selector: '#map',
                map: 'world',
                backgroundColor: 'transparent',
                zoomButtons: false,
                regionStyle: {
                    initial: { fill: low, stroke: 'none' },
                    hover: { fillOpacity: 0.85 }
                }
            });
            // Paint each country with its own colour (matches the top list); done
            // directly rather than through the numeric colour-scale visualizer.
            Object.keys(values).forEach(function (code) {
                if (map.regions[code] && map.regions[code].element) {
                    map.regions[code].element.setStyle('fill', values[code]);
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function () {
            if (window.jQuery && jQuery.fn.select2) { jQuery('#server_id').select2({ width: '100%' }); }
            renderMap();
            document.querySelectorAll('.dashboard-loc-progress .progress-bar').forEach(function (b) {
                b.style.width = (b.getAttribute('data-width') || 0) + '%';
            });
            pollStats(true);
            if (doGraphs) { pollGraphs(true); }
            if (typeof ApexCharts !== 'undefined') { renderSparklines(); }
            var sel = document.getElementById('server_id');
            if (sel) sel.addEventListener('change', function () {
                window.location.href = this.value ? './dashboard?server_id=' + encodeURIComponent(this.value) : './dashboard';
            });
        });
    })();
</script>
</body>

</html>
