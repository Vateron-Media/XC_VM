<?php

/**
 * Generate Active Codes (Bootstrap 5, Reseller)
 *
 * Interactive creation wizard for Smart Activation Codes with live credit calculation,
 * batch naming, bouquet customization, and instant result export.
 */


$rUserInfo = $GLOBALS['rUserInfo'] ?? [];
$rPermissions = $GLOBALS['rPermissions'] ?? [];

$rPackages = $rPackages ?? [];
$rBouquets = $rBouquets ?? [];
$userCredits = floatval($rUserInfo['credits'] ?? 0);

// Pre-calculate package prices with override
$packagePrices = [];
$override = json_decode((string)($rUserInfo['override_packages'] ?? ''), true) ?: [];
foreach ($rPackages as $pkg) {
    $pkgId = (int)$pkg['id'];
    $isTrial = !empty($pkg['is_trial']);
    if ($isTrial) {
        $cost = floatval($pkg['trial_credits'] ?? 0);
    } else {
        if (isset($override[$pkgId]['official_credits']) && strlen((string)$override[$pkgId]['official_credits']) > 0) {
            $cost = floatval($override[$pkgId]['official_credits']);
        } else {
            $cost = floatval($pkg['official_credits'] ?? 0);
        }
    }
    $packagePrices[$pkgId] = [
        'cost' => $cost,
        'is_trial' => $isTrial,
        'name' => (string)$pkg['package_name'],
        'duration' => (int)($isTrial ? $pkg['trial_duration'] : $pkg['official_duration']),
        'duration_in' => (string)($isTrial ? $pkg['trial_duration_in'] : $pkg['official_duration_in']),
        'max_connections' => (int)($pkg['max_connections'] ?: 1),
        'forced_country' => (string)($pkg['forced_country'] ?? ''),
        'bouquets' => json_decode((string)($pkg['bouquets'] ?? '[]'), true) ?: [],
    ];
}

// Streaming DNS options
$dnsList = array_filter(array_map('trim', explode(',', (string)($rUserInfo['reseller_dns'] ?? ''))));
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
    <!-- Left Column: Generator Form -->
    <div class="col-12 col-xl-8" id="generator-form-col">
        <div class="card shadow-sm border-0">
            <div class="card-header border-bottom d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="card-title mb-1"><i class="ti tabler-plus text-primary me-2"></i><?= $language::get('generate_codes') ?: 'Generate Active Codes'; ?></h5>
                    <p class="text-muted small mb-0">Pre-generate stock activation vouchers for clients. Credits are deducted, but countdown begins only upon first activation.</p>
                </div>
                <a href="active_codes" class="btn btn-sm btn-label-secondary">
                    <i class="ti tabler-arrow-left me-1"></i>Back to Codes
                </a>
            </div>

            <div class="card-body p-4">
                <form id="active-code-form">
                    <!-- Batch Information -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-8">
                            <label class="form-label fw-semibold" for="batch_name">Batch Name / Voucher Label</label>
                            <div class="input-group">
                                <span class="input-group-text"><i class="ti tabler-tag"></i></span>
                                <input type="text" id="batch_name" name="batch_name" class="form-control font-monospace" placeholder="BATCH-202609-XXXX" value="BATCH-<?= date('Ymd'); ?>-<?= strtoupper(substr(bin2hex(random_bytes(2)), 0, 4)); ?>">
                                <button type="button" class="btn btn-outline-secondary" id="btn-rand-batch" title="Generate New Batch ID">
                                    <i class="ti tabler-refresh"></i>
                                </button>
                            </div>
                            <div class="form-text small">Used to group vouchers for batch export, printing, and management.</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label class="form-label fw-semibold" for="code_format">Code Format</label>
                            <select id="code_format" name="code_format" class="form-select">
                                <option value="alphanumeric" selected>Alphanumeric (8X7K9P2M)</option>
                                <option value="numeric">Numeric PIN (87219430)</option>
                            </select>
                        </div>
                    </div>

                    <hr class="my-4 text-muted opacity-25">

                    <!-- Quantity & Code Length -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-7">
                            <label class="form-label fw-semibold" for="num_codes">Quantity of Codes to Generate</label>
                            <div class="input-group">
                                <input type="number" id="num_codes" name="num_codes" class="form-control fs-5 fw-bold text-center" value="1" min="1" max="500">
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="5">5</button>
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="10">10</button>
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="25">25</button>
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="50">50</button>
                                <button type="button" class="btn btn-outline-secondary qty-pill" data-qty="100">100</button>
                            </div>
                        </div>

                        <div class="col-12 col-md-5">
                            <label class="form-label fw-semibold" for="code_length">Code Character Length</label>
                            <select id="code_length" name="code_length" class="form-select">
                                <option value="8">8 Characters</option>
                                <option value="10" selected>10 Characters (Standard)</option>
                                <option value="12">12 Characters</option>
                                <option value="16">16 Characters</option>
                            </select>
                        </div>
                    </div>

                    <!-- Package Selection -->
                    <div class="mb-4">
                        <label class="form-label fw-semibold" for="package_id">Target Subscription Package <span class="text-danger">*</span></label>
                        <select id="package_id" name="package_id" class="form-select form-select-lg" required>
                            <option value="" disabled selected>-- Select a Package --</option>
                            <?php foreach ($rPackages as $pkg): ?>
                                <option value="<?= (int)$pkg['id']; ?>" data-cost="<?= $packagePrices[(int)$pkg['id']]['cost']; ?>" data-trial="<?= $packagePrices[(int)$pkg['id']]['is_trial'] ? 1 : 0; ?>">
                                    <?= htmlspecialchars((string)$pkg['package_name'], ENT_QUOTES); ?>
                                    (<?= $packagePrices[(int)$pkg['id']]['cost']; ?> Credits)
                                    <?= $packagePrices[(int)$pkg['id']]['is_trial'] ? ' - [TRIAL]' : ''; ?>
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
                                            <label class="bq-tile is-checked d-flex align-items-center justify-content-between p-2 px-3 w-100 mb-0" for="bq_<?= (int)$bq['id']; ?>">
                                                <div class="d-flex align-items-center gap-2 text-truncate me-2">
                                                    <input class="form-check-input bq-checkbox m-0 flex-shrink-0" type="checkbox" name="bouquets_selected[]" value="<?= (int)$bq['id']; ?>" id="bq_<?= (int)$bq['id']; ?>" checked>
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

                    <!-- Routing Options -->
                    <div class="row g-3 mb-4">
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="dns_base">Custom DNS Portal (Optional)</label>
                            <select id="dns_base" name="dns_base" class="form-select">
                                <option value="">Default Server Domain</option>
                                <?php foreach ($dnsList as $dns): ?>
                                    <option value="<?= htmlspecialchars($dns, ENT_QUOTES); ?>"><?= htmlspecialchars($dns, ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="forced_country">Geo-Lock Country (Optional)</label>
                            <input type="text" id="forced_country" name="forced_country" class="form-control text-uppercase" maxlength="2" placeholder="e.g. US, EG, SA, GB, or leave empty">
                        </div>
                    </div>

                    <!-- Action Buttons -->
                    <div class="d-flex justify-content-end gap-3 pt-3 border-top">
                        <button type="reset" class="btn btn-label-secondary">Reset</button>
                        <button type="submit" class="btn btn-primary px-4" id="btn-generate-submit">
                            <i class="ti tabler-sparkles me-1"></i>Generate Codes
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Right Column: Live Credit Calculator & Guidelines -->
    <div class="col-12 col-xl-4">
        <!-- Live Credit Calculator Card -->
        <div class="card shadow-sm border-0 mb-4 bg-primary text-white">
            <div class="card-body p-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <span class="text-white-50 text-uppercase fw-semibold small">Credit Accounting</span>
                    <i class="ti tabler-wallet fs-3 text-white"></i>
                </div>

                <div class="mb-4">
                    <small class="text-white-50 d-block">Your Current Balance</small>
                    <h2 class="text-white fw-bold mb-0" id="card-current-balance"><?= number_format($userCredits, 2); ?> <span class="fs-6 fw-normal">Credits</span></h2>
                </div>

                <div class="p-3 bg-white bg-opacity-10 rounded-3 mb-3">
                    <div class="d-flex justify-content-between mb-2">
                        <span class="small text-white-50">Cost Per Voucher:</span>
                        <span class="fw-semibold" id="card-cost-per-code">0.00 Credits</span>
                    </div>
                    <div class="d-flex justify-content-between mb-2">
                        <span class="small text-white-50">Quantity:</span>
                        <span class="fw-semibold" id="card-qty">1</span>
                    </div>
                    <hr class="border-white opacity-25 my-2">
                    <div class="d-flex justify-content-between align-items-center">
                        <span class="fw-bold">Total Cost:</span>
                        <span class="fs-5 fw-bold text-warning" id="card-total-cost">0.00 Credits</span>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center p-2 rounded-3 bg-black bg-opacity-25" id="balance-after-box">
                    <span class="small text-white-50">Balance After Generation:</span>
                    <span class="fw-bold" id="card-balance-after"><?= number_format($userCredits, 2); ?> Credits</span>
                </div>

                <div id="insufficient-balance-alert" class="alert alert-danger bg-danger text-white border-0 mt-3 d-none mb-0">
                    <i class="ti tabler-alert-circle me-1"></i> Insufficient balance for this generation request.
                </div>
            </div>
        </div>

        <!-- Stock Mode Explainer Card -->
        <div class="card shadow-sm border-0">
            <div class="card-body p-4">
                <h6 class="fw-semibold mb-3 d-flex align-items-center gap-2">
                    <i class="ti tabler-info-circle text-info"></i>How Active Codes Work
                </h6>
                <ul class="list-unstyled mb-0 d-flex flex-column gap-3 small text-muted">
                    <li class="d-flex gap-2">
                        <i class="ti tabler-snowflake text-primary fs-5 mt-1"></i>
                        <div>
                            <strong class="text-dark d-block">Stock Mode (Delayed Timer)</strong>
                            Codes sit safely in your inventory without aging. The 30-day (or 1-year) countdown is triggered only when the buyer inputs the code into their TV app or web portal.
                        </div>
                    </li>
                    <li class="d-flex gap-2">
                        <i class="ti tabler-folders text-success fs-5 mt-1"></i>
                        <div>
                            <strong class="text-dark d-block">Scratch-Card Batch Printing</strong>
                            Group vouchers by batch and download ready-to-print formatted text files for physical cards or retail store distribution.
                        </div>
                    </li>
                    <li class="d-flex gap-2">
                        <i class="ti tabler-shield-check text-warning fs-5 mt-1"></i>
                        <div>
                            <strong class="text-dark d-block">Safe Refund on Unused Vouchers</strong>
                            If you ever delete a batch of unused codes, your credits are automatically returned to your reseller balance.
                        </div>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</div>

<!-- Success Results Container (Shown after generation) -->
<div id="generation-results-card" class="card shadow-sm border-0 d-none mt-4">
    <div class="card-header bg-success text-white d-flex justify-content-between align-items-center py-3">
        <div class="d-flex align-items-center gap-2">
            <i class="ti tabler-circle-check fs-4"></i>
            <h5 class="text-white mb-0" id="res-title">Codes Generated Successfully!</h5>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-light" id="btn-copy-all-res">
                <i class="ti tabler-copy me-1"></i>Copy All Codes
            </button>
            <a href="active_codes" class="btn btn-sm btn-outline-light">View in Inventory</a>
        </div>
    </div>
    <div class="card-body p-4">
        <div class="alert alert-primary d-flex align-items-center justify-content-between mb-4">
            <div>
                <strong>Batch Name:</strong> <span class="font-monospace fw-bold" id="res-batch-name"></span>
                <span class="mx-2">•</span>
                <strong>Total Vouchers:</strong> <span class="fw-bold" id="res-qty"></span>
            </div>
            <a href="#" id="res-batch-link" class="btn btn-sm btn-primary">Open in Batch Manager</a>
        </div>

        <div class="table-responsive">
            <table class="table table-bordered table-hover">
                <thead class="table-light">
                    <tr>
                        <th style="width: 50px;">#</th>
                        <th>Activation Code</th>
                        <th>Companion Username</th>
                        <th>Companion Password</th>
                        <th>Initial Status</th>
                    </tr>
                </thead>
                <tbody id="res-codes-table"></tbody>
            </table>
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
            const userCredits = <?= json_encode($userCredits); ?>;
            const packagePrices = <?= json_encode($packagePrices); ?>;
            let currentGeneratedCodes = [];

            // Pill clicks for quick quantity
            jQuery('.qty-pill').on('click', function() {
                jQuery('#num_codes').val(jQuery(this).data('qty')).trigger('input');
            });

            // Random Batch Name generator button
            jQuery('#btn-rand-batch').on('click', function() {
                const rand = Math.random().toString(36).substring(2, 6).toUpperCase();
                const dateStr = new Date().toISOString().slice(0, 10).replace(/-/g, "");
                jQuery('#batch_name').val(`BATCH-${dateStr}-${rand}`);
            });

            // Dynamic Credit Calculator
            function updateCalculator() {
                const pkgId = jQuery('#package_id').val();
                const qty = Math.max(1, parseInt(jQuery('#num_codes').val(), 10) || 1);
                let costPerCode = 0;

                if (pkgId && packagePrices[pkgId]) {
                    costPerCode = packagePrices[pkgId].cost;
                }

                const totalCost = qty * costPerCode;
                const balanceAfter = userCredits - totalCost;

                jQuery('#card-qty').text(qty);
                jQuery('#card-cost-per-code').text(costPerCode.toFixed(2) + ' Credits');
                jQuery('#card-total-cost').text(totalCost.toFixed(2) + ' Credits');
                jQuery('#card-balance-after').text(balanceAfter.toFixed(2) + ' Credits');

                if (balanceAfter < 0) {
                    jQuery('#insufficient-balance-alert').removeClass('d-none');
                    jQuery('#btn-generate-submit').prop('disabled', true);
                    jQuery('#card-balance-after').addClass('text-danger');
                } else {
                    jQuery('#insufficient-balance-alert').addClass('d-none');
                    jQuery('#btn-generate-submit').prop('disabled', false);
                    jQuery('#card-balance-after').removeClass('text-danger');
                }
            }

            jQuery('#package_id, #num_codes').on('change input', updateCalculator);

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

            // Auto-check bouquets matching selected package
            jQuery('#package_id').on('change', function() {
                const pkgId = this.value;
                if (packagePrices[pkgId] && packagePrices[pkgId].bouquets) {
                    const allowed = new Set(packagePrices[pkgId].bouquets.map(Number));
                    jQuery('.bq-checkbox').each(function() {
                        this.checked = allowed.has(Number(this.value));
                    });
                    updateBouquetCounts();
                }
            });

            // Submit Form via AJAX
            jQuery('#active-code-form').on('submit', function(e) {
                e.preventDefault();

                const btn = jQuery('#btn-generate-submit');
                const origText = btn.html();
                btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Generating...');

                const formData = jQuery(this).serializeArray();
                const postData = {
                    action: 'generate_active_codes'
                };
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
                    btn.prop('disabled', false).html(origText);
                    let data = res;
                    if (typeof res === 'string') {
                        try {
                            data = JSON.parse(res);
                        } catch (e) {}
                    }

                    if (!data.result) {
                        alert(data.message || 'Generation failed.');
                        return;
                    }

                    currentGeneratedCodes = data.codes || [];
                    jQuery('#res-batch-name').text(data.batch_name);
                    jQuery('#res-qty').text(data.qty);
                    jQuery('#res-batch-link').attr('href', 'active_codes_batch');

                    let html = '';
                    currentGeneratedCodes.forEach((c, idx) => {
                        html += `
                    <tr>
                        <td>${idx + 1}</td>
                        <td>
                            <code class="fw-bold font-monospace text-primary fs-6">${c.code}</code>
                            <button type="button" class="btn btn-sm btn-icon btn-text-secondary btn-copy-one" data-code="${c.code}"><i class="ti tabler-copy"></i></button>
                        </td>
                        <td class="font-monospace">${c.username}</td>
                        <td class="font-monospace">${c.password}</td>
                        <td><span class="badge bg-label-success badge-pulse">Ready (Stock)</span></td>
                    </tr>
                `;
                    });

                    jQuery('#res-codes-table').html(html);
                    jQuery('#generation-results-card').removeClass('d-none');
                    // Smooth scroll to results
                    document.getElementById('generation-results-card').scrollIntoView({
                        behavior: 'smooth'
                    });
                }).fail(function() {
                    btn.prop('disabled', false).html(origText);
                    alert('Request failed. Please check your network connection.');
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

            // Copy single code
            jQuery(document).on('click', '.btn-copy-one', function() {
                const code = jQuery(this).data('code');
                const btn = jQuery(this);
                const orig = btn.html();
                copyToClipboard(code).then(() => {
                    btn.html('<i class="ti tabler-check text-success"></i>');
                    setTimeout(() => btn.html(orig), 1500);
                }).catch(() => {
                    prompt('Copy code:', code);
                });
            });

            // Copy all generated codes
            jQuery('#btn-copy-all-res').on('click', function() {
                if (!currentGeneratedCodes.length) return;
                const text = currentGeneratedCodes.map(c => c.code).join("\n");
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