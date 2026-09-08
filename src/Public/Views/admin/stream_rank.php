<?php

/**
 * Stream rank (Bootstrap 5). This table is client-side: the controller passes the
 * precomputed $rRows (ranked by watch time for the chosen $rPeriod) into the
 * view, so the rows are rendered server-side into the DOM and a client-side
 * datatables-bs5 table handles search / pagination / Responsive. The period
 * select reloads the page (the data set is computed per period).
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'streams')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rPeriods = ['today' => 'today', 'week' => 'week', 'month' => 'month', 'all' => 'all_time'];
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('stream_rank'); ?></h5>
        <div style="min-width:12rem">
            <select id="filter-period" class="form-select form-select-sm">
                <?php foreach ($rPeriods as $rValue => $rKey): ?>
                    <option value="<?= htmlspecialchars($rValue, ENT_QUOTES); ?>" <?= ($rPeriod === $rValue) ? 'selected' : ''; ?>><?= $language::get($rKey); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="rank-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('rank'); ?></th>
                    <th><?= $language::get('stream_name'); ?></th>
                    <th><?= $language::get('time_watched'); ?></th>
                    <th><?= $language::get('total_connections'); ?></th>
                    <th><?= $language::get('total_users'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php $i = 0; ?>
                <?php foreach ($rRows as $rRow): ?>
                    <?php
                    $i++;
                    $rSeconds = (int) $rRow['time'];
                    $rTime = (86400 <= $rSeconds)
                        ? sprintf('%02dd %02dh %02dm', $rSeconds / 86400, ($rSeconds / 3600) % 24, ($rSeconds / 60) % 60)
                        : sprintf('%02dh %02dm %02ds', $rSeconds / 3600, ($rSeconds / 60) % 60, $rSeconds % 60);
                    ?>
                    <tr>
                        <td></td>
                        <td class="text-center"><?= $i; ?></td>
                        <td><a href="stream_view?id=<?= (int) $rRow['id']; ?>" class="text-body"><?= htmlspecialchars((string) $rRow['stream_display_name'], ENT_QUOTES); ?></a></td>
                        <td class="text-center" data-order="<?= $rSeconds; ?>"><span class="badge bg-label-secondary"><?= $rTime; ?></span></td>
                        <td class="text-center" data-order="<?= (int) $rRow['connections']; ?>"><?= number_format((float) $rRow['connections'], 0); ?></td>
                        <td class="text-center" data-order="<?= (int) $rRow['users']; ?>"><?= number_format((float) $rRow['users'], 0); ?></td>
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
        jQuery('#rank-table').DataTable({
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
            }],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        document.getElementById('filter-period').addEventListener('change', function() {
            window.location.href = 'stream_rank?period=' + encodeURIComponent(this.value);
        });
    })();
</script>
</body>

</html>