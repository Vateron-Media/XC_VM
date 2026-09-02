<?php

/**
 * Blocked user-agents (Vuexy). Client-side table: BlocklistService::getAllUserAgents
 * provides the rows, rendered server-side into the DOM, with a client-side
 * datatables-bs5 table. Delete via api?action=useragent&sub=delete.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Security\BlocklistService;

if (!Authorization::check('adv', 'block_uas')):
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
        <h5 class="card-title mb-0"><?= $language::get('blocked_useragents'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="ua-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('user_agent_label'); ?></th>
                    <th><?= $language::get('exact_match'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (BlocklistService::getAllUserAgents() as $rUserAgent): ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rUserAgent['id']; ?></td>
                        <td><?= htmlspecialchars((string) $rUserAgent['user_agent'], ENT_QUOTES); ?></td>
                        <td class="text-center" data-order="<?= (int) (bool) $rUserAgent['exact_match']; ?>">
                            <i class="icon-base ti tabler-square-filled <?= $rUserAgent['exact_match'] ? 'text-success' : 'text-body-secondary'; ?>"></i>
                        </td>
                        <td class="text-center text-nowrap">
                            <a href="useragent?id=<?= (int) $rUserAgent['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rUserAgent['id']; ?>"><i class="icon-base ti tabler-trash"></i></button>
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
        var table = jQuery('#ua-table').DataTable({
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'desc']],
            columnDefs: [
                { targets: 0, orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { targets: 1, visible: false }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });
        jQuery('#ua-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id) { return; }
            window.xcConfirm(delMsg).then(function(ok) {
                if (!ok) { return; }
                fetch('./api?action=useragent&sub=delete&ua_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } table.row(row).remove().draw(false); })
                    .catch(function() { alert(errMsg); });
            });
        });
    })();
</script>
</body>

</html>
