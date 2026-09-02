<?php

/**
 * Access codes (Vuexy). Client-side table: AuthRepository::getAllCodes provides
 * the rows, rendered server-side into the DOM, with a client-side datatables-bs5
 * table. Delete via api?action=code&sub=delete.
 */

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'add_code')):
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
        <h5 class="card-title mb-0"><?= $language::get('access_codes'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="codes-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('access_code'); ?></th>
                    <th><?= $language::get('type'); ?></th>
                    <th><?= $language::get('enabled'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (AuthRepository::getAllCodes() as $rCode): ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rCode['id']; ?></td>
                        <td class="text-nowrap"><?= htmlspecialchars((string) $rCode['code'], ENT_QUOTES); ?></td>
                        <td class="text-center"><span class="badge bg-label-secondary text-uppercase"><?= htmlspecialchars((string) $rCode['type'], ENT_QUOTES); ?></span></td>
                        <td class="text-center" data-order="<?= (int) (bool) $rCode['enabled']; ?>">
                            <i class="icon-base ti tabler-square-filled <?= $rCode['enabled'] ? 'text-success' : 'text-body-secondary'; ?>"></i>
                        </td>
                        <td class="text-center text-nowrap">
                            <a href="code?id=<?= (int) $rCode['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('edit_code'); ?>"><i class="icon-base ti tabler-pencil"></i></a>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rCode['id']; ?>" title="<?= $language::get('delete_code'); ?>"><i class="icon-base ti tabler-trash"></i></button>
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
        var table = jQuery('#codes-table').DataTable({
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'desc']],
            columnDefs: [
                { targets: 0, orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { targets: 1, visible: false }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });
        jQuery('#codes-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id || !window.confirm(delMsg)) { return; }
            fetch('./api?action=code&sub=delete&code_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } table.row(row).remove().draw(false); })
                .catch(function() { alert(errMsg); });
        });
    })();
</script>
</body>

</html>
