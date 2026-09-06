<?php

/**
 * Reseller Radio stations (Bootstrap 5). Full-parity port of admin/radios.php adapted
 * to the reseller contract: clean-JSON keyed serverSide DataTable
 * (ResellerTableRenderer::handleRadios emits data-only rows), with the icon, title,
 * category, connections badge and per-row action dropdown all rendered client-side.
 *
 * Reseller differences vs admin (the reseller API/renderer simply does not expose
 * these, so the view legitimately omits them): no bulk toolbar (reseller has no
 * `multi` endpoint), no start / stop / restart / edit / delete / player
 * (ResellerApiDispatcher exposes only `action=connections&sub=purge`), no server /
 * status / stream-info columns (the reseller radios query does not fetch that data).
 * The only row action is Kill Connections, gated by
 * `reseller_client_connection_logs`. The category filter is limited to the
 * reseller's permitted categories (`$rPermissions['category_ids']`). Permission
 * gating uses $rPermissions['can_view_vod'], not Authorization::check.
 */

if (empty($rPermissions['can_view_vod'])):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('reseller');
    echo '</body></html>';
    return;
endif;

$rCanKill = !empty($rPermissions['reseller_client_connection_logs']);
$rCategoryIDs = (array) ($rPermissions['category_ids'] ?? []);
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('radio_stations'); ?></h5>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6">
                <label class="form-label" for="filter-category"><?= $language::get('category'); ?></label>
                <select id="filter-category" class="form-select">
                    <option value=""><?= $language::get('all_categories'); ?></option>
                    <?php foreach ($categories as $rCat): ?>
                        <?php if (in_array($rCat['id'], $rCategoryIDs)): ?>
                            <option value="<?= (int) $rCat['id']; ?>"><?= htmlspecialchars((string) $rCat['category_name'], ENT_QUOTES); ?></option>
                        <?php endif; ?>
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
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('icon'); ?></th>
                    <th><?= $language::get('title'); ?></th>
                    <th><?= $language::get('category'); ?></th>
                    <th class="text-center"><?= $language::get('connections'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    (function() {
        var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
        var canKill = <?= $rCanKill ? 'true' : 'false'; ?>;
        var lang = {
            kill: <?= json_encode($language::get('kill') ?: 'Kill Connections'); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>,
            confirmKill: 'Are you sure you want to kill all connections for this station?'
        };

        var table = jQuery('#radios-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'desc']],
            searchDelay: 400,
            lengthMenu: [10, 25, 50, 250, 500, 1000],
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 25); ?>,
            ajax: {
                url: './table',
                data: function(d) { d.id = 'radios'; d.category = document.getElementById('filter-category').value; }
            },
            columnDefs: [{ orderable: false, targets: [0, 2, 6] }],
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'id', className: 'text-center', render: function(d) { return '<a href="stream_view?id=' + encodeURIComponent(d) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'icon', orderable: false, searchable: false, render: function(d) { return d ? '<a href="resize?maxw=512&maxh=512&url=' + encodeURIComponent(d) + '" target="_blank"><img loading="lazy" src="resize?maxw=96&maxh=32&url=' + encodeURIComponent(d) + '" alt=""></a>' : ''; } },
                {
                    data: 'title',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        return '<a href="stream_view?id=' + encodeURIComponent(row.id) + '" class="text-body"><span class="fw-medium">' + esc(d) + '</span></a>';
                    }
                },
                { data: 'category', render: function(d) { return '<small class="text-body-secondary">' + esc(d || '') + '</small>'; } },
                {
                    data: 'clients',
                    className: 'text-center',
                    render: function(d, t, row) {
                        var badge = '<span class="badge bg-label-' + (d > 0 ? 'info' : 'secondary') + '">' + (d || 0) + '</span>';
                        return (d > 0 && canKill) ? '<a href="live_connections?stream=' + encodeURIComponent(row.id) + '&stream_id=' + encodeURIComponent(row.id) + '">' + badge + '</a>' : badge;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        if (!canKill || !(row.clients > 0)) { return ''; }
                        var items = '<a class="dropdown-item js-act" href="javascript:void(0);" data-id="' + esc(row.id) + '">' + esc(lang.kill) + '</a>';
                        return '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' + items + '</div></div>';
                    }
                }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        document.getElementById('filter-category').addEventListener('change', function() { table.ajax.reload(); });

        // Kill connections -> reseller api (action=connections&sub=purge&stream_id=…).
        jQuery('#radios-table tbody').on('click', '.js-act', function() {
            var id = this.getAttribute('data-id');
            xcConfirm(lang.confirmKill).then(function(ok) {
                if (!ok) { return; }
                fetch('./api?action=connections&sub=purge&stream_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } table.ajax.reload(null, false); })
                    .catch(function() { xcToast(lang.error, 'error'); });
            });
        });
    })();
</script>
</body>

</html>
