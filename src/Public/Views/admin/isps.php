<?php

/**
 * Blocked ISPs (Vuexy). Client-side table: BlocklistService::getAllISPs provides
 * the rows, rendered server-side into the DOM, with a client-side datatables-bs5
 * table for search / pagination / Responsive. Delete via api?action=isp&sub=delete.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Security\BlocklistService;

if (!Authorization::check('adv', 'block_isps')):
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
        <h5 class="card-title mb-0"><?= $language::get('blocked_isps'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="isps-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('isp_name'); ?></th>
                    <th><?= $language::get('blocked'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (BlocklistService::getAllISPs() as $rISP): ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rISP['id']; ?></td>
                        <td><?= htmlspecialchars((string) $rISP['isp'], ENT_QUOTES); ?></td>
                        <td class="text-center" data-order="<?= (int) (bool) $rISP['blocked']; ?>">
                            <i class="icon-base ti tabler-square-filled <?= $rISP['blocked'] ? 'text-success' : 'text-body-secondary'; ?>"></i>
                        </td>
                        <td class="text-center text-nowrap">
                            <a href="isp?id=<?= (int) $rISP['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rISP['id']; ?>"><i class="icon-base ti tabler-trash"></i></button>
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
        var table = jQuery('#isps-table').DataTable({
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'desc']],
            columnDefs: [
                { targets: 0, orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { targets: 1, visible: false }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });
        jQuery('#isps-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id) { return; }
            window.xcConfirm(delMsg).then(function(ok) {
                if (!ok) { return; }
                fetch('./api?action=isp&sub=delete&isp_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } table.row(row).remove().draw(false); })
                    .catch(function() { alert(errMsg); });
            });
        });
    })();
</script>
</body>

</html>
