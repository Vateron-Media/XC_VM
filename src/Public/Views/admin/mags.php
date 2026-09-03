<?php

/**
 * MAG devices (Bootstrap 5). Clean-JSON table pattern: TableController::handleMags
 * returns structured rows (connection / last-activity gathering resolved
 * server-side) and this page renders the cells client-side via datatables-bs5
 * columns[].render. Bulk-select (action=multi&type=mag), row actions (MAG event,
 * convert to line, fingerprint, edit modal, ban/unban, enable/disable, delete)
 * and the status/reseller filters are wired inline.
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'manage_mag')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_mag');
$rCanConvert = Authorization::check('adv', 'edit_user');
$rCanEvents = Authorization::check('adv', 'manage_events');
$rCanLive = Authorization::check('adv', 'live_connections');
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('mag_devices'); ?></h5>
        <?php if ($rCanEdit): ?>
            <div id="bulk-bar" class="d-none align-items-center gap-2">
                <span class="text-body-secondary"><span id="bulk-count">0</span></span>
                <button type="button" class="btn btn-sm btn-label-secondary js-bulk" data-sub="enable"><?= $language::get('enable'); ?></button>
                <button type="button" class="btn btn-sm btn-label-secondary js-bulk" data-sub="disable"><?= $language::get('disable'); ?></button>
                <button type="button" class="btn btn-sm btn-label-warning js-bulk" data-sub="ban"><?= $language::get('ban'); ?></button>
                <button type="button" class="btn btn-sm btn-label-warning js-bulk" data-sub="unban"><?= $language::get('unban'); ?></button>
                <button type="button" class="btn btn-sm btn-label-danger js-bulk" data-sub="delete"><?= $language::get('delete'); ?></button>
            </div>
        <?php endif; ?>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-reseller"><?= $language::get('reseller'); ?></label>
                <select id="filter-reseller" class="form-select"></select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-status"><?= $language::get('status'); ?></label>
                <select id="filter-status" class="form-select">
                    <option value=""><?= $language::get('no_filter'); ?></option>
                    <option value="1"><?= $language::get('active'); ?></option>
                    <option value="2"><?= $language::get('disabled'); ?></option>
                    <option value="3"><?= $language::get('banned'); ?></option>
                    <option value="4"><?= $language::get('expired'); ?></option>
                    <option value="5"><?= $language::get('trial'); ?></option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="mags-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><input type="checkbox" class="form-check-input" id="check-all"></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('mac'); ?></th>
                    <th><?= $language::get('stb_type'); ?></th>
                    <th><?= $language::get('owner'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('active'); ?></th>
                    <th><?= $language::get('trial'); ?></th>
                    <th><?= $language::get('expires'); ?></th>
                    <th><?= $language::get('last_active'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- MAG event -->
<div class="modal fade" id="eventModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('mag_event'); ?> — <span id="event-mac"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="event-type"><?= $language::get('type'); ?></label>
                    <select id="event-type" class="form-select">
                        <option value="send_msg"><?= $language::get('send_message'); ?></option>
                        <option value="play_channel"><?= $language::get('play_channel'); ?></option>
                        <option value="reset_stb_lock"><?= $language::get('reset_stb'); ?></option>
                    </select>
                </div>
                <div class="mb-0" id="event-msg-wrap">
                    <label class="form-label" for="event-message"><?= $language::get('message'); ?></label>
                    <textarea class="form-control" id="event-message" rows="3"></textarea>
                </div>
                <div class="mb-0 d-none" id="event-channel-wrap">
                    <label class="form-label" for="event-channel"><?= $language::get('channel'); ?></label>
                    <input type="number" class="form-control" id="event-channel">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="event-submit"><?= $language::get('send'); ?></button>
            </div>
        </div>
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
        var sq = function(cls, title) { return '<i class="icon-base ti tabler-square-filled ' + cls + '" title="' + esc(title || '') + '"></i>'; };
        var fmtDur = function(sec) {
            sec = Math.max(0, sec);
            if (sec >= 86400) { return Math.floor(sec / 86400) + 'd ' + (Math.floor(sec / 3600) % 24) + 'h'; }
            if (sec >= 3600) { return Math.floor(sec / 3600) + 'h ' + (Math.floor(sec / 60) % 60) + 'm'; }
            return (Math.floor(sec / 60) % 60) + 'm ' + (sec % 60) + 's';
        };
        var canEdit = <?= $rCanEdit ? 'true' : 'false'; ?>,
            canConvert = <?= $rCanConvert ? 'true' : 'false'; ?>,
            canEvents = <?= $rCanEvents ? 'true' : 'false'; ?>,
            canLive = <?= $rCanLive ? 'true' : 'false'; ?>;
        var lang = {
            event: <?= json_encode($language::get('mag_event')); ?>,
            convert: <?= json_encode($language::get('convert_to_line')); ?>,
            fingerprint: <?= json_encode($language::get('fingerprint')); ?>,
            edit: <?= json_encode($language::get('edit')); ?>,
            ban: <?= json_encode($language::get('ban')); ?>,
            unban: <?= json_encode($language::get('unban')); ?>,
            enable: <?= json_encode($language::get('enable')); ?>,
            disable: <?= json_encode($language::get('disable')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            never: <?= json_encode($language::get('never') ?: 'Never'); ?>,
            online: <?= json_encode($language::get('online') ?: 'Online'); ?>,
            selected: <?= json_encode($language::get('selected')); ?>,
            statusActive: <?= json_encode($language::get('active')); ?>,
            statusBanned: <?= json_encode($language::get('banned')); ?>,
            statusDisabled: <?= json_encode($language::get('disabled')); ?>,
            statusExpired: <?= json_encode($language::get('expired')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };
        var selected = {};
        var updateBulk = function() {
            var n = Object.keys(selected).length;
            var bar = document.getElementById('bulk-bar');
            if (!bar) { return; }
            document.getElementById('bulk-count').textContent = n + ' ' + lang.selected;
            bar.classList.toggle('d-none', n === 0);
            bar.classList.toggle('d-flex', n > 0);
        };

        var statusCell = function(row) {
            if (!row.line_id) { return sq('text-danger', 'Damaged'); }
            if (!row.admin_enabled) { return sq('text-danger', lang.statusBanned); }
            if (!row.enabled) { return sq('text-body-secondary', lang.statusDisabled); }
            if (row.exp_date && row.exp_date < (Date.now() / 1000)) { return sq('text-warning', lang.statusExpired); }
            return sq('text-success', lang.statusActive);
        };

        var table = jQuery('#mags-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[2, 'desc']],
            ajax: { url: './table', data: function(d) { d.id = 'mags'; d.reseller = jQuery('#filter-reseller').val() || ''; d.filter = document.getElementById('filter-status').value; } },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'mag_id', orderable: false, searchable: false, className: 'text-center', render: function(d) { return '<input type="checkbox" class="form-check-input row-check" data-id="' + esc(d) + '"' + (selected[d] ? ' checked' : '') + '>'; } },
                { data: 'mag_id', className: 'text-center', render: function(d) { return '<a href="mag?id=' + encodeURIComponent(d) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'username', responsivePriority: 1 },
                { data: 'mac', className: 'text-nowrap', render: function(d, t, row) { return '<a href="mag?id=' + encodeURIComponent(row.mag_id) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'stb_type', className: 'text-center' },
                { data: 'owner_name', render: function(d, t, row) { return d ? (row.member_id > 0 ? '<a href="user?id=' + encodeURIComponent(row.member_id) + '" class="text-body">' + esc(d) + '</a>' : esc(d)) : ''; } },
                { data: null, className: 'text-center', render: function(d, t, row) { return statusCell(row); } },
                { data: 'active_connections', className: 'text-center', render: function(d) { return d > 0 ? sq('text-success') : sq('text-warning'); } },
                { data: 'is_trial', className: 'text-center', render: function(d) { return d ? sq('text-warning') : sq('text-body-secondary'); } },
                {
                    data: 'exp_date',
                    className: 'text-nowrap text-center',
                    render: function(d) {
                        if (!d) { return '∞'; }
                        var s = new Date(d * 1000).toLocaleDateString();
                        return d < (Date.now() / 1000) ? '<span class="text-danger">' + esc(s) + '</span>' : esc(s);
                    }
                },
                {
                    data: 'last_active',
                    className: 'text-nowrap',
                    render: function(d, t, row) {
                        if (row.active_connections > 0 && d) {
                            var link = row.stream_id ? '<a href="stream_view?id=' + encodeURIComponent(row.stream_id) + '" class="text-body">' + esc(row.stream_display_name || '') + '</a>' : esc(row.stream_display_name || '');
                            return link + '<br><small class="text-body-secondary">' + esc(lang.online) + ': ' + esc(fmtDur(Math.floor(Date.now() / 1000) - d)) + '</small>';
                        }
                        return d ? esc(new Date(d * 1000).toLocaleString()) : lang.never;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var items = '';
                        if (canEvents) { items += '<a class="dropdown-item js-event" href="javascript:void(0);" data-id="' + esc(row.mag_id) + '" data-mac="' + esc(row.mac) + '">' + esc(lang.event) + '</a>'; }
                        if (canConvert) { items += '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.mag_id) + '" data-sub="convert">' + esc(lang.convert) + '</a>'; }
                        if (canEdit) {
                            items += '<a class="dropdown-item js-edit" href="javascript:void(0);" data-id="' + esc(row.mag_id) + '">' + esc(lang.edit) + '</a>';
                            items += row.admin_enabled ?
                                '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.mag_id) + '" data-sub="ban">' + esc(lang.ban) + '</a>' :
                                '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.mag_id) + '" data-sub="unban">' + esc(lang.unban) + '</a>';
                            items += row.enabled ?
                                '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.mag_id) + '" data-sub="disable">' + esc(lang.disable) + '</a>' :
                                '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.mag_id) + '" data-sub="enable">' + esc(lang.enable) + '</a>';
                            items += '<a class="dropdown-item text-danger js-api" href="javascript:void(0);" data-id="' + esc(row.mag_id) + '" data-sub="delete">' + esc(lang.del) + '</a>';
                        }
                        if (!items) { return ''; }
                        return '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' + items + '</div></div>';
                    }
                }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        document.getElementById('filter-status').addEventListener('change', function() { table.ajax.reload(); });
        if (jQuery.fn.select2) {
            jQuery('#filter-reseller').select2({
                width: '100%', allowClear: true, placeholder: <?= json_encode($language::get('reseller')); ?>,
                ajax: {
                    url: './api', dataType: 'json', delay: 250,
                    data: function(params) { return { search: params.term, action: 'reguserlist', page: params.page }; },
                    processResults: function(data, params) { params.page = params.page || 1; return { results: data.items, pagination: { more: (params.page * 100) < data.total_count } }; },
                    cache: true
                }
            }).on('change', function() { table.ajax.reload(); });
        }

        // Single row actions.
        jQuery('#mags-table tbody').on('click', '.js-api', function() {
            var id = this.getAttribute('data-id');
            var sub = this.getAttribute('data-sub');
            var _do = function() {
                fetch('./api?action=mag&sub=' + encodeURIComponent(sub) + '&mag_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } table.ajax.reload(null, false); })
                    .catch(function() { alert(lang.error); });
            };
            if (sub === 'delete') { window.xcConfirm(lang.del + '?').then(function(ok) { if (ok) { _do(); } }); }
            else { _do(); }
        });

        // Bulk.
        jQuery('#mags-table tbody').on('change', '.row-check', function() {
            var id = this.getAttribute('data-id');
            if (this.checked) { selected[id] = true; } else { delete selected[id]; }
            updateBulk();
        });
        document.getElementById('check-all').addEventListener('change', function() {
            var on = this.checked;
            jQuery('#mags-table tbody .row-check').each(function() { this.checked = on; var id = this.getAttribute('data-id'); if (on) { selected[id] = true; } else { delete selected[id]; } });
            updateBulk();
        });
        table.on('draw', function() { document.getElementById('check-all').checked = false; });
        jQuery('.js-bulk').on('click', function() {
            var sub = this.getAttribute('data-sub');
            var ids = Object.keys(selected);
            if (!ids.length) { return; }
            window.xcConfirm((sub === 'delete' ? lang.del : sub) + ' (' + ids.length + ')?').then(function(ok) {
                if (!ok) { return; }
                fetch('./api?action=multi&type=mag&sub=' + encodeURIComponent(sub) + '&ids=' + encodeURIComponent(JSON.stringify(ids)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } selected = {}; updateBulk(); table.ajax.reload(null, false); })
                    .catch(function() { alert(lang.error); });
            });
        });

        // Edit modal.
        var editModal = document.getElementById('editModal');
        jQuery('#mags-table tbody').on('click', '.js-edit', function() {
            document.getElementById('edit-frame').src = 'mag?id=' + encodeURIComponent(this.getAttribute('data-id')) + '&modal=1';
            bootstrap.Modal.getOrCreateInstance(editModal).show();
        });
        editModal.addEventListener('hidden.bs.modal', function() { document.getElementById('edit-frame').src = 'about:blank'; table.ajax.reload(null, false); });

        // MAG event modal.
        var eventModal = document.getElementById('eventModal');
        var eventId = null;
        document.getElementById('event-type').addEventListener('change', function() {
            document.getElementById('event-msg-wrap').classList.toggle('d-none', this.value !== 'send_msg');
            document.getElementById('event-channel-wrap').classList.toggle('d-none', this.value !== 'play_channel');
        });
        jQuery('#mags-table tbody').on('click', '.js-event', function() {
            eventId = this.getAttribute('data-id');
            document.getElementById('event-mac').textContent = (this.getAttribute('data-mac') || '').toUpperCase();
            document.getElementById('event-type').value = 'send_msg';
            document.getElementById('event-type').dispatchEvent(new Event('change'));
            document.getElementById('event-message').value = '';
            document.getElementById('event-channel').value = '';
            bootstrap.Modal.getOrCreateInstance(eventModal).show();
        });
        document.getElementById('event-submit').addEventListener('click', function() {
            var type = document.getElementById('event-type').value;
            var payload = { id: eventId, type: type };
            if (type === 'send_msg') { payload.message = document.getElementById('event-message').value; }
            if (type === 'play_channel') { payload.channel = document.getElementById('event-channel').value; }
            fetch('./api?action=send_event&data=' + encodeURIComponent(JSON.stringify(payload)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(d) {
                    bootstrap.Modal.getOrCreateInstance(eventModal).hide();
                    if (!d || d.result !== true) { throw new Error('fail'); }
                })
                .catch(function() { alert(lang.error); });
        });
    })();
</script>
</body>

</html>
