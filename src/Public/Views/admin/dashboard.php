<?php

/**
 * Admin Dashboard (Bootstrap 5). Rebuilt clean on the current backend:
 *  - Service-Status probes are prepared by DashboardController ($rStatusItems).
 *  - Live data keeps the legacy contract: ./api?action=stats (1s) and
 *    ./api?action=graph_stats (60s); DOM ids/classes are unchanged so the
 *    endpoints need no edits.
 *  - No jVectorMap / jQuery Knob / Peity / Switchery: locations and per-server
 *    stats are progress bars; CPU history is an ApexCharts sparkline.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\License\LicenseGate;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Server\ServerRepository;

if (!Authorization::check('adv', 'index')):
?>
    <div class="alert alert-danger text-center" role="alert">
        <?= $language::get('dashboard_no_permissions'); ?><br>
        <?= $language::get('dashboard_nav_top'); ?>
    </div>
<?php
    LayoutRenderer::renderFooter('admin');
    echo '</body></html>';
    return;
endif;

$xmServerId = RequestManager::has('server_id') ? intval(RequestManager::get('server_id')) : null;
$xmAccents  = ['primary', 'success', 'info', 'warning', 'danger'];

// Statistics items: [wrapClass, icon, accent, label, link|null, showSecondary, unit].
// The "/ total" secondary is "this server / whole fleet", so it only means
// something with a server selected; in the fleet view it duplicates or reads 0.
$xmPerServer = $xmServerId !== null;
$xmTiles = [
    ['active-connections', 'ti tabler-plug-connected', 'primary', $language::get('dashboard_online_connections'), Authorization::check('adv', 'live_connections') ? 'live_connections' : null, $xmPerServer, ''],
    ['online-users',       'ti tabler-users',          'success', $language::get('dashboard_active_lines'),        Authorization::check('adv', 'live_connections') ? 'live_connections' : null, $xmPerServer, ''],
    ['active-streams',     'ti tabler-player-play',    'info',    $language::get('dashboard_live_streams'),        Authorization::check('adv', 'streams') ? 'streams?filter=1' : null, false, ''],
    ['offline-streams',    'ti tabler-alert-triangle', 'danger',  $language::get('dashboard_down_streams'),        Authorization::check('adv', 'streams') ? 'streams?filter=2' : null, false, ''],
    ['output-flow',        'ti tabler-arrow-up-right', 'primary', $language::get('dashboard_network_output'),      null, false, 'Mbps'],
    ['input-flow',         'ti tabler-arrow-down-left', 'warning', $language::get('dashboard_network_input'),       null, false, 'Mbps'],
];

// CPU-history seed for the per-server sparklines (id => number[]).
$xmSparklines = [];
foreach ($rServerStats as $rSid => $rHistory) {
    // Each entry is already a {x: epoch-ms, y: cpu%} pair from DashboardController.
    $xmSparklines[(int) $rSid] = array_values((array) $rHistory);
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

<?php
// Activation nudge: shown while the install is NOT licensed, as judged by the
// extension itself (XC_VM::license_valid via LicenseGate). Deliberately not a
// file_exists() on config/activation_key — a key file proves only that someone
// pasted something, not that it unlocks anything, so that check hid the banner
// for rejected keys and kept showing it for licensed installs that carry no key
// file at all. Soft UI hint only; real enforcement lives in the extension, and
// LicenseGate fails open where it is absent (dev/CI).
if (!LicenseGate::licensed()):
    $xmHwid = (class_exists('XC_VM') && method_exists('XC_VM', 'install_id')) ? (string) \XC_VM::install_id() : '';
?>
    <div class="alert alert-warning mb-4" role="alert">
        <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
            <i class="icon-base ti tabler-shield-lock"></i>
            <div class="flex-grow-1">
                <strong><?= $language::get('activation_required_title'); ?></strong>
                <div class="small">
                    <?= $language::get('activation_required_text'); ?>
                    <?php if ($xmHwid !== ''): ?>
                        <br><?= $language::get('activation_hwid'); ?>:
                        <code class="user-select-all"><?= htmlspecialchars($xmHwid, ENT_QUOTES); ?></code>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <form id="activation-form" class="d-flex flex-wrap gap-2" onsubmit="return false;">
            <input type="text" id="activation-key-input" class="form-control form-control-sm" style="flex:1 1 260px;min-width:0;max-width:720px;"
                autocomplete="off" spellcheck="false"
                placeholder="<?= htmlspecialchars($language::get('activation_key_placeholder'), ENT_QUOTES); ?>">
            <a href="https://www.xcvm.tech/activate" target="_blank" rel="noopener" id="activation-get-key" class="btn btn-sm btn-primary"
                data-hwid="<?= htmlspecialchars($xmHwid, ENT_QUOTES); ?>">
                <i class="icon-base ti tabler-copy me-1"></i><?= $language::get('activation_copy_hwid_get_key'); ?>
            </a>
            <button type="submit" id="activation-submit" class="btn btn-sm btn-warning">
                <?= $language::get('activation_activate'); ?>
            </button>
        </form>
        <div id="activation-msg" class="small mt-1 d-none"></div>
    </div>
    <script>
        (function () {
            var form = document.getElementById('activation-form');
            if (!form) return;
            // The key is issued for this HWID: copy it on the way to the store
            // (the link still opens it). Over plain HTTP there is no
            // navigator.clipboard, so a hidden textarea and execCommand do it.
            document.getElementById('activation-get-key').addEventListener('click', function () {
                var hwid = this.dataset.hwid || '';
                if (!hwid) return;
                var done = function () {
                    if (window.xcToast) window.xcToast(<?= json_encode($language::get('activation_hwid_copied')); ?>, 'success');
                };
                if (navigator.clipboard && window.isSecureContext) {
                    navigator.clipboard.writeText(hwid).then(done, function () {});
                    return;
                }
                var area = document.createElement('textarea');
                area.value = hwid;
                area.setAttribute('readonly', '');
                area.style.position = 'fixed';
                area.style.opacity = '0';
                document.body.appendChild(area);
                area.select();
                try {
                    if (document.execCommand('copy')) done();
                } catch (e) {}
                document.body.removeChild(area);
            });
            form.addEventListener('submit', function () {
                var input = document.getElementById('activation-key-input');
                var msg = document.getElementById('activation-msg');
                var btn = document.getElementById('activation-submit');
                var key = (input.value || '').trim();
                if (!key) return;
                btn.disabled = true;
                fetch('./api?action=save_activation_key', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: 'activation_key=' + encodeURIComponent(key)
                }).then(function (r) { return r.json(); }).then(function (d) {
                    if (d && d.result) { location.reload(); return; }
                    btn.disabled = false;
                    msg.textContent = (d && d.message) ? d.message : '';
                    msg.classList.remove('d-none');
                    msg.classList.add('text-danger');
                }).catch(function () { btn.disabled = false; });
            });
        })();
    </script>
<?php endif; ?>

<?php // Maintenance mode is on (Core\Config\Maintenance): left on, it is an outage of its own. ?>
<?php if (isset($rMaintenance)): ?>
    <div class="alert alert-warning mb-4" role="alert">
        <i class="icon-base ti tabler-tool me-1"></i><?= htmlspecialchars($rMaintenance > 0 ? $language::get('maintenance_banner_until', ['{until}' => date('Y-m-d H:i', $rMaintenance)]) : $language::get('maintenance_banner'), ENT_QUOTES); ?>
        <a href="settings" class="alert-link ms-1"><?= $language::get('settings'); ?></a>
    </div>
<?php endif; ?>

<?php // The Cluster Nodes page's licence and certificate warnings (ClusterOverview::dashboardBanners). ?>
<?php foreach ($clusterBanners ?? [] as $rBanner): ?>
    <div class="alert alert-<?= htmlspecialchars($rBanner['type'], ENT_QUOTES); ?> mb-4" role="alert">
        <i class="icon-base ti tabler-<?= $rBanner['type'] === 'danger' ? 'alert-octagon' : 'alert-triangle'; ?> me-1"></i><?= htmlspecialchars($language::get($rBanner['key'], $rBanner['vars']), ENT_QUOTES); ?>
        <a href="cluster_nodes" class="alert-link ms-1"><?= $language::get('cluster_nodes'); ?></a>
    </div>
<?php endforeach; ?>

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

<!-- Statistics -->
<div class="card mb-4">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0"><?= $language::get('dashboard_statistics'); ?></h5>
        <small class="text-body-secondary"><?= $language::get('last_updated'); ?> <span class="tabular-nums" id="stats_updated">&mdash;</span></small>
    </div>
    <div class="card-body">
        <div class="row gy-4">
            <?php foreach ($xmTiles as [$rWrap, $rIcon, $rAccent, $rLabel, $rLink, $rSub, $rUnit]): ?>
                <div class="col-xl-2 col-md-4 col-6 dashboard-stat-item">
                    <?php if ($rLink): ?><a href="<?= htmlspecialchars($rLink, ENT_QUOTES); ?>" class="text-body text-decoration-none"><?php endif; ?>
                        <div class="d-flex align-items-center <?= $rWrap; ?>">
                            <div class="badge rounded bg-label-<?= $rAccent; ?> me-4 p-2">
                                <i class="icon-base <?= $rIcon; ?> icon-lg"></i>
                            </div>
                            <div class="card-info">
                                <h5 class="mb-0 tabular-nums">
                                    <span class="entry">0</span><?php if ($rSub): ?><small class="text-body-secondary fw-normal"> / <span class="stat-sub">0</span></small><?php endif; ?><?php if ($rUnit): ?> <small class="text-body-secondary fw-normal"><?= $rUnit; ?></small><?php endif; ?>
                                </h5>
                                <small><?= htmlspecialchars($rLabel); ?></small>
                            </div>
                        </div>
                        <?php if ($rLink): ?>
                    </a><?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="row g-4 mb-4">
    <?php if ($rSettings['dashboard_status']): ?>
        <!-- Service Status -->
        <div class="col-xl-6">
            <?php
            // check state => [accent, badge icon]
            $xmCheckStyle = [
                'ok'   => ['success', 'tabler-circle-check'],
                'warn' => ['warning', 'tabler-alert-circle'],
                'fail' => ['danger', 'tabler-circle-x'],
                'off'  => ['secondary', 'tabler-circle-minus'],
            ];
            $xmIssues = count(array_filter($rStatusChecks, fn($c) => in_array($c['state'], ['warn', 'fail'], true)));
            ?>
            <div class="card h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2"><i class="icon-base ti tabler-heartbeat icon-22px text-<?= $xmIssues ? 'danger' : 'success'; ?>"></i><span><?= $language::get('dashboard_service_status'); ?></span></h5>
                    <span class="badge rounded-pill bg-label-<?= $xmIssues ? 'danger' : 'success'; ?>">
                        <?= $xmIssues ? $language::get('dashboard_status_issues', ['{count}' => (string) $xmIssues]) : $language::get('dashboard_status_all_ok'); ?>
                    </span>
                </div>
                <div class="card-body dashboard-status-scroll">
                    <ul class="list-unstyled mb-0">
                        <?php foreach ($rStatusChecks as $rCheck):
                            [$rAcc, $rStateIcon] = $xmCheckStyle[$rCheck['state']];
                        ?>
                            <li class="d-flex align-items-start gap-3 mb-4">
                                <div class="avatar flex-shrink-0">
                                    <span class="avatar-initial rounded bg-label-<?= $rAcc; ?>"><i class="icon-base ti <?= $rCheck['icon']; ?> icon-22px"></i></span>
                                </div>
                                <div class="flex-grow-1 min-w-0">
                                    <h6 class="mb-0"><?= htmlspecialchars($rCheck['title']); ?></h6>
                                    <?php if ($rCheck['detail'] !== ''): ?><small class="text-body-secondary d-block"><?= htmlspecialchars($rCheck['detail']); ?></small><?php endif; ?>
                                    <?php if ($rCheck['help'] !== ''): ?><small class="d-block mt-1 text-<?= $rAcc; ?>"><?= $rCheck['help']; ?></small><?php endif; ?>
                                </div>
                                <i class="icon-base ti <?= $rStateIcon; ?> icon-24px text-<?= $rAcc; ?> flex-shrink-0"></i>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!$rMobile && $rSettings['dashboard_stats']): ?>
        <!-- CPU & Memory -->
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2"><i class="icon-base ti tabler-cpu icon-22px text-primary"></i><span><?= $language::get('dashboard_cpu_memory'); ?></span></h5>
                </div>
                <div class="card-body">
                    <div id="cpu_chart"></div>
                </div>
            </div>
        </div>
        <!-- Network -->
        <div class="col-xl-6">
            <div class="card h-100">
                <div class="card-header">
                    <h5 class="card-title mb-0 d-flex align-items-center gap-2"><i class="icon-base ti tabler-arrows-up-down icon-22px text-info"></i><span><?= $language::get('dashboard_network_traffic'); ?></span></h5>
                </div>
                <div class="card-body">
                    <div id="network_chart"></div>
                </div>
            </div>
        </div>
        <?php if ($rSettings['save_closed_connection']): ?>
            <!-- Connections -->
            <div class="col-xl-6">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0 d-flex align-items-center gap-2"><i class="icon-base ti tabler-plug-connected icon-22px text-warning"></i><span><?= $language::get('dashboard_connections'); ?></span></h5>
                    </div>
                    <div class="card-body">
                        <div id="connections_chart"></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($rSettings['save_closed_connection'] && $rSettings['dashboard_map'] && $rConnectionCount > 0): ?>
            <!-- Connections by Location -->
            <div class="col-12">
                <div class="card h-100">
                    <div class="card-header">
                        <h5 class="card-title mb-0 d-flex align-items-center gap-2"><i class="icon-base ti tabler-world icon-22px text-primary"></i><span><?= $language::get('dashboard_connections_by_location'); ?></span></h5>
                    </div>
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
        <?php $i = 0;
        foreach ($rOrderedServers as $rServer): ?>
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
                <div class="card h-100 dashboard-card-hover">
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
                            if ($rServer['status'] == 3) {
                                $rOffText = $language::get('dashboard_installing');
                                $rOffState = 'info';
                            } elseif ($rServer['status'] == 4) {
                                $rOffText = $language::get('dashboard_install_failed');
                                $rOffState = 'warning';
                            }
                            ?>
                            <i class="icon-base ti tabler-alert-triangle icon-36px text-<?= $rOffState; ?> mb-2"></i>
                            <h6 class="text-<?= $rOffState; ?> mb-0"><?= $rOffText; ?></h6>
                        </div>
                    <?php else: ?>
                        <div class="card-body">
                            <div class="row g-2 mb-4 dashboard-server-stats">
                                <?php
                                $rStatCells = [
                                    ['conns',  $language::get('conns'),  'tabler-plug-connected',  'primary',   ''],
                                    ['users',  $language::get('users'),  'tabler-users',           'success',   ''],
                                    ['online', $language::get('online'), 'tabler-player-play',     'info',      ''],
                                    ['input',  $language::get('input'),  'tabler-arrow-down-left', 'warning',   'Mbps'],
                                    ['output', $language::get('output'), 'tabler-arrow-up-right',  'primary',   'Mbps'],
                                    ['uptime', $language::get('uptime'), 'tabler-clock',           'secondary', 'text'],
                                ];
                                foreach ($rStatCells as [$rSk, $rSlabel, $rSicon, $rSacc, $rSunit]): ?>
                                    <div class="col-4">
                                        <div class="dashboard-stat">
                                            <span class="stat-ico bg-label-<?= $rSacc; ?>"><i class="icon-base ti <?= $rSicon; ?>"></i></span>
                                            <div class="lh-1">
                                                <span class="stat-label text-body-secondary d-block"><?= htmlspecialchars($rSlabel); ?></span>
                                                <?php if ($rSunit === 'text'): ?>
                                                    <span class="stat-value tabular-nums" id="s_<?= $rId; ?>_<?= $rSk; ?>">0d 0h</span>
                                                <?php elseif ($rSunit !== ''): ?>
                                                    <span class="stat-value tabular-nums"><span id="s_<?= $rId; ?>_<?= $rSk; ?>">0</span> <small class="text-body-secondary fw-normal"><?= $rSunit; ?></small></span>
                                                <?php else: ?>
                                                    <span class="stat-value tabular-nums" id="s_<?= $rId; ?>_<?= $rSk; ?>">0</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <?php
                            $rMetrics = [['cpu', 'CPU', 'tabler-cpu'], ['mem', 'MEM', 'tabler-device-sd-card']];
                            if ($rIsMain) {
                                $rMetrics[] = ['io', 'IO', 'tabler-arrows-exchange'];
                                $rMetrics[] = ['fs', 'DISK', 'tabler-database'];
                            }
                            foreach ($rMetrics as [$rMk, $rMl, $rMi]): ?>
                                <div class="dashboard-metric">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <span class="dashboard-metric-label"><i class="icon-base ti <?= $rMi; ?> me-1"></i><?= $rMl; ?></span>
                                        <span class="dashboard-metric-val tabular-nums text-success" id="s_<?= $rId; ?>_<?= $rMk; ?>_val">0%</span>
                                    </div>
                                    <div class="progress dashboard-metric-progress">
                                        <div class="progress-bar bg-success" role="progressbar" id="s_<?= $rId; ?>_<?= $rMk; ?>" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <span class="d-none" id="s_<?= $rId; ?>_requests">0</span>
                            <div class="dashboard-spark mt-3" id="s_<?= $rId; ?>_spark" data-accent="<?= $rAccent; ?>"></div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var nf = new Intl.NumberFormat('en-US');
        var num = function(n) {
            return nf.format(n || 0);
        };
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
            var e = root.querySelector('.entry');
            if (e) e.textContent = num(value);
            if (secondary !== null) {
                var s = root.querySelector('.stat-sub');
                if (s) s.textContent = num(secondary);
            }
            var bar = root.querySelector('.progress-bar');
            if (bar && pct !== null) {
                bar.style.width = pct + '%';
                bar.setAttribute('aria-valuenow', pct);
            }
        }

        function barClass(v) {
            return v > 75 ? 'bg-danger' : (v > 50 ? 'bg-warning' : 'bg-success');
        }

        function barTone(v) {
            return v > 75 ? 'text-danger' : (v > 50 ? 'text-warning' : 'text-success');
        }

        function setServerBar(id, key, v) {
            var el = document.getElementById('s_' + id + '_' + key);
            if (!el) return;
            el.className = 'progress-bar ' + barClass(v);
            el.style.width = v + '%';
            el.setAttribute('aria-valuenow', v);
            var val = document.getElementById('s_' + id + '_' + key + '_val');
            if (val) {
                val.textContent = v + '%';
                val.className = 'dashboard-metric-val tabular-nums ' + barTone(v);
            }
        }

        function setText(id, txt) {
            var el = document.getElementById(id);
            if (el) el.textContent = txt;
        }

        function pollStats(auto) {
            var url = './api?action=stats' + (hasServerId ? '&server_id=' + serverParam : '');
            var start = Date.now();
            fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    setTile('active-connections', d.open_connections, d.total_connections, pctOf(d.open_connections, d.total_connections));
                    setTile('online-users', d.online_users, d.total_users, pctOf(d.online_users, d.total_users));
                    setTile('active-streams', d.total_running_streams, d.offline_streams, pctOf(d.total_running_streams, d.offline_streams + d.total_running_streams));
                    setTile('offline-streams', d.offline_streams, null, null);
                    var out = Math.floor((d.bytes_sent || 0) / 125000);
                    var inp = Math.floor((d.bytes_received || 0) / 125000);
                    setTile('output-flow', out, null, pctOf(out, d.network_guaranteed_speed));
                    setTile('input-flow', inp, null, pctOf(inp, d.network_guaranteed_speed));
                    setText('stats_updated', new Date().toLocaleTimeString());

                    if (!hasServerId && Array.isArray(d.servers)) {
                        d.servers.forEach(function(s) {
                            setText('s_' + s.server_id + '_conns', num(s.open_connections));
                            setText('s_' + s.server_id + '_users', num(s.online_users));
                            setText('s_' + s.server_id + '_online', num(s.total_running_streams));
                            setText('s_' + s.server_id + '_input', num(Math.floor(s.bytes_received / 125000)));
                            setText('s_' + s.server_id + '_output', num(Math.floor(s.bytes_sent / 125000)));
                            if (s.uptime) setText('s_' + s.server_id + '_uptime', s.uptime.split(' ').slice(0, 2).join(' '));
                            setServerBar(s.server_id, 'cpu', s.cpu);
                            setServerBar(s.server_id, 'mem', s.mem);
                            if (s.server_type == 0) {
                                setServerBar(s.server_id, 'io', s.io);
                                setServerBar(s.server_id, 'fs', s.fs);
                            }
                        });
                    }
                })
                .catch(function() {
                    /* keep last values */
                })
                .finally(function() {
                    if (auto) {
                        var wait = Math.max(0, 5000 - (Date.now() - start));
                        setTimeout(function() {
                            pollStats(true);
                        }, wait);
                    }
                });
        }

        function areaOptions(el, series, colors, unit) {
            return {
                chart: {
                    height: 300,
                    type: 'area',
                    stacked: false,
                    zoom: {
                        enabled: false
                    },
                    toolbar: {
                        show: false
                    },
                    animations: {
                        enabled: false
                    }
                },
                colors: colors,
                dataLabels: {
                    enabled: false
                },
                stroke: {
                    width: 2,
                    curve: 'smooth'
                },
                series: series,
                fill: {
                    type: 'gradient',
                    gradient: {
                        opacityFrom: 0.4,
                        opacityTo: 0.1
                    }
                },
                xaxis: {
                    type: 'datetime',
                    labels: {
                        datetimeUTC: false,
                        formatter: function(v, ts) {
                            var dt = new Date(ts);
                            return ('0' + dt.getHours()).slice(-2) + ':' + ('0' + dt.getMinutes()).slice(-2);
                        }
                    }
                },
                tooltip: {
                    y: {
                        formatter: function(v) {
                            return unit ? v + unit : v;
                        }
                    }
                }
            };
        }
        var charts = {};

        function drawChart(key, sel, opts) {
            var node = document.querySelector(sel);
            if (!node) return;
            if (charts[key]) {
                charts[key].destroy();
            }
            charts[key] = new ApexCharts(node, opts);
            charts[key].render();
        }

        function pollGraphs(auto) {
            var url = './api?action=graph_stats' + (hasServerId ? '&server_id=' + serverParam : '');
            var start = Date.now();
            fetch(url, {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    drawChart('cpu', '#cpu_chart', areaOptions('#cpu_chart',
                        [{
                            name: 'CPU Usage',
                            data: d.cpu
                        }, {
                            name: 'Memory Usage',
                            data: d.memory
                        }, {
                            name: 'IO Usage',
                            data: d.io
                        }],
                        ['#7367f0', '#00cfe8', '#28c76f'], '%'));
                    drawChart('net', '#network_chart', areaOptions('#network_chart',
                        [{
                            name: 'Input',
                            data: d.input
                        }, {
                            name: 'Output',
                            data: d.output
                        }],
                        ['#7367f0', '#00cfe8'], ' Mbps'));
                    if (doConnections) {
                        drawChart('conn', '#connections_chart', areaOptions('#connections_chart',
                            [{
                                name: 'Online Streams',
                                data: d.streams
                            }, {
                                name: 'Unique Users',
                                data: d.users
                            }, {
                                name: 'Total Connections',
                                data: d.connections
                            }],
                            ['#7367f0', '#00cfe8', '#28c76f'], ''));
                    }
                })
                .catch(function() {
                    /* keep last chart */
                })
                .finally(function() {
                    if (auto) {
                        setTimeout(function() {
                            pollGraphs(true);
                        }, Math.max(0, 60000 - (Date.now() - start)));
                    }
                });
        }

        function renderSparklines() {
            var seed = <?= json_encode($xmSparklines, JSON_UNESCAPED_SLASHES); ?>;
            document.querySelectorAll('.dashboard-spark').forEach(function(node) {
                var id = node.id.replace('s_', '').replace('_spark', '');
                var data = seed[id] || [];
                if (!data.length) return;
                new ApexCharts(node, {
                    chart: {
                        type: 'area',
                        height: 50,
                        sparkline: {
                            enabled: true
                        },
                        animations: {
                            enabled: false
                        }
                    },
                    stroke: {
                        width: 2,
                        curve: 'smooth'
                    },
                    fill: {
                        type: 'gradient',
                        gradient: {
                            opacityFrom: 0.4,
                            opacityTo: 0.1
                        }
                    },
                    series: [{
                        name: 'CPU',
                        data: data
                    }],
                    xaxis: {
                        type: 'datetime',
                        labels: {
                            // Apex formats datetime tooltips in UTC by default.
                            datetimeUTC: false
                        }
                    },
                    tooltip: {
                        x: {
                            format: 'dd MMM HH:mm'
                        },
                        y: {
                            formatter: function(v) {
                                return v + '%';
                            }
                        }
                    }
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
                    initial: {
                        fill: low,
                        stroke: 'none'
                    },
                    hover: {
                        fillOpacity: 0.85
                    }
                }
            });
            // Paint each country with its own colour (matches the top list); done
            // directly rather than through the numeric colour-scale visualizer.
            Object.keys(values).forEach(function(code) {
                if (map.regions[code] && map.regions[code].element) {
                    map.regions[code].element.setStyle('fill', values[code]);
                }
            });
        }

        document.addEventListener('DOMContentLoaded', function() {
            if (window.jQuery && jQuery.fn.select2) {
                jQuery('#server_id').select2({
                    width: '100%'
                });
            }
            renderMap();
            document.querySelectorAll('.dashboard-loc-progress .progress-bar').forEach(function(b) {
                b.style.width = (b.getAttribute('data-width') || 0) + '%';
            });
            pollStats(true);
            if (doGraphs) {
                pollGraphs(true);
            }
            if (typeof ApexCharts !== 'undefined') {
                renderSparklines();
            }
            var sel = document.getElementById('server_id');
            if (sel) sel.addEventListener('change', function() {
                window.location.href = this.value ? './dashboard?server_id=' + encodeURIComponent(this.value) : './dashboard';
            });
        });
    })();
</script>
</body>

</html>