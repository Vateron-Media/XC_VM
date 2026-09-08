<?php

/**
 * Reseller Users (Bootstrap 5). Full-parity port of admin/users.php adapted to the
 * reseller contract: clean-JSON keyed serverSide DataTable
 * (ResellerTableRenderer::handleRegUsers emits data-only rows), with the status
 * icon, credit / line-count badges, IP whois link and the per-row action dropdown
 * all rendered client-side.
 *
 * Reseller differences vs admin (the reseller API/renderer simply does not expose
 * these): no per-type line/mag/enigma count columns and no group/type column (the
 * reseller reg_users query only computes `user_count` = number of lines); the
 * status filter offers only Active / Disabled (the renderer accepts filter 1/2);
 * the owner filter is a static select2 built from the reseller's report tree
 * (Global / Direct Reports / Indirect Reports) mapped to the `reseller` (owner_id)
 * param, not an ajax reguser search; edit navigates to `user?id=…` (the reseller
 * user editor has no iframe-modal shell). Row actions are wired ONLY to endpoints
 * ResellerApiDispatcher exposes: `action=reg_user&sub=enable|disable|delete`
 * (delete gated by `delete_users`) and `action=adjust_credits`. Permission gating
 * uses $rPermissions['create_sub_resellers'], not Authorization::check.
 */

use XcVm\Core\Http\RequestManager;

if (empty($rPermissions['create_sub_resellers'])):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('reseller');
    echo '</body></html>';
    return;
endif;

$rCanDelete = !empty($rPermissions['delete_users']);

// Deep-link owner filter (?owner=ID) — pre-selects the matching static option.
$rSelectedOwner = RequestManager::has('owner') ? (string) RequestManager::get('owner') : '';
$rSelectedFilter = RequestManager::has('filter') ? (string) RequestManager::get('filter') : '';

$rDirectReports = (array) ($rPermissions['direct_reports'] ?? []);
$rAllReports = (array) ($rPermissions['all_reports'] ?? []);
$rReportUsers = (array) ($rPermissions['users'] ?? []);
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('users'); ?></h5>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-lg-5">
                <label class="form-label" for="filter-search"><?= $language::get('search'); ?></label>
                <input type="text" id="filter-search" class="form-control" autocomplete="off" placeholder="<?= htmlspecialchars((string) $language::get('search'), ENT_QUOTES); ?>">
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-reseller"><?= $language::get('owner'); ?></label>
                <select id="filter-reseller" class="form-select">
                    <optgroup label="Global">
                        <option value=""<?= $rSelectedOwner === '' ? ' selected' : ''; ?>><?= $language::get('all'); ?></option>
                        <option value="<?= (int) $rUserInfo['id']; ?>"<?= ($rSelectedOwner !== '' && $rSelectedOwner == $rUserInfo['id']) ? ' selected' : ''; ?>>My Users</option>
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
                    <option value="" <?= $rSelectedFilter === '' ? 'selected' : ''; ?>><?= $language::get('no_filter'); ?></option>
                    <option value="1" <?= $rSelectedFilter === '1' ? 'selected' : ''; ?>><?= $language::get('enabled'); ?></option>
                    <option value="2" <?= $rSelectedFilter === '2' ? 'selected' : ''; ?>><?= $language::get('disabled'); ?></option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="users-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('owner'); ?></th>
                    <th><?= $language::get('ip'); ?></th>
                    <th class="text-center"><?= $language::get('status'); ?></th>
                    <th class="text-center"><?= $language::get('credits'); ?></th>
                    <th class="text-center"><?= $language::get('lines'); ?></th>
                    <th class="text-center"><?= $language::get('last_login'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- Adjust credits -->
<div class="modal fade" id="creditsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('adjust_credits'); ?> — <span id="credits-user"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="credits-amount"><?= $language::get('credits'); ?></label>
                    <input type="number" class="form-control" id="credits-amount" value="0">
                </div>
                <div class="mb-0">
                    <label class="form-label" for="credits-reason"><?= $language::get('reason'); ?></label>
                    <input type="text" class="form-control" id="credits-reason" autocomplete="off">
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="credits-submit"><?= $language::get('adjust_credits'); ?></button>
            </div>
        </div>
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
        var canDelete = <?= $rCanDelete ? 'true' : 'false'; ?>;
        var lang = {
            edit: <?= json_encode($language::get('edit')); ?>,
            adjust: <?= json_encode($language::get('adjust_credits')); ?>,
            enable: <?= json_encode($language::get('enable')); ?>,
            disable: <?= json_encode($language::get('disable')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            never: <?= json_encode($language::get('never') ?: 'Never'); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>,
            confirmDelete: 'Are you sure you want to delete this user?'
        };

        var countBadge = function(n, url) {
            if (n > 0) { return '<a href="' + esc(url) + '" class="badge bg-label-info">' + Number(n).toLocaleString() + '</a>'; }
            return '<span class="badge bg-label-secondary">0</span>';
        };
        var ownerLink = function(id, name, indirect) {
            if (!name) { return ''; }
            var body = id > 0 ? '<a href="user?id=' + encodeURIComponent(id) + '" class="text-body">' + esc(name) + '</a>' : esc(name);
            return indirect ? body + '<br><small class="text-body-secondary">(indirect)</small>' : body;
        };

        var table = jQuery('#users-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'desc']],
            searchDelay: 400,
            lengthMenu: [10, 25, 50, 250, 500, 1000],
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 25); ?>,
            ajax: {
                url: './table',
                data: function(d) { d.id = 'reg_users'; d.reseller = jQuery('#filter-reseller').val() || ''; d.filter = document.getElementById('filter-status').value; }
            },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'id', className: 'text-center', render: function(d) { return '<a href="user?id=' + encodeURIComponent(d) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'username', responsivePriority: 1, render: function(d, t, row) { var body = '<a href="user?id=' + encodeURIComponent(row.id) + '" class="text-body fw-medium">' + esc(d) + '</a>'; return row.indirect ? body + '<br><small class="text-body-secondary">(indirect)</small>' : body; } },
                { data: 'owner_username', render: function(d, t, row) { return ownerLink(row.owner_id, d, row.owner_indirect); } },
                {
                    data: 'ip',
                    className: 'text-nowrap',
                    render: function(d) {
                        if (isLocal(d)) { return '<span class="text-body-secondary">' + esc(d || '') + '</span>'; }
                        return '<a href="javascript:void(0);" class="text-body js-whois" data-ip="' + esc(d) + '">' + esc(d) + '</a>';
                    }
                },
                {
                    data: 'status',
                    className: 'text-center',
                    render: function(d) {
                        return d === 1 ? '<i class="icon-base ti tabler-square-filled text-success" title="' + esc(lang.enable) + '"></i>' :
                            '<i class="icon-base ti tabler-square-filled text-body-secondary" title="' + esc(lang.disable) + '"></i>';
                    }
                },
                { data: 'credits', className: 'text-center', render: function(d, t, row) { return row.is_reseller ? '<span class="badge bg-label-primary">' + Number(d).toLocaleString() + '</span>' : '<span class="badge bg-label-secondary">-</span>'; } },
                { data: 'user_count', className: 'text-center', render: function(d, t, row) { return countBadge(d, 'lines?owner=' + row.id); } },
                { data: 'last_login', className: 'text-nowrap text-center', render: function(d) { return d ? esc(d) : '<span class="text-body-secondary">' + esc(lang.never) + '</span>'; } },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var items = '';
                        items += '<a class="dropdown-item" href="user?id=' + encodeURIComponent(row.id) + '">' + esc(lang.edit) + '</a>';
                        if (row.is_reseller) {
                            items += '<a class="dropdown-item js-credits" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-user="' + esc(row.username) + '">' + esc(lang.adjust) + '</a>';
                        }
                        items += row.status === 1 ?
                            '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-sub="disable">' + esc(lang.disable) + '</a>' :
                            '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-sub="enable">' + esc(lang.enable) + '</a>';
                        if (canDelete) {
                            items += '<a class="dropdown-item text-danger js-api" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-sub="delete">' + esc(lang.del) + '</a>';
                        }
                        var note = row.notes ? '<i class="icon-base ti tabler-note text-primary me-2" title="' + esc(row.notes) + '"></i>' : '';
                        return '<div class="d-inline-flex align-items-center">' + note +
                            '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button>' +
                            '<div class="dropdown-menu dropdown-menu-end">' + items + '</div></div></div>';
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

        // Row actions (enable/disable/delete) -> reseller api (action=reg_user&sub=…&user_id=…).
        jQuery('#users-table tbody').on('click', '.js-api', function() {
            var id = this.getAttribute('data-id'), sub = this.getAttribute('data-sub');
            var go = function() {
                fetch('./api?action=reg_user&sub=' + encodeURIComponent(sub) + '&user_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } table.ajax.reload(null, false); })
                    .catch(function() { xcToast(lang.error, 'error'); });
            };
            if (sub === 'delete') { xcConfirm(lang.confirmDelete).then(function(ok) { if (ok) { go(); } }); }
            else { go(); }
        });

        // Adjust credits modal -> reseller api (action=adjust_credits&id=…&credits=…&reason=…).
        var creditsModal = document.getElementById('creditsModal');
        var creditsId = null;
        jQuery('#users-table tbody').on('click', '.js-credits', function() {
            creditsId = this.getAttribute('data-id');
            document.getElementById('credits-user').textContent = this.getAttribute('data-user');
            document.getElementById('credits-amount').value = 0;
            document.getElementById('credits-reason').value = '';
            bootstrap.Modal.getOrCreateInstance(creditsModal).show();
        });
        document.getElementById('credits-submit').addEventListener('click', function() {
            var amount = document.getElementById('credits-amount').value;
            var reason = document.getElementById('credits-reason').value;
            fetch('./api?action=adjust_credits&id=' + encodeURIComponent(creditsId) + '&reason=' + encodeURIComponent(reason) + '&credits=' + encodeURIComponent(amount), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(dt) {
                    bootstrap.Modal.getOrCreateInstance(creditsModal).hide();
                    if (!dt || dt.result !== true) { throw new Error('fail'); }
                    table.ajax.reload(null, false);
                })
                .catch(function() { xcToast(lang.error, 'error'); });
        });

        // Whois modal -> reseller api (action=ip_whois&isp=1&ip=…) returns {result, data}.
        jQuery('#users-table tbody').on('click', '.js-whois', function() {
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
