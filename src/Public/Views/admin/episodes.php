<?php

/**
 * Episodes (Bootstrap 5). Clean-JSON serverSide table: TableController::handleEpisodes
 * (type=5 streams joined to streams_series/streams_episodes) resolves the transcode
 * status (StatusBadge::vod code), series/season, server info, client count, duration
 * and codec stream-info server-side; this page renders each cell and the per-row action
 * dropdown client-side. Filters: series (select2 ajax) / server / status / resolution /
 * video / audio. Bulk-select (action=multi&type=episode), row actions (encode/stop,
 * edit, delete via action=episode), player and live-connections link are inline. The
 * "+N servers" and duplicate drill-downs are links to the same table filtered by
 * stream_id / source_id (read from the URL on load). Reached full-page in the new-UI shell.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Server\ServerRepository;

if (!Authorization::check('adv', 'episodes') && !Authorization::check('adv', 'mass_sedits')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_episode');
$rCanLive = Authorization::check('adv', 'live_connections');
$rCanPlayer = Authorization::check('adv', 'player');

$rFilters = [1 => 'encoded', 2 => 'encoding', 3 => 'down', 4 => 'ready', 5 => 'direct', 6 => 'duplicate', 7 => 'transcoding'];
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('episodes'); ?></h5>
        <div class="d-flex flex-wrap align-items-center gap-2">
            <?php if ($rCanEdit): ?>
                <div id="bulk-bar" class="d-none align-items-center gap-2">
                    <span class="text-body-secondary"><span id="bulk-count">0</span></span>
                    <div class="btn-group btn-group-sm">
                        <button type="button" class="btn btn-label-success" data-bulk="start"><?= $language::get('encode') ?: 'Encode'; ?></button>
                        <button type="button" class="btn btn-label-secondary" data-bulk="stop"><?= $language::get('stop'); ?></button>
                        <button type="button" class="btn btn-label-info" data-bulk="restart"><?= $language::get('restart'); ?></button>
                        <button type="button" class="btn btn-label-dark" data-bulk="purge"><?= $language::get('kill'); ?></button>
                        <button type="button" class="btn btn-label-danger" data-bulk="delete"><?= $language::get('delete'); ?></button>
                    </div>
                </div>
                <button type="button" class="btn btn-sm btn-primary" id="add-episode-btn"><i class="icon-base ti tabler-plus me-1"></i>Add Episode</button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-series"><?= $language::get('series') ?: 'Series'; ?></label>
                <select id="filter-series" class="form-select"></select>
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
            <div class="col-6 col-sm-4 col-lg-2">
                <label class="form-label" for="filter-status"><?= $language::get('filter'); ?></label>
                <select id="filter-status" class="form-select">
                    <option value=""><?= $language::get('no_filter'); ?></option>
                    <?php foreach ($rFilters as $rK => $rV): ?>
                        <option value="<?= $rK; ?>"><?= $language::get($rV); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-4 col-sm-2 col-lg-1">
                <label class="form-label" for="filter-resolution"><?= $language::get('quality'); ?></label>
                <input type="text" id="filter-resolution" class="form-control" autocomplete="off">
            </div>
            <div class="col-4 col-sm-3 col-lg-1">
                <label class="form-label" for="filter-video"><?= $language::get('video'); ?></label>
                <input type="text" id="filter-video" class="form-control" autocomplete="off">
            </div>
            <div class="col-4 col-sm-3 col-lg-2">
                <label class="form-label" for="filter-audio"><?= $language::get('audio'); ?></label>
                <input type="text" id="filter-audio" class="form-control" autocomplete="off">
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="episodes-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><input type="checkbox" class="form-check-input" id="check-all"></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('image'); ?></th>
                    <th><?= $language::get('name'); ?></th>
                    <th><?= $language::get('server'); ?></th>
                    <th><?= $language::get('clients'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                    <th><?= $language::get('player'); ?></th>
                    <th><?= $language::get('duration') ?: 'Duration'; ?></th>
                    <th><?= $language::get('stream_info'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<?php if ($rCanEdit): ?>
    <div class="modal fade" id="addEpisodeModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Add Episode</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <label class="form-label" for="add-series"><?= $language::get('series') ?: 'Series'; ?></label>
                    <select id="add-series" class="form-select"></select>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-label-secondary" id="add-multi">Add Multiple</button>
                    <button type="button" class="btn btn-primary" id="add-single">Add Episode</button>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- Player modal -->
<div class="modal fade" id="playerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('player'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0"><iframe id="player-frame" src="about:blank" style="width:100%;height:60vh;border:0"></iframe></div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
        var canEdit = <?= $rCanEdit ? 'true' : 'false'; ?>, canLive = <?= $rCanLive ? 'true' : 'false'; ?>, canPlayer = <?= $rCanPlayer ? 'true' : 'false'; ?>;
        var lang = {
            encode: <?= json_encode($language::get('encode') ?: 'Encode'); ?>,
            stopEnc: <?= json_encode($language::get('stop_encoding') ?: 'Stop Encoding'); ?>,
            edit: <?= json_encode($language::get('edit')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            kill: <?= json_encode($language::get('kill') ?: 'Kill Connections'); ?>,
            selected: <?= json_encode($language::get('selected')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };
        var toast = window.xcToast || function() {};
        var confirmSwal = function(text) { return window.xcConfirm ? window.xcConfirm(text) : Promise.resolve(window.confirm(text)); };
        // StatusBadge::vod — episode encode-state codes.
        var STATUS = {
            '0': ['dark', 'Not Encoded'], '1': ['success', 'Encoded'], '2': ['warning', 'Encoding'],
            '3': ['danger', 'Down'], '4': ['info', 'On Demand'], '5': ['primary', 'Direct']
        };
        var qs = new URLSearchParams(location.search);
        var urlStreamId = qs.get('stream_id') || '', urlSourceId = qs.get('source_id') || '';

        var seriesSelect2 = function(sel, placeholder) {
            $(sel).select2({
                width: '100%',
                placeholder: placeholder,
                allowClear: true,
                ajax: {
                    url: './api', dataType: 'json', delay: 250,
                    data: function(params) { return { search: params.term, action: 'serieslist', page: params.page }; },
                    processResults: function(data, params) { params.page = params.page || 1; return { results: data.items, pagination: { more: (params.page * 100) < data.total_count } }; },
                    cache: true
                }
            });
        };
        seriesSelect2('#filter-series', 'All Series');

        var selected = {};
        var updateBulk = function() {
            var n = Object.keys(selected).length, bar = document.getElementById('bulk-bar');
            if (!bar) { return; }
            document.getElementById('bulk-count').textContent = n + ' ' + lang.selected;
            bar.classList.toggle('d-none', n === 0);
            bar.classList.toggle('d-flex', n > 0);
        };

        var table = $('#episodes-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[2, 'desc']],
            searchDelay: 400,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'episodes';
                    d.series = document.getElementById('filter-series').value;
                    d.server = document.getElementById('filter-server').value;
                    d.filter = document.getElementById('filter-status').value;
                    d.resolution = document.getElementById('filter-resolution').value;
                    d.video = document.getElementById('filter-video').value;
                    d.audio = document.getElementById('filter-audio').value;
                    if (urlStreamId) { d.stream_id = urlStreamId; d.single = true; }
                    if (urlSourceId) { d.source_id = urlSourceId; d.grouped = true; }
                }
            },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'id', orderable: false, searchable: false, className: 'text-center', render: function(d) { return '<input type="checkbox" class="form-check-input row-check" data-id="' + esc(d) + '"' + (selected[d] ? ' checked' : '') + '>'; } },
                { data: 'display_id', className: 'text-center', render: function(d, t, row) { return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'image', orderable: false, render: function(d) { return d ? '<a href="resize?maxw=512&maxh=512&url=' + encodeURIComponent(d) + '" target="_blank"><img loading="lazy" src="resize?maxh=32&maxw=64&url=' + encodeURIComponent(d) + '" alt=""></a>' : ''; } },
                {
                    data: 'title',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        var sub = esc(row.series || '') + (row.season != null && row.season !== '' ? ' — Season ' + esc(row.season) : '');
                        return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body"><span class="fw-medium">' + esc(d) + '</span>' + (sub ? '<br><small class="text-body-secondary">' + sub + '</small>' : '') + '</a>';
                    }
                },
                {
                    data: 'server_name',
                    render: function(d, t, row) {
                        if (!d) { return '<span class="text-body-secondary">No Server Selected</span>'; }
                        var html = row.server_url ? '<a href="' + esc(row.server_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                        if (row.server_count > 1) { html += ' <a href="episodes?stream_id=' + encodeURIComponent(row.id) + '" class="badge bg-label-info">+' + (row.server_count - 1) + '</a>'; }
                        if (row.server_offline) { html += ' <i class="icon-base ti tabler-alert-triangle text-danger" title="Server offline"></i>'; }
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
                { data: 'status', className: 'text-center', render: function(d) { var s = STATUS[String(d)] || ['secondary', '']; return '<span class="badge bg-label-' + s[0] + '" title="' + esc(s[1]) + '">' + esc(s[1]) + '</span>'; } },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var items = '';
                        if (canEdit) {
                            if (row.status === 2) { items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '" data-sub="stop">' + esc(lang.stopEnc) + '</a>'; }
                            else if (row.status !== 3) { items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '" data-sub="start">' + esc(lang.encode) + '</a>'; }
                            if (row.clients > 0) { items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '" data-sub="purge">' + esc(lang.kill) + '</a>'; }
                            items += '<a class="dropdown-item js-edit" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-sid="' + esc(row.sid) + '">' + esc(lang.edit) + '</a>';
                            items += '<a class="dropdown-item text-danger js-act" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '" data-sub="delete">' + esc(lang.del) + '</a>';
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
                        if (!canPlayer || !playable) { return '<button class="btn btn-sm btn-icon btn-label-secondary" disabled><i class="icon-base ti tabler-player-play"></i></button>'; }
                        return '<button class="btn btn-sm btn-icon btn-label-info js-play" data-id="' + esc(row.id) + '" data-container="' + esc(row.target_container || '') + '"><i class="icon-base ti tabler-player-play"></i></button>';
                    }
                },
                {
                    data: 'duration',
                    orderable: false,
                    className: 'text-center text-nowrap',
                    render: function(d, t, row) {
                        if (row.duplicates != null) { return '<a href="episodes?source_id=' + encodeURIComponent(row.source) + '">Duplicate of <strong>' + esc(row.duplicates) + '</strong></a>'; }
                        var dur = '<i class="icon-base ti tabler-clock text-success"></i> ' + esc(d || '--:--:--');
                        return '<div>' + dur + '</div>' + (row.modified ? '<small class="text-body-secondary">' + esc(row.modified) + '</small>' : '');
                    }
                },
                {
                    data: 'info',
                    orderable: false,
                    render: function(d) {
                        if (!d) { return '<small class="text-body-secondary">—</small>'; }
                        return '<div class="d-flex flex-wrap gap-1">' +
                            '<span class="badge bg-label-secondary">' + esc(Number(d.bitrate).toLocaleString()) + ' Kbps</span>' +
                            '<span class="badge bg-label-primary">' + esc(d.width) + '×' + esc(d.height) + '</span>' +
                            '<span class="badge bg-label-info">' + esc(d.video_codec) + '</span>' +
                            '<span class="badge bg-label-success">' + esc(d.audio_codec) + '</span></div>';
                    }
                }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        ['filter-series', 'filter-server', 'filter-status'].forEach(function(id) { $('#' + id).on('change', function() { table.ajax.reload(); }); });
        ['filter-resolution', 'filter-video', 'filter-audio'].forEach(function(id) {
            var el = document.getElementById(id), tmr;
            el.addEventListener('input', function() { clearTimeout(tmr); tmr = setTimeout(function() { table.ajax.reload(); }, 400); });
        });

        // Row single actions.
        $('#episodes-table tbody').on('click', '.js-act', function() {
            var sub = this.getAttribute('data-sub'), id = this.getAttribute('data-id'), server = this.getAttribute('data-server');
            var go = function() {
                fetch('./api?action=episode&sub=' + encodeURIComponent(sub) + '&stream_id=' + encodeURIComponent(id) + '&server_id=' + encodeURIComponent(server), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } table.ajax.reload(null, false); })
                    .catch(function() { toast(lang.error, 'error'); });
            };
            if (sub === 'delete') { confirmSwal(lang.del + '?').then(function(ok) { if (ok) { go(); } }); }
            else if (sub === 'purge') { confirmSwal(lang.kill + '?').then(function(ok) { if (ok) { go(); } }); }
            else { go(); }
        });

        // Edit (legacy full-page episode form).
        $('#episodes-table tbody').on('click', '.js-edit', function() {
            var id = this.getAttribute('data-id'), sid = this.getAttribute('data-sid');
            window.location.href = 'episode?id=' + encodeURIComponent(id) + '&sid=' + encodeURIComponent(sid);
        });

        // Player.
        var playerModal = document.getElementById('playerModal');
        $('#episodes-table tbody').on('click', '.js-play', function() {
            var id = this.getAttribute('data-id'), container = this.getAttribute('data-container') || '';
            document.getElementById('player-frame').src = './player?type=series&id=' + encodeURIComponent(id) + '&container=' + encodeURIComponent(container);
            if (window.bootstrap) { bootstrap.Modal.getOrCreateInstance(playerModal).show(); }
        });
        playerModal.addEventListener('hidden.bs.modal', function() { document.getElementById('player-frame').src = 'about:blank'; });

        // Bulk.
        $('#episodes-table tbody').on('change', '.row-check', function() {
            var id = this.getAttribute('data-id');
            if (this.checked) { selected[id] = true; } else { delete selected[id]; }
            updateBulk();
        });
        var chkAll = document.getElementById('check-all');
        if (chkAll) {
            chkAll.addEventListener('change', function() {
                var on = this.checked;
                $('#episodes-table tbody .row-check').each(function() { this.checked = on; var id = this.getAttribute('data-id'); if (on) { selected[id] = true; } else { delete selected[id]; } });
                updateBulk();
            });
        }
        table.on('draw', function() { if (chkAll) { chkAll.checked = false; } });
        $('#bulk-bar').on('click', '[data-bulk]', function() {
            var sub = this.getAttribute('data-bulk'), ids = Object.keys(selected);
            if (!ids.length) { return; }
            var run = function() {
                fetch('./api?action=multi&type=episode&sub=' + encodeURIComponent(sub) + '&ids=' + encodeURIComponent(JSON.stringify(ids)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } selected = {}; updateBulk(); table.ajax.reload(null, false); })
                    .catch(function() { toast(lang.error, 'error'); });
            };
            if (sub === 'delete' || sub === 'purge') { confirmSwal((sub === 'delete' ? lang.del : lang.kill) + ' (' + ids.length + ')?').then(function(ok) { if (ok) { run(); } }); }
            else { run(); }
        });

        // Add Episode modal.
        if (canEdit) {
            seriesSelect2('#add-series', 'Search for a series...');
            var addModal = document.getElementById('addEpisodeModal');
            document.getElementById('add-episode-btn').addEventListener('click', function() {
                var cur = document.getElementById('filter-series').value;
                if (cur) { $('#add-series').val(cur).trigger('change'); }
                bootstrap.Modal.getOrCreateInstance(addModal).show();
            });
            document.getElementById('add-single').addEventListener('click', function() {
                var sid = document.getElementById('add-series').value;
                if (sid) { window.location.href = 'episode?sid=' + encodeURIComponent(sid); }
            });
            document.getElementById('add-multi').addEventListener('click', function() {
                var sid = document.getElementById('add-series').value;
                if (sid) { window.location.href = 'episode?sid=' + encodeURIComponent(sid) + '&multi'; }
            });
        }
    })();
</script>
</body>

</html>
