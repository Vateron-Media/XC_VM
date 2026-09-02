<?php

/**
 * Packages (Vuexy). Client-side table: PackageService::getAll provides the rows
 * (add-ons are skipped), rendered server-side into the DOM, with a client-side
 * datatables-bs5 table. Delete via api?action=package&sub=delete.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Line\PackageService;

if (!Authorization::check('adv', 'mng_packages') && !Authorization::check('adv', 'edit_package')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_package');
$rFlag = fn($v) => '<i class="icon-base ti tabler-square-filled ' . ($v ? 'text-success' : 'text-body-secondary') . '"></i>';
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('packages'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="packages-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('package_name'); ?></th>
                    <th><?= $language::get('trial'); ?></th>
                    <th><?= $language::get('official'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (PackageService::getAll() as $rPackage): ?>
                    <?php if (!empty($rPackage['is_addon'])) {
                        continue;
                    } ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rPackage['id']; ?></td>
                        <td><?= htmlspecialchars((string) $rPackage['package_name'], ENT_QUOTES); ?></td>
                        <td class="text-center" data-order="<?= (int) (bool) $rPackage['is_trial']; ?>"><?= $rFlag($rPackage['is_trial']); ?></td>
                        <td class="text-center" data-order="<?= (int) (bool) $rPackage['is_official']; ?>"><?= $rFlag($rPackage['is_official']); ?></td>
                        <td class="text-center text-nowrap">
                            <?php if ($rCanEdit): ?>
                                <a href="package?id=<?= (int) $rPackage['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('edit_package'); ?>"><i class="icon-base ti tabler-pencil"></i></a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rPackage['id']; ?>" title="<?= $language::get('delete_package'); ?>"><i class="icon-base ti tabler-trash"></i></button>
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
        var table = jQuery('#packages-table').DataTable({
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'asc']],
            columnDefs: [
                { targets: 0, orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { targets: 1, visible: false }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });
        jQuery('#packages-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id) { return; }
            window.xcConfirm(delMsg).then(function(ok) {
                if (!ok) { return; }
                fetch('./api?action=package&sub=delete&package_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } table.row(row).remove().draw(false); })
                    .catch(function() { alert(errMsg); });
            });
        });
    })();
</script>
</body>

</html>
