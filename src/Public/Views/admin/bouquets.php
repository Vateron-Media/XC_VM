<?php

/**
 * Bouquets (Bootstrap 5). Client-rendered: $rBouquets is echoed as <tbody> and a plain
 * client-side DataTable adds search / sort / paging. Each row shows the per-type channel
 * counts (streams / movies / series / radios) and reorder / edit / duplicate / delete
 * actions (delete via ./api?action=bouquet). Reached full-page in the new-UI shell.
 */

use XcVm\Core\Auth\Authorization;

$rCanEdit = Authorization::check('adv', 'edit_bouquet');

$rCount = static fn($rJson): string => number_format(count(json_decode((string) $rJson, true) ?: []), 0);
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('bouquets'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="bouquets-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th class="text-center"><?= $language::get('id'); ?></th>
                    <th><?= $language::get('bouquet_name'); ?></th>
                    <th class="text-center"><?= $language::get('streams'); ?></th>
                    <th class="text-center"><?= $language::get('movies'); ?></th>
                    <th class="text-center"><?= $language::get('series'); ?></th>
                    <th class="text-center"><?= $language::get('stations'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rBouquets as $rBouquet): ?>
                    <tr id="bouquet-<?= (int) $rBouquet['id']; ?>">
                        <td class="text-center"><?= (int) $rBouquet['id']; ?></td>
                        <td class="fw-medium"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></td>
                        <td class="text-center"><span class="badge bg-label-secondary"><?= $rCount($rBouquet['bouquet_channels']); ?></span></td>
                        <td class="text-center"><span class="badge bg-label-secondary"><?= $rCount($rBouquet['bouquet_movies']); ?></span></td>
                        <td class="text-center"><span class="badge bg-label-secondary"><?= $rCount($rBouquet['bouquet_series']); ?></span></td>
                        <td class="text-center"><span class="badge bg-label-secondary"><?= $rCount($rBouquet['bouquet_radios']); ?></span></td>
                        <td class="text-center">
                            <?php if ($rCanEdit): ?>
                                <div class="btn-group">
                                    <a href="./bouquet_sort?id=<?= (int) $rBouquet['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('reorder_bouquet'); ?>"><i class="icon-base ti tabler-arrows-sort"></i></a>
                                    <a href="./bouquet?id=<?= (int) $rBouquet['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('edit_bouquet'); ?>"><i class="icon-base ti tabler-pencil"></i></a>
                                    <a href="./bouquet?duplicate=<?= (int) $rBouquet['id']; ?>" class="btn btn-sm btn-icon btn-label-secondary" title="<?= $language::get('duuplicate_bouquet'); ?>"><i class="icon-base ti tabler-copy"></i></a>
                                    <button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" data-id="<?= (int) $rBouquet['id']; ?>" title="<?= $language::get('delete_bouquet'); ?>"><i class="icon-base ti tabler-trash"></i></button>
                                </div>
                            <?php else: ?>
                                <span class="text-body-secondary">—</span>
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
        var $ = window.jQuery;
        if (!$) { return; }
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var delText = <?= json_encode($language::get('delete_confirm')); ?>;
        var toast = window.xcToast || function() {};

        var table = $('#bouquets-table').DataTable({
            order: [[0, 'asc']],
            columnDefs: [{ orderable: false, targets: [6] }],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        $('#bouquets-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            (window.xcConfirm ? window.xcConfirm(delText) : Promise.resolve(confirm(delText))).then(function(ok) {
                if (!ok) { return; }
                fetch('./api?action=bouquet&sub=delete&bouquet_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.json(); })
                    .then(function(d) { if (!d || d.result !== true) { throw new Error('fail'); } table.row($('#bouquet-' + id)).remove().draw(false); toast('Deleted.'); })
                    .catch(function() { toast(errText, 'error'); });
            });
        });
    })();
</script>
</body>

</html>
