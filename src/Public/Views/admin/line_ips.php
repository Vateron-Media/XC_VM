<?php

/**
 * Line IP usage (Bootstrap 5). Client-rendered: $rLineIPs[$rRange] (per-user distinct IP
 * counts over the selected time range) is echoed as <tbody> and a plain client-side
 * DataTable adds search / sort / paging. The range select reloads the page with ?range=,
 * since the aggregation is computed server-side. Reached full-page in the new-UI shell.
 */

use XcVm\Core\Auth\Authorization;

$rCanEditUser = Authorization::check('adv', 'edit_user');
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('line_ip_usage'); ?></h5>
        <div class="d-flex align-items-center gap-2">
            <label class="form-label mb-0 text-nowrap">Range</label>
            <select id="range" class="form-select form-select-sm" style="width:auto">
                <option value="0" <?= $rRange == 0 ? 'selected' : ''; ?>>All Time</option>
                <option value="604800" <?= $rRange == 604800 ? 'selected' : ''; ?>>Last 7 Days</option>
                <option value="86400" <?= $rRange == 86400 ? 'selected' : ''; ?>>Last 24 Hours</option>
                <option value="3600" <?= $rRange == 3600 ? 'selected' : ''; ?>>Last Hour</option>
            </select>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="lineips-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th class="text-center"><?= $language::get('user_id'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th class="text-center"><?= $language::get('ip_count'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rLineIPs[$rRange] as $rRow): ?>
                    <?php
                    $rLogsUrl = $rRange == 0
                        ? 'line_activity?user_id=' . (int) $rRow['user_id']
                        : 'line_activity?user_id=' . (int) $rRow['user_id'] . '&range=' . date($rSettings['date_format'], time() - $rRange) . ' - ' . date($rSettings['date_format'], time());
                    ?>
                    <tr>
                        <td class="text-center">
                            <?php if ($rCanEditUser): ?><a href="line?id=<?= (int) $rRow['user_id']; ?>"><?= (int) $rRow['user_id']; ?></a><?php else: ?><?= (int) $rRow['user_id']; ?><?php endif; ?>
                        </td>
                        <td class="fw-medium">
                            <?php if ($rCanEditUser): ?><a href="line?id=<?= (int) $rRow['user_id']; ?>"><?= htmlspecialchars((string) $rRow['username'], ENT_QUOTES); ?></a><?php else: ?><?= htmlspecialchars((string) $rRow['username'], ENT_QUOTES); ?><?php endif; ?>
                        </td>
                        <td class="text-center"><span class="badge bg-label-info"><?= (int) $rRow['ip_count']; ?></span></td>
                        <td class="text-center"><a href="<?= htmlspecialchars($rLogsUrl, ENT_QUOTES); ?>" class="btn btn-sm btn-label-secondary"><?= $language::get('view_logs'); ?></a></td>
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
        if (!$) {
            return;
        }
        $('#lineips-table').DataTable({
            order: [
                [2, 'desc']
            ],
            columnDefs: [{
                orderable: false,
                targets: [3]
            }],
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 10); ?>,
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });
        document.getElementById('range').addEventListener('change', function() {
            window.location.href = 'line_ips?range=' + encodeURIComponent(this.value);
        });
    })();
</script>
</body>

</html>