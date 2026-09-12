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
        <div class="card h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <span class="d-block text-body-secondary small text-uppercase fw-semibold mb-1">Total Batches</span>
                    <h4 class="mb-0 fw-bold"><?= $totalBatches; ?></h4>
                </div>
                <div class="avatar avatar-lg bg-label-primary rounded p-2">
                    <i class="icon-base ti tabler-folders fs-2"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <span class="d-block text-body-secondary small text-uppercase fw-semibold mb-1">Total Generated</span>
                    <h4 class="mb-0 fw-bold text-info"><?= $totalCodes; ?></h4>
                </div>
                <div class="avatar avatar-lg bg-label-info rounded p-2">
                    <i class="icon-base ti tabler-key fs-2"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <span class="d-block text-body-secondary small text-uppercase fw-semibold mb-1">Stock / Unused</span>
                    <h4 class="mb-0 fw-bold text-success"><?= $totalStock; ?></h4>
                </div>
                <div class="avatar avatar-lg bg-label-success rounded p-2">
                    <i class="icon-base ti tabler-snowflake fs-2"></i>
                </div>
            </div>
        </div>
    </div>
    <div class="col-12 col-sm-6 col-xl-3">
        <div class="card h-100">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <span class="d-block text-body-secondary small text-uppercase fw-semibold mb-1">Active Subscriptions</span>
                    <h4 class="mb-0 fw-bold text-primary"><?= $totalActive; ?></h4>
                </div>
                <div class="avatar avatar-lg bg-label-warning rounded p-2">
                    <i class="icon-base ti tabler-player-play fs-2"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Batches DataTable Card -->
<div class="card">
    <div class="card-header border-bottom d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="card-title mb-1 d-flex align-items-center gap-2">
                <span class="avatar avatar-sm bg-label-primary rounded p-1">
                    <i class="icon-base ti tabler-folders fs-5"></i>
                </span>
                <span>Batch Manager</span>
            </h5>
            <p class="text-body-secondary small mb-0">Grouped voucher management, scratch card voucher exporting, and batch operations.</p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <a href="active_code" class="btn btn-primary">
                <i class="icon-base ti tabler-plus me-1"></i>New Batch
            </a>
            <a href="active_codes" class="btn btn-label-secondary">
                <i class="icon-base ti tabler-list me-1"></i>All Codes
            </a>
        </div>
    </div>

    <div class="card-datatable table-responsive">
        <table id="batches-table" class="table table-hover border-top" style="width:100%">
            <thead>
                <tr>
                    <th>Batch Name</th>
                    <th>Package</th>
                    <th style="min-width: 200px;">Vouchers Breakdown</th>
                    <th>Created Date</th>
                    <th class="text-end" style="width: 160px;">Batch Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batches as $b): 
                    $tot = max(1, (int)$b['total_codes']);
                    $stockCount = (int)$b['stock_count'];
                    $activeCount = (int)$b['active_count'];
                    $stockPct = round(($stockCount / $tot) * 100);
                    $activePct = round(($activeCount / $tot) * 100);
                    $bName = (string)$b['batch_name'];
                    $pkgName = (string)$b['package_name'];
                ?>
                    <tr>
                        <!-- Batch Name -->
                        <td>
                            <div class="d-flex align-items-center gap-2">
                                <div class="avatar avatar-sm rounded bg-label-primary p-1 d-flex align-items-center justify-content-center flex-shrink-0">
                                    <i class="icon-base ti tabler-folder fs-5"></i>
                                </div>
                                <div>
                                    <div class="d-flex align-items-center gap-1">
                                        <span class="font-monospace fw-bold text-heading" title="<?= htmlspecialchars($bName, ENT_QUOTES); ?>">
                                            <?= htmlspecialchars($bName, ENT_QUOTES); ?>
                                        </span>
                                        <button type="button" class="btn btn-xs btn-icon btn-label-secondary js-copy-batch ms-1" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>" title="Copy Batch Code">
                                            <i class="icon-base ti tabler-copy fs-6"></i>
                                        </button>
                                    </div>
                                    <a href="active_codes?batch=<?= urlencode($bName); ?>" class="small text-primary d-inline-flex align-items-center gap-1 text-decoration-none mt-1">
                                        <span>View individual codes (<?= (int)$b['total_codes']; ?>)</span>
                                        <i class="icon-base ti tabler-arrow-right fs-7"></i>
                                    </a>
                                </div>
                            </div>
                        </td>

                        <!-- Package -->
                        <td>
                            <div class="d-inline-flex flex-wrap align-items-center gap-1" title="<?= htmlspecialchars($pkgName, ENT_QUOTES); ?>">
                                <span class="badge bg-label-info text-truncate d-inline-block" style="max-width: 220px;">
                                    <i class="icon-base ti tabler-cube me-1"></i><?= htmlspecialchars($pkgName, ENT_QUOTES); ?>
                                </span>
                                <?php if (!empty($b['is_trial'])): ?>
                                    <span class="badge bg-label-warning">Trial</span>
                                <?php endif; ?>
                            </div>
                        </td>

                        <!-- Vouchers Breakdown -->
                        <td>
                            <div class="d-flex flex-column gap-1" style="min-width: 180px;">
                                <div class="d-flex justify-content-between align-items-center small">
                                    <span class="badge bg-label-success px-2 py-0" title="<?= $stockCount; ?> Stock (<?= $stockPct; ?>%)">
                                        <i class="icon-base ti tabler-snowflake me-1"></i><?= $stockCount; ?> Stock
                                    </span>
                                    <span class="badge bg-label-primary px-2 py-0" title="<?= $activeCount; ?> Active (<?= $activePct; ?>%)">
                                        <i class="icon-base ti tabler-player-play me-1"></i><?= $activeCount; ?> Active
                                    </span>
                                    <span class="text-body-secondary small fw-medium">
                                        <?= (int)$b['total_codes']; ?> Total
                                    </span>
                                </div>
                                <div class="progress w-100" style="height: 6px;" title="Stock: <?= $stockPct; ?>% | Active: <?= $activePct; ?>%">
                                    <div class="progress-bar bg-success" role="progressbar" style="width: <?= $stockPct; ?>%;" aria-valuenow="<?= $stockPct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                    <div class="progress-bar bg-primary" role="progressbar" style="width: <?= $activePct; ?>%;" aria-valuenow="<?= $activePct; ?>" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                            </div>
                        </td>

                        <!-- Created Date -->
                        <td data-order="<?= (int)$b['created_at']; ?>">
                            <span class="d-block text-heading fw-medium small">
                                <?= $b['created_at'] ? date('Y-m-d', (int)$b['created_at']) : '-'; ?>
                            </span>
                            <span class="text-body-secondary small">
                                <?= $b['created_at'] ? date('H:i A', (int)$b['created_at']) : ''; ?>
                            </span>
                        </td>

                        <!-- Batch Actions -->
                        <td class="text-end text-nowrap">
                            <div class="d-inline-flex align-items-center gap-1">
                                <a href="./api?action=active_codes_export_txt&batch_name=<?= urlencode($bName); ?>" class="btn btn-sm btn-label-primary" title="Download Printable Vouchers .txt">
                                    <i class="icon-base ti tabler-file-download me-1"></i>Export
                                </a>
                                <div class="btn-group">
                                    <button type="button" class="btn btn-sm btn-icon btn-label-secondary dropdown-toggle hide-arrow" data-bs-toggle="dropdown" aria-expanded="false" title="More Actions">
                                        <i class="icon-base ti tabler-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end">
                                        <li>
                                            <h6 class="dropdown-header text-uppercase">Batch Operations</h6>
                                        </li>
                                        <li>
                                            <a class="dropdown-item btn-batch-enable d-flex align-items-center gap-2" href="#" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>">
                                                <i class="icon-base ti tabler-check text-success"></i>
                                                <span>Enable Batch</span>
                                            </a>
                                        </li>
                                        <li>
                                            <a class="dropdown-item btn-batch-disable d-flex align-items-center gap-2" href="#" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>">
                                                <i class="icon-base ti tabler-ban text-warning"></i>
                                                <span>Disable Batch</span>
                                            </a>
                                        </li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li>
                                            <a class="dropdown-item text-danger btn-batch-delete d-flex align-items-center gap-2" href="#" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>" data-stock="<?= $stockCount; ?>">
                                                <i class="icon-base ti tabler-trash"></i>
                                                <span>Delete Batch</span>
                                            </a>
                                        </li>
                                    </ul>
                                </div>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Batch Delete Confirmation Modal -->
<div class="modal fade" id="batchDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title text-danger d-flex align-items-center gap-2">
                    <i class="icon-base ti tabler-alert-triangle fs-4"></i>
                    <span>Delete Voucher Batch</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-3">Are you sure you want to permanently delete batch <strong id="modal-batch-name" class="font-monospace text-primary"></strong> and all its codes?</p>
                <div class="form-check p-3 bg-label-secondary rounded border">
                    <input class="form-check-input" type="checkbox" id="batch-refund" checked>
                    <label class="form-check-label fw-semibold" for="batch-refund">
                        Refund credits for unactivated stock codes (<span id="modal-stock-count" class="text-success">0</span> codes)
                    </label>
                    <div class="form-text small text-body-secondary mt-1">Refunds purchase credits directly back to your balance for any code that has not been redeemed.</div>
                </div>
            </div>
            <div class="modal-footer">
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
        var table = $('#batches-table').DataTable({
            order: [[3, 'desc']],
            columnDefs: [
                { targets: [2, 4], orderable: false },
                { targets: 4, searchable: false }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        var targetBatch = '';

        // Copy batch name to clipboard
        $(document).on('click', '.js-copy-batch', function(e) {
            e.preventDefault();
            var textToCopy = $(this).data('batch');
            if (!textToCopy) return;

            var $btn = $(this);
            var origHtml = $btn.html();

            var onSuccess = function() {
                if (window.xcToast) {
                    window.xcToast('Copied ' + textToCopy + ' to clipboard!', 'success');
                }
                $btn.html('<i class="icon-base ti tabler-check text-success fs-6"></i>');
                setTimeout(function() {
                    $btn.html(origHtml);
                }, 1500);
            };

            if (navigator.clipboard && window.isSecureContext) {
                navigator.clipboard.writeText(textToCopy).then(onSuccess).catch(function() {
                    fallbackCopy(textToCopy, onSuccess);
                });
            } else {
                fallbackCopy(textToCopy, onSuccess);
            }
        });

        function fallbackCopy(text, cb) {
            var ta = document.createElement('textarea');
            ta.value = text;
            ta.style.position = 'fixed';
            ta.style.left = '-9999px';
            document.body.appendChild(ta);
            ta.select();
            try {
                document.execCommand('copy');
                cb();
            } catch(e) {}
            document.body.removeChild(ta);
        }

        // Batch Enable
        $(document).on('click', '.btn-batch-enable', function(e) {
            e.preventDefault();
            execBatchAction($(this).data('batch'), 'mass_enable');
        });

        // Batch Disable
        $(document).on('click', '.btn-batch-disable', function(e) {
            e.preventDefault();
            execBatchAction($(this).data('batch'), 'mass_disable');
        });

        // Batch Delete Modal
        var batchDeleteModal = new bootstrap.Modal(document.getElementById('batchDeleteModal'));
        $(document).on('click', '.btn-batch-delete', function(e) {
            e.preventDefault();
            targetBatch = $(this).data('batch');
            var stockCount = $(this).data('stock');
            $('#modal-batch-name').text(targetBatch);
            $('#modal-stock-count').text(stockCount);
            batchDeleteModal.show();
        });

        $('#btn-confirm-batch-delete').on('click', function() {
            if (!targetBatch) return;
            var refund = $('#batch-refund').is(':checked') ? 1 : 0;
            execBatchAction(targetBatch, 'mass_delete', { refund_credits: refund });
            batchDeleteModal.hide();
        });

        function execBatchAction(batchName, subAction, extra) {
            extra = extra || {};
            var postData = Object.assign({
                action: 'active_codes_batch_action',
                batch_name: batchName,
                sub_action: subAction
            }, extra);

            $.post('./api', postData, function(res) {
                var data = res;
                if (typeof res === 'string') {
                    try { data = JSON.parse(res); } catch(e) {}
                }
                if (data && data.result) {
                    if (window.xcToast) {
                        window.xcToast(data.message || 'Action executed successfully.', 'success');
                    }
                    location.reload();
                } else {
                    var errMsg = (data && data.message) ? data.message : 'Action failed.';
                    if (window.xcToast) {
                        window.xcToast(errMsg, 'error');
                    } else {
                        alert(errMsg);
                    }
                }
            });
        }
    });
})(jQuery);
</script>
</body>

</html>
