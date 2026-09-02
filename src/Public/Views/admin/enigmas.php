<?php

/**
 * Enigma2 devices (Vuexy). Clean-JSON table pattern: TableController::handleEnigmas
 * returns structured rows (connection / last-activity gathering server-side) and
 * this page renders the cells client-side via datatables-bs5 columns[].render.
 * Bulk-select (action=multi&type=enigma), row actions (convert, fingerprint, edit
 * modal, ban/unban, enable/disable, delete) + IP whois + status/reseller filters.
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'manage_e2')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_e2');
$rCanConvert = Authorization::check('adv', 'edit_user');
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('enigma_devices'); ?></h5>
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
        <table id="e2-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><input type="checkbox" class="form-check-input" id="check-all"></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('mac'); ?></th>
                    <th><?= $language::get('ip'); ?></th>
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

<!-- Whois -->
<div class="modal fade" id="whoisModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('login_logs_whois'); ?> — <span id="whois-ip"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="whois-body"></div>
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
        var isLocal = function(ip) { return !ip || ip === '127.0.0.1' || ip === '::1'; };
        var sq = function(cls, title) { return '<i class="icon-base ti tabler-square-filled ' + cls + '" title="' + esc(title || '') + '"></i>'; };
        var fmtDur = function(sec) {
            sec = Math.max(0, sec);
            if (sec >= 86400) { return Math.floor(sec / 86400) + 'd ' + (Math.floor(sec / 3600) % 24) + 'h'; }
            if (sec >= 3600) { return Math.floor(sec / 3600) + 'h ' + (Math.floor(sec / 60) % 60) + 'm'; }
            return (Math.floor(sec / 60) % 60) + 'm ' + (sec % 60) + 's';
        };
        var canEdit = <?= $rCanEdit ? 'true' : 'false'; ?>,
            canConvert = <?= $rCanConvert ? 'true' : 'false'; ?>;
        var lang = {
            convert: <?= json_encode($language::get('convert_to_line')); ?>,
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
            var n = Object.keys(selected).length, bar = document.getElementById('bulk-bar');
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

        var table = jQuery('#e2-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[2, 'desc']],
            ajax: { url: './table', data: function(d) { d.id = 'enigmas'; d.reseller = jQuery('#filter-reseller').val() || ''; d.filter = document.getElementById('filter-status').value; } },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'device_id', orderable: false, searchable: false, className: 'text-center', render: function(d) { return '<input type="checkbox" class="form-check-input row-check" data-id="' + esc(d) + '"' + (selected[d] ? ' checked' : '') + '>'; } },
                { data: 'device_id', className: 'text-center', render: function(d) { return '<a href="enigma?id=' + encodeURIComponent(d) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'username', responsivePriority: 1 },
                { data: 'mac', className: 'text-nowrap', render: function(d, t, row) { return '<a href="enigma?id=' + encodeURIComponent(row.device_id) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'public_ip', className: 'text-nowrap', render: function(d) { if (isLocal(d)) { return '<span class="text-body-secondary">' + esc(d || '') + '</span>'; } return '<a href="javascript:void(0);" class="text-body js-whois" data-ip="' + esc(d) + '">' + esc(d) + '</a>'; } },
                { data: 'owner_name', render: function(d, t, row) { return d ? (row.member_id > 0 ? '<a href="user?id=' + encodeURIComponent(row.member_id) + '" class="text-body">' + esc(d) + '</a>' : esc(d)) : ''; } },
                { data: null, className: 'text-center', render: function(d, t, row) { return statusCell(row); } },
                { data: 'active_connections', className: 'text-center', render: function(d) { return d > 0 ? sq('text-success') : sq('text-warning'); } },
                { data: 'is_trial', className: 'text-center', render: function(d) { return d ? sq('text-warning') : sq('text-body-secondary'); } },
                { data: 'exp_date', className: 'text-nowrap text-center', render: function(d) { if (!d) { return '∞'; } var s = new Date(d * 1000).toLocaleDateString(); return d < (Date.now() / 1000) ? '<span class="text-danger">' + esc(s) + '</span>' : esc(s); } },
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
                        if (canConvert) { items += '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.device_id) + '" data-sub="convert">' + esc(lang.convert) + '</a>'; }
                        if (canEdit) {
                            items += '<a class="dropdown-item js-edit" href="javascript:void(0);" data-id="' + esc(row.device_id) + '">' + esc(lang.edit) + '</a>';
                            items += row.admin_enabled ? '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.device_id) + '" data-sub="ban">' + esc(lang.ban) + '</a>' : '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.device_id) + '" data-sub="unban">' + esc(lang.unban) + '</a>';
                            items += row.enabled ? '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.device_id) + '" data-sub="disable">' + esc(lang.disable) + '</a>' : '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.device_id) + '" data-sub="enable">' + esc(lang.enable) + '</a>';
                            items += '<a class="dropdown-item text-danger js-api" href="javascript:void(0);" data-id="' + esc(row.device_id) + '" data-sub="delete">' + esc(lang.del) + '</a>';
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
                ajax: { url: './api', dataType: 'json', delay: 250, data: function(params) { return { search: params.term, action: 'reguserlist', page: params.page }; }, processResults: function(data, params) { params.page = params.page || 1; return { results: data.items, pagination: { more: (params.page * 100) < data.total_count } }; }, cache: true }
            }).on('change', function() { table.ajax.reload(); });
        }

        jQuery('#e2-table tbody').on('click', '.js-api', function() {
            var id = this.getAttribute('data-id'), sub = this.getAttribute('data-sub');
            var _do = function() {
                fetch('./api?action=enigma&sub=' + encodeURIComponent(sub) + '&e2_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } table.ajax.reload(null, false); })
                    .catch(function() { alert(lang.error); });
            };
            if (sub === 'delete') { window.xcConfirm(lang.del + '?').then(function(ok) { if (ok) { _do(); } }); }
            else { _do(); }
        });

        jQuery('#e2-table tbody').on('change', '.row-check', function() {
            var id = this.getAttribute('data-id');
            if (this.checked) { selected[id] = true; } else { delete selected[id]; }
            updateBulk();
        });
        document.getElementById('check-all').addEventListener('change', function() {
            var on = this.checked;
            jQuery('#e2-table tbody .row-check').each(function() { this.checked = on; var id = this.getAttribute('data-id'); if (on) { selected[id] = true; } else { delete selected[id]; } });
            updateBulk();
        });
        table.on('draw', function() { document.getElementById('check-all').checked = false; });
        jQuery('.js-bulk').on('click', function() {
            var sub = this.getAttribute('data-sub'), ids = Object.keys(selected);
            if (!ids.length) { return; }
            window.xcConfirm((sub === 'delete' ? lang.del : sub) + ' (' + ids.length + ')?').then(function(ok) {
                if (!ok) { return; }
                fetch('./api?action=multi&type=enigma&sub=' + encodeURIComponent(sub) + '&ids=' + encodeURIComponent(JSON.stringify(ids)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } selected = {}; updateBulk(); table.ajax.reload(null, false); })
                    .catch(function() { alert(lang.error); });
            });
        });

        var editModal = document.getElementById('editModal');
        jQuery('#e2-table tbody').on('click', '.js-edit', function() {
            document.getElementById('edit-frame').src = 'enigma?id=' + encodeURIComponent(this.getAttribute('data-id')) + '&modal=1';
            bootstrap.Modal.getOrCreateInstance(editModal).show();
        });
        editModal.addEventListener('hidden.bs.modal', function() { document.getElementById('edit-frame').src = 'about:blank'; table.ajax.reload(null, false); });

        jQuery('#e2-table tbody').on('click', '.js-whois', function() {
            var ip = this.getAttribute('data-ip'), body = document.getElementById('whois-body');
            document.getElementById('whois-ip').textContent = ip;
            body.innerHTML = '<div class="text-center py-3"><span class="spinner-border" role="status"></span></div>';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('whoisModal')).show();
            fetch('./api?action=ip_whois&isp=1&ip=' + encodeURIComponent(ip), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(w) {
                    var rows = [], add = function(label, val) { if (val) { rows.push('<dt class="col-4 text-body-secondary">' + esc(label) + '</dt><dd class="col-8">' + esc(val) + '</dd>'); } };
                    add(<?= json_encode($language::get('country')); ?>, w && w.country && w.country.names && w.country.names.en);
                    add(<?= json_encode($language::get('city')); ?>, w && w.city && w.city.names && w.city.names.en);
                    add(<?= json_encode($language::get('isp')); ?>, w && w.isp && (w.isp.isp || w.isp.organization));
                    add('ASN', w && w.isp && w.isp.autonomous_system_number);
                    body.innerHTML = rows.length ? '<dl class="row mb-0">' + rows.join('') + '</dl>' : '<div class="text-center text-body-secondary py-2">—</div>';
                })
                .catch(function() { body.innerHTML = '<div class="alert alert-danger mb-0">' + esc(lang.error) + '</div>'; });
        });
    })();
</script>
</body>

</html>
