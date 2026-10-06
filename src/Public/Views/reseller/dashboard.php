<?php
use XcVm\Core\Util\LayoutRenderer;


/**
 * Reseller Dashboard (Bootstrap 5). Content-only markup rendered inside the
 * reseller new-UI shell (reseller/header.php + footer.php).
 *
 * Live tiles keep the legacy data contract: ./api?action=dashboard (5s) feeds
 * the .active-connections / .online-users / .active-accounts / .credits tiles
 * by their unchanged CSS classes, so the endpoint needs no edits.
 *
 * Recent Activity and Expiring Lines are server-rendered from data prepared by
 * ResellerDashboardController, which also builds the connections-by-location
 * map. Live Connections and the Recently Added panels are serverSide
 * DataTables pointed at ./table — the same permission-scoped endpoints the
 * Live Connections / Streams / Movies / Episodes pages use, so the dashboard
 * adds no queries of its own and cannot widen what a reseller may see.
 */

$xmCreditsAssigned = count($rRegisteredUsers) > 1;

// Live stat tiles: [wrapClass, icon, accent, label, link|null].
$xmTiles = [
    ['active-connections', 'ti tabler-plug-connected', 'primary', $language::get('connections'),           !empty($rPermissions['reseller_client_connection_logs']) ? 'live_connections' : null],
    ['online-users',       'ti tabler-users',          'success', $language::get('lines_online'),           !empty($rPermissions['reseller_client_connection_logs']) ? 'live_connections' : null],
    ['active-accounts',    'ti tabler-circle-check',   'info',    $language::get('dashboard_active_lines'), null],
    ['credits',            'ti tabler-coin',           'warning', $xmCreditsAssigned ? $language::get('assigned_credits') : $language::get('total_credits'), !empty($rPermissions['create_sub_resellers']) ? 'users' : null],
];

$xmCanSeeConnections = !empty($rPermissions['reseller_client_connection_logs']);
$xmCanVod = !empty($rPermissions['can_view_vod']);
$xmShowMap = !empty($rSettings['save_closed_connection']) && !empty($rSettings['dashboard_map']) && $rConnectionCount > 0;

// World-map region fills for jsvectormap: ISO2 country code => the same colour
// hex the top list uses. Skip non-country GeoIP codes (A1/A2/O1/AP/EU/…).
$xmMapValues = [];
foreach ($rConnectionMap as $rCountry) {
    $rCode = $rCountry['geoip_country_code'];
    if (preg_match('/^[A-Z]{2}$/', $rCode) && !in_array($rCode, ['A1', 'A2', 'O1', 'AP', 'EU', 'AN'], true)) {
        $xmMapValues[$rCode] = $rCountry['colour'][0];
    }
}
?>

<!-- Page header -->
<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-3">
    <h4 class="mb-0"><?= htmlspecialchars($language::get('welcome') . ' ' . ($rUserInfo['username'] ?? '')); ?></h4>
</div>

<?php if (!empty($rNotice)): ?>
    <div class="card mb-4">
        <div class="card-body"><?= $rNotice; ?></div>
    </div>
<?php endif; ?>

<!-- Stat tiles -->
<div class="row g-4 mb-4">
    <?php foreach ($xmTiles as [$rWrap, $rIcon, $rAccent, $rLabel, $rLink]): ?>
        <div class="col-sm-6 col-xl-3">
            <?php if ($rLink): ?><a href="<?= htmlspecialchars($rLink, ENT_QUOTES); ?>" class="text-body text-decoration-none"><?php endif; ?>
            <div class="card h-100">
                <div class="card-body d-flex justify-content-between align-items-center <?= $rWrap; ?>">
                    <div class="card-title mb-0">
                        <h5 class="mb-1 me-2"><span class="entry">0</span></h5>
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

<?php if ($xmShowMap): ?>
    <!-- Connections by Location -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                <i class="icon-base ti tabler-world icon-22px text-primary"></i>
                <span><?= htmlspecialchars($language::get('dashboard_connections_by_location')); ?></span>
            </h5>
            <span class="badge bg-label-primary"><?= number_format($rConnectionCount, 0); ?></span>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-lg-8 mb-4 mb-lg-0">
                    <div id="map" class="dashboard-map"></div>
                </div>
                <div class="col-lg-4 align-self-center">
                    <h6 class="text-body-secondary mb-3"><?= htmlspecialchars($language::get('top_connected_countries')); ?></h6>
                    <?php foreach (array_slice($rConnectionMap, 0, 6) as $rCountry):
                        $rPct = (int) round($rCountry['count'] / $rConnectionCount * 100);
                        $rBar = $rCountry['colour'][1] ?? 'bg-primary';
                        $rIso = strtolower($rCountry['geoip_country_code']);
                    ?>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="fw-medium d-inline-flex align-items-center gap-1">
                                <img loading="lazy" src="assets/img/countries/<?= htmlspecialchars($rIso, ENT_QUOTES); ?>.png" alt="">
                                <?= htmlspecialchars($rCountry['name']); ?>
                            </span>
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
<?php endif; ?>

<?php if ($xmCanSeeConnections): ?>
    <!-- Live connections (serverSide: ./table?id=live_connections) -->
    <div class="card mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                <i class="icon-base ti tabler-activity icon-22px text-success"></i>
                <a href="live_connections" class="text-body"><?= htmlspecialchars($language::get('live_connections')); ?></a>
            </h5>
            <a href="live_connections" class="btn btn-sm btn-label-secondary"><?= htmlspecialchars($language::get('view_all')); ?></a>
        </div>
        <div class="card-datatable table-responsive">
            <table id="dash-live-table" class="table" style="width:100%">
                <thead>
                    <tr>
                        <th></th><!-- responsive control (+/-) -->
                        <th><?= $language::get('id'); ?></th>
                        <th><?= $language::get('divergence'); ?></th>
                        <th><?= $language::get('username'); ?></th>
                        <th><?= $language::get('stream'); ?></th>
                        <th><?= $language::get('player'); ?></th>
                        <th><?= $language::get('isp'); ?></th>
                        <th><?= $language::get('ip'); ?></th>
                        <th><?= $language::get('duration'); ?></th>
                        <th><?= $language::get('container'); ?></th>
                        <th><?= $language::get('restreamer'); ?></th>
                        <th><?= $language::get('actions'); ?></th>
                    </tr>
                </thead>
                <tbody></tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<div class="row g-4 mb-4">
    <!-- Recent Activity -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                    <i class="icon-base ti tabler-history icon-22px text-info"></i>
                    <a href="user_logs" class="text-body"><?= htmlspecialchars($language::get('recent_activity')); ?></a>
                </h5>
                <a href="user_logs" class="btn btn-sm btn-label-secondary"><?= htmlspecialchars($language::get('view_all')); ?></a>
            </div>
            <div class="card-datatable table-responsive">
                <table id="dash-activity-table" class="table" style="width:100%">
                    <thead>
                        <tr>
                            <th class="text-center"><?= htmlspecialchars($language::get('reseller')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('line_user')); ?></th>
                            <th><?= htmlspecialchars($language::get('action')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('date')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rActivityRows as $rRow): ?>
                            <tr>
                                <td class="text-center"><a class="text-body" href="user?id=<?= intval($rRow['owner_id']); ?>"><?= htmlspecialchars($rRow['username']); ?></a></td>
                                <td class="text-center"><?= $rRow['target_html'] ?? '<span class="text-body-secondary">-</span>'; ?></td>
                                <td><?= $rRow['text']; ?></td>
                                <td class="text-center"><?= date($rSettings['date_format'] . ' H:i', $rRow['date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- Expiring Lines -->
    <div class="col-xl-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                    <i class="icon-base ti tabler-clock-exclamation icon-22px text-warning"></i>
                    <a href="lines" class="text-body"><?= htmlspecialchars($language::get('expiring_lines')); ?></a>
                </h5>
                <a href="lines" class="btn btn-sm btn-label-secondary"><?= htmlspecialchars($language::get('view_all')); ?></a>
            </div>
            <div class="card-datatable table-responsive">
                <table id="dash-expiring-table" class="table" style="width:100%">
                    <thead>
                        <tr>
                            <th class="text-center"><?= htmlspecialchars($language::get('type')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('identity')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('owner')); ?></th>
                            <th class="text-center"><?= htmlspecialchars($language::get('expires')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rExpiringLines as $rUser): ?>
                            <tr>
                                <?php if ($rUser['is_mag']): ?>
                                    <td class="text-center"><?= htmlspecialchars($language::get('mag_device')); ?></td>
                                    <td class="text-center"><a class="text-body" href="mag?id=<?= intval($rUser['mag_id']); ?>"><?= htmlspecialchars($rUser['mag_mac']); ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <i class="icon-base ti tabler-note text-body-secondary" title="' . htmlspecialchars($rUser['reseller_notes'], ENT_QUOTES) . '"></i>' : ''; ?></a></td>
                                <?php elseif ($rUser['is_e2']): ?>
                                    <td class="text-center"><?= htmlspecialchars($language::get('enigma_device')); ?></td>
                                    <td class="text-center"><a class="text-body" href="enigma?id=<?= intval($rUser['e2_id']); ?>"><?= htmlspecialchars($rUser['e2_mac']); ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <i class="icon-base ti tabler-note text-body-secondary" title="' . htmlspecialchars($rUser['reseller_notes'], ENT_QUOTES) . '"></i>' : ''; ?></a></td>
                                <?php else: ?>
                                    <td class="text-center"><?= htmlspecialchars($language::get('line')); ?></td>
                                    <td class="text-center"><a class="text-body" href="line?id=<?= intval($rUser['line_id']); ?>"><?= htmlspecialchars($rUser['username']); ?><?= !empty($rUser['reseller_notes']) ? ' &nbsp; <i class="icon-base ti tabler-note text-body-secondary" title="' . htmlspecialchars($rUser['reseller_notes'], ENT_QUOTES) . '"></i>' : ''; ?></a></td>
                                <?php endif; ?>
                                <td class="text-center"><a class="text-body" href="user?id=<?= intval($rUser['member_id']); ?>"><?= htmlspecialchars($rRegisteredUsers[$rUser['member_id']]['username'] ?? ''); ?></a></td>
                                <td class="text-center"><?= date($rSettings['date_format'] . ' H:i', $rUser['exp_date']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if ($xmCanVod): ?>
    <?php
    // [action, paneId, tableId, tabIcon, rowIcon, accent, imageColumnLabel, imageWidth].
    // `action` is both the ./table id and the list page the View All button opens.
    // The column order mirrors the Streams / Movies / Episodes pages because
    // ResellerTableRenderer resolves the sort column by DataTables index — only
    // id (1), title (3) and category (4) are orderable, so the columns added
    // after them are safe to append.
    $xmMediaTabs = [
        ['streams',  'dash-tab-streams',  'dash-streams-table',  'tabler-broadcast', 'tabler-broadcast', 'danger',  $language::get('icon'),  96],
        ['movies',   'dash-tab-movies',   'dash-movies-table',   'tabler-movie',     'tabler-movie',     'primary', $language::get('cover'), 32],
        ['episodes', 'dash-tab-episodes', 'dash-episodes-table', 'tabler-device-tv', 'tabler-device-tv', 'info',    $language::get('cover'), 32],
    ];
    ?>
    <!-- Recently added media (serverSide: ./table?id=streams|movies|episodes) -->
    <div class="card mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2 pb-0">
            <h5 class="card-title mb-0 d-flex align-items-center gap-2">
                <i class="icon-base ti tabler-player-play icon-22px text-danger"></i>
                <span><?= htmlspecialchars($language::get('recently_added_media')); ?></span>
            </h5>
            <ul class="nav nav-tabs card-header-tabs" role="tablist">
                <?php foreach ($xmMediaTabs as $rIndex => [$rAction, $rPaneId, $rTableId, $rTabIcon, $rRowIcon, $rAccent, $rImageLabel, $rImageWidth]): ?>
                    <li class="nav-item">
                        <button type="button" class="nav-link d-flex align-items-center gap-1<?= $rIndex === 0 ? ' active' : ''; ?>"
                            data-bs-toggle="tab" data-bs-target="#<?= $rPaneId; ?>" role="tab" aria-selected="<?= $rIndex === 0 ? 'true' : 'false'; ?>">
                            <i class="icon-base ti <?= $rTabIcon; ?>"></i>
                            <span><?= htmlspecialchars($language::get($rAction)); ?></span>
                            <!-- Filled from the endpoint's recordsTotal on the first draw. -->
                            <span class="badge rounded-pill bg-label-<?= $rAccent; ?> ms-1" id="count-<?= $rTableId; ?>">–</span>
                        </button>
                    </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <div class="tab-content p-0">
            <?php foreach ($xmMediaTabs as $rIndex => [$rAction, $rPaneId, $rTableId, $rTabIcon, $rRowIcon, $rAccent, $rImageLabel, $rImageWidth]): ?>
                <div class="tab-pane fade<?= $rIndex === 0 ? ' show active' : ''; ?>" id="<?= $rPaneId; ?>" role="tabpanel">
                    <div class="card-datatable table-responsive">
                        <table id="<?= $rTableId; ?>" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th></th><!-- responsive control (+/-) -->
                                    <th class="text-center"><?= $language::get('id'); ?></th>
                                    <th><?= htmlspecialchars($rImageLabel); ?></th>
                                    <th><?= $language::get('title'); ?></th>
                                    <th><?= $language::get('category'); ?></th>
                                    <th class="text-center"><?= $language::get('added'); ?></th>
                                    <th class="text-center"><?= $language::get('connections'); ?></th>
                                    <?php if ($xmCanSeeConnections): ?>
                                        <th class="text-center"><?= $language::get('action'); ?></th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                    <div class="card-body pt-0 text-end">
                        <a href="<?= $rAction; ?>" class="btn btn-sm btn-label-secondary">
                            <?= htmlspecialchars($language::get('view_all')); ?>
                            <i class="icon-base ti tabler-arrow-right ms-1"></i>
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
<?php endif; ?>

<?php
LayoutRenderer::renderFooter('reseller');
?>
<script>
    // Live reseller stat tiles — poll ./api?action=dashboard (legacy contract:
    // open_connections / online_users / active_accounts / credits(_assigned)).
    (function() {
        var nf = new Intl.NumberFormat('en-US');
        var creditsAssigned = <?= $xmCreditsAssigned ? 'true' : 'false'; ?>;

        function setTile(cls, value) {
            var e = document.querySelector('.' + cls + ' .entry');
            if (e) {
                e.textContent = nf.format(value || 0);
            }
        }

        function poll() {
            var start = Date.now();
            fetch('./api?action=dashboard', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    setTile('active-connections', d.open_connections);
                    setTile('online-users', d.online_users);
                    setTile('active-accounts', d.active_accounts);
                    setTile('credits', creditsAssigned ? d.credits_assigned : d.credits);
                })
                .catch(function() {
                    /* keep last values */
                })
                .finally(function() {
                    var wait = Math.max(0, 5000 - (Date.now() - start));
                    setTimeout(poll, wait);
                });
        }
        poll();

        var esc = function(s) { return (s == null ? '' : String(s)).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;'); };
        var pad = function(n) {
            return (n < 10 ? '0' : '') + n;
        };
        var lang = {
            kill: <?= json_encode($language::get('kill')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };
        var langSeason = <?= json_encode($language::get('season')); ?>;
        var langConnections = <?= json_encode($language::get('connections')); ?>;

        // Same colour-coded live duration the Live Connections page renders.
        function fmtDuration(startTs, isRestreamer) {
            var sec = Math.max(0, Math.floor(Date.now() / 1000) - (startTs || 0));
            var colour = 'success',
                txt;
            if (sec >= 86400) {
                txt = pad(Math.floor(sec / 86400)) + 'd ' + pad(Math.floor(sec / 3600) % 24) + 'h';
                colour = 'danger';
            } else if (sec >= 3600) {
                txt = pad(Math.floor(sec / 3600)) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm';
                if (sec > 14400) {
                    colour = 'warning';
                }
            } else {
                txt = pad(Math.floor(sec / 60) % 60) + 'm ' + pad(sec % 60) + 's';
            }
            if (isRestreamer) {
                colour = 'success';
            }
            return '<span class="badge bg-label-' + colour + '">' + esc(txt) + '</span>';
        }

        // jsvectormap world map; the controller only ships the vendor when there
        // is something to paint, so a missing global is the normal empty case.
        function renderMap() {
            var el = document.getElementById('map');
            if (!el || typeof jsVectorMap === 'undefined') {
                return;
            }
            var values = <?= json_encode($xmMapValues, JSON_UNESCAPED_SLASHES); ?>;
            var dark = document.documentElement.getAttribute('data-bs-theme') === 'dark';
            var map = new jsVectorMap({
                selector: '#map',
                map: 'world',
                backgroundColor: 'transparent',
                zoomButtons: false,
                regionStyle: {
                    initial: {
                        fill: dark ? '#3b4253' : '#e7eaec',
                        stroke: 'none'
                    },
                    hover: {
                        fillOpacity: 0.85
                    }
                }
            });
            Object.keys(values).forEach(function(code) {
                if (map.regions[code] && map.regions[code].element) {
                    map.regions[code].element.setStyle('fill', values[code]);
                }
            });
        }

        var canSeeConnections = <?= $xmCanSeeConnections ? 'true' : 'false'; ?>;

        function fmtDate(ts) {
            return ts ? new Date(ts * 1000).toLocaleString() : '—';
        }

        // Media panels: the Streams / Movies / Episodes endpoints share one
        // column contract (id, image, title, added, category, clients), so one
        // factory covers all three. The only per-stream action a reseller has is
        // its live connections — there is no reseller stream_view route.
        function mediaTable(tab) {
            var columns = [{
                    data: null,
                    defaultContent: '',
                    orderable: false,
                    searchable: false,
                    className: 'control',
                    responsivePriority: 2
                },
                {
                    data: 'id',
                    className: 'text-center',
                    render: function(d) {
                        return '<span class="badge bg-label-secondary">' + esc(d) + '</span>';
                    }
                },
                {
                    data: tab.action === 'streams' ? 'icon' : 'image',
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d) {
                        if (d) {
                            return '<img class="rounded" loading="lazy" src="resize?maxw=' + tab.imageWidth + '&maxh=32&url=' + encodeURIComponent(d) + '" alt="">';
                        }
                        // Artwork is optional; a tinted type badge keeps the column
                        // from collapsing into an empty cell.
                        return '<span class="badge bg-label-' + tab.accent + ' rounded p-2"><i class="icon-base ti ' + tab.rowIcon + '"></i></span>';
                    }
                },
                {
                    data: 'title',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        var sub = '';
                        if (row.series) {
                            sub = esc(row.series) + (row.season != null && row.season !== '' ? ' — ' + esc(langSeason) + ' ' + esc(row.season) : '');
                            sub = '<br><small class="text-body-secondary">' + sub + '</small>';
                        }
                        return '<span class="fw-medium">' + esc(d) + '</span>' + sub;
                    }
                },
                {
                    data: 'category',
                    render: function(d) {
                        if (!d) {
                            return '<span class="text-body-secondary">—</span>';
                        }
                        return '<span class="badge bg-label-' + tab.accent + ' text-uppercase"><i class="icon-base ti tabler-folder me-1"></i>' + esc(d) + '</span>';
                    }
                },
                {
                    data: 'added',
                    orderable: false,
                    searchable: false,
                    className: 'text-center text-nowrap',
                    render: function(d) {
                        return '<small class="text-body-secondary">' + esc(fmtDate(d)) + '</small>';
                    }
                },
                {
                    data: 'clients',
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d) {
                        return '<span class="badge bg-label-' + (d > 0 ? 'success' : 'secondary') + '">' + (d || 0) + '</span>';
                    }
                }
            ];

            if (canSeeConnections) {
                columns.push({
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        return '<a href="live_connections?stream=' + encodeURIComponent(row.id) + '&stream_id=' + encodeURIComponent(row.id) + '"' +
                            ' class="btn btn-sm btn-icon btn-label-primary" title="' + esc(langConnections) + '">' +
                            '<i class="icon-base ti tabler-plug-connected"></i></a>';
                    }
                });
            }

            var table = jQuery('#' + tab.tableId).DataTable({
                serverSide: true,
                responsive: {
                    details: {
                        type: 'column',
                        target: 0
                    }
                },
                // Newest first. `added` is not orderable — the endpoint maps the
                // sort column by index and only knows id, title and category.
                order: [
                    [1, 'desc']
                ],
                searchDelay: 400,
                lengthMenu: [5, 10, 25],
                pageLength: 5,
                ajax: {
                    url: './table',
                    data: function(d) {
                        d.id = tab.action;
                    }
                },
                columns: columns,
                layout: {
                    topStart: 'pageLength',
                    topEnd: 'search'
                }
            });

            // The tab badge shows the real catalogue size, not the page length.
            table.on('xhr', function(e, settings, json) {
                var badge = document.getElementById('count-' + tab.tableId);
                if (badge && json) {
                    badge.textContent = nf.format(json.recordsTotal || 0);
                }
            });

            return table;
        }

        <?php if ($xmCanSeeConnections): ?>
            // Live connections. The column list must stay identical to the Live
            // Connections page: ResellerTableRenderer resolves the sort column by
            // DataTables index, so hiding columns is safe but reordering is not.
            var liveTable = jQuery('#dash-live-table').DataTable({
                serverSide: true,
                responsive: {
                    details: {
                        type: 'column',
                        target: 0
                    }
                },
                order: [
                    [8, 'desc']
                ],
                lengthMenu: [5, 10, 25],
                pageLength: 5,
                ajax: {
                    url: './table',
                    data: function(d) {
                        d.id = 'live_connections';
                    }
                },
                columnDefs: [{
                    orderable: false,
                    targets: [0, 10, 11]
                }],
                columns: [{
                        data: null,
                        defaultContent: '',
                        orderable: false,
                        searchable: false,
                        className: 'control',
                        responsivePriority: 2
                    },
                    {
                        data: 'activity_id',
                        visible: false
                    },
                    {
                        data: 'divergence',
                        className: 'text-center',
                        render: function(d) {
                            var cls = d <= 50 ? 'text-success' : (d <= 80 ? 'text-warning' : 'text-danger');
                            return '<i class="icon-base ti tabler-square-filled ' + cls + '" title="' + (100 - (d || 0)) + '%"></i>';
                        }
                    },
                    {
                        data: 'user_label',
                        responsivePriority: 1,
                        render: function(d, t, row) {
                            if (!d) {
                                return '';
                            }
                            return row.user_url ? '<a href="' + esc(row.user_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                        }
                    },
                    {
                        data: 'stream_name',
                        responsivePriority: 3,
                        render: esc
                    },
                    {
                        data: 'player',
                        render: esc
                    },
                    {
                        data: 'isp',
                        visible: false,
                        render: esc
                    },
                    {
                        data: 'user_ip',
                        className: 'text-nowrap',
                        render: function(d, t, row) {
                            var flag = row.country ? '<img loading="lazy" class="me-1" src="assets/img/countries/' + esc(row.country) + '.png" alt="">' : '';
                            return flag + esc(d || '');
                        }
                    },
                    {
                        data: 'date_start',
                        className: 'text-center',
                        render: function(d, t, row) {
                            return fmtDuration(d, row.is_restreamer);
                        }
                    },
                    {
                        data: 'container',
                        className: 'text-center',
                        visible: false,
                        render: esc
                    },
                    {
                        data: 'is_restreamer',
                        visible: false
                    },
                    {
                        data: null,
                        orderable: false,
                        searchable: false,
                        className: 'text-center text-nowrap',
                        render: function(d, t, row) {
                            if (!row.uuid) {
                                return '';
                            }
                            return '<button type="button" class="btn btn-sm btn-icon btn-label-danger js-kill" title="' + esc(lang.kill) + '" data-uuid="' + esc(row.uuid) + '"><i class="icon-base ti tabler-hammer"></i></button>';
                        }
                    }
                ],
                layout: {
                    topStart: 'pageLength',
                    topEnd: 'search'
                }
            });

            // Kill a connection (reseller api: action=line_activity&sub=kill&uuid=…).
            jQuery('#dash-live-table tbody').on('click', '.js-kill', function() {
                var uuid = this.getAttribute('data-uuid');
                if (!uuid) {
                    return;
                }
                fetch('./api?action=line_activity&sub=kill&uuid=' + encodeURIComponent(uuid), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(data) {
                        if (!data || data.result !== true) {
                            throw new Error('fail');
                        }
                        liveTable.ajax.reload(null, false);
                    })
                    .catch(function() {
                        if (window.xcToast) {
                            xcToast(lang.error, 'error');
                        } else {
                            alert(lang.error);
                        }
                    });
            });

            setInterval(function() {
                liveTable.ajax.reload(null, false);
            }, 10000);
        <?php endif; ?>

        <?php if ($xmCanVod): ?>
            <?php foreach ($xmMediaTabs as [$rAction, $rPaneId, $rTableId, $rTabIcon, $rRowIcon, $rAccent, $rImageLabel, $rImageWidth]): ?>
                mediaTable(<?= json_encode([
                                'action' => $rAction,
                                'tableId' => $rTableId,
                                'rowIcon' => $rRowIcon,
                                'accent' => $rAccent,
                                'imageWidth' => $rImageWidth,
                            ], JSON_UNESCAPED_SLASHES); ?>);
            <?php endforeach; ?>
        <?php endif; ?>

        // Server-rendered panels: client-side DataTables for sort, search and paging.
        jQuery('#dash-activity-table, #dash-expiring-table').DataTable({
            responsive: true,
            lengthMenu: [5, 10, 25],
            pageLength: 5,
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        // A table built inside a hidden tab measures its columns at zero width.
        jQuery('button[data-bs-toggle="tab"]').on('shown.bs.tab', function() {
            jQuery.fn.dataTable.tables({
                visible: true,
                api: true
            }).columns.adjust().responsive.recalc();
        });

        renderMap();
        document.querySelectorAll('.dashboard-loc-progress .progress-bar').forEach(function(b) {
            b.style.width = (b.getAttribute('data-width') || 0) + '%';
        });
    })();
</script>
</body>

</html>
