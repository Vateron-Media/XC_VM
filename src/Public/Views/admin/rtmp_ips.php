<?php

/**
 * RTMP IPs (Bootstrap 5). Client-side table: BlocklistService::getRTMPIPsSimple provides
 * the rows, rendered server-side into the DOM, with a client-side datatables-bs5
 * table. Delete via api?action=rtmp_ip&sub=delete.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Security\BlocklistService;

if (!Authorization::check('adv', 'rtmp')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'add_rtmp');
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('rtmp_ip_addresses'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="rtmp-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('ip_address'); ?></th>
                    <th><?= $language::get('password'); ?></th>
                    <th><?= $language::get('push'); ?></th>
                    <th><?= $language::get('pull'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (BlocklistService::getRTMPIPsSimple() as $rIP): ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rIP['id']; ?></td>
                        <td class="text-center text-nowrap"><?= htmlspecialchars((string) $rIP['ip'], ENT_QUOTES); ?></td>
                        <td class="text-center"><?= htmlspecialchars((string) $rIP['password'], ENT_QUOTES); ?></td>
                        <td class="text-center" data-order="<?= (int) (bool) $rIP['push']; ?>">
                            <i class="icon-base ti tabler-square-filled <?= $rIP['push'] ? 'text-success' : 'text-body-secondary'; ?>"></i>
                        </td>
                        <td class="text-center" data-order="<?= (int) (bool) $rIP['pull']; ?>">
                            <i class="icon-base ti tabler-square-filled <?= $rIP['pull'] ? 'text-success' : 'text-body-secondary'; ?>"></i>
                        </td>
                        <td class="text-center text-nowrap">
                            <?php if ($rCanEdit): ?>
                                <a href="rtmp_ip?id=<?= (int) $rIP['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary"><i class="icon-base ti tabler-pencil"></i></a>
                            <?php endif; ?>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rIP['id']; ?>"><i class="icon-base ti tabler-trash"></i></button>
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
        var table = jQuery('#rtmp-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [1, 'desc']
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
        jQuery('#rtmp-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id) {
                return;
            }
            window.xcConfirm(delMsg).then(function(ok) {
                if (!ok) {
                    return;
                }
                fetch('./api?action=rtmp_ip&sub=delete&ip=' + encodeURIComponent(id), {
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