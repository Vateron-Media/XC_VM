<?php

/**
 * Admin Active Codes Batch Manager (Bootstrap 5)
 *
 * Client-side DataTable: ActiveCodeService::getBatchSummary() provides the rows,
 * rendered server-side into the DOM, enhanced by datatables-bs5 (same template as
 * the other admin tables). Batch actions via api?action=active_codes_batch_action.
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
                    <h6 class="text-muted fw-normal small text-uppercase mb-1"><?= $language::get('ac_total_codes') ?></h6>
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
                    <h6 class="text-muted fw-normal small text-uppercase mb-1"><?= $language::get('ac_unused_stock') ?></h6>
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
        <h5 class="card-title mb-0"><i class="ti tabler-folders text-primary me-2"></i><?= $language::get('ac_voucher_batches_all_resellers') ?></h5>
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
                    <th><?= $language::get('ac_creator_reseller') ?></th>
                    <th><?= $language::get('package') ?></th>
                    <th><?= $language::get('ac_breakdown') ?></th>
                    <th><?= $language::get('ac_date_created') ?></th>
                    <th><?= $language::get('actions') ?></th>
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
                                <?= $language::get('ac_view_codes') ?> <i class="ti tabler-arrow-right"></i>
                            </a>
                        </td>
                        <td>
                            <span class="badge bg-label-dark"><i class="ti tabler-user me-1"></i><?= htmlspecialchars((string) $b['creator_name'], ENT_QUOTES); ?></span>
                        </td>
                        <td>
                            <span class="badge bg-label-info"><?= htmlspecialchars((string) $b['package_name'], ENT_QUOTES); ?></span>
                        </td>
                        <td class="text-nowrap" data-order="<?= (int) $b['total_codes']; ?>">
                            <span class="badge bg-label-success me-1"><i class="ti tabler-snowflake me-1"></i><?= (int) $b['stock_count']; ?> <?= $language::get('ac_stock') ?></span>
                            <span class="badge bg-label-primary me-1"><i class="ti tabler-player-play me-1"></i><?= (int) $b['active_count']; ?> <?= $language::get('active') ?></span>
                            <span class="badge bg-label-secondary"><?= (int) $b['total_codes']; ?> <?= $language::get('ac_total') ?></span>
                        </td>
                        <td class="text-nowrap" data-order="<?= (int) $b['created_at']; ?>">
                            <span class="text-muted"><?= $b['created_at'] ? date('Y-m-d H:i', (int) $b['created_at']) : '-'; ?></span>
                        </td>
                        <td class="text-nowrap">
                            <a href="./api?action=active_codes_export_txt&batch_name=<?= urlencode($bName); ?>" class="btn btn-sm btn-icon btn-label-primary me-1" title="<?= $language::get('ac_export_scratch_cards_txt') ?>">
                                <i class="ti tabler-printer"></i>
                            </a>
                            <button type="button" class="btn btn-sm btn-icon btn-label-success js-batch-enable me-1" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>" title="<?= $language::get('ac_enable_batch') ?>">
                                <i class="ti tabler-check"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-icon btn-label-warning js-batch-disable me-1" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>" title="<?= $language::get('ac_disable_batch') ?>">
                                <i class="ti tabler-ban"></i>
                            </button>
                            <button type="button" class="btn btn-sm btn-icon btn-label-danger js-batch-delete" data-batch="<?= htmlspecialchars($bName, ENT_QUOTES); ?>" title="<?= $language::get('ac_delete_batch') ?>">
                                <i class="ti tabler-trash"></i>
                            </button>
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
        'use strict';
        var table = jQuery('#batches-table').DataTable({
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [5, 'desc']
            ],
            columnDefs: [{
                    targets: 0,
                    orderable: false,
                    searchable: false,
                    className: 'control',
                    responsivePriority: 2
                },
                {
                    targets: [4, 6],
                    orderable: false,
                    searchable: false
                },
                {
                    targets: 6,
                    responsivePriority: 1
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        function batchAction(batchName, subAction) {
            jQuery.post('./api', {
                action: 'active_codes_batch_action',
                batch_name: batchName,
                sub_action: subAction
            }, function(res) {
                var data = res;
                if (typeof res === 'string') {
                    try {
                        data = JSON.parse(res);
                    } catch (e) {}
                }
                if (data && data.result) {
                    location.reload();
                } else {
                    xcToast((data && data.message) || 'Action failed.', 'error');
                }
            });
        }

        jQuery('#batches-table tbody').on('click', '.js-batch-enable', function() {
            batchAction(this.getAttribute('data-batch'), 'mass_enable');
        });

        jQuery('#batches-table tbody').on('click', '.js-batch-disable', function() {
            batchAction(this.getAttribute('data-batch'), 'mass_disable');
        });

        jQuery('#batches-table tbody').on('click', '.js-batch-delete', function() {
            var batch = this.getAttribute('data-batch');
            window.xcConfirm('Delete batch "' + batch + '" and all its codes?').then(function(ok) {
                if (ok) {
                    batchAction(batch, 'mass_delete');
                }
            });
        });
    })();
</script>
</body>

</html>
