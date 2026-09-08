<?php

/**
 * Reseller Enigma2 devices (Bootstrap 5). Full-parity port of admin/enigmas.php
 * adapted to the reseller contract: clean-JSON keyed serverSide DataTable
 * (ResellerTableRenderer::handleEnigmas emits data-only rows), with the status
 * icon, online/trial dots, expiration cell, IP whois link and the per-row action
 * dropdown all rendered client-side.
 *
 * Reseller differences vs admin (the reseller API/renderer simply does not expose
 * these): no bulk toolbar (reseller has no `multi` endpoint), no ban/unban (the
 * reseller cannot toggle admin_enabled) and no fingerprint; no last-connection
 * column (the reseller enigmas query does not fetch it). Edit navigates to
 * `enigma?id=…` (the reseller Enigma2 editor has no iframe-modal shell). Row
 * actions are wired ONLY to endpoints ResellerApiDispatcher exposes:
 * `action=enigma&sub=enable|disable|delete|convert|reset_isp|kill_line`. Convert
 * is gated by `create_line`, kill by `reseller_client_connection_logs`. The owner
 * filter is a static select2 built from the reseller's report tree. Permission
 * gating uses $rPermissions['create_enigma'], not Authorization::check.
 */

use XcVm\Core\Http\RequestManager;

if (empty($rPermissions['create_enigma'])):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('reseller');
    echo '</body></html>';
    return;
endif;

$rCanConvert = !empty($rPermissions['create_line']);
$rCanKill = !empty($rPermissions['reseller_client_connection_logs']);

// Deep-link owner filter (?owner=ID) — pre-selects the matching static option.
$rSelectedOwner = RequestManager::has('owner') ? (string) RequestManager::get('owner') : '';
$rSelectedFilter = RequestManager::has('filter') ? (int) RequestManager::get('filter') : 0;

// Reseller Enigma2 status filter values handled by ResellerTableRenderer::handleEnigmas.
$rStatusFilters = [1 => $language::get('active'), 2 => $language::get('disabled'), 3 => $language::get('expired'), 4 => $language::get('trial')];

$rDirectReports = (array) ($rPermissions['direct_reports'] ?? []);
$rAllReports = (array) ($rPermissions['all_reports'] ?? []);
$rReportUsers = (array) ($rPermissions['users'] ?? []);
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('enigma_devices'); ?></h5>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-lg-5">
                <label class="form-label" for="filter-search"><?= $language::get('search'); ?></label>
                <input type="text" id="filter-search" class="form-control" autocomplete="off" placeholder="<?= htmlspecialchars((string) $language::get('search_devices'), ENT_QUOTES); ?>">
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-reseller"><?= $language::get('owner'); ?></label>
                <select id="filter-reseller" class="form-select">
                    <optgroup label="Global">
                        <option value=""<?= $rSelectedOwner === '' ? ' selected' : ''; ?>><?= $language::get('all'); ?></option>
                        <option value="<?= (int) $rUserInfo['id']; ?>"<?= ($rSelectedOwner !== '' && $rSelectedOwner == $rUserInfo['id']) ? ' selected' : ''; ?>>My Devices</option>
                    </optgroup>
                    <?php if (count($rDirectReports) > 0): ?>
                        <optgroup label="Direct Reports">
                            <?php foreach ($rDirectReports as $rUserID): ?>
                                <option value="<?= (int) $rUserID; ?>"<?= ($rSelectedOwner !== '' && $rSelectedOwner == $rUserID) ? ' selected' : ''; ?>><?= htmlspecialchars((string) ($rReportUsers[$rUserID]['username'] ?? $rUserID), ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if (count($rAllReports) > count($rDirectReports)): ?>
                        <optgroup label="Indirect Reports">
                            <?php foreach ($rAllReports as $rUserID): ?>
                                <?php if (!in_array($rUserID, $rDirectReports)): ?>
                                    <option value="<?= (int) $rUserID; ?>"<?= ($rSelectedOwner !== '' && $rSelectedOwner == $rUserID) ? ' selected' : ''; ?>><?= htmlspecialchars((string) ($rReportUsers[$rUserID]['username'] ?? $rUserID), ENT_QUOTES); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-status"><?= $language::get('status'); ?></label>
                <select id="filter-status" class="form-select">
                    <option value=""><?= $language::get('no_filter'); ?></option>
                    <?php foreach ($rStatusFilters as $rK => $rV): ?>
                        <option value="<?= $rK; ?>" <?= $rSelectedFilter === $rK ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rV, ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="e2-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('mac_address'); ?></th>
                    <th><?= $language::get('ip'); ?></th>
                    <th><?= $language::get('owner'); ?></th>
                    <th class="text-center"><?= $language::get('status'); ?></th>
                    <th class="text-center"><?= $language::get('online'); ?></th>
                    <th class="text-center"><?= $language::get('trial'); ?></th>
                    <th class="text-center"><?= $language::get('expiration'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
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

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    (function() {
        var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
        var isLocal = function(ip) { return !ip || ip === '127.0.0.1' || ip === '::1'; };
        var sq = function(cls, title) { return '<i class="icon-base ti tabler-square-filled ' + cls + '" title="' + esc(title || '') + '"></i>'; };
        var canConvert = <?= $rCanConvert ? 'true' : 'false'; ?>, canKill = <?= $rCanKill ? 'true' : 'false'; ?>;
        var lang = {
            convert: <?= json_encode($language::get('convert_to_line')); ?>,
            edit: <?= json_encode($language::get('edit')); ?>,
            resetIsp: 'Reset ISP Lock',
            kill: <?= json_encode($language::get('kill') ?: 'Kill Connections'); ?>,
            enable: <?= json_encode($language::get('enable')); ?>,
            disable: <?= json_encode($language::get('disable')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            statusActive: <?= json_encode($language::get('active')); ?>,
            statusBanned: <?= json_encode($language::get('banned')); ?>,
            statusDisabled: <?= json_encode($language::get('disabled')); ?>,
            statusExpired: <?= json_encode($language::get('expired')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>,
            confirmDelete: 'Are you sure you want to delete this device?',
            confirmKill: 'Are you sure you want to kill all connections for this device?'
        };

        var statusCell = function(row) {
            if (!row.admin_enabled) { return sq('text-danger', lang.statusBanned); }
            if (!row.enabled) { return sq('text-body-secondary', lang.statusDisabled); }
            if (row.exp_date && row.exp_date < (Date.now() / 1000)) { return sq('text-warning', lang.statusExpired); }
            return sq('text-success', lang.statusActive);
        };

        var table = jQuery('#e2-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'desc']],
            searchDelay: 400,
            lengthMenu: [10, 25, 50, 250, 500, 1000],
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 25); ?>,
            ajax: {
                url: './table',
                data: function(d) { d.id = 'enigmas'; d.reseller = jQuery('#filter-reseller').val() || ''; d.filter = document.getElementById('filter-status').value; }
            },
            columnDefs: [{ orderable: false, targets: [0, 10] }],
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'device_id', className: 'text-center', render: function(d) { return '<a href="enigma?id=' + encodeURIComponent(d) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'username', responsivePriority: 1 },
                { data: 'mac', className: 'text-nowrap', render: function(d, t, row) { return '<a href="enigma?id=' + encodeURIComponent(row.device_id) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'public_ip', className: 'text-nowrap', render: function(d) { if (isLocal(d)) { return '<span class="text-body-secondary">' + esc(d || '') + '</span>'; } return '<a href="javascript:void(0);" class="text-body js-whois" data-ip="' + esc(d) + '">' + esc(d) + '</a>'; } },
                { data: 'owner_name', render: function(d, t, row) { if (!d) { return ''; } var body = row.member_id > 0 ? '<a href="user?id=' + encodeURIComponent(row.member_id) + '" class="text-body">' + esc(d) + '</a>' : esc(d); return row.indirect ? body + '<br><small class="text-body-secondary">(indirect)</small>' : body; } },
                { data: null, className: 'text-center', render: function(d, t, row) { return statusCell(row); } },
                { data: 'active_connections', className: 'text-center', render: function(d) { return d > 0 ? sq('text-success') : sq('text-warning'); } },
                { data: 'is_trial', className: 'text-center', render: function(d) { return d ? sq('text-warning') : sq('text-body-secondary'); } },
                {
                    data: 'exp_date',
                    className: 'text-nowrap text-center',
                    render: function(d) {
                        if (!d) { return '<span class="fs-4">&infin;</span>'; }
                        var s = new Date(d * 1000).toLocaleDateString();
                        return d < (Date.now() / 1000) ? '<span class="text-danger">' + esc(s) + '</span>' : esc(s);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var items = '';
                        if (row.notes) { items += '<h6 class="dropdown-header text-wrap" style="max-width:18rem">' + esc(row.notes) + '</h6><div class="dropdown-divider"></div>'; }
                        items += '<a class="dropdown-item" href="enigma?id=' + encodeURIComponent(row.device_id) + '">' + esc(lang.edit) + '</a>';
                        if (canConvert) { items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="convert" data-id="' + esc(row.device_id) + '">' + esc(lang.convert) + '</a>'; }
                        if (row.is_isplock) { items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="reset_isp" data-id="' + esc(row.device_id) + '">' + esc(lang.resetIsp) + '</a>'; }
                        if (canKill && row.active_connections > 0) { items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="kill_line" data-id="' + esc(row.device_id) + '">' + esc(lang.kill) + '</a>'; }
                        items += row.enabled ?
                            '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="disable" data-id="' + esc(row.device_id) + '">' + esc(lang.disable) + '</a>' :
                            '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="enable" data-id="' + esc(row.device_id) + '">' + esc(lang.enable) + '</a>';
                        items += '<a class="dropdown-item text-danger js-act" href="javascript:void(0);" data-sub="delete" data-id="' + esc(row.device_id) + '">' + esc(lang.del) + '</a>';
                        return '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' + items + '</div></div>';
                    }
                }
            ],
            layout: { topStart: 'pageLength', topEnd: null }
        });

        // Filters. Owner filter is a static select2 (report tree), not an ajax search.
        jQuery('#filter-reseller').select2({ allowClear: false, width: '100%' }).on('change', function() { table.ajax.reload(); });
        document.getElementById('filter-status').addEventListener('change', function() { table.ajax.reload(); });
        var searchTimer;
        document.getElementById('filter-search').addEventListener('keyup', function() {
            var v = this.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() { table.search(v).draw(); }, 400);
        });

        // Row single actions -> reseller api (action=enigma&sub=…&e2_id=…).
        var rowApi = function(id, sub) {
            return fetch('./api?action=enigma&sub=' + encodeURIComponent(sub) + '&e2_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } });
        };
        jQuery('#e2-table tbody').on('click', '.js-act', function() {
            var sub = this.getAttribute('data-sub'), id = this.getAttribute('data-id');
            var go = function() { rowApi(id, sub).then(function() { table.ajax.reload(null, false); }).catch(function() { xcToast(lang.error, 'error'); }); };
            if (sub === 'delete') { xcConfirm(lang.confirmDelete).then(function(ok) { if (ok) { go(); } }); }
            else if (sub === 'kill_line') { xcConfirm(lang.confirmKill).then(function(ok) { if (ok) { go(); } }); }
            else { go(); }
        });

        // Whois modal -> reseller api (action=ip_whois&isp=1&ip=…) returns {result, data}.
        jQuery('#e2-table tbody').on('click', '.js-whois', function() {
            var ip = this.getAttribute('data-ip'), body = document.getElementById('whois-body');
            document.getElementById('whois-ip').textContent = ip;
            body.innerHTML = '<div class="text-center py-3"><span class="spinner-border" role="status"></span></div>';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('whoisModal')).show();
            fetch('./api?action=ip_whois&isp=1&ip=' + encodeURIComponent(ip), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(res) {
                    var w = (res && res.data) ? res.data : {};
                    var rows = [], add = function(label, val) { if (val) { rows.push('<dt class="col-4 text-body-secondary">' + esc(label) + '</dt><dd class="col-8">' + esc(val) + '</dd>'); } };
                    add(<?= json_encode($language::get('country')); ?>, w.country && w.country.names && w.country.names.en);
                    add(<?= json_encode($language::get('city')); ?>, w.city && w.city.names && w.city.names.en);
                    add(<?= json_encode($language::get('isp')); ?>, w.isp && (w.isp.isp || w.isp.organization));
                    add('ASN', w.isp && w.isp.autonomous_system_number);
                    body.innerHTML = rows.length ? '<dl class="row mb-0">' + rows.join('') + '</dl>' : '<div class="text-center text-body-secondary py-2">—</div>';
                })
                .catch(function() { body.innerHTML = '<div class="alert alert-danger mb-0">' + esc(lang.error) + '</div>'; });
        });
    })();
</script>
</body>

</html>
