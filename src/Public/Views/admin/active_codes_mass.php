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
                    <h5 class="card-title mb-1"><i class="ti tabler-adjustments text-primary me-2"></i>Mass Edit Active Codes</h5>
                    <p class="text-muted small mb-0">Apply bulk changes to codes across a batch, a reseller, or specific codes.</p>
                </div>
                <a href="active_codes" class="btn btn-sm btn-label-secondary">
                    <i class="ti tabler-arrow-left me-1"></i>Back to Codes
                </a>
            </div>

            <div class="card-body p-4">
                <form id="mass-edit-form">
                    <!-- Target Selection Mode -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold">1. Select Target Codes</label>
                        <div class="row g-3">
                            <div class="col-12 col-md-6">
                                <label class="form-label small" for="target_batch">By Batch</label>
                                <select id="target_batch" name="target_batch" class="form-select">
                                    <option value="">-- All Batches --</option>
                                    <?php foreach ($batches as $b): ?>
                                        <option value="<?= htmlspecialchars((string)$b['batch_name'], ENT_QUOTES); ?>">
                                            <?= htmlspecialchars((string)$b['batch_name'], ENT_QUOTES); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12 col-md-6">
                                <label class="form-label small" for="target_reseller">By Reseller</label>
                                <select id="target_reseller" name="target_reseller" class="form-select">
                                    <option value="">-- All Resellers --</option>
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
                        <label class="form-label fw-semibold">2. Choose Mass Operation</label>
                        <select id="mass_action_type" name="mass_action_type" class="form-select form-select-lg">
                            <option value="mass_enable">Enable Selected Codes & Subscribers</option>
                            <option value="mass_disable">Disable / Suspend Selected Codes</option>
                            <option value="mass_extend">Extend Subscription Expiry (Add Days)</option>
                            <option value="mass_change_package">Change Package & Bouquets</option>
                            <option value="mass_reset_device">Reset Device / MAC Hardware Lock</option>
                            <option value="mass_delete" class="text-danger">Delete Codes & Associated Lines</option>
                        </select>
                    </div>

                    <!-- Dynamic Options -->
                    <div id="option-extend-box" class="p-3 bg-light-subtle rounded-3 border mb-4 d-none">
                        <label class="form-label fw-semibold" for="mass_days">Days to Extend</label>
                        <input type="number" id="mass_days" name="days" class="form-control" value="30" min="1" max="365">
                        <div class="form-text small">Adds this number of days to all currently active codes matching the filter.</div>
                    </div>

                    <div id="option-package-box" class="p-3 bg-light-subtle rounded-3 border mb-4 d-none">
                        <label class="form-label fw-semibold" for="new_package_id">New Target Package</label>
                        <select id="new_package_id" name="new_package_id" class="form-select">
                            <?php foreach ($rPackages as $pkg): ?>
                                <option value="<?= (int)$pkg['id']; ?>">
                                    <?= htmlspecialchars((string)$pkg['package_name'], ENT_QUOTES); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small">Updates both the voucher package and bouquets on the linked subscriber line.</div>
                    </div>

                    <div class="d-flex justify-content-end gap-3 pt-3 border-top">
                        <button type="reset" class="btn btn-label-secondary">Reset</button>
                        <button type="submit" class="btn btn-primary px-4" id="btn-apply-mass">
                            <i class="ti tabler-bolt me-1"></i>Apply Mass Action
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

    actionSelect.on('change', function() {
        const val = this.value;
        extendBox.toggleClass('d-none', val !== 'mass_extend');
        packageBox.toggleClass('d-none', val !== 'mass_change_package');

        const btn = jQuery('#btn-apply-mass');
        if (val === 'mass_delete') {
            btn.removeClass('btn-primary').addClass('btn-danger').html('<i class="ti tabler-trash me-1"></i>Delete Matching Codes');
        } else {
            btn.removeClass('btn-danger').addClass('btn-primary').html('<i class="ti tabler-bolt me-1"></i>Apply Mass Action');
        }
    });

    jQuery('#mass-edit-form').on('submit', function(e) {
        e.preventDefault();

        const action = actionSelect.val();
        const batch = jQuery('#target_batch').val();
        const reseller = jQuery('#target_reseller').val();

        if (!batch && !reseller) {
            if (!confirm('You have not selected a specific batch or reseller. This will apply to ALL codes in the system. Proceed?')) {
                return;
            }
        }

        const btn = jQuery('#btn-apply-mass');
        btn.prop('disabled', true);

        // Fetch matching code IDs
        jQuery.post('./table', {
            id: 'active_codes',
            batch: batch,
            reseller: reseller,
            start: 0,
            length: 1000
        }, function(tableRes) {
            let res = tableRes;
            if (typeof tableRes === 'string') {
                try { res = JSON.parse(tableRes); } catch(e) {}
            }

            const ids = (res.data || []).map(row => {
                const match = row[1] && row[1].match(/value="(\d+)"/);
                return match ? parseInt(match[1], 10) : null;
            }).filter(Boolean);

            if (!ids.length) {
                btn.prop('disabled', false);
                alert('No matching codes found for the selected target.');
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
                    try { aRes = JSON.parse(actionRes); } catch(e) {}
                }
                if (aRes.result) {
                    alert(aRes.message || 'Mass action applied successfully!');
                    window.location.href = 'active_codes';
                } else {
                    alert(aRes.message || 'Action failed.');
                }
            });
        });
    });
    });
})(jQuery);
</script>
</body>

</html>

