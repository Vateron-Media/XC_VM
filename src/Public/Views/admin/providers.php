<?php

/**
 * Stream providers (Bootstrap 5). Client-side table: ProviderService::getAll provides
 * the rows, rendered server-side into the DOM, with a client-side datatables-bs5
 * table. Force-reload / delete via api?action=provider&sub=reload|delete.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Stream\ProviderService;

if (!Authorization::check('adv', 'streams')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('stream_providers'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="providers-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('provider'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('connections'); ?></th>
                    <th><?= $language::get('streams'); ?></th>
                    <th><?= $language::get('movies'); ?></th>
                    <th><?= $language::get('series'); ?></th>
                    <th><?= $language::get('expires'); ?></th>
                    <th><?= $language::get('last_changed'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (ProviderService::getAll() as $rProvider): ?>
                    <?php
                    $rData = json_decode($rProvider['data'] ?? '[]', true) ?: [];
                    $rState = !$rProvider['enabled'] ? 0 : (($rProvider['status']) ? 2 : 1);
                    $rStateCls = [0 => 'text-body-secondary', 1 => 'text-danger', 2 => 'text-success'][$rState];
                    $rMax = (int) ($rData['max_connections'] ?? 0);
                    $rActive = (int) ($rData['active_connections'] ?? 0);
                    $rConnCls = $rMax > 0 ? ($rMax * 0.75 < $rActive ? 'danger' : ($rMax * 0.5 < $rActive ? 'warning' : 'success')) : 'success';
                    $rExp = $rData['exp_date'] ?? 0;
                    ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rProvider['id']; ?></td>
                        <td class="text-center" data-order="<?= $rState; ?>"><i class="icon-base ti tabler-square-filled <?= $rStateCls; ?>"></i></td>
                        <td><?= htmlspecialchars((string) $rProvider['name'], ENT_QUOTES); ?><br><small class="text-body-secondary"><?= htmlspecialchars((string) $rProvider['ip'], ENT_QUOTES); ?>:<?= (int) $rProvider['port']; ?></small></td>
                        <td class="text-center text-nowrap"><?= htmlspecialchars((string) $rProvider['username'], ENT_QUOTES); ?></td>
                        <td class="text-center text-nowrap" data-order="<?= $rActive; ?>">
                            <a href="streams?search=<?= urlencode(strtolower((string) $rProvider['ip'])); ?>&filter=1" class="badge bg-label-<?= $rConnCls; ?>"><?= number_format($rActive, 0); ?> / <?= $rMax > 0 ? number_format($rMax, 0) : '&infin;'; ?></a>
                        </td>
                        <td class="text-center" data-order="<?= (int) ($rData['streams'] ?? 0); ?>"><span class="badge bg-label-<?= ($rData['streams'] ?? 0) > 0 ? 'info' : 'secondary'; ?>"><?= number_format((int) ($rData['streams'] ?? 0), 0); ?></span></td>
                        <td class="text-center" data-order="<?= (int) ($rData['movies'] ?? 0); ?>"><span class="badge bg-label-<?= ($rData['movies'] ?? 0) > 0 ? 'info' : 'secondary'; ?>"><?= number_format((int) ($rData['movies'] ?? 0), 0); ?></span></td>
                        <td class="text-center" data-order="<?= (int) ($rData['series'] ?? 0); ?>"><span class="badge bg-label-<?= ($rData['series'] ?? 0) > 0 ? 'info' : 'secondary'; ?>"><?= number_format((int) ($rData['series'] ?? 0), 0); ?></span></td>
                        <td class="text-center text-nowrap" data-order="<?= (int) ($rExp === -1 ? 0 : $rExp); ?>">
                            <?= $rExp == -1 ? 'Unknown' : ($rExp ? date('Y-m-d', (int) $rExp) : 'Never'); ?>
                        </td>
                        <td class="text-center text-nowrap" data-order="<?= (int) $rProvider['last_changed']; ?>">
                            <?= $rProvider['last_changed'] ? date('Y-m-d H:i', (int) $rProvider['last_changed']) : 'Never'; ?>
                        </td>
                        <td class="text-center text-nowrap">
                            <a href="provider?id=<?= (int) $rProvider['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-icon btn-label-secondary js-reload" data-id="<?= (int) $rProvider['id']; ?>" title="<?= $language::get('force_reload'); ?>"><i class="icon-base ti tabler-refresh"></i></button>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rProvider['id']; ?>"><i class="icon-base ti tabler-trash"></i></button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errMsg = <?= json_encode($language::get('error_occured')); ?>;
        var delMsg = <?= json_encode($language::get('delete') . '?'); ?>;
        var table = jQuery('#providers-table').DataTable({
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'asc']],
            columnDefs: [
                { targets: 0, orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { targets: 1, visible: false }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        function call(sub, id) {
            return fetch('./api?action=provider&sub=' + sub + '&id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } }).then(function(r) { return r.json(); });
        }
        jQuery('#providers-table tbody').on('click', '.js-reload', function() {
            call('reload', this.getAttribute('data-id')).catch(function() {});
        });
        jQuery('#providers-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id) { return; }
            window.xcConfirm(delMsg).then(function(ok) {
                if (!ok) { return; }
                call('delete', id)
                    .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } table.row(row).remove().draw(false); })
                    .catch(function() { alert(errMsg); });
            });
        });
    })();
</script>
</body>

</html>
