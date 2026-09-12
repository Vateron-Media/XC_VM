<?php

/**
 * Batch Manager for Active Codes (Bootstrap 5, Reseller)
 *
 * Client-side DataTable of voucher batches (same template as the admin batch
 * manager): export scratch-card .txt, batch enable/disable, and delete with a
 * refund option. Batch actions via api?action=active_codes_batch_action.
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
                    <h6 class="text-muted fw-normal small text-uppercase mb-1"><?= $language::get('ac_total_batches') ?></h6>
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
                    <h6 class="text-muted fw-normal small text-uppercase mb-1"><?= $language::get('ac_total_generated') ?></h6>
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
                    <h6 class="text-muted fw-normal small text-uppercase mb-1"><?= $language::get('ac_stock_unused') ?></h6>
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
                    <h6 class="text-muted fw-normal small text-uppercase mb-1"><?= $language::get('ac_active_subscriptions') ?></h6>
                    <h3 class="mb-0 fw-bold text-primary"><?= $totalActive; ?></h3>
                </div>
                <div class="avatar avatar-lg bg-label-warning rounded p-2">
                    <i class="ti tabler-player-play fs-2"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h5 class="card-title mb-0"><i class="ti tabler-folders text-primary me-2"></i><?= $language::get('batch_manager') ?></h5>
        <div class="d-flex gap-2">
            <a href="active_code" class="btn btn-sm btn-primary">
                <i class="ti tabler-plus me-1"></i><?= $language::get('ac_new_batch') ?>
            </a>
            <a href="active_codes" class="btn btn-sm btn-label-secondary">
                <i class="ti tabler-list me-1"></i><?= $language::get('ac_all_codes') ?>
            </a>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="batches-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('ac_batch_name') ?></th>
                    <th><?= $language::get('package') ?></th>
                    <th><?= $language::get('ac_vouchers_breakdown') ?></th>
                    <th><?= $language::get('ac_created_date') ?></th>
                    <th class="text-end"><?= $language::get('ac_batch_actions') ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($batches as $b):
                    $bName = (string) $b['batch_name'];
                ?>
                    <tr>
                        <td></td>
                        <td class="text-nowrap">
                            <span class="fw-semibold font-monospace"><?= htmlspecialchars($bName, ENT_QUOTES); ?></span>
                            <a href="active_codes?batch=<?= urlencode($bName); ?>" class="d-block small text-muted text-decoration-none">
                                <?= $language::get('ac_view_individual_codes') ?> <i class="ti tabler-arrow-right"></i>
                            </a>
                        </td>
                        <td>
                            <span class="badge bg-label-info"><?= htmlspecialchars((string) $b['package_name'], ENT_QUOTES); ?></span>
                            <?php if (!empty($b['is_trial'])): ?><span class="badge bg-label-warning ms-1"><?= $language::get('trial') ?></span><?php endif; ?>
                        </td>
                        <td class="text-nowrap" data-order="<?= (int) $b['total_codes']; ?>">
                            <span class="badge bg-label-success me-1"><i class="ti tabler-snowflake me-1"></i><?= (int) $b['stock_count']; ?> <?= $language::get('ac_stock') ?></span>
                            <span class="badge bg-label-primary me-1"><i class="ti tabler-player-play me-1"></i><?= (int) $b['active_count']; ?> <?= $language::get('active') ?></span>
                            <span class="badge bg-label-secondary"><?= (int) $b['total_codes']; ?> <?= $language::get('ac_total') ?></span>
                        </td>
                        <td class="text-nowrap" data-order="<?= (int) $b['created_at']; ?>">
                            <span class="text-muted"><?= $b['created_at'] ? date('Y-m-d H:i', (int) $b['created_at']) : '-'; ?></span>
                        </td>
                        <td class="text-end text-nowrap">
                            <a href="./api?action=active_codes_export_txt&batch_name=<?= urlencode($bName); ?>" class="btn btn-sm btn-icon btn-label-primary me-1" title="<?= $language::get('ac_download_printable_vouchers') ?>">
                                <i class="ti tabler-printer"></i>
                            </a>
                            <button type="button" class="btn btn-sm btn-icon btn-label-success btn-batch-enable me-1" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>" title="<?= $language::get('ac_enable_batch') ?>">
                                <i class="ti tabler-check"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-icon btn-label-warning btn-batch-disable me-1" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>" title="<?= $language::get('ac_disable_batch') ?>">
                                <i class="ti tabler-ban"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger btn-batch-delete" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>" data-stock="<?= (int) $b['stock_count']; ?>" title="<?= $language::get('ac_delete_batch') ?>">
                                <i class="ti tabler-trash"></i>
                            </button>
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
            <div class="modal-header border-bottom">
                <h5 class="modal-title text-danger"><i class="ti tabler-alert-triangle me-2"></i><?= $language::get('ac_delete_voucher_batch') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p><?= $language::get('ac_delete_batch_confirm_pre') ?> <strong id="modal-batch-name" class="font-monospace"></strong> <?= $language::get('ac_delete_batch_confirm_post') ?></p>
                <div class="form-check p-3 bg-light rounded-3 border mt-3">
                    <input class="form-check-input" type="checkbox" id="batch-refund" checked>
                    <label class="form-check-label fw-semibold" for="batch-refund">
                        <?= $language::get('ac_refund_unactivated_stock') ?> (<span id="modal-stock-count">0</span> <?= $language::get('ac_codes') ?>)
                    </label>
                    <div class="form-text small text-muted"><?= $language::get('ac_batch_refund_help') ?></div>
                </div>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel') ?></button>
                <button type="button" class="btn btn-danger" id="btn-confirm-batch-delete"><?= $language::get('ac_delete_batch') ?></button>
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
        const L = { failed: <?= json_encode($language::get('ac_action_failed')) ?> };
        let targetBatch = '';

        const table = jQuery('#batches-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [4, 'desc']
            ],
            columnDefs: [
                { targets: 0, orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { targets: [3, 5], orderable: false, searchable: false },
                { targets: 5, responsivePriority: 1 }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        function execBatchAction(batchName, subAction, extra) {
            jQuery.post('./api', Object.assign({
                action: 'active_codes_batch_action',
                batch_name: batchName,
                sub_action: subAction
            }, extra || {}), function(res) {
                let data = res;
                if (typeof res === 'string') {
                    try { data = JSON.parse(res); } catch (e) {}
                }
                if (data && data.result) {
                    location.reload();
                } else {
                    xcToast((data && data.message) || L.failed, 'error');
                }
            });
        }

        jQuery('#batches-table tbody').on('click', '.btn-batch-enable', function() {
            execBatchAction(jQuery(this).data('batch'), 'mass_enable');
        });

        jQuery('#batches-table tbody').on('click', '.btn-batch-disable', function() {
            execBatchAction(jQuery(this).data('batch'), 'mass_disable');
        });

        const batchDeleteModal = new bootstrap.Modal(document.getElementById('batchDeleteModal'));
        jQuery('#batches-table tbody').on('click', '.btn-batch-delete', function() {
            targetBatch = jQuery(this).data('batch');
            jQuery('#modal-batch-name').text(targetBatch);
            jQuery('#modal-stock-count').text(jQuery(this).data('stock'));
            batchDeleteModal.show();
        });

        jQuery('#btn-confirm-batch-delete').on('click', function() {
            if (!targetBatch) {
                return;
            }
            const refund = jQuery('#batch-refund').is(':checked') ? 1 : 0;
            execBatchAction(targetBatch, 'mass_delete', { refund_credits: refund });
            batchDeleteModal.hide();
        });
    });
})(jQuery);
</script>
</body>

</html>
