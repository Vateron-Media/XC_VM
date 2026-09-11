<?php

/**
 * Admin Generate Active Codes (Bootstrap 5)
 *
 * Provision active codes for any reseller without credit restrictions.
 */

$rPackages = $rPackages ?? [];
$rBouquets = $rBouquets ?? [];
$rResellers = $rResellers ?? [];

?>

<style>
.bq-wrapper {
    background-color: var(--bs-body-bg, #25293c);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 0.75rem;
}
.bq-scroll-area {
    max-height: 290px;
    overflow-y: auto;
    scrollbar-width: thin;
}
.bq-tile {
    background-color: var(--bs-card-bg, #2f3349);
    border: 1px solid rgba(255, 255, 255, 0.08);
    border-radius: 0.5rem;
    transition: all 0.2s ease-in-out;
    cursor: pointer;
    user-select: none;
}
.bq-tile:hover {
    border-color: #7367f0;
    background-color: rgba(115, 103, 240, 0.06);
    transform: translateY(-1px);
}
.bq-tile.is-checked {
    border-color: #7367f0 !important;
    background-color: rgba(115, 103, 240, 0.12) !important;
}
.bq-filter-tab.active {
    background-color: #7367f0 !important;
    color: #fff !important;
    border-color: #7367f0 !important;
}
</style>

<div class="row g-4">
    <div class="col-12 col-xl-8">
        <div class="card shadow-sm border-0">
            <div class="card-header border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title mb-1"><i class="ti tabler-sparkles text-primary me-2"></i>Generate Active Codes (Admin)</h5>
                    <p class="text-muted small mb-0">Admins can provision active vouchers for any reseller or system inventory with 0 credit deduction.</p>
                </div>
                <a href="active_codes" class="btn btn-sm btn-label-secondary">
                    <i class="ti tabler-arrow-left me-1"></i>Back to Codes
                </a>
            </div>

            <div class="card-body p-4">
                <form id="admin-active-code-form">
                    <!-- Reseller Assignment -->
                    <div class="mb-4 p-3 bg-light-subtle rounded-3 border">
                        <label class="form-label fw-semibold" for="created_by">Assign Ownership to Reseller</label>
                        <select id="created_by" name="created_by" class="form-select select2">
                            <option value="1">System Administrator (Admin Inventory)</option>
                            <?php foreach ($rResellers as $res): 
                                if ((int)$res['id'] === 1) continue;
                            ?>
                                <option value="<?= (int)$res['id']; ?>">
                                    <?= htmlspecialchars((string)$res['username'], ENT_QUOTES); ?> (<?= number_format((float)$res['credits'], 2); ?> Credits)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text small">Admin generations are exempt from credit costs. Selected reseller will be designated as the creator.</div>
                    </div>

                    <!-- Batch Information -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-8">
                            <label class="form-label fw-semibold" for="batch_name">Batch Name / Voucher Label</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ti tabler-tag"></i></span>
                                <input type="text" id="batch_name" name="batch_name" class="form-control font-monospace" placeholder="BATCH-202609-XXXX" value="BATCH-<?= date('Ymd'); ?>-<?= strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)); ?>">
                                <button type="button" class="btn btn-outline-secondary" id="btn-rand-batch">
                                    <i class="ti tabler-refresh"></i>
                                </button>
                            </div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold" for="code_format">Format</label>
                            <select id="code_format" name="code_format" class="form-select">
                                <option value="alphanumeric" selected>Alphanumeric (8X7K9P2M)</option>
                                <option value="numeric">Numeric PIN (87219430)</option>
                            </select>
                        </div>
                    </div>

                    <!-- Quantity & Length -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-7">
                            <label class="form-label fw-semibold" for="num_codes">Quantity of Codes</label>
                            <div class="input-group">
                                <input type="number" id="num_codes" name="num_codes" class="form-control fs-5 fw-bold text-center" value="5" min="1" max="500">
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="5">5</button>
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="10">10</button>
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="25">25</button>
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="50">50</button>
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="100">100</button>
                            </div>
                        </div>

                        <div class="col-12 col-md-5">
                            <label class="form-label fw-semibold" for="code_length">Code Length</label>
                            <select id="code_length" name="code_length" class="form-select">
                                <option value="8">8 Characters</option>
                                <option value="10" selected>10 Characters (Standard)</option>
                                <option value="12">12 Characters</option>
                                <option value="16">16 Characters</option>
                            </select>
                        </div>
                    </div>

                    <!-- Target Package -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="package_id">Subscription Package <span class="text-danger">*</span></label>
                        <select id="package_id" name="package_id" class="form-select form-select-lg" required>
                            <option value="" disabled selected>-- Select a Package --</option>
                            <?php foreach ($rPackages as $pkg): ?>
                                <option value="<?= (int)$pkg['id']; ?>" data-trial="<?= !empty($pkg['is_trial']) ? 1 : 0; ?>">
                                    <?= htmlspecialchars((string)$pkg['package_name'], ENT_QUOTES); ?>
                                    <?= !empty($pkg['is_trial']) ? ' - [TRIAL]' : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Bouquets Customization -->
                    <div class="mb-4">
                        <div class="bq-wrapper p-3">
                            <!-- Header Toolbar -->
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                                <div class="d-flex align-items-center gap-2">
                                    <div class="avatar avatar-xs bg-label-primary rounded-2 p-1 d-flex align-items-center justify-content-center" style="width: 34px; height: 34px;">
                                        <i class="ti tabler-category-2 fs-5 text-primary"></i>
                                    </div>
                                    <div>
                                        <label class="form-label fw-bold mb-0 fs-6">Bouquets Included</label>
                                        <div class="small text-muted">Select streaming content categories for this voucher</div>
                                    </div>
                                </div>
                                <div class="d-flex align-items-center gap-2">
                                    <span class="badge bg-label-primary px-3 py-2 fs-6" id="bq-selected-count">
                                        <?= count($rBouquets); ?> / <?= count($rBouquets); ?> Selected
                                    </span>
                                </div>
                            </div>

                            <!-- Search Bar & Action Buttons -->
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3 pb-2 border-bottom border-secondary border-opacity-10">
                                <!-- Search -->
                                <div class="input-group input-group-sm" style="max-width: 260px;">
                                    <span class="input-group-text bg-transparent"><i class="ti tabler-search"></i></span>
                                    <input type="text" id="filter-bq-input" class="form-control" placeholder="Search bouquets...">
                                    <button type="button" class="btn btn-outline-secondary d-none" id="btn-clear-bq-search">
                                        <i class="ti tabler-x"></i>
                                    </button>
                                </div>

                                <!-- Action Buttons -->
                                <div class="d-flex flex-wrap gap-1">
                                    <button type="button" class="btn btn-sm btn-label-primary" id="btn-select-all-bq" title="Select all bouquets">
                                        <i class="ti tabler-checks me-1"></i>Select All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-label-secondary" id="btn-deselect-all-bq" title="Deselect all">
                                        <i class="ti tabler-x me-1"></i>Clear All
                                    </button>
                                    <button type="button" class="btn btn-sm btn-label-info" id="btn-invert-bq" title="Invert selection">
                                        <i class="ti tabler-arrows-shuffle me-1"></i>Invert
                                    </button>
                                    <button type="button" class="btn btn-sm btn-label-danger" id="btn-filter-adult" title="Exclude adult 18+ content">
                                        <i class="ti tabler-shield-lock me-1"></i>Exclude 18+
                                    </button>
                                </div>
                            </div>

                            <!-- Filter Tabs: All | Selected | Unselected | 18+ -->
                            <div class="d-flex flex-wrap gap-1 mb-3">
                                <button type="button" class="btn btn-xs btn-label-secondary bq-filter-tab active" data-filter="all">
                                    All (<span class="tab-count-all"><?= count($rBouquets); ?></span>)
                                </button>
                                <button type="button" class="btn btn-xs btn-label-secondary bq-filter-tab" data-filter="selected">
                                    Selected (<span class="tab-count-sel"><?= count($rBouquets); ?></span>)
                                </button>
                                <button type="button" class="btn btn-xs btn-label-secondary bq-filter-tab" data-filter="unselected">
                                    Unselected (<span class="tab-count-unsel">0</span>)
                                </button>
                                <?php 
                                    $adultCount = 0;
                                    foreach ($rBouquets as $b) {
                                        $bn = (string)$b['bouquet_name'];
                                        if (stripos($bn, 'adult') !== false || stripos($bn, 'xxx') !== false || stripos($bn, '+18') !== false || stripos($bn, '18+') !== false) {
                                            $adultCount++;
                                        }
                                    }
                                    if ($adultCount > 0):
                                ?>
                                <button type="button" class="btn btn-xs btn-label-danger bq-filter-tab" data-filter="adult">
                                    <i class="ti tabler-lock me-1"></i>18+ Adult (<?= $adultCount; ?>)
                                </button>
                                <?php endif; ?>
                            </div>

                            <!-- Bouquets Grid with Custom Scroll -->
                            <div class="bq-scroll-area p-1">
                                <div class="row g-2" id="bouquets-grid">
                                    <?php foreach ($rBouquets as $bq): 
                                        $bqName = (string)$bq['bouquet_name'];
                                        $isAdult = (stripos($bqName, 'adult') !== false || stripos($bqName, 'xxx') !== false || stripos($bqName, '+18') !== false || stripos($bqName, '18+') !== false);
                                    ?>
                                        <div class="col-12 col-md-6 bouquet-item" data-name="<?= strtolower(htmlspecialchars($bqName, ENT_QUOTES)); ?>" data-adult="<?= $isAdult ? 1 : 0; ?>">
                                            <label class="bq-tile is-checked d-flex align-items-center justify-content-between p-2 px-3 w-100 mb-0" for="abq_<?= (int)$bq['id']; ?>">
                                                <div class="d-flex align-items-center gap-2 text-truncate me-2">
                                                    <input class="form-check-input bq-checkbox m-0 flex-shrink-0" type="checkbox" name="bouquets_selected[]" value="<?= (int)$bq['id']; ?>" id="abq_<?= (int)$bq['id']; ?>" checked>
                                                    <span class="bq-name small fw-semibold text-truncate text-body"><?= htmlspecialchars($bqName, ENT_QUOTES); ?></span>
                                                </div>
                                                <?php if ($isAdult): ?>
                                                    <span class="badge bg-label-danger flex-shrink-0 px-2 py-1" style="font-size: 0.65rem;">
                                                        <i class="ti tabler-lock me-1"></i>18+
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-label-secondary flex-shrink-0 px-2 py-1" style="font-size: 0.65rem;">
                                                        <i class="ti tabler-device-tv text-primary"></i>
                                                    </span>
                                                <?php endif; ?>
                                            </label>
                                        </div>
                                    <?php endforeach; ?>
                                    <div id="bq-no-results" class="col-12 text-center py-4 text-muted d-none">
                                        <i class="ti tabler-folder-off fs-1 mb-1 d-block text-secondary"></i>
                                        <div>No bouquets found matching your filter.</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Routing & Geo -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="dns_base">Portal Base DNS (Optional)</label>
                            <input type="text" id="dns_base" name="dns_base" class="form-control" placeholder="http://domain.com:port">
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="forced_country">Forced Geo-Lock Country</label>
                            <input type="text" id="forced_country" name="forced_country" class="form-control text-uppercase" maxlength="2" placeholder="e.g. US, EG, SA">
                        </div>
                    </div>

                    <div class="d-flex justify-content-end gap-3 pt-3 border-top">
                        <button type="reset" class="btn btn-label-secondary">Reset</button>
                        <button type="submit" class="btn btn-primary px-4" id="btn-admin-generate">
                            <i class="ti tabler-sparkles me-1"></i>Generate Now
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Side Info: Admin Privileges & Live Batch Overview -->
    <div class="col-12 col-xl-4">
        <!-- Admin Privileges Card -->
        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header border-bottom py-3 d-flex align-items-center justify-content-between">
                <div class="d-flex align-items-center gap-2">
                    <div class="avatar avatar-sm bg-label-warning rounded-2 d-flex align-items-center justify-content-center p-2" style="width: 38px; height: 38px;">
                        <i class="ti tabler-crown fs-4 text-warning"></i>
                    </div>
                    <div>
                        <h6 class="card-title mb-0 fw-bold">Admin Privileges Active</h6>
                        <small class="text-muted">Unrestricted System Authority</small>
                    </div>
                </div>
                <span class="badge bg-label-warning fw-bold px-2 py-1">
                    <i class="ti tabler-shield-check me-1"></i>Root Authority
                </span>
            </div>

            <div class="card-body p-4">
                <!-- 2x2 Capabilities Matrix -->
                <div class="row g-2 mb-4">
                    <div class="col-6">
                        <div class="p-3 bg-light-subtle rounded-3 border h-100">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="ti tabler-coins text-warning fs-5"></i>
                                <span class="small text-muted fw-semibold">Credit Cost</span>
                            </div>
                            <div class="fs-6 fw-bold text-success">FREE (0.00)</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">No credit deduction</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light-subtle rounded-3 border h-100">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="ti tabler-hourglass-empty text-primary fs-5"></i>
                                <span class="small text-muted fw-semibold">Delayed Timer</span>
                            </div>
                            <div class="fs-6 fw-bold text-primary">On 1st Stream</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Starts upon activation</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light-subtle rounded-3 border h-100">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="ti tabler-users text-info fs-5"></i>
                                <span class="small text-muted fw-semibold">Assignment</span>
                            </div>
                            <div class="fs-6 fw-bold text-info">Any Reseller</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Direct stock transfer</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="p-3 bg-light-subtle rounded-3 border h-100">
                            <div class="d-flex align-items-center gap-2 mb-1">
                                <i class="ti tabler-shield-check text-success fs-5"></i>
                                <span class="small text-muted fw-semibold">Stock Protection</span>
                            </div>
                            <div class="fs-6 fw-bold text-success">Protected</div>
                            <small class="text-muted d-block" style="font-size: 0.72rem;">Never expires in stock</small>
                        </div>
                    </div>
                </div>

                <!-- Live Generation Batch Overview -->
                <div class="p-3 rounded-3 border mb-3" style="background: rgba(115, 103, 240, 0.05); border-color: rgba(115, 103, 240, 0.25) !important;">
                    <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom border-secondary border-opacity-10">
                        <span class="small fw-semibold text-uppercase d-flex align-items-center" style="color: #7367f0;">
                            <i class="ti tabler-eye me-1 fs-5"></i>Live Batch Preview
                        </span>
                        <span class="badge bg-label-primary fs-6 px-2 py-1" id="preview-qty-pill">5 Vouchers</span>
                    </div>

                    <div class="d-flex justify-content-between align-items-center py-1 small">
                        <span class="text-muted">Target Owner:</span>
                        <span class="fw-semibold text-body text-truncate ms-2" id="preview-owner-name">System Administrator</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1 small">
                        <span class="text-muted">Target Package:</span>
                        <span class="fw-semibold text-body text-truncate ms-2" id="preview-package-name">-- Not Selected --</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center py-1 small">
                        <span class="text-muted">Code Format:</span>
                        <span class="font-monospace text-body" id="preview-format-val">Alphanumeric (10 chars)</span>
                    </div>
                    <div class="d-flex justify-content-between align-items-center pt-2 mt-1 border-top border-secondary border-opacity-10 small">
                        <span class="text-muted">Total Admin Cost:</span>
                        <span class="fw-bold text-success fs-6">0.00 Credits (Exempt)</span>
                    </div>
                </div>

                <!-- Guidance Note -->
                <div class="d-flex align-items-start gap-2 p-2 px-3 rounded-2 bg-light-subtle border small text-muted">
                    <i class="ti tabler-info-circle text-primary fs-5 mt-1 flex-shrink-0"></i>
                    <div style="font-size: 0.82rem; line-height: 1.45;">
                        Admin vouchers bypass all balance checks. Created codes remain in <strong>Ready (Stock)</strong> status indefinitely until redeemed by end-users in the Activation Portal.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Results Card -->
<div id="admin-results-card" class="card shadow-sm border-0 d-none mt-4">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center py-3">
        <h5 class="text-white mb-0"><i class="ti tabler-circle-check me-2"></i>Active Codes Generated!</h5>
        <button type="button" class="btn btn-sm btn-light" id="btn-copy-all-admin">
            <i class="ti tabler-copy me-1"></i>Copy All Codes
        </button>
    </div>
    <div class="card-body p-4">
        <div class="alert alert-primary d-flex align-items-center justify-content-between mb-4">
            <div>
                <strong>Batch:</strong> <span class="font-monospace fw-bold" id="admin-res-batch"></span>
                <span class="mx-2">•</span>
                <strong>Count:</strong> <span class="fw-bold" id="admin-res-count"></span>
            </div>
            <a href="active_codes_batch" class="btn btn-sm btn-primary">Open Batch Manager</a>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Activation Code</th>
                        <th>Username</th>
                        <th>Password</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody id="admin-res-table"></tbody>
            </table>
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
        let generatedAdminCodes = [];

    function updateLivePreview() {
        const qty = jQuery('#num_codes').val() || 1;
        jQuery('#preview-qty-pill').text(`${qty} Voucher${qty > 1 ? 's' : ''}`);

        const ownerText = jQuery('#created_by option:selected').text().split('(')[0].trim();
        jQuery('#preview-owner-name').text(ownerText || 'System Administrator');

        const pkgText = jQuery('#package_id option:selected').text().trim();
        jQuery('#preview-package-name').text(pkgText && !pkgText.startsWith('--') ? pkgText : '-- Not Selected --');

        const format = jQuery('#code_format').val();
        const length = jQuery('#code_length').val() || 10;
        const fmtTitle = format === 'numeric' ? 'Numeric PIN' : 'Alphanumeric';
        jQuery('#preview-format-val').text(`${fmtTitle} (${length} chars)`);
    }

    jQuery('#num_codes, #created_by, #package_id, #code_format, #code_length').on('change input', updateLivePreview);

    jQuery('.qty-pill').on('click', function() {
        jQuery('#num_codes').val(jQuery(this).data('qty'));
        updateLivePreview();
    });

    updateLivePreview();

    jQuery('#btn-rand-batch').on('click', function() {
        const rand = Math.random().toString(36).substring(2, 6).toUpperCase();
        const dateStr = new Date().toISOString().slice(0,10).replace(/-/g,"");
        jQuery('#batch_name').val(`BATCH-${dateStr}-${rand}`);
    });

    // Bouquets Selection & Interactive Filtering
    function updateBouquetCounts() {
        const total = jQuery('.bq-checkbox').length;
        const selected = jQuery('.bq-checkbox:checked').length;
        const unselected = total - selected;

        jQuery('#bq-selected-count').text(`${selected} / ${total} Selected`);
        jQuery('.tab-count-all').text(total);
        jQuery('.tab-count-sel').text(selected);
        jQuery('.tab-count-unsel').text(unselected);

        // Update tile styles
        jQuery('.bq-checkbox').each(function() {
            const tile = jQuery(this).closest('.bq-tile');
            if (this.checked) {
                tile.addClass('is-checked');
            } else {
                tile.removeClass('is-checked');
            }
        });
    }

    // Toggle tile state on change
    jQuery(document).on('change', '.bq-checkbox', function() {
        updateBouquetCounts();
        applyBouquetFilter();
    });

    // Select All
    jQuery('#btn-select-all-bq').on('click', function() {
        jQuery('.bouquet-item:visible .bq-checkbox').prop('checked', true);
        updateBouquetCounts();
    });

    // Clear All
    jQuery('#btn-deselect-all-bq').on('click', function() {
        jQuery('.bouquet-item:visible .bq-checkbox').prop('checked', false);
        updateBouquetCounts();
    });

    // Invert Selection
    jQuery('#btn-invert-bq').on('click', function() {
        jQuery('.bouquet-item:visible .bq-checkbox').each(function() {
            this.checked = !this.checked;
        });
        updateBouquetCounts();
    });

    // Exclude Adult
    jQuery('#btn-filter-adult').on('click', function() {
        jQuery('.bouquet-item[data-adult="1"] .bq-checkbox').prop('checked', false);
        updateBouquetCounts();
    });

    // Tab Filter & Search Combined Function
    let currentBqFilter = 'all';
    function applyBouquetFilter() {
        const term = (jQuery('#filter-bq-input').val() || '').toLowerCase().trim();
        let visibleCount = 0;

        jQuery('.bouquet-item').each(function() {
            const item = jQuery(this);
            const name = item.data('name') || '';
            const isAdult = item.data('adult') == 1;
            const isChecked = item.find('.bq-checkbox').is(':checked');

            const matchesSearch = !term || name.includes(term);
            let matchesTab = true;

            if (currentBqFilter === 'selected') {
                matchesTab = isChecked;
            } else if (currentBqFilter === 'unselected') {
                matchesTab = !isChecked;
            } else if (currentBqFilter === 'adult') {
                matchesTab = isAdult;
            }

            const isVisible = matchesSearch && matchesTab;
            item.toggle(isVisible);
            if (isVisible) visibleCount++;
        });

        jQuery('#bq-no-results').toggleClass('d-none', visibleCount > 0);
    }

    jQuery('.bq-filter-tab').on('click', function() {
        jQuery('.bq-filter-tab').removeClass('active');
        jQuery(this).addClass('active');
        currentBqFilter = jQuery(this).data('filter');
        applyBouquetFilter();
    });

    jQuery('#filter-bq-input').on('input keyup', function() {
        const hasText = (this.value.trim().length > 0);
        jQuery('#btn-clear-bq-search').toggleClass('d-none', !hasText);
        applyBouquetFilter();
    });

    jQuery('#btn-clear-bq-search').on('click', function() {
        jQuery('#filter-bq-input').val('');
        jQuery(this).addClass('d-none');
        applyBouquetFilter();
        jQuery('#filter-bq-input').focus();
    });

    updateBouquetCounts();

    jQuery('#admin-active-code-form').on('submit', function(e) {
        e.preventDefault();

        const btn = jQuery('#btn-admin-generate');
        const orig = btn.html();
        btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Generating...');

        const formData = jQuery(this).serializeArray();
        const postData = { action: 'generate_active_codes' };
        formData.forEach(item => {
            if (item.name.endsWith('[]')) {
                const key = item.name.slice(0, -2);
                if (!postData[key]) postData[key] = [];
                postData[key].push(item.value);
            } else {
                postData[item.name] = item.value;
            }
        });

        jQuery.post('./api', postData, function(res) {
            btn.prop('disabled', false).html(orig);
            let data = res;
            if (typeof res === 'string') {
                try { data = JSON.parse(res); } catch(e) {}
            }

            if (!data.result) {
                alert(data.message || 'Generation failed.');
                return;
            }

            generatedAdminCodes = data.codes || [];
            jQuery('#admin-res-batch').text(data.batch_name);
            jQuery('#admin-res-count').text(data.qty);

            let html = '';
            generatedAdminCodes.forEach((c, idx) => {
                html += `
                    <tr>
                        <td>${idx + 1}</td>
                        <td><code class="fw-bold font-monospace text-primary fs-6">${c.code}</code></td>
                        <td class="font-monospace">${c.username}</td>
                        <td class="font-monospace">${c.password}</td>
                        <td><span class="badge bg-label-success badge-pulse">Ready (Stock)</span></td>
                    </tr>
                `;
            });

            jQuery('#admin-res-table').html(html);
            jQuery('#admin-results-card').removeClass('d-none');
            document.getElementById('admin-results-card').scrollIntoView({ behavior: 'smooth' });
        });
    });

    function copyToClipboard(text) {
        if (navigator.clipboard && window.isSecureContext) {
            return navigator.clipboard.writeText(text);
        }
        return new Promise((resolve, reject) => {
            try {
                const textarea = document.createElement('textarea');
                textarea.value = String(text);
                textarea.style.position = 'fixed';
                textarea.style.left = '-9999px';
                textarea.style.top = '0';
                textarea.setAttribute('readonly', '');
                document.body.appendChild(textarea);
                textarea.focus();
                textarea.select();
                const success = document.execCommand('copy');
                document.body.removeChild(textarea);
                success ? resolve() : reject();
            } catch (err) {
                reject(err);
            }
        });
    }

    jQuery('#btn-copy-all-admin').on('click', function() {
        if (!generatedAdminCodes.length) return;
        const text = generatedAdminCodes.map(c => c.code).join("\n");
        const btn = jQuery(this);
        const orig = btn.html();
        copyToClipboard(text).then(() => {
            btn.html('<i class="ti tabler-check me-1"></i>Copied!');
            setTimeout(() => btn.html(orig), 1500);
        }).catch(() => {
            prompt('Copy codes:', text);
        });
    });
});
})(jQuery);
</script>
</body>

</html>

