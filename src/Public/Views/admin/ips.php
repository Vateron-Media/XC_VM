<?php

/**
 * Blocked IP addresses (Bootstrap 5). Client-side table: BlocklistService provides the
 * rows, rendered server-side into the DOM, and a client-side datatables-bs5 table
 * handles search / pagination / Responsive. Per-row delete wired inline
 * (api?action=ip&sub=delete).
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Security\BlocklistService;

if (!Authorization::check('adv', 'block_ips')):
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
        <h5 class="card-title mb-0"><?= $language::get('blocked_ip_addresses'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="ips-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('ip_address'); ?></th>
                    <th><?= $language::get('notes'); ?></th>
                    <th><?= $language::get('date'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (BlocklistService::getBlockedIPsSimple() as $rIP): ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rIP['id']; ?></td>
                        <td class="text-center text-nowrap"><?= htmlspecialchars((string) $rIP['ip'], ENT_QUOTES); ?></td>
                        <td><?= htmlspecialchars((string) $rIP['notes'], ENT_QUOTES); ?></td>
                        <td class="text-nowrap" data-order="<?= (int) $rIP['date']; ?>"><?= date('Y-m-d H:i:s', (int) $rIP['date']); ?></td>
                        <td class="text-center">
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
        var confirmMsg = <?= json_encode($language::get('block_ip_delete_confirm')); ?>;

        var table = jQuery('#ips-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [4, 'desc']
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

        jQuery('#ips-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            var row = jQuery(this).closest('tr');
            if (!id || !confirm(confirmMsg)) {
                return;
            }
            fetch('./api?action=ip&sub=delete&ip=' + encodeURIComponent(id), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (!data || data.result !== true) {
                        throw new Error('fail');
                    }
                    table.row(row).remove().draw(false);
                })
                .catch(function() {
                    alert(errMsg);
                });
        });
    })();
</script>
</body>

</html>
