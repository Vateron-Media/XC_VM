<?php

/**
 * Movies / VOD (Bootstrap 5). Clean-JSON table pattern: TableController::handleMovies
 * resolves the transcode status (StatusBadge::vod code), category, server info,
 * client count and codec stream-info server-side; this page renders the cells
 * client-side via datatables-bs5 columns[].render. Bulk-select
 * (action=multi&type=movie), row actions (encode/stop, edit modal, delete),
 * player, live-connections link and category/server/codec filters are inline.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;

if (!Authorization::check('adv', 'movies') && !Authorization::check('adv', 'mass_sedits_vod')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_movie');
$rCanLive = Authorization::check('adv', 'live_connections');
$rCanPlayer = Authorization::check('adv', 'player');
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('movies'); ?></h5>
        <?php if ($rCanEdit): ?>
            <div id="bulk-bar" class="d-none align-items-center gap-2">
                <span class="text-body-secondary"><span id="bulk-count">0</span></span>
                <button type="button" class="btn btn-sm btn-label-danger" id="bulk-delete"><i class="icon-base ti tabler-trash me-1"></i><?= $language::get('delete_selected'); ?></button>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-category"><?= $language::get('category'); ?></label>
                <select id="filter-category" class="form-select">
                    <option value=""><?= $language::get('all_categories'); ?></option>
                    <option value="-1"><?= $language::get('no_category'); ?></option>
                    <?php foreach (CategoryService::getAllByType('movie') as $rCatId => $rCat): ?>
                        <option value="<?= (int) $rCatId; ?>"><?= htmlspecialchars((string) $rCat['category_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-server"><?= $language::get('server'); ?></label>
                <select id="filter-server" class="form-select">
                    <option value=""><?= $language::get('all_servers'); ?></option>
                    <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                        <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="filter-resolution"><?= $language::get('resolution'); ?></label>
                <input type="text" id="filter-resolution" class="form-control" autocomplete="off">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="filter-video"><?= $language::get('video'); ?></label>
                <input type="text" id="filter-video" class="form-control" autocomplete="off">
            </div>
            <div class="col-6 col-lg-2">
                <label class="form-label" for="filter-audio"><?= $language::get('audio'); ?></label>
                <input type="text" id="filter-audio" class="form-control" autocomplete="off">
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="movies-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><input type="checkbox" class="form-check-input" id="check-all"></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('cover'); ?></th>
                    <th><?= $language::get('title'); ?></th>
                    <th><?= $language::get('server'); ?></th>
                    <th><?= $language::get('connections'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th>TMDB</th>
                    <th><?= $language::get('actions'); ?></th>
                    <th><?= $language::get('player'); ?></th>
                    <th><?= $language::get('stream_info'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- Edit -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('edit'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="edit-frame" src="about:blank" style="width:100%;height:70vh;border:0"></iframe>
            </div>
        </div>
    </div>
</div>

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
        var esc = function(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : String(s));
            return d.innerHTML;
        };
        var canEdit = <?= $rCanEdit ? 'true' : 'false'; ?>,
            canLive = <?= $rCanLive ? 'true' : 'false'; ?>,
            canPlayer = <?= $rCanPlayer ? 'true' : 'false'; ?>;
        var lang = {
            encode: <?= json_encode($language::get('encode') ?: 'Encode'); ?>,
            startEnc: <?= json_encode($language::get('start_encoding') ?: 'Start Encoding'); ?>,
            stopEnc: <?= json_encode($language::get('stop_encoding') ?: 'Stop Encoding'); ?>,
            edit: <?= json_encode($language::get('edit')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            selected: <?= json_encode($language::get('selected')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };
        var STATUS = {
            '-1': ['secondary', 'No Server Selected'],
            '0': ['dark', 'Not Encoded'],
            '1': ['success', 'Encoded'],
            '2': ['warning', 'Encoding'],
            '3': ['primary', 'Direct Source'],
            '4': ['danger', 'Down'],
            '5': ['info', 'Direct Stream']
        };
        var selected = {};
        var updateBulk = function() {
            var n = Object.keys(selected).length, bar = document.getElementById('bulk-bar');
            if (!bar) { return; }
            document.getElementById('bulk-count').textContent = n + ' ' + lang.selected;
            bar.classList.toggle('d-none', n === 0);
            bar.classList.toggle('d-flex', n > 0);
        };

        var table = jQuery('#movies-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[2, 'desc']],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'movies';
                    d.category = document.getElementById('filter-category').value;
                    d.server = document.getElementById('filter-server').value;
                    d.resolution = document.getElementById('filter-resolution').value;
                    d.video = document.getElementById('filter-video').value;
                    d.audio = document.getElementById('filter-audio').value;
                }
            },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'id', orderable: false, searchable: false, className: 'text-center', render: function(d) { return '<input type="checkbox" class="form-check-input row-check" data-id="' + esc(d) + '"' + (selected[d] ? ' checked' : '') + '>'; } },
                { data: 'display_id', className: 'text-center', render: function(d, t, row) { return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'image', orderable: false, render: function(d) { return d ? '<a href="resize?maxw=512&maxh=512&url=' + encodeURIComponent(d) + '" target="_blank"><img loading="lazy" src="resize?maxh=58&maxw=32&url=' + encodeURIComponent(d) + '" alt=""></a>' : ''; } },
                {
                    data: 'title',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        var sub = (row.year ? '<strong>' + esc(row.year) + '</strong> ' : '') + esc(row.category || '');
                        return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body"><span class="fw-medium">' + esc(d) + '</span>' + (sub ? '<br><small class="text-body-secondary">' + sub + '</small>' : '') + '</a>';
                    }
                },
                {
                    data: 'server_name',
                    render: function(d, t, row) {
                        if (!d) { return '<span class="text-body-secondary">' + esc('No Server') + '</span>'; }
                        var html = row.server_url ? '<a href="' + esc(row.server_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                        if (row.server_count > 1) { html += ' <a href="movies?stream_id=' + encodeURIComponent(row.id) + '" class="badge bg-label-info">+' + (row.server_count - 1) + '</a>'; }
                        if (row.server_offline) { html += ' <i class="icon-base ti tabler-alert-triangle text-danger" title="Server offline"></i>'; }
                        return html;
                    }
                },
                {
                    data: 'clients',
                    className: 'text-center',
                    render: function(d, t, row) {
                        if (d > 0 && canLive) { return '<a href="live_connections?stream_id=' + encodeURIComponent(row.id) + '&server_id=' + encodeURIComponent(row.server_col_id) + '" class="badge bg-label-info">' + d + '</a>'; }
                        return '<span class="badge bg-label-secondary">' + (d || 0) + '</span>';
                    }
                },
                { data: 'status', className: 'text-center', render: function(d) { var s = STATUS[String(d)] || ['secondary', '']; return '<span class="badge bg-label-' + s[0] + '" title="' + esc(s[1]) + '">' + esc(s[1]) + '</span>'; } },
                { data: 'tmdb', className: 'text-center', render: function(d) { return d ? '<i class="icon-base ti tabler-circle-check text-success"></i>' : '<i class="icon-base ti tabler-circle-minus text-body-secondary"></i>'; } },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var items = '';
                        if (canEdit) {
                            if (row.status === 2) { items += '<a class="dropdown-item js-enc" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '" data-sub="stop">' + esc(lang.stopEnc) + '</a>'; }
                            else if (row.status !== 3 && row.status !== 5) { items += '<a class="dropdown-item js-enc" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '" data-sub="start">' + esc(row.status === 1 ? lang.encode : lang.startEnc) + '</a>'; }
                            items += '<a class="dropdown-item js-edit" href="javascript:void(0);" data-id="' + esc(row.id) + '">' + esc(lang.edit) + '</a>';
                            items += '<a class="dropdown-item text-danger js-del" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '">' + esc(lang.del) + '</a>';
                        }
                        if (row.notes) { items = '<h6 class="dropdown-header text-truncate" style="max-width:16rem" title="' + esc(row.notes) + '">' + esc(row.notes) + '</h6><div class="dropdown-divider"></div>' + items; }
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
                        var playable = (row.status === 1 || row.status === 3);
                        if (!canPlayer || !playable) { return '<button class="btn btn-sm btn-icon btn-label-secondary" disabled><i class="icon-base ti tabler-player-play"></i></button>'; }
                        return '<button class="btn btn-sm btn-icon btn-label-info js-play" data-id="' + esc(row.id) + '" data-container="' + esc(row.target_container || '') + '"><i class="icon-base ti tabler-player-play"></i></button>';
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
                            '<span class="badge bg-label-success">' + esc(d.audio_codec) + '</span>' +
                            '<span class="badge bg-label-secondary">' + esc(d.duration) + '</span></div>';
                    }
                }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        ['filter-category', 'filter-server'].forEach(function(id) { document.getElementById(id).addEventListener('change', function() { table.ajax.reload(); }); });
        ['filter-resolution', 'filter-video', 'filter-audio'].forEach(function(id) {
            var el = document.getElementById(id), tmr;
            el.addEventListener('input', function() { clearTimeout(tmr); tmr = setTimeout(function() { table.ajax.reload(); }, 400); });
        });

        // Encode start/stop + delete.
        jQuery('#movies-table tbody').on('click', '.js-enc', function() {
            call(this.getAttribute('data-sub'), this.getAttribute('data-id'), this.getAttribute('data-server'));
        });
        jQuery('#movies-table tbody').on('click', '.js-del', function() {
            var _id = this.getAttribute('data-id'), _server = this.getAttribute('data-server');
            window.xcConfirm(lang.del + '?').then(function(ok) {
                if (!ok) { return; }
                call('delete', _id, _server);
            });
        });
        function call(sub, id, server) {
            fetch('./api?action=movie&sub=' + encodeURIComponent(sub) + '&stream_id=' + encodeURIComponent(id) + '&server_id=' + encodeURIComponent(server), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } table.ajax.reload(null, false); })
                .catch(function() { alert(lang.error); });
        }

        // Player (legacy modal if available, else open the stream page).
        var playerModal = document.getElementById('playerModal');
        jQuery('#movies-table tbody').on('click', '.js-play', function() {
            var id = this.getAttribute('data-id'), container = this.getAttribute('data-container') || '';
            document.getElementById('player-frame').src = './player?type=movie&id=' + encodeURIComponent(id) + '&container=' + encodeURIComponent(container);
            if (window.bootstrap) { bootstrap.Modal.getOrCreateInstance(playerModal).show(); }
        });
        playerModal.addEventListener('hidden.bs.modal', function() { document.getElementById('player-frame').src = 'about:blank'; });

        // Bulk.
        jQuery('#movies-table tbody').on('change', '.row-check', function() {
            var id = this.getAttribute('data-id');
            if (this.checked) { selected[id] = true; } else { delete selected[id]; }
            updateBulk();
        });
        document.getElementById('check-all').addEventListener('change', function() {
            var on = this.checked;
            jQuery('#movies-table tbody .row-check').each(function() { this.checked = on; var id = this.getAttribute('data-id'); if (on) { selected[id] = true; } else { delete selected[id]; } });
            updateBulk();
        });
        table.on('draw', function() { document.getElementById('check-all').checked = false; });
        var bulkDel = document.getElementById('bulk-delete');
        if (bulkDel) {
            bulkDel.addEventListener('click', function() {
                var ids = Object.keys(selected);
                if (!ids.length) { return; }
                window.xcConfirm(lang.del + ' (' + ids.length + ')?').then(function(ok) {
                    if (!ok) { return; }
                    fetch('./api?action=multi&type=movie&sub=delete&ids=' + encodeURIComponent(JSON.stringify(ids)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function(r) { return r.json(); })
                        .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } selected = {}; updateBulk(); table.ajax.reload(null, false); })
                        .catch(function() { alert(lang.error); });
                });
            });
        }

        // Edit modal.
        var editModal = document.getElementById('editModal');
        jQuery('#movies-table tbody').on('click', '.js-edit', function() {
            document.getElementById('edit-frame').src = 'movie?id=' + encodeURIComponent(this.getAttribute('data-id')) + '&modal=1';
            bootstrap.Modal.getOrCreateInstance(editModal).show();
        });
        editModal.addEventListener('hidden.bs.modal', function() { document.getElementById('edit-frame').src = 'about:blank'; table.ajax.reload(null, false); });
    })();
</script>
</body>

</html>
