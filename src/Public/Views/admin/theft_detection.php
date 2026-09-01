<?php

/**
 * VOD theft detection (Vuexy). This table is client-side: the controller passes
 * the precomputed $rTheftDetection map (keyed by time range) into the view, so
 * the rows are rendered server-side into the DOM and a client-side datatables-bs5
 * table handles search / pagination / Responsive. The range select reloads the
 * page (the data set is computed per range).
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'movies')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEdit = Authorization::check('adv', 'edit_user');
$rRanges  = [0 => 'all_time', 604800 => 'last_7_days', 86400 => 'last_24_hours', 3600 => 'last_hour'];
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('vod_theft_detection'); ?></h5>
        <div style="min-width:12rem">
            <select id="filter-range" class="form-select form-select-sm">
                <?php foreach ($rRanges as $rValue => $rKey): ?>
                    <option value="<?= (int) $rValue; ?>" <?= ((int) $rRange === (int) $rValue) ? 'selected' : ''; ?>><?= $language::get($rKey); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="theft-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('user_id'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('view_count'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach (($rTheftDetection[$rRange] ?? []) as $rRow): ?>
                    <tr>
                        <td></td>
                        <td class="text-center">
                            <?php if ($rCanEdit): ?>
                                <a href="line?id=<?= (int) $rRow['user_id']; ?>" class="text-body"><?= (int) $rRow['user_id']; ?></a>
                            <?php else: ?>
                                <?= (int) $rRow['user_id']; ?>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($rCanEdit): ?>
                                <a href="line?id=<?= (int) $rRow['user_id']; ?>" class="text-body"><?= htmlspecialchars((string) $rRow['username'], ENT_QUOTES); ?></a>
                            <?php else: ?>
                                <?= htmlspecialchars((string) $rRow['username'], ENT_QUOTES); ?>
                            <?php endif; ?>
                        </td>
                        <td class="text-center"><?= (int) $rRow['vod_count']; ?></td>
                        <td class="text-center">
                            <a href="line_activity?search=<?= urlencode((string) $rRow['username']); ?>" class="btn btn-sm btn-label-secondary">
                                <i class="icon-base ti tabler-file-text me-1"></i><?= $language::get('view_logs'); ?>
                            </a>
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
        jQuery('#theft-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [3, 'desc']
            ],
            columnDefs: [{
                targets: 0,
                orderable: false,
                searchable: false,
                className: 'control',
                responsivePriority: 2
            }],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        document.getElementById('filter-range').addEventListener('change', function() {
            window.location.href = 'theft_detection?range=' + encodeURIComponent(this.value);
        });
    })();
</script>
</body>

</html>
