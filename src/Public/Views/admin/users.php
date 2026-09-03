<?php

/**
 * Registered users / resellers (Bootstrap 5). Clean-JSON table pattern:
 * TableController::handleRegUsers returns structured rows (batch line/mag/e2 and
 * group counts resolved server-side) and this page renders the cells client-side
 * via datatables-bs5 columns[].render. Row actions (edit modal, adjust credits,
 * enable/disable/delete) and the reseller/group/status filters are wired inline.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;

if (!Authorization::check('adv', 'mng_regusers')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_reguser');
$rPreOwner = RequestManager::has('owner') ? UserRepository::getRegisteredUserById((int) RequestManager::get('owner')) : null;
$rPreFilter = RequestManager::has('filter') ? (string) RequestManager::get('filter') : '';
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('users'); ?></h5>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-reseller"><?= $language::get('reseller'); ?></label>
                <select id="filter-reseller" class="form-select">
                    <?php if ($rPreOwner): ?>
                        <option value="<?= (int) $rPreOwner['id']; ?>" selected><?= htmlspecialchars((string) $rPreOwner['username'], ENT_QUOTES); ?></option>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-status"><?= $language::get('status'); ?></label>
                <select id="filter-status" class="form-select">
                    <option value="" <?= $rPreFilter === '' ? 'selected' : ''; ?>><?= $language::get('no_filter'); ?></option>
                    <option value="-1" <?= $rPreFilter === '-1' ? 'selected' : ''; ?>><?= $language::get('enabled'); ?></option>
                    <option value="-2" <?= $rPreFilter === '-2' ? 'selected' : ''; ?>><?= $language::get('disabled'); ?></option>
                    <?php foreach (GroupService::getAll() as $rGroup): ?>
                        <option value="<?= (int) $rGroup['group_id']; ?>" <?= $rPreFilter === (string) (int) $rGroup['group_id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rGroup['group_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
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
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('type'); ?></th>
                    <th><?= $language::get('credits'); ?></th>
                    <th><?= $language::get('users'); ?></th>
                    <th><?= $language::get('lines'); ?></th>
                    <th><?= $language::get('mags'); ?></th>
                    <th><?= $language::get('enigmas'); ?></th>
                    <th><?= $language::get('last_login'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
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
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="credits-submit"><?= $language::get('adjust_credits'); ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Edit (loads the edit form in a modal) -->
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
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var esc = function(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : String(s));
            return d.innerHTML;
        };
        var isLocal = function(ip) {
            return !ip || ip === '127.0.0.1' || ip === '::1';
        };
        var canEdit = <?= $rCanEdit ? 'true' : 'false'; ?>;
        var lang = {
            edit: <?= json_encode($language::get('edit')); ?>,
            adjust: <?= json_encode($language::get('adjust_credits')); ?>,
            enable: <?= json_encode($language::get('enable')); ?>,
            disable: <?= json_encode($language::get('disable')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            adjusted: <?= json_encode($language::get('credits_adjusted')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>,
            delConfirm: <?= json_encode($language::get('login_logs_block_confirm')); ?>
        };

        var countBadge = function(n, url) {
            if (n > 0) {
                return '<a href="' + esc(url) + '" class="badge bg-label-info">' + Number(n).toLocaleString() + '</a>';
            }
            return '<span class="badge bg-label-secondary">0</span>';
        };

        var table = jQuery('#users-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'desc']],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'reg_users';
                    d.reseller = jQuery('#filter-reseller').val() || '';
                    d.filter = document.getElementById('filter-status').value;
                }
            },
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'id', className: 'text-center', render: function(d) { return '<a href="user?id=' + encodeURIComponent(d) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'username', responsivePriority: 1, render: function(d, t, row) { return '<a href="user?id=' + encodeURIComponent(row.id) + '" class="text-body fw-medium">' + esc(d) + '</a>'; } },
                { data: 'owner_username', render: function(d, t, row) { return d ? '<a href="user?id=' + encodeURIComponent(row.owner_id) + '" class="text-body">' + esc(d) + '</a>' : ''; } },
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
                {
                    data: 'group_name',
                    className: 'text-center',
                    render: function(d, t, row) {
                        return '<a href="users?filter=' + encodeURIComponent(row.member_group_id) + '" class="badge bg-label-dark">' + esc(d) + '</a>';
                    }
                },
                {
                    data: 'credits',
                    className: 'text-center',
                    render: function(d, t, row) {
                        return row.is_reseller ? '<span class="badge bg-label-primary">' + Number(d).toLocaleString() + '</span>' : '<span class="badge bg-label-secondary">-</span>';
                    }
                },
                { data: 'user_count', className: 'text-center', render: function(d, t, row) { return countBadge(d, 'users?owner=' + row.id); } },
                { data: 'user_lines', className: 'text-center', render: function(d, t, row) { return countBadge(d, 'lines?owner=' + row.id); } },
                { data: 'mag_lines', className: 'text-center', render: function(d, t, row) { return countBadge(d, 'mags?owner=' + row.id); } },
                { data: 'e2_lines', className: 'text-center', render: function(d, t, row) { return countBadge(d, 'enigmas?owner=' + row.id); } },
                { data: 'last_login', className: 'text-nowrap text-center', render: function(d) { return esc(d); } },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        if (!canEdit) {
                            if (row.notes) {
                                return '<i class="icon-base ti tabler-note text-primary" title="' + esc(row.notes) + '"></i>';
                            }
                            return '';
                        }
                        var items = '';
                        items += '<a class="dropdown-item js-edit" href="javascript:void(0);" data-id="' + esc(row.id) + '">' + esc(lang.edit) + '</a>';
                        if (row.is_reseller) {
                            items += '<a class="dropdown-item js-credits" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-user="' + esc(row.username) + '" data-credits="' + esc(row.credits) + '">' + esc(lang.adjust) + '</a>';
                        }
                        items += row.status === 1 ?
                            '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-sub="disable">' + esc(lang.disable) + '</a>' :
                            '<a class="dropdown-item js-api" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-sub="enable">' + esc(lang.enable) + '</a>';
                        items += '<a class="dropdown-item text-danger js-api" href="javascript:void(0);" data-id="' + esc(row.id) + '" data-sub="delete">' + esc(lang.del) + '</a>';
                        var note = row.notes ? '<i class="icon-base ti tabler-note text-primary me-2" title="' + esc(row.notes) + '"></i>' : '';
                        return '<div class="d-inline-flex align-items-center">' + note +
                            '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button>' +
                            '<div class="dropdown-menu dropdown-menu-end">' + items + '</div></div></div>';
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

        // Row actions (enable/disable/delete).
        jQuery('#users-table tbody').on('click', '.js-api', function() {
            var id = this.getAttribute('data-id');
            var sub = this.getAttribute('data-sub');
            var _do = function() {
                fetch('./api?action=reg_user&sub=' + encodeURIComponent(sub) + '&user_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } table.ajax.reload(null, false); })
                    .catch(function() { alert(lang.error); });
            };
            if (sub === 'delete') { window.xcConfirm(lang.delConfirm.replace(/IP address/i, 'user')).then(function(ok) { if (ok) { _do(); } }); }
            else { _do(); }
        });

        // Edit modal (iframe of the edit form).
        var editModal = document.getElementById('editModal');
        jQuery('#users-table tbody').on('click', '.js-edit', function() {
            document.getElementById('edit-frame').src = 'user?id=' + encodeURIComponent(this.getAttribute('data-id')) + '&modal=1';
            bootstrap.Modal.getOrCreateInstance(editModal).show();
        });
        editModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('edit-frame').src = 'about:blank';
            table.ajax.reload(null, false);
        });

        // Adjust credits modal.
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
                .then(function(d) {
                    bootstrap.Modal.getOrCreateInstance(creditsModal).hide();
                    if (!d || d.result !== true) { throw new Error('fail'); }
                    table.ajax.reload(null, false);
                })
                .catch(function() { alert(lang.error); });
        });

        // Whois.
        jQuery('#users-table tbody').on('click', '.js-whois', function() {
            var ip = this.getAttribute('data-ip');
            var body = document.getElementById('whois-body');
            document.getElementById('whois-ip').textContent = ip;
            body.innerHTML = '<div class="text-center py-3"><span class="spinner-border" role="status"></span></div>';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('whoisModal')).show();
            fetch('./api?action=ip_whois&isp=1&ip=' + encodeURIComponent(ip), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(w) {
                    var rows = [];
                    var add = function(label, val) { if (val) { rows.push('<dt class="col-4 text-body-secondary">' + esc(label) + '</dt><dd class="col-8">' + esc(val) + '</dd>'); } };
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
