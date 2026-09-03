<?php

/**
 * Radio stations (Bootstrap 5). Clean-JSON table pattern: TableController::handleRadios
 * resolves the live-stream status (StatusBadge::stream code), category, server
 * info, client count, uptime and codec stream-info server-side; this page renders
 * the cells client-side. Bulk-select (action=multi&type=radio), row actions
 * (start/stop/restart/kill, edit modal, delete via action=stream) + category /
 * server filters, all inline.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;

if (!Authorization::check('adv', 'radio') && !Authorization::check('adv', 'mass_edit_radio')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_radio');
$rCanLive = Authorization::check('adv', 'live_connections');
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('radio_stations'); ?></h5>
        <?php if ($rCanEdit): ?>
            <div id="bulk-bar" class="d-none align-items-center gap-2">
                <span class="text-body-secondary"><span id="bulk-count">0</span></span>
                <button type="button" class="btn btn-sm btn-label-danger" id="bulk-delete"><i class="icon-base ti tabler-trash me-1"></i><?= $language::get('delete_selected'); ?></button>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-category"><?= $language::get('category'); ?></label>
                <select id="filter-category" class="form-select">
                    <option value=""><?= $language::get('all_categories'); ?></option>
                    <option value="-1"><?= $language::get('no_category'); ?></option>
                    <?php foreach (CategoryService::getAllByType('radio') as $rCatId => $rCat): ?>
                        <option value="<?= (int) $rCatId; ?>"><?= htmlspecialchars((string) $rCat['category_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-server"><?= $language::get('server'); ?></label>
                <select id="filter-server" class="form-select">
                    <option value=""><?= $language::get('all_servers'); ?></option>
                    <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                        <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="radios-table" class="table" style="width:100%">
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
        var pad = function(n) { return (n < 10 ? '0' : '') + n; };
        var fmtUptime = function(sec) {
            if (sec >= 86400) { return pad(Math.floor(sec / 86400)) + 'd ' + pad(Math.floor(sec / 3600) % 24) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm'; }
            return pad(Math.floor(sec / 3600)) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm ' + pad(sec % 60) + 's';
        };
        var canEdit = <?= $rCanEdit ? 'true' : 'false'; ?>, canLive = <?= $rCanLive ? 'true' : 'false'; ?>;
        var lang = {
            start: <?= json_encode($language::get('start') ?: 'Start'); ?>,
            stop: <?= json_encode($language::get('stop') ?: 'Stop'); ?>,
            restart: <?= json_encode($language::get('restart') ?: 'Restart'); ?>,
            kill: <?= json_encode($language::get('kill') ?: 'Kill Connections'); ?>,
            edit: <?= json_encode($language::get('edit')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            selected: <?= json_encode($language::get('selected')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };
        // StatusBadge::stream — code => [bootstrap colour, label].
        var STREAM = {
            '-1': ['secondary', 'NO SERVERS'], '0': ['dark', 'STOPPED'], '1': ['success', 'ONLINE'],
            '2': ['warning', 'STARTING'], '3': ['danger', 'DOWN'], '4': ['info', 'ON DEMAND'], '5': ['dark', 'DIRECT SOURCE']
        };
        var selected = {};
        var updateBulk = function() {
            var n = Object.keys(selected).length, bar = document.getElementById('bulk-bar');
            if (!bar) { return; }
            document.getElementById('bulk-count').textContent = n + ' ' + lang.selected;
            bar.classList.toggle('d-none', n === 0);
            bar.classList.toggle('d-flex', n > 0);
        };
        // Statuses that mean the stream is up (Stop/Restart/Kill offered instead of Start).
        var running = function(row) { return row.status === 1 || row.status === 2 || row.status === 3 || row.status === 5 || row.on_demand; };

        var table = jQuery('#radios-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[2, 'desc']],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'radios';
                    d.category = document.getElementById('filter-category').value;
                    d.server = document.getElementById('filter-server').value;
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
                        var sub = esc(row.category || '') + (row.source_label ? '<br>' + esc(row.source_label) : '');
                        return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body"><span class="fw-medium">' + esc(d) + '</span>' + (sub ? '<br><small class="text-body-secondary">' + sub + '</small>' : '') + '</a>';
                    }
                },
                {
                    data: 'server_name',
                    render: function(d, t, row) {
                        if (!d) { return '<span class="text-body-secondary">No Server</span>'; }
                        var html = row.server_url ? '<a href="' + esc(row.server_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                        if (row.server_count > 1) { html += ' <a href="radios?stream_id=' + encodeURIComponent(row.id) + '" class="badge bg-label-info">+' + (row.server_count - 1) + '</a>'; }
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
                {
                    data: 'status',
                    className: 'text-center text-nowrap',
                    render: function(d, t, row) {
                        if (d === 1) { return '<span class="badge bg-label-success">' + esc(fmtUptime(row.uptime)) + '</span>'; }
                        var s = STREAM[String(d)] || ['secondary', ''];
                        return '<span class="badge bg-label-' + s[0] + '">' + esc(s[1]) + '</span>';
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
                                items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="stop" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '">' + esc(lang.stop) + '</a>';
                                items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="restart" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '">' + esc(lang.restart) + '</a>';
                                items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="purge" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '">' + esc(lang.kill) + '</a>';
                            } else {
                                items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="start" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '">' + esc(lang.start) + '</a>';
                            }
                            items += '<a class="dropdown-item js-edit" href="javascript:void(0);" data-id="' + esc(row.id) + '">' + esc(lang.edit) + '</a>';
                            items += '<a class="dropdown-item text-danger js-act" href="javascript:void(0);" data-sub="delete" data-id="' + esc(row.id) + '" data-server="' + esc(row.server_id) + '">' + esc(lang.del) + '</a>';
                        }
                        if (row.notes) { items = '<h6 class="dropdown-header text-truncate" style="max-width:16rem" title="' + esc(row.notes) + '">' + esc(row.notes) + '</h6><div class="dropdown-divider"></div>' + items; }
                        if (!items) { return ''; }
                        return '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' + items + '</div></div>';
                    }
                },
                {
                    data: 'info',
                    orderable: false,
                    render: function(d) {
                        if (!d) { return '<small class="text-body-secondary">—</small>'; }
                        return '<div class="d-flex flex-wrap gap-1">' +
                            '<span class="badge bg-label-secondary">' + esc(d.bitrate) + ' Kbps</span>' +
                            '<span class="badge bg-label-success">' + esc(d.audio_codec) + '</span>' +
                            '<span class="badge bg-label-info">' + esc(d.speed) + '</span></div>';
                    }
                }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        ['filter-category', 'filter-server'].forEach(function(id) { document.getElementById(id).addEventListener('change', function() { table.ajax.reload(); }); });

        jQuery('#radios-table tbody').on('click', '.js-act', function() {
            var sub = this.getAttribute('data-sub');
            var _id = this.getAttribute('data-id'), _server = this.getAttribute('data-server');
            var _do = function() {
                fetch('./api?action=stream&sub=' + encodeURIComponent(sub) + '&stream_id=' + encodeURIComponent(_id) + '&server_id=' + encodeURIComponent(_server), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } table.ajax.reload(null, false); })
                    .catch(function() { alert(lang.error); });
            };
            if (sub === 'delete') { window.xcConfirm(lang.del + '?').then(function(ok) { if (ok) { _do(); } }); }
            else { _do(); }
        });

        // Bulk delete.
        jQuery('#radios-table tbody').on('change', '.row-check', function() {
            var id = this.getAttribute('data-id');
            if (this.checked) { selected[id] = true; } else { delete selected[id]; }
            updateBulk();
        });
        document.getElementById('check-all').addEventListener('change', function() {
            var on = this.checked;
            jQuery('#radios-table tbody .row-check').each(function() { this.checked = on; var id = this.getAttribute('data-id'); if (on) { selected[id] = true; } else { delete selected[id]; } });
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
                    fetch('./api?action=multi&type=radio&sub=delete&ids=' + encodeURIComponent(JSON.stringify(ids)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                        .then(function(r) { return r.json(); })
                        .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } selected = {}; updateBulk(); table.ajax.reload(null, false); })
                        .catch(function() { alert(lang.error); });
                });
            });
        }

        // Edit modal.
        var editModal = document.getElementById('editModal');
        jQuery('#radios-table tbody').on('click', '.js-edit', function() {
            document.getElementById('edit-frame').src = 'radio?id=' + encodeURIComponent(this.getAttribute('data-id')) + '&modal=1';
            bootstrap.Modal.getOrCreateInstance(editModal).show();
        });
        editModal.addEventListener('hidden.bs.modal', function() { document.getElementById('edit-frame').src = 'about:blank'; table.ajax.reload(null, false); });
    })();
</script>
</body>

</html>
