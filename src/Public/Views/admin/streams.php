<?php

/**
 * Live Streams (Bootstrap 5). The panel's most complex serverSide table. Clean-JSON
 * pattern: TableController::handleStreams resolves each stream's status
 * (StatusBadge::stream code -1..7), live uptime, convert-to-channel encode
 * progress, restart-fails indicator, current server/source, client count, EPG
 * availability, player-codec compatibility and codec stream-info server-side;
 * this page renders every cell and the per-row action dropdown client-side.
 *
 * Features: category / server / status / codec filters, bulk-select toolbar
 * (start/stop/restart/kill/delete via action=multi&type=stream), per-row actions
 * (edit + fingerprint iframe modals, start/stop/restart/purge/delete via
 * action=stream), player, live-connections link and CSV export.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;

if (!Authorization::check('adv', 'streams') && !Authorization::check('adv', 'mass_edit_streams')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_stream');
$rCanLive = Authorization::check('adv', 'live_connections');
$rCanFinger = Authorization::check('adv', 'fingerprint');
$rCanPlayer = Authorization::check('adv', 'player');

$rStatusFilters = [
    1 => 'Online', 2 => 'Down', 3 => 'Stopped', 4 => 'Starting', 5 => 'On Demand',
    6 => 'Direct', 7 => 'Timeshift', 8 => 'Looping', 9 => 'Has EPG', 10 => 'No EPG',
    11 => 'Adaptive', 12 => 'Title Sync', 13 => 'Transcoding',
];
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('streams'); ?></h5>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($rCanEdit): ?>
                <div id="bulk-bar" class="d-none align-items-center gap-2">
                    <span class="text-body-secondary"><span id="bulk-count">0</span></span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-label-success" data-bulk="start"><?= $language::get('start'); ?></button>
                        <button type="button" class="btn btn-label-secondary" data-bulk="stop"><?= $language::get('stop'); ?></button>
                        <button type="button" class="btn btn-label-info" data-bulk="restart"><?= $language::get('restart'); ?></button>
                        <button type="button" class="btn btn-label-dark" data-bulk="purge"><?= $language::get('kill'); ?></button>
                        <button type="button" class="btn btn-label-danger" data-bulk="delete"><?= $language::get('delete'); ?></button>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-category"><?= $language::get('category'); ?></label>
                <select id="filter-category" class="form-select">
                    <option value=""><?= $language::get('all_categories'); ?></option>
                    <option value="-1"><?= $language::get('no_category'); ?></option>
                    <?php foreach (CategoryService::getAllByType('live') as $rCatId => $rCat): ?>
                        <option value="<?= (int) $rCatId; ?>"><?= htmlspecialchars((string) $rCat['category_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-server"><?= $language::get('server'); ?></label>
                <select id="filter-server" class="form-select">
                    <option value=""><?= $language::get('all_servers'); ?></option>
                    <option value="-1"><?= $language::get('no_servers'); ?></option>
                    <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                        <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-status"><?= $language::get('filter'); ?></label>
                <select id="filter-status" class="form-select">
                    <option value=""><?= $language::get('no_filter'); ?></option>
                    <?php foreach ($rStatusFilters as $rK => $rV): ?>
                        <option value="<?= $rK; ?>"><?= $rV; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-lg-1">
                <label class="form-label" for="filter-resolution"><?= $language::get('resolution'); ?></label>
                <input type="text" id="filter-resolution" class="form-control" autocomplete="off">
            </div>
            <div class="col-4 col-lg-1">
                <label class="form-label" for="filter-video"><?= $language::get('video'); ?></label>
                <input type="text" id="filter-video" class="form-control" autocomplete="off">
            </div>
            <div class="col-4 col-lg-1">
                <label class="form-label" for="filter-audio"><?= $language::get('audio'); ?></label>
                <input type="text" id="filter-audio" class="form-control" autocomplete="off">
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="streams-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><input type="checkbox" class="form-check-input" id="check-all"></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('icon'); ?></th>
                    <th><?= $language::get('title'); ?></th>
                    <th><?= $language::get('server'); ?></th>
                    <th><?= $language::get('connections'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                    <th><?= $language::get('player'); ?></th>
                    <th>EPG</th>
                    <th><?= $language::get('stream_info'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- Edit / Fingerprint iframe modal -->
<div class="modal fade" id="frameModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0" id="frameModalTitle"><?= $language::get('edit'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="frame-src" src="about:blank" style="width:100%;height:70vh;border:0"></iframe>
            </div>
        </div>
    </div>
</div>

<!-- Restart / failures log modal -->
<div class="modal fade" id="failuresModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('stream_error_logs') ?: 'Stream Logs'; ?></h5>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-sm btn-label-danger" id="fails-clear"><i class="icon-base ti tabler-trash me-1"></i><?= $language::get('clear_stream_logs'); ?></button>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
            </div>
            <div class="modal-body">
                <div class="table-responsive">
                    <table id="failures-table" class="table" style="width:100%">
                        <thead>
                            <tr>
                                <th><?= $language::get('server_name'); ?></th>
                                <th><?= $language::get('source'); ?></th>
                                <th><?= $language::get('action'); ?></th>
                                <th><?= $language::get('date'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
        var canEdit = <?= $rCanEdit ? 'true' : 'false'; ?>, canLive = <?= $rCanLive ? 'true' : 'false'; ?>, canFinger = <?= $rCanFinger ? 'true' : 'false'; ?>, canPlayer = <?= $rCanPlayer ? 'true' : 'false'; ?>;
        var lang = {
            start: <?= json_encode($language::get('start') ?: 'Start'); ?>,
            stop: <?= json_encode($language::get('stop') ?: 'Stop'); ?>,
            restart: <?= json_encode($language::get('restart') ?: 'Restart'); ?>,
            kill: <?= json_encode($language::get('kill') ?: 'Kill Connections'); ?>,
            fingerprint: <?= json_encode($language::get('fingerprint') ?: 'Fingerprint'); ?>,
            edit: <?= json_encode($language::get('edit')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            selected: <?= json_encode($language::get('selected')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };
        // StatusBadge::stream — code => [bootstrap colour, label].
        var STREAM = {
            '-1': ['secondary', 'No Server'], '0': ['dark', 'Stopped'], '1': ['success', 'Online'],
            '2': ['warning', 'Starting'], '3': ['danger', 'Down'], '4': ['info', 'On Demand'],
            '5': ['primary', 'Direct Source'], '6': ['primary', 'Converting'], '7': ['danger', 'Proxy Down']
        };
        var pad = function(n) { return (n < 10 ? '0' : '') + n; };
        var fmtUptime = function(sec) {
            sec = Math.max(0, Math.floor(sec));
            if (sec >= 86400) { return pad(Math.floor(sec / 86400)) + 'd ' + pad(Math.floor(sec / 3600) % 24) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm'; }
            return pad(Math.floor(sec / 3600)) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm ' + pad(sec % 60) + 's';
        };
        // Fails indicator colour thresholds (mirror the legacy restart-count buckets).
        var failsDot = function(f, id, server) {
            if (!f || !f[0]) { return ''; }
            var count = f[0], last = f[1] || 0, color;
            if (count <= 2) { color = 'success'; }
            else if (count <= 4 || last > 21600) { color = 'info'; }
            else if (count <= 144 || last > 600) { color = 'warning'; }
            else { color = 'danger'; }
            // Clickable — opens the restart/failures log modal for this stream.
            return '<a href="javascript:void(0);" class="js-fails me-1" data-id="' + esc(id) + '" data-server="' + esc(server) + '" title="' + count + ' restarts"><i class="icon-base ti tabler-alert-circle text-' + color + '"></i></a>';
        };
        var running = function(row) { return row.status === 1 || row.status === 2 || row.status === 3 || row.status === 5 || row.on_demand; };

        var selected = {};
        var updateBulk = function() {
            var n = Object.keys(selected).length, bar = document.getElementById('bulk-bar');
            if (!bar) { return; }
            document.getElementById('bulk-count').textContent = n + ' ' + lang.selected;
            bar.classList.toggle('d-none', n === 0);
            bar.classList.toggle('d-flex', n > 0);
        };
        var confirmSwal = function(text) {
            if (window.Swal) { return Swal.fire({ text: text, icon: 'warning', showCancelButton: true, confirmButtonText: 'OK', customClass: { confirmButton: 'btn btn-primary', cancelButton: 'btn btn-label-secondary ms-2' }, buttonsStyling: false }).then(function(r) { return r.isConfirmed; }); }
            return Promise.resolve(window.confirm(text));
        };

        var table = jQuery('#streams-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[2, 'desc']],
            searchDelay: 400,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'streams';
                    d.category = document.getElementById('filter-category').value;
                    d.server = document.getElementById('filter-server').value;
                    d.filter = document.getElementById('filter-status').value;
                    d.resolution = document.getElementById('filter-resolution').value;
                    d.video = document.getElementById('filter-video').value;
                    d.audio = document.getElementById('filter-audio').value;
                }
            },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'id', orderable: false, searchable: false, className: 'text-center', render: function(d) { return '<input type="checkbox" class="form-check-input row-check" data-id="' + esc(d) + '"' + (selected[d] ? ' checked' : '') + '>'; } },
                { data: 'display_id', className: 'text-center', render: function(d, t, row) { return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'icon', orderable: false, render: function(d) { return d ? '<a href="resize?maxw=512&maxh=512&url=' + encodeURIComponent(d) + '" target="_blank"><img loading="lazy" src="resize?maxw=96&maxh=32&url=' + encodeURIComponent(d) + '" alt=""></a>' : ''; } },
                {
                    data: 'title',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        var badges = '';
                        if (row.archive) { badges += ' <a href="archive?id=' + encodeURIComponent(row.id) + '"><i class="icon-base ti tabler-player-record text-danger"></i></a>'; }
                        if (row.adaptive) { badges += ' <a href="stream_view?id=' + encodeURIComponent(row.id) + '"><i class="icon-base ti tabler-antenna text-info"></i></a>'; }
                        if (row.title_sync) { badges += ' <i class="icon-base ti tabler-refresh text-info" title="Title Sync"></i>'; }
                        return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body"><span class="fw-medium">' + esc(d) + '</span>' + badges + '<br><small class="text-body-secondary">' + esc(row.category || '') + '</small></a>';
                    }
                },
                {
                    data: 'server_name',
                    render: function(d, t, row) {
                        if (!d) { return '<span class="text-body-secondary">No Server Selected</span>'; }
                        var html = row.server_url ? '<a href="' + esc(row.server_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                        if (row.server_count > 1) { html += ' <a href="streams?stream_id=' + encodeURIComponent(row.id) + '" class="badge bg-label-info">+' + (row.server_count - 1) + '</a>'; }
                        if (row.server_offline) { html += ' <i class="icon-base ti tabler-alert-triangle text-danger" title="Server offline"></i>'; }
                        if (row.source_host) { html += '<br><small class="text-body-secondary">' + esc(row.source_host) + '</small>'; }
                        return html;
                    }
                },
                {
                    data: 'clients',
                    className: 'text-center',
                    render: function(d, t, row) {
                        if (d > 0 && canLive) { return '<a href="live_connections?stream_id=' + encodeURIComponent(row.id) + '&server_id=' + encodeURIComponent(row.server_col_id) + '" class="badge bg-label-info">' + Number(d).toLocaleString() + '</a>'; }
                        return '<span class="badge bg-label-secondary">' + (d || 0) + '</span>';
                    }
                },
                {
                    data: 'status',
                    className: 'text-center text-nowrap',
                    render: function(d, t, row) {
                        var dot = failsDot(row.fails, row.id, row.server_col_id);
                        if (d === 1) { return dot + '<span class="badge bg-label-success">' + esc(fmtUptime(row.uptime)) + '</span>'; }
                        if (d === 6) { return '<span class="badge bg-label-primary">' + (row.encode_pct != null ? esc(row.encode_pct) + '% DONE' : 'Converting') + '</span>'; }
                        var s = STREAM[String(d)] || ['secondary', ''];
                        return dot + '<span class="badge bg-label-' + s[0] + '">' + esc(s[1]) + '</span>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var items = '';
                        if (canEdit) {
                            if (running(row)) {
                                items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="stop" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_col_id) + '">' + esc(lang.stop) + '</a>';
                                items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="restart" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_col_id) + '">' + esc(lang.restart) + '</a>';
                                items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="purge" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_col_id) + '">' + esc(lang.kill) + '</a>';
                            } else {
                                items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="start" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_col_id) + '">' + esc(lang.start) + '</a>';
                            }
                        }
                        if (canFinger && row.clients > 0) { items += '<a class="dropdown-item js-finger" href="javascript:void(0);" data-id="' + esc(row.id) + '">' + esc(lang.fingerprint) + '</a>'; }
                        if (canEdit) {
                            items += '<a class="dropdown-item js-edit" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-type="' + esc(row.type) + '">' + esc(lang.edit) + '</a>';
                            items += '<a class="dropdown-item text-danger js-act" href="javascript:void(0);" data-sub="delete" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_col_id) + '">' + esc(lang.del) + '</a>';
                        }
                        if (row.notes) { items = '<h6 class="dropdown-header text-wrap" style="max-width:18rem" title="' + esc(row.notes) + '">' + esc(row.notes) + '</h6><div class="dropdown-divider"></div>' + items; }
                        if (!items) { return ''; }
                        return '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' + items + '</div></div>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var playable = (row.status === 1 || row.status === 4);
                        if (!canPlayer || !playable || !row.player_ok) { return '<button class="btn btn-sm btn-icon btn-label-secondary" disabled><i class="icon-base ti tabler-player-play"></i></button>'; }
                        return '<button class="btn btn-sm btn-icon btn-label-info js-play" data-id="' + esc(row.id) + '"><i class="icon-base ti tabler-player-play"></i></button>';
                    }
                },
                {
                    data: 'epg',
                    orderable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var map = { available: 'success', pending: 'warning', none: 'secondary' };
                        var dot = '<i class="icon-base ti tabler-square-rounded-filled text-' + (map[d] || 'secondary') + '"></i>';
                        return d === 'available' ? '<a href="epg_view?id=' + encodeURIComponent(row.id) + '">' + dot + '</a>' : dot;
                    }
                },
                {
                    data: 'info',
                    orderable: false,
                    render: function(d) {
                        if (!d) { return '<small class="text-body-secondary">—</small>'; }
                        return '<div class="d-flex flex-wrap gap-1">' +
                            '<span class="badge bg-label-secondary">' + esc(d.bitrate) + ' Kbps</span>' +
                            '<span class="badge bg-label-primary">' + esc(d.resolution) + '</span>' +
                            '<span class="badge bg-label-info">' + esc(d.video) + '</span>' +
                            '<span class="badge bg-label-success">' + esc(d.audio) + '</span>' +
                            '<span class="badge bg-label-secondary">' + esc(d.speed) + '</span>' +
                            '<span class="badge bg-label-secondary">' + esc(d.fps) + '</span></div>';
                    }
                }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        ['filter-category', 'filter-server', 'filter-status'].forEach(function(id) { document.getElementById(id).addEventListener('change', function() { table.ajax.reload(); }); });
        ['filter-resolution', 'filter-video', 'filter-audio'].forEach(function(id) {
            var el = document.getElementById(id), tmr;
            el.addEventListener('input', function() { clearTimeout(tmr); tmr = setTimeout(function() { table.ajax.reload(); }, 400); });
        });

        // Row single actions.
        jQuery('#streams-table tbody').on('click', '.js-act', function() {
            var sub = this.getAttribute('data-sub'), id = this.getAttribute('data-id'), server = this.getAttribute('data-server');
            var go = function() {
                fetch('./api?action=stream&sub=' + encodeURIComponent(sub) + '&stream_id=' + encodeURIComponent(id) + '&server_id=' + encodeURIComponent(server), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } table.ajax.reload(null, false); })
                    .catch(function() { xcToast(lang.error, 'error'); });
            };
            if (sub === 'delete') { confirmSwal(lang.del + '?').then(function(ok) { if (ok) { go(); } }); }
            else if (sub === 'purge') { confirmSwal(lang.kill + '?').then(function(ok) { if (ok) { go(); } }); }
            else { go(); }
        });

        // Player.
        jQuery('#streams-table tbody').on('click', '.js-play', function() {
            var id = this.getAttribute('data-id');
            if (window.player) { window.player(id); } else { window.open('stream_view?id=' + encodeURIComponent(id)); }
        });

        // Bulk.
        jQuery('#streams-table tbody').on('change', '.row-check', function() {
            var id = this.getAttribute('data-id');
            if (this.checked) { selected[id] = true; } else { delete selected[id]; }
            updateBulk();
        });
        var chkAll = document.getElementById('check-all');
        if (chkAll) {
            chkAll.addEventListener('change', function() {
                var on = this.checked;
                jQuery('#streams-table tbody .row-check').each(function() { this.checked = on; var id = this.getAttribute('data-id'); if (on) { selected[id] = true; } else { delete selected[id]; } });
                updateBulk();
            });
        }
        table.on('draw', function() { if (chkAll) { chkAll.checked = false; } });
        jQuery('#bulk-bar').on('click', '[data-bulk]', function() {
            var sub = this.getAttribute('data-bulk'), ids = Object.keys(selected);
            if (!ids.length) { return; }
            var run = function() {
                fetch('./api?action=multi&type=stream&sub=' + encodeURIComponent(sub) + '&ids=' + encodeURIComponent(JSON.stringify(ids)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } selected = {}; updateBulk(); table.ajax.reload(null, false); })
                    .catch(function() { xcToast(lang.error, 'error'); });
            };
            if (sub === 'delete' || sub === 'purge') { confirmSwal((sub === 'delete' ? lang.del : lang.kill) + ' (' + ids.length + ')?').then(function(ok) { if (ok) { run(); } }); }
            else { run(); }
        });

        // Edit / fingerprint iframe modal.
        var frameModal = document.getElementById('frameModal');
        var openFrame = function(title, src) {
            document.getElementById('frameModalTitle').textContent = title;
            document.getElementById('frame-src').src = src;
            bootstrap.Modal.getOrCreateInstance(frameModal).show();
        };
        jQuery('#streams-table tbody').on('click', '.js-edit', function() {
            var id = this.getAttribute('data-id'), page = this.getAttribute('data-type') === '3' ? 'created_channel' : 'stream';
            openFrame(lang.edit, page + '?id=' + encodeURIComponent(id) + '&modal=1');
        });
        jQuery('#streams-table tbody').on('click', '.js-finger', function() { openFrame(lang.fingerprint, 'fingerprint?id=' + encodeURIComponent(this.getAttribute('data-id')) + '&type=stream&modal=1'); });
        frameModal.addEventListener('hidden.bs.modal', function() { document.getElementById('frame-src').src = 'about:blank'; table.ajax.reload(null, false); });

        // Restart / failures log modal — opened by the fails indicator in the status
        // column. Server-generated HTML rows (server link + action badge) come from
        // TableController::handleFailuresModal (d.id = 'failures_modal').
        var failsStream = null, failsServer = null;
        var failsTable = jQuery('#failures-table').DataTable({
            processing: true,
            serverSide: true,
            paging: false,
            searching: false,
            info: false,
            ordering: false,
            ajax: { url: './table', data: function(d) { d.id = 'failures_modal'; d.stream_id = failsStream; d.server_id = failsServer; } },
            columns: [{ data: 0 }, { data: 1 }, { data: 2 }, { data: 3 }],
            language: { emptyTable: '—' }
        });
        var failuresModal = document.getElementById('failuresModal');
        jQuery('#streams-table tbody').on('click', '.js-fails', function() {
            failsStream = this.getAttribute('data-id');
            failsServer = this.getAttribute('data-server');
            failsTable.ajax.reload();
            bootstrap.Modal.getOrCreateInstance(failuresModal).show();
        });
        document.getElementById('fails-clear').addEventListener('click', function() {
            if (!failsStream) { return; }
            confirmSwal(lang.del + '?').then(function(ok) {
                if (!ok) { return; }
                fetch('./api?action=clear_failures&id=' + encodeURIComponent(failsStream), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } failsTable.ajax.reload(); table.ajax.reload(null, false); })
                    .catch(function() { xcToast(lang.error, 'error'); });
            });
        });
    })();
</script>
</body>

</html>
