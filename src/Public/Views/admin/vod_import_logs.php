<?php

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Events\Vod\VodImportResultEvent;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Server\ServerRepository;

if (!Authorization::check('adv', 'import_movies') && !Authorization::check('adv', 'import_episodes')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    LayoutRenderer::renderFooter('admin');
    echo '</body></html>';
    return;
endif;

$rStatuses = [
    VodImportResultEvent::STATUS_QUEUED => $language::get('queued'),
    VodImportResultEvent::STATUS_IMPORTED => $language::get('added'),
    VodImportResultEvent::STATUS_INSERT_FAILED => $language::get('vod_import_status_insert_failed'),
    VodImportResultEvent::STATUS_NO_CATEGORY => $language::get('no_category'),
    VodImportResultEvent::STATUS_NO_MATCH => $language::get('vod_import_status_no_match'),
    VodImportResultEvent::STATUS_BROKEN_FILE => $language::get('vod_import_status_broken_file'),
    VodImportResultEvent::STATUS_UPGRADED => $language::get('vod_import_status_upgraded'),
    VodImportResultEvent::STATUS_DUPLICATE => $language::get('duplicate'),
];
?>

<style>
    #vod-import-logs-table td.vod-import-source {
        max-width: 34rem;
        white-space: normal;
        overflow-wrap: anywhere;
    }
</style>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('vod_import_logs'); ?></h5>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-server"><?= $language::get('server'); ?></label>
                <select id="filter-server" class="form-select">
                    <option value=""><?= $language::get('all_servers'); ?></option>
                    <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                        <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-sm-3 col-lg-4">
                <label class="form-label" for="filter-type"><?= $language::get('type'); ?></label>
                <select id="filter-type" class="form-select">
                    <option value=""><?= $language::get('all_types'); ?></option>
                    <option value="1"><?= $language::get('movies'); ?></option>
                    <option value="2"><?= $language::get('series'); ?></option>
                </select>
            </div>
            <div class="col-6 col-sm-3 col-lg-4">
                <label class="form-label" for="filter-status"><?= $language::get('status'); ?></label>
                <select id="filter-status" class="form-select">
                    <option value=""><?= $language::get('all_statuses'); ?></option>
                    <?php foreach ($rStatuses as $rID => $rLabel): ?>
                        <option value="<?= (int) $rID; ?>"><?= htmlspecialchars($rLabel, ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="vod-import-logs-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('type'); ?></th>
                    <th><?= $language::get('server'); ?></th>
                    <th><?= $language::get('filename'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('date'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<?php LayoutRenderer::renderFooter('admin'); ?>
<script>
    (function() {
        var statuses = <?= json_encode($rStatuses, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var statusStyles = {0: 'info', 1: 'success', 2: 'danger', 3: 'danger', 4: 'danger', 5: 'danger', 6: 'info', 7: 'secondary'};
        var typeLabels = {1: <?= json_encode($language::get('movies'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>, 2: <?= json_encode($language::get('series'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>};
        var retryLabel = <?= json_encode($language::get('try_again'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var queuedMessage = <?= json_encode($language::get('vod_import_queued'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var errorMessage = <?= json_encode($language::get('error'), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
        var esc = function(value) {
            var element = document.createElement('div');
            element.textContent = value == null ? '' : String(value);
            return element.innerHTML;
        };
        var table = jQuery('#vod-import-logs-table').DataTable({
            serverSide: true,
            responsive: {details: {type: 'column', target: 0}},
            order: [[5, 'desc']],
            ajax: {
                url: './table',
                data: function(data) {
                    data.id = 'vod_import_logs';
                    data.server = document.getElementById('filter-server').value;
                    data.type = document.getElementById('filter-type').value;
                    data.status = document.getElementById('filter-status').value;
                }
            },
            columns: [
                {data: null, defaultContent: '', orderable: false, searchable: false, className: 'control'},
                {data: 'type', render: function(value) { return esc(typeLabels[value] || ''); }},
                {data: 'server_name', render: function(value, type, row) {
                    return row.server_url ? '<a href="' + esc(row.server_url) + '">' + esc(value) + '</a>' : esc(value);
                }},
                {data: 'filename', className: 'vod-import-source', render: function(value, type, row) {
                    var source = '<small class="text-body-secondary">' + esc(value) + '</small>';
                    if (!row.title) return source;
                    var title = row.stream_url ? '<a href="' + esc(row.stream_url) + '" class="fw-medium">' + esc(row.title) + '</a>' : '<div class="fw-medium">' + esc(row.title) + '</div>';
                    return title + source;
                }},
                {data: 'status', render: function(value) {
                    return statuses[value] ? '<span class="badge bg-label-' + statusStyles[value] + '">' + esc(statuses[value]) + '</span>' : '';
                }},
                {data: 'date', className: 'text-nowrap', render: function(value) {
                    return value ? esc(new Date(value * 1000).toLocaleString()) : '';
                }},
                {data: null, orderable: false, searchable: false, render: function(value, type, row) {
                    if (!row.retryable) return '';
                    return '<div class="input-group input-group-sm flex-nowrap" style="min-width:22rem">' +
                        '<input type="text" class="form-control" data-vod-retry-title maxlength="1024" required value="' + esc(row.title || '') + '">' +
                        '<button type="button" class="btn btn-primary" data-vod-retry="' + row.id + '" data-vod-type="' + row.type + '">' +
                        '<i class="icon-base ti tabler-refresh me-1"></i>' + esc(retryLabel) + '</button></div>';
                }}
            ],
            layout: {topStart: 'pageLength', topEnd: 'search'}
        });
        ['filter-server', 'filter-type', 'filter-status'].forEach(function(id) {
            document.getElementById(id).addEventListener('change', function() { table.ajax.reload(); });
        });
        document.getElementById('vod-import-logs-table').addEventListener('click', function(event) {
            var button = event.target.closest('[data-vod-retry]');
            if (!button) return;
            var input = button.closest('.input-group').querySelector('[data-vod-retry-title]');
            if (!input.reportValidity()) return;
            button.disabled = true;
            var body = new URLSearchParams({id: button.dataset.vodRetry, type: button.dataset.vodType, title: input.value.trim()});
            fetch('api?action=vod_import_retry', {method: 'POST', body: body, headers: {'X-Requested-With': 'XMLHttpRequest'}})
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data.result) throw new Error('retry failed');
                    xcToast(queuedMessage, 'success');
                    table.ajax.reload(null, false);
                })
                .catch(function() {
                    button.disabled = false;
                    xcToast(errorMessage, 'error');
                });
        });
    })();
</script>
</body>
</html>
