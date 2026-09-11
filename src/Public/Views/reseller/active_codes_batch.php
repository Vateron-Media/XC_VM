<?php

/**
 * Batch Manager for Active Codes (Bootstrap 5, Reseller)
 *
 * Manage voucher batches, export scratch card formatted .txt files,
 * copy full batches, and batch toggle/delete with refund calculation.
 */

$batches = $batches ?? [];

$totalBatches = count($batches);
$totalCodes = array_sum(array_column($batches, 'total_codes'));
$totalStock = array_sum(array_column($batches, 'stock_count'));
$totalActive = array_sum(array_column($batches, 'active_count'));

?>

<!-- Metrics Summary -->
<div class="row g-4 mb-4">
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted fw-normal small text-uppercase mb-1">Total Batches</h6>
                    <h3 class="mb-0 fw-bold"><?= $totalBatches; ?></h3>
                </div>
                <div class="avatar avatar-lg bg-label-primary rounded p-2">
                    <i class="ti tabler-folders fs-2"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted fw-normal small text-uppercase mb-1">Total Generated</h6>
                    <h3 class="mb-0 fw-bold text-info"><?= $totalCodes; ?></h3>
                </div>
                <div class="avatar avatar-lg bg-label-info rounded p-2">
                    <i class="ti tabler-key fs-2"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted fw-normal small text-uppercase mb-1">Stock / Unused</h6>
                    <h3 class="mb-0 fw-bold text-success"><?= $totalStock; ?></h3>
                </div>
                <div class="avatar avatar-lg bg-label-success rounded p-2">
                    <i class="ti tabler-snowflake fs-2"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card shadow-sm border-0">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <h6 class="text-muted fw-normal small text-uppercase mb-1">Active Subscriptions</h6>
                    <h3 class="mb-0 fw-bold text-primary"><?= $totalActive; ?></h3>
                </div>
                <div class="avatar avatar-lg bg-label-warning rounded p-2">
                    <i class="ti tabler-player-play fs-2"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Batches List -->
<div class="card shadow-sm border-0">
    <div class="card-header border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="card-title mb-1"><i class="ti tabler-folders text-primary me-2"></i>Batch Manager</h5>
            <p class="text-muted small mb-0">Grouped voucher management, scratch card voucher exporting, and batch operations.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="active_code" class="btn btn-primary">
                <i class="ti tabler-plus me-1"></i>New Batch
            </a>
            <a href="active_codes" class="btn btn-label-secondary">
                <i class="ti tabler-list me-1"></i>All Codes
            </a>
        </div>
    </div>

    <div class="card-body p-0">
        <?php if (empty($batches)): ?>
            <div class="text-center py-5">
                <div class="avatar avatar-xl bg-label-secondary rounded-circle mx-auto mb-3 p-3">
                    <i class="ti tabler-folders-off fs-1"></i>
                </div>
                <h5>No batches created yet</h5>
                <p class="text-muted">Generate your first batch of active codes to begin managing vouchers.</p>
                <a href="active_code" class="btn btn-primary mt-2">Generate Codes</a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>Batch Name</th>
                            <th>Package</th>
                            <th style="min-width: 220px;">Vouchers Breakdown</th>
                            <th>Created Date</th>
                            <th class="text-end" style="width: 200px;">Batch Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($batches as $b): 
                            $tot = max(1, (int)$b['total_codes']);
                            $stockPct = round(((int)$b['stock_count'] / $tot) * 100);
                            $activePct = round(((int)$b['active_count'] / $tot) * 100);
                            $bName = (string)$b['batch_name'];
                        ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <div class="avatar avatar-sm bg-label-primary rounded p-1">
                                            <i class="ti tabler-folder"></i>
                                        </div>
                                        <div>
                                            <span class="fw-bold font-monospace fs-6"><?= htmlspecialchars($bName, ENT_QUOTES); ?></span>
                                            <a href="active_codes?batch=<?= urlencode($bName); ?>" class="d-block small text-muted text-decoration-none">
                                                View individual codes <i class="ti tabler-arrow-right small"></i>
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-label-info"><?= htmlspecialchars((string)$b['package_name'], ENT_QUOTES); ?></span>
                                    <?php if (!empty($b['is_trial'])): ?>
                                        <span class="badge bg-label-warning ms-1">Trial</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex justify-content-between small mb-1">
                                        <span class="text-success fw-semibold"><i class="ti tabler-snowflake"></i> <?= (int)$b['stock_count']; ?> Stock</span>
                                        <span class="text-primary fw-semibold"><i class="ti tabler-player-play"></i> <?= (int)$b['active_count']; ?> Active</span>
                                        <span class="text-muted"><?= (int)$b['total_codes']; ?> Total</span>
                                    </div>
                                    <div class="progress" style="height: 6px;">
                                        <div class="progress-bar bg-success" role="progressbar" style="width: <?= $stockPct; ?>%" title="Stock: <?= $stockPct; ?>%"></div>
                                        <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $activePct; ?>%" title="Active: <?= $activePct; ?>%"></div>
                                    </div>
                                </td>
                                <td>
                                    <span class="text-muted small"><?= $b['created_at'] ? date('Y-m-d H:i', (int)$b['created_at']) : '-'; ?></span>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <a href="./api?action=active_codes_export_txt&batch_name=<?= urlencode($bName); ?>" class="btn btn-sm btn-outline-primary" title="Download Printable Vouchers .txt">
                                            <i class="ti tabler-printer me-1"></i>Print / Export
                                        </a>
                                        <button type="button" class="btn btn-sm btn-outline-primary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown"></button>
                                        <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                                            <li>
                                                <a class="dropdown-item btn-batch-enable" href="#" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>">
                                                    <i class="ti tabler-check text-success me-2"></i>Enable Batch
                                                </a>
                                            </li>
                                            <li>
                                                <a class="dropdown-item btn-batch-disable" href="#" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>">
                                                    <i class="ti tabler-ban text-warning me-2"></i>Disable Batch
                                                </a>
                                            </li>
                                            <li><hr class="dropdown-divider"></li>
                                            <li>
                                                <a class="dropdown-item text-danger btn-batch-delete" href="#" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>" data-stock="<?= (int)$b['stock_count']; ?>">
                                                    <i class="ti tabler-trash me-2"></i>Delete Batch
                                                </a>
                                            </li>
                                        </ul>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Batch Delete Confirmation Modal -->
<div class="modal fade" id="batchDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-bottom">
                <h5 class="modal-title text-danger"><i class="ti tabler-alert-triangle me-2"></i>Delete Voucher Batch</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p>Are you sure you want to permanently delete batch <strong id="modal-batch-name" class="font-monospace"></strong> and all its codes?</p>
                <div class="form-check p-3 bg-light rounded-3 border mt-3">
                    <input class="form-check-input" type="checkbox" id="batch-refund" checked>
                    <label class="form-check-label fw-semibold" for="batch-refund">
                        Refund credits for unactivated stock codes (<span id="modal-stock-count">0</span> codes)
                    </label>
                    <div class="form-text small text-muted">Refunds purchase credits directly back to your balance for any code that has not been redeemed.</div>
                </div>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-batch-delete">Delete Batch</button>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
(function($) {
    'use strict';
    $(function() {
        let targetBatch = '';

    // Batch Enable
    jQuery('.btn-batch-enable').on('click', function(e) {
        e.preventDefault();
        const batch = jQuery(this).data('batch');
        execBatchAction(batch, 'mass_enable');
    });

    // Batch Disable
    jQuery('.btn-batch-disable').on('click', function(e) {
        e.preventDefault();
        const batch = jQuery(this).data('batch');
        execBatchAction(batch, 'mass_disable');
    });

    // Batch Delete
    const batchDeleteModal = new bootstrap.Modal(document.getElementById('batchDeleteModal'));
    jQuery('.btn-batch-delete').on('click', function(e) {
        e.preventDefault();
        targetBatch = jQuery(this).data('batch');
        const stockCount = jQuery(this).data('stock');
        jQuery('#modal-batch-name').text(targetBatch);
        jQuery('#modal-stock-count').text(stockCount);
        batchDeleteModal.show();
    });

    jQuery('#btn-confirm-batch-delete').on('click', function() {
        if (!targetBatch) return;
        const refund = jQuery('#batch-refund').is(':checked') ? 1 : 0;
        execBatchAction(targetBatch, 'mass_delete', { refund_credits: refund });
        batchDeleteModal.hide();
    });

    function execBatchAction(batchName, subAction, extra = {}) {
        const postData = Object.assign({
            action: 'active_codes_batch_action',
            batch_name: batchName,
            sub_action: subAction
        }, extra);

        jQuery.post('./api', postData, function(res) {
            let data = res;
            if (typeof res === 'string') {
                try { data = JSON.parse(res); } catch(e) {}
            }
            if (data.result) {
                location.reload();
            } else {
                alert(data.message || 'Action failed.');
            }
        });
    }
    });
})(jQuery);
</script>
</body>

</html>

