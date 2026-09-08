<?php

/**
 * User groups (Bootstrap 5). Client-side table: GroupService::getAll provides the rows,
 * rendered server-side into the DOM, with a client-side datatables-bs5 table.
 * Delete via api?action=group&sub=delete.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\User\GroupService;

if (!Authorization::check('adv', 'mng_groups') && !Authorization::check('adv', 'edit_group')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_group');

if (!function_exists('xc_bool_flag')) {
    function xc_bool_flag($rOn) {
        return '<i class="icon-base ti tabler-square-filled ' . ($rOn ? 'text-success' : 'text-body-secondary') . '"></i>';
    }
}
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('groups'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="groups-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('group_name'); ?></th>
                    <th><?= $language::get('is_admin'); ?></th>
                    <th><?= $language::get('is_reseller'); ?></th>
                    <th><?= $language::get('subresellers'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (GroupService::getAll() as $rGroup): ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rGroup['group_id']; ?></td>
                        <td><?= htmlspecialchars((string) $rGroup['group_name'], ENT_QUOTES); ?></td>
                        <td class="text-center" data-order="<?= (int) (bool) $rGroup['is_admin']; ?>"><?= xc_bool_flag($rGroup['is_admin']); ?></td>
                        <td class="text-center" data-order="<?= (int) (bool) $rGroup['is_reseller']; ?>"><?= xc_bool_flag($rGroup['is_reseller']); ?></td>
                        <td class="text-center" data-order="<?= (int) (bool) $rGroup['create_sub_resellers']; ?>"><?= xc_bool_flag($rGroup['create_sub_resellers']); ?></td>
                        <td class="text-center text-nowrap">
                            <?php if ($rCanEdit): ?>
                                <a href="group?id=<?= (int) $rGroup['group_id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('edit_group'); ?>"><i class="icon-base ti tabler-pencil"></i></a>
                            <?php endif; ?>
                            <?php if (!empty($rGroup['can_delete'])): ?>
                                <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rGroup['group_id']; ?>" title="<?= $language::get('delete_group'); ?>"><i class="icon-base ti tabler-trash"></i></button>
                            <?php endif; ?>
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
        var table = jQuery('#groups-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [1, 'asc']
            ],
            columnDefs: [{
                    targets: 0,
                    orderable: false,
                    searchable: false,
                    className: 'control',
                    responsivePriority: 2
                },
                {
                    targets: 1,
                    visible: false
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });
        jQuery('#groups-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id) {
                return;
            }
            window.xcConfirm(delMsg).then(function(ok) {
                if (!ok) {
                    return;
                }
                fetch('./api?action=group&sub=delete&group_id=' + encodeURIComponent(id), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(d) {
                        if (!d || d.result !== true) {
                            throw new Error('fail');
                        }
                        table.row(row).remove().draw(false);
                    })
                    .catch(function() {
                        alert(errMsg);
                    });
            });
        });
    })();
</script>
</body>

</html>