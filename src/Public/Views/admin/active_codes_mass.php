<?php

/**
 * Mass Edit Active Codes (Bootstrap 5, Admin)
 */

$rPackages = $rPackages ?? [];
$batches   = $batches ?? [];
$resellers = $resellers ?? [];

?>

<div class="row justify-content-center">
    <div class="col-12 col-lg-9">
        <div class="card shadow-sm border-0">
            <div class="card-header border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title mb-1"><i class="ti tabler-adjustments text-primary me-2"></i><?= $language::get('mass_edit_active_codes') ?></h5>
                    <p class="text-muted small mb-0"><?= $language::get('ac_mass_edit_subtitle') ?></p>
                </div>
                <a href="active_codes" class="btn btn-sm btn-label-secondary">
                    <i class="ti tabler-arrow-left me-1"></i><?= $language::get('ac_back_to_codes') ?>
                </a>
            </div>

            <div class="card-body p-4">
                <form id="mass-edit-form">
                    <!-- Target Selection Mode -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">1. <?= $language::get('ac_select_target_codes') ?></label>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small" for="target_batch"><?= $language::get('ac_by_batch') ?></label>
                                <select id="target_batch" name="target_batch" class="form-select">
                                    <option value="">-- <?= $language::get('ac_all_batches') ?> --</option>
                                    <?php foreach ($batches as $b): ?>
                                        <option value="<?= htmlspecialchars((string)$b['batch_name'], ENT_QUOTES); ?>">
                                            <?= htmlspecialchars((string)$b['batch_name'], ENT_QUOTES); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small" for="target_reseller"><?= $language::get('ac_by_reseller') ?></label>
                                <select id="target_reseller" name="target_reseller" class="form-select">
                                    <option value="">-- <?= $language::get('ac_all_resellers') ?> --</option>
                                    <?php foreach ($resellers as $res): ?>
                                        <option value="<?= (int)$res['id']; ?>">
                                            <?= htmlspecialchars((string)$res['username'], ENT_QUOTES); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>

                    <hr class="text-muted opacity-25 my-4">

                    <!-- Action Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">2. <?= $language::get('ac_choose_mass_operation') ?></label>
                        <select id="mass_action_type" name="mass_action_type" class="form-select form-select-lg">
                            <option value="mass_enable"><?= $language::get('ac_enable_selected_codes_subscribers') ?></option>
                            <option value="mass_disable"><?= $language::get('ac_disable_suspend_selected_codes') ?></option>
                            <option value="mass_extend"><?= $language::get('ac_extend_subscription_expiry_add_days') ?></option>
                            <option value="mass_change_package"><?= $language::get('ac_change_package_bouquets') ?></option>
                            <option value="mass_reset_device"><?= $language::get('ac_reset_device_mac_hardware_lock') ?></option>
                            <option value="mass_delete" class="text-danger"><?= $language::get('ac_delete_codes_associated_lines') ?></option>
                        </select>
                    </div>

                    <!-- Dynamic Options -->
                    <div id="option-extend-box" class="p-3 bg-light-subtle rounded-3 border mb-4 d-none">
                        <label class="form-label fw-semibold" for="mass_days"><?= $language::get('ac_days_to_extend') ?></label>
                        <input type="number" id="mass_days" name="days" class="form-control" value="30" min="1" max="365">
                        <div class="form-text small"><?= $language::get('ac_extend_days_help') ?></div>
                    </div>

                    <div id="option-package-box" class="p-3 bg-light-subtle rounded-3 border mb-4 d-none">
                        <label class="form-label fw-semibold" for="new_package_id"><?= $language::get('ac_new_target_package') ?></label>
                        <select id="new_package_id" name="new_package_id" class="form-select">
                            <?php foreach ($rPackages as $pkg): ?>
                                <option value="<?= (int)$pkg['id']; ?>">
                                    <?= htmlspecialchars((string)$pkg['package_name'], ENT_QUOTES); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small"><?= $language::get('ac_change_package_help') ?></div>
                    </div>

                    <div class="d-flex justify-content-end gap-3 pt-3 border-top">
                        <button type="reset" class="btn btn-label-secondary"><?= $language::get('ac_reset') ?></button>
                        <button type="submit" class="btn btn-primary px-4" id="btn-apply-mass">
                            <i class="ti tabler-bolt me-1"></i><?= $language::get('ac_apply_mass_action') ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function($) {
        'use strict';
        $(function() {
            const actionSelect = $('#mass_action_type');
            const extendBox = $('#option-extend-box');
            const packageBox = $('#option-package-box');

            // Localized strings for the dynamic JS below.
            const L = {
                delete_matching: <?= json_encode($language::get('ac_delete_matching_codes')) ?>,
                apply: <?= json_encode($language::get('ac_apply_mass_action')) ?>,
                confirm_all: <?= json_encode($language::get('ac_mass_confirm_all')) ?>,
                no_matching: <?= json_encode($language::get('ac_no_matching_codes')) ?>,
                success: <?= json_encode($language::get('ac_mass_action_success')) ?>,
                failed: <?= json_encode($language::get('ac_action_failed')) ?>
            };

            actionSelect.on('change', function() {
                const val = this.value;
                extendBox.toggleClass('d-none', val !== 'mass_extend');
                packageBox.toggleClass('d-none', val !== 'mass_change_package');

                const btn = jQuery('#btn-apply-mass');
                if (val === 'mass_delete') {
                    btn.removeClass('btn-primary').addClass('btn-danger').html('<i class="ti tabler-trash me-1"></i>' + L.delete_matching);
                } else {
                    btn.removeClass('btn-danger').addClass('btn-primary').html('<i class="ti tabler-bolt me-1"></i>' + L.apply);
                }
            });

            jQuery('#mass-edit-form').on('submit', function(e) {
                e.preventDefault();

                const action = actionSelect.val();
                const batch = jQuery('#target_batch').val();
                const reseller = jQuery('#target_reseller').val();

                const proceed = (!batch && !reseller) ? window.xcConfirm(L.confirm_all) : Promise.resolve(true);
                proceed.then(function(ok) {
                    if (!ok) {
                        return;
                    }

                    const btn = jQuery('#btn-apply-mass');
                    btn.prop('disabled', true);

                    // Fetch matching code IDs (server returns keyed rows: use row.id).
                    jQuery.post('./table', {
                        id: 'active_codes',
                        batch: batch,
                        reseller: reseller,
                        start: 0,
                        length: 1000
                    }, function(tableRes) {
                        let res = tableRes;
                        if (typeof tableRes === 'string') {
                            try {
                                res = JSON.parse(tableRes);
                            } catch (e) {}
                        }

                        const ids = (res.data || []).map(row => row.id).filter(Boolean);

                        if (!ids.length) {
                            btn.prop('disabled', false);
                            xcToast(L.no_matching, 'error');
                            return;
                        }

                        const postData = {
                            action: 'multi',
                            type: 'active_code',
                            sub: action,
                            ids: JSON.stringify(ids),
                            days: jQuery('#mass_days').val(),
                            package_id: jQuery('#new_package_id').val()
                        };

                        jQuery.post('./api', postData, function(actionRes) {
                            btn.prop('disabled', false);
                            let aRes = actionRes;
                            if (typeof actionRes === 'string') {
                                try {
                                    aRes = JSON.parse(actionRes);
                                } catch (e) {}
                            }
                            if (aRes.result) {
                                xcToast(aRes.message || L.success, 'success');
                                window.location.href = 'active_codes';
                            } else {
                                xcToast(aRes.message || L.failed, 'error');
                            }
                        });
                    });
                });
            });
        });
    })(jQuery);
</script>
</body>

</html>