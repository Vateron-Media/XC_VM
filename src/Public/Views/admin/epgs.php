<?php

/**
 * EPG sources (Vuexy). Client-side table: EpgService::getAll provides the rows,
 * rendered server-side into the DOM, with a client-side datatables-bs5 table.
 * Force-reload / delete via api?action=epg&sub=reload|delete.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Epg\EpgService;

if (!Authorization::check('adv', 'epg')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'epg_edit');
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('epgs'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="epgs-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('epg_name'); ?></th>
                    <th><?= $language::get('source'); ?></th>
                    <th><?= $language::get('days_to_keep'); ?></th>
                    <th><?= $language::get('last_updated'); ?></th>
                    <th><?= $language::get('channels'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (EpgService::getAll() as $rEPG): ?>
                    <?php $rChannels = count(json_decode($rEPG['data'] ?? '[]', true) ?? []); ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= (int) $rEPG['id']; ?></td>
                        <td><?= htmlspecialchars((string) $rEPG['epg_name'], ENT_QUOTES); ?></td>
                        <td><?= htmlspecialchars((string) (parse_url((string) $rEPG['epg_file'])['host'] ?? ''), ENT_QUOTES); ?></td>
                        <td class="text-center"><?= (int) $rEPG['days_keep']; ?></td>
                        <td class="text-nowrap text-center" data-order="<?= (int) $rEPG['last_updated']; ?>"><?= $rEPG['last_updated'] ? date('Y-m-d H:i', (int) $rEPG['last_updated']) : $language::get('never'); ?></td>
                        <td class="text-center"><span class="badge bg-label-secondary"><?= number_format($rChannels, 0); ?></span></td>
                        <td class="text-center text-nowrap">
                            <?php if ($rCanEdit): ?>
                                <a href="epg?id=<?= (int) $rEPG['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('edit_epg'); ?>"><i class="icon-base ti tabler-pencil"></i></a>
                                <button type="button" class="btn btn-sm btn-icon btn-label-secondary js-reload" data-id="<?= (int) $rEPG['id']; ?>" title="<?= $language::get('force_reload'); ?>"><i class="icon-base ti tabler-refresh"></i></button>
                                <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rEPG['id']; ?>" title="<?= $language::get('delete_epg'); ?>"><i class="icon-base ti tabler-trash"></i></button>
                            <?php else: ?>
                                <span class="text-body-secondary">&mdash;</span>
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
        var table = jQuery('#epgs-table').DataTable({
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'asc']],
            columnDefs: [
                { targets: 0, orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { targets: 1, visible: false }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        function call(sub, id) {
            return fetch('./api?action=epg&sub=' + sub + '&epg_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); });
        }
        jQuery('#epgs-table tbody').on('click', '.js-reload', function() {
            var id = this.getAttribute('data-id');
            call('reload', id).catch(function() {});
        });
        jQuery('#epgs-table tbody').on('click', '.js-del', function() {
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
