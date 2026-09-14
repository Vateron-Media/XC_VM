<?php

/**
 * Reseller Active Codes (Bootstrap 5)
 *
 * Full-parity management table for Smart Activation Codes with delayed countdown
 * (Stock Mode), instant clipboard copy, live modal details, and floating mass actions bar.
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\PackageService;

global $db;

$rUserInfo = $GLOBALS['rUserInfo'] ?? [];
$rPermissions = $GLOBALS['rPermissions'] ?? [];

$rPackages = PackageService::getAll($rUserInfo['member_group_id'] ?? 0, 'line') ?: [];

// Get distinct batches for filter dropdown
$allowedReports = (array)($rUserInfo['reports'] ?? [$rUserInfo['id']]);
$batches = $db->fetchAll(
    "SELECT DISTINCT `batch_name` FROM `activation_codes` 
     WHERE `created_by` IN (" . implode(',', array_map('intval', $allowedReports)) . ") AND `batch_name` IS NOT NULL 
     ORDER BY `created_at` DESC LIMIT 100;"
);

?>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3">
        <div>
            <h5 class="card-title mb-1"><i class="ti tabler-key text-primary me-2"></i><?= $language::get('active_codes') ?: 'Active Codes'; ?></h5>
            <p class="text-muted small mb-0">Pre-generated stock vouchers. Subscriptions count down only upon client's first activation.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="active_codes_batch" class="btn btn-label-secondary">
                <i class="ti tabler-folders me-1"></i><?= $language::get('batch_manager') ?: 'Batch Manager'; ?>
            </a>
            <a href="active_code" class="btn btn-primary">
                <i class="ti tabler-plus me-1"></i><?= $language::get('generate_codes') ?: 'Generate Codes'; ?>
            </a>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card-body border-bottom bg-light-subtle">
        <div class="row g-3">
            <div class="col-12 col-md-4">
                <label class="form-label small text-uppercase fw-semibold" for="filter-search"><?= $language::get('search') ?: 'Search'; ?></label>
                <div class="input-group">
                    <span class="input-group-text"><i class="ti tabler-search"></i></span>
                    <input type="text" id="filter-search" class="form-control" placeholder="Search by code, batch, subscriber, MAC...">
                </div>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-uppercase fw-semibold" for="filter-status">Status</label>
                <select id="filter-status" class="form-select">
                    <option value="0">All Statuses</option>
                    <option value="1">Ready (Stock / Unused)</option>
                    <option value="2">Active (Streaming)</option>
                    <option value="3">Expired</option>
                    <option value="4">Disabled</option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-uppercase fw-semibold" for="filter-batch">Batch</label>
                <select id="filter-batch" class="form-select">
                    <option value="">All Batches</option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= htmlspecialchars((string)$b['batch_name'], ENT_QUOTES); ?>">
                            <?= htmlspecialchars((string)$b['batch_name'], ENT_QUOTES); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-md-2">
                <label class="form-label small text-uppercase fw-semibold" for="filter-package">Package</label>
                <select id="filter-package" class="form-select">
                    <option value="0">All Packages</option>
                    <?php foreach ($rPackages as $pkg): ?>
                        <option value="<?= (int)$pkg['id']; ?>">
                            <?= htmlspecialchars((string)$pkg['package_name'], ENT_QUOTES); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <!-- DataTable Container -->
    <div class="card-datatable table-responsive">
        <table id="active-codes-table" class="table table-hover border-top">
            <thead>
                <tr>
                    <th style="width: 35px;">
                        <input type="checkbox" id="select-all" class="form-check-input">
                    </th>
                    <th>Code</th>
                    <th>Batch</th>
                    <th>Package</th>
                    <th>Status</th>
                    <th>Expiration</th>
                    <th>Subscriber</th>
                    <th>Device Lock</th>
                    <th>Created</th>
                    <th class="text-center" style="width: 120px;">Actions</th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- Floating Mass Actions Bar -->
<div id="mass-action-bar" class="position-fixed bottom-0 start-50 translate-middle-x p-3 bg-dark text-white rounded-4 shadow-lg d-none align-items-center gap-3" style="z-index: 1080; min-width: 480px; max-width: 90%;">
    <div class="d-flex align-items-center gap-2">
        <span class="badge bg-primary fs-6 px-2 py-1" id="selected-count">0</span>
        <span class="small fw-semibold">Codes Selected</span>
    </div>
    <div class="vr bg-secondary opacity-50 my-1"></div>
    <div class="d-flex flex-wrap gap-2 ms-auto">
        <button type="button" class="btn btn-sm btn-success" id="btn-mass-enable">
            <i class="ti tabler-check me-1"></i>Enable
        </button>
        <button type="button" class="btn btn-sm btn-warning" id="btn-mass-disable">
            <i class="ti tabler-ban me-1"></i>Disable
        </button>
        <button type="button" class="btn btn-sm btn-info" id="btn-mass-extend">
            <i class="ti tabler-calendar-plus me-1"></i>Extend
        </button>
        <button type="button" class="btn btn-sm btn-secondary" id="btn-mass-reset">
            <i class="ti tabler-device-desktop-off me-1"></i>Reset Device
        </button>
        <button type="button" class="btn btn-sm btn-danger" id="btn-mass-delete">
            <i class="ti tabler-trash me-1"></i>Delete
        </button>
        <button type="button" class="btn btn-sm btn-outline-light" id="btn-mass-cancel">
            <i class="ti tabler-x"></i>
        </button>
    </div>
</div>

<!-- Code Details Modal -->
<div class="modal fade" id="codeDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-bottom">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="ti tabler-key text-primary"></i>
                    <span>Activation Code Details</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4" id="modal-details-body">
                <div class="text-center py-5">
                    <div class="spinner-border text-primary" role="status">
                        <span class="visually-hidden">Loading...</span>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top d-flex justify-content-between">
                <button type="button" class="btn btn-outline-danger btn-delete-from-details">
                    <i class="ti tabler-trash me-1"></i>Delete Code
                </button>
                <div class="d-flex align-items-center gap-2">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
                    <button type="button" class="btn btn-primary btn-edit-from-details">
                        <i class="ti tabler-pencil me-1"></i>Edit Code
                    </button>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Edit Active Code Modal -->
<div class="modal fade" id="editCodeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-bottom">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="ti tabler-pencil text-primary"></i>
                    <span>Edit Active Code</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="form-edit-code">
                <input type="hidden" id="edit-code-id" name="id" value="">
                <div class="modal-body p-4">
                    <div class="row g-3">
                        <!-- Code String -->
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="edit-code-string">Activation Code <span class="text-danger">*</span></label>
                            <div class="input-group input-group-merge">
                                <span class="input-group-text"><i class="ti tabler-key"></i></span>
                                <input type="text" id="edit-code-string" name="activation_code" class="form-control font-monospace fw-bold" required>
                            </div>
                            <div class="form-text small text-body-secondary">Voucher code redeemed by subscribers or used as username.</div>
                        </div>

                        <!-- Status -->
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="edit-code-status">Status</label>
                            <select id="edit-code-status" name="status" class="form-select">
                                <option value="1">Ready (Stock / Frozen)</option>
                                <option value="2">Active (Bound to subscriber)</option>
                                <option value="0">Disabled (Suspended)</option>
                            </select>
                        </div>

                        <!-- Package -->
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="edit-code-package">Assigned Package</label>
                            <select id="edit-code-package" name="package_id" class="form-select">
                                <?php foreach ($rPackages as $pkg): ?>
                                    <option value="<?= (int)$pkg['id']; ?>">
                                        <?= htmlspecialchars((string)$pkg['package_name'], ENT_QUOTES); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Batch Name -->
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="edit-code-batch">Batch Name</label>
                            <div class="input-group input-group-merge">
                                <span class="input-group-text"><i class="ti tabler-folder"></i></span>
                                <input type="text" id="edit-code-batch" name="batch_name" class="form-control">
                            </div>
                        </div>

                        <!-- Expiration Date -->
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="edit-code-exp-date">Expiration Date & Time</label>
                            <div class="input-group input-group-merge">
                                <span class="input-group-text"><i class="ti tabler-calendar"></i></span>
                                <input type="datetime-local" id="edit-code-exp-date" name="exp_date" class="form-control">
                            </div>
                            <div class="form-text small text-body-secondary">Leave blank to keep stock/frozen status if unactivated.</div>
                        </div>

                        <!-- Max Connections -->
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="edit-code-max-conn">Max Concurrent Streams</label>
                            <div class="input-group input-group-merge">
                                <span class="input-group-text"><i class="ti tabler-users"></i></span>
                                <input type="number" id="edit-code-max-conn" name="max_connections" class="form-control" min="1" max="50" value="1">
                            </div>
                        </div>

                        <!-- Device Lock / MAC -->
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="edit-code-mac">Device Lock (MAC Address)</label>
                            <div class="input-group">
                                <input type="text" id="edit-code-mac" name="mac" class="form-control font-monospace" placeholder="e.g. 00:1A:79:AB:CD:EF">
                                <button type="button" class="btn btn-outline-secondary" id="btn-clear-mac" title="Clear MAC Lock">Clear</button>
                            </div>
                        </div>

                        <!-- Streaming Password -->
                        <div class="col-12 col-md-6">
                            <label class="form-label fw-semibold" for="edit-code-password">Streaming Password</label>
                            <div class="input-group input-group-merge">
                                <span class="input-group-text"><i class="ti tabler-lock"></i></span>
                                <input type="text" id="edit-code-password" name="password" class="form-control font-monospace">
                            </div>
                            <div class="form-text small text-body-secondary">Password used by IPTV apps to connect.</div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top d-flex justify-content-between">
                    <button type="button" class="btn btn-outline-danger btn-delete-from-edit">
                        <i class="ti tabler-trash me-1"></i>Delete Code
                    </button>
                    <div class="d-flex align-items-center gap-2">
                        <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-save-code-edit">
                            <i class="ti tabler-check me-1"></i>Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Extend Days Modal -->
<div class="modal fade" id="extendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Extend Active Codes</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="extend-days">Number of Days</label>
                <input type="number" id="extend-days" class="form-control" value="30" min="1" max="365">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-primary" id="btn-confirm-extend">Extend Now</button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-bottom">
                <h5 class="modal-title text-danger"><i class="ti tabler-alert-triangle me-2"></i>Confirm Deletion</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p class="mb-3">Are you sure you want to delete the selected activation codes? This will also remove their associated subscriber lines.</p>
                <div class="form-check p-3 bg-label-secondary rounded border">
                    <input class="form-check-input" type="checkbox" id="delete-refund" checked>
                    <label class="form-check-label fw-semibold" for="delete-refund">
                        Refund credits for unactivated stock codes
                    </label>
                    <div class="form-text small text-body-secondary mt-1">Any codes that have not yet been activated by a client will have their purchase credits refunded to your balance.</div>
                </div>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete">Delete Codes</button>
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

    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }

    $(function() {
        const tableEl = $('#active-codes-table');
    let selectedIds = new Set();

    const dt = tableEl.DataTable({
        processing: true,
        serverSide: true,
        responsive: true,
        order: [[8, 'desc']],
        pageLength: 25,
        lengthMenu: [10, 25, 50, 100],
        ajax: {
            url: './table',
            type: 'POST',
            data: function(d) {
                d.id = 'active_codes';
                d.filter = jQuery('#filter-status').val();
                d.batch = jQuery('#filter-batch').val();
                d.package = jQuery('#filter-package').val();
            }
        },
        columnDefs: [
            { targets: [0, 9], orderable: false, searchable: false }
        ],
        drawCallback: function() {
            updateFloatingBar();
            // Restore checkbox state
            tableEl.find('.row-select').each(function() {
                if (selectedIds.has(this.value)) {
                    this.checked = true;
                }
            });
        }
    });

    // Filters event listeners
    jQuery('#filter-search').on('keyup', function() {
        dt.search(this.value).draw();
    });
    jQuery('#filter-status, #filter-batch, #filter-package').on('change', function() {
        dt.ajax.reload();
    });

    // Checkbox selections
    tableEl.on('change', '.row-select', function() {
        if (this.checked) {
            selectedIds.add(this.value);
        } else {
            selectedIds.delete(this.value);
        }
        updateFloatingBar();
    });

    jQuery('#select-all').on('change', function() {
        const checked = this.checked;
        tableEl.find('.row-select').each(function() {
            this.checked = checked;
            if (checked) {
                selectedIds.add(this.value);
            } else {
                selectedIds.delete(this.value);
            }
        });
        updateFloatingBar();
    });

    function updateFloatingBar() {
        const bar = jQuery('#mass-action-bar');
        const count = selectedIds.size;
        jQuery('#selected-count').text(count);
        if (count > 0) {
            bar.removeClass('d-none').addClass('d-flex');
        } else {
            bar.removeClass('d-flex').addClass('d-none');
            jQuery('#select-all').prop('checked', false);
        }
    }

    jQuery('#btn-mass-cancel').on('click', function() {
        selectedIds.clear();
        tableEl.find('.row-select').prop('checked', false);
        updateFloatingBar();
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

    // 1-Click Copy Code
    jQuery(document).on('click', '.btn-copy-code', function(e) {
        e.preventDefault();
        const code = jQuery(this).data('code');
        const btn = jQuery(this);
        const orig = btn.html();
        copyToClipboard(code).then(() => {
            btn.html('<i class="ti tabler-check text-success"></i>');
            setTimeout(() => btn.html(orig), 1500);
        }).catch(() => {
            prompt('Copy to clipboard:', code);
        });
    });

    // View Details Modal
    let currentDetailsCodeId = null;
    const detailsModal = new bootstrap.Modal(document.getElementById('codeDetailsModal'));
    jQuery(document).on('click', '.btn-view-code', function() {
        const id = jQuery(this).data('id');
        currentDetailsCodeId = id;
        jQuery('#modal-details-body').html(`
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="mt-2 text-muted">Loading voucher details...</div>
            </div>
        `);
        detailsModal.show();

        jQuery.getJSON('./api', { action: 'active_code_details', id: id }, function(res) {
            if (!res.result) {
                jQuery('#modal-details-body').html(`<div class="alert alert-danger">${escHtml(res.message || 'Error loading details')}</div>`);
                return;
            }
            const d = res.data;
            // Ensure Server Host / URL dynamically reflects the website URL with http or https
            let portalUrl = d.portal_url || '';
            const currentProtocol = window.location.protocol;
            const currentHost = window.location.host;
            const defaultOrigin = `${currentProtocol}//${currentHost}`;

            if (portalUrl.startsWith('http')) {
                try {
                    const u = new URL(portalUrl);
                    if (u.hostname === 'localhost' || u.hostname === '127.0.0.1' || !u.hostname) {
                        portalUrl = defaultOrigin;
                    } else {
                        portalUrl = `${currentProtocol}//${u.host}`;
                    }
                } catch(e) {
                    portalUrl = defaultOrigin;
                }
            } else if (portalUrl) {
                portalUrl = `${currentProtocol}//${portalUrl.replace(/^\/+/, '')}`;
            } else {
                portalUrl = defaultOrigin;
            }
            d.portal_url = portalUrl;

            // Extract port if not explicit
            if (!d.port || d.port == 80 || d.port == 443) {
                try {
                    const pu = new URL(portalUrl);
                    if (pu.port) {
                        d.port = pu.port;
                    } else {
                        d.port = currentProtocol === 'https:' ? 443 : 80;
                    }
                } catch(e) {}
            }

            // Sync direct stream and M3U links
            if (d.m3u_hls && d.m3u_hls.startsWith('http')) {
                try {
                    const hu = new URL(d.m3u_hls);
                    d.m3u_hls = `${portalUrl}${hu.pathname}${hu.search}`;
                } catch(e) {}
            }
            if (d.m3u_ts && d.m3u_ts.startsWith('http')) {
                try {
                    const tu = new URL(d.m3u_ts);
                    d.m3u_ts = `${portalUrl}${tu.pathname}${tu.search}`;
                } catch(e) {}
            }

            const html = `
                <div class="row g-4">
                    <div class="col-12 col-md-6">
                        <div class="p-3 bg-light-subtle rounded-3 border">
                            <label class="small text-muted text-uppercase fw-semibold d-block">Activation Code</label>
                            <div class="d-flex align-items-center justify-content-between mt-1">
                                <span class="fs-4 fw-bold font-monospace text-primary">${escHtml(d.code)}</span>
                                <button class="btn btn-sm btn-outline-primary btn-copy-code" data-code="${escHtml(d.code)}"><i class="ti tabler-copy me-1"></i>Copy</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="p-3 bg-light-subtle rounded-3 border">
                            <label class="small text-muted text-uppercase fw-semibold d-block">Current Status</label>
                            <div class="mt-1">
                                <span class="badge ${d.status === 1 ? 'bg-success' : (d.status === 2 ? 'bg-primary' : 'bg-danger')} fs-6 px-3 py-2">
                                    ${escHtml(d.status_text)}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Package:</span>
                                <span class="fw-semibold">${escHtml(d.package_name)} ${d.is_trial ? '<span class="badge bg-warning ms-1">Trial</span>' : ''}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Batch:</span>
                                <span class="font-monospace">${escHtml(d.batch_name)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Max Connections:</span>
                                <span class="fw-semibold">${escHtml(d.max_connections)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Device / MAC Lock:</span>
                                <span class="font-monospace">${escHtml(d.mac)}</span>
                            </li>
                        </ul>
                    </div>

                    <div class="col-12 col-md-6">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Countdown Expiry:</span>
                                <span class="fw-semibold ${d.status === 1 ? 'text-info' : 'text-primary'}">${escHtml(d.exp_date)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">First Activated:</span>
                                <span>${escHtml(d.activated_at)}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Created:</span>
                                <span>${escHtml(d.created_at)}</span>
                            </li>
                        </ul>
                    </div>

                    <div class="col-12">
                        <div class="xc-cred-hub" data-host="${escHtml(d.portal_url)}" data-port="${escHtml(d.port)}" data-user="${escHtml(d.username)}" data-pass="${escHtml(d.password)}" data-m3u="${escHtml(d.m3u_hls)}">
                            <div class="p-3 p-md-4">
                                <!-- Hub Header -->
                                <div class="xc-cred-header">
                                    <div>
                                        <div class="xc-cred-title">
                                            <i class="ti tabler-device-tv text-warning fs-4"></i>
                                            <span>Streaming & Xtream Codes Credentials</span>
                                            <span class="xc-status-pill ms-2">
                                                <span class="xc-pulse-dot"></span>
                                                Live Ready
                                            </span>
                                        </div>
                                        <span class="xc-cred-subtitle">High-speed endpoints for IPTV Apps, Smart TVs, MAG, and Mobile Devices</span>
                                    </div>
                                    <div class="d-flex align-items-center gap-2">
                                        <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3 btn-copy-all-reseller" title="Copy all parameters formatted together">
                                            <i class="ti tabler-copy me-1"></i>Copy All Parameters
                                        </button>
                                        <button type="button" class="btn btn-sm btn-label-secondary rounded-pill px-3 btn-qr-toggle" title="Toggle QR Code">
                                            <i class="ti tabler-qrcode me-1"></i>QR Code
                                        </button>
                                    </div>
                                </div>

                                <!-- Interactive Credential Tiles -->
                                <div class="row g-3">
                                    <!-- Server URL -->
                                    <div class="col-12 col-md-6">
                                        <div class="xc-cred-tile h-100">
                                            <div class="xc-cred-tile-top">
                                                <div class="xc-cred-label-wrap">
                                                    <div class="xc-cred-icon xc-icon-server">
                                                        <i class="ti tabler-server"></i>
                                                    </div>
                                                    <span class="xc-cred-label">Server Host / URL</span>
                                                </div>
                                                <button class="btn btn-xc-icon btn-copy-code" data-code="${escHtml(d.portal_url)}" title="Copy Server URL">
                                                    <i class="ti tabler-copy"></i>
                                                </button>
                                            </div>
                                            <div class="xc-cred-val" title="${escHtml(d.portal_url)}">${escHtml(d.portal_url)}</div>
                                        </div>
                                    </div>

                                    <!-- Server Port -->
                                    <div class="col-12 col-md-6">
                                        <div class="xc-cred-tile h-100">
                                            <div class="xc-cred-tile-top">
                                                <div class="xc-cred-label-wrap">
                                                    <div class="xc-cred-icon xc-icon-port">
                                                        <i class="ti tabler-network"></i>
                                                    </div>
                                                    <span class="xc-cred-label">Server Port</span>
                                                </div>
                                                <button class="btn btn-xc-icon btn-copy-code" data-code="${escHtml(d.port)}" title="Copy Port">
                                                    <i class="ti tabler-copy"></i>
                                                </button>
                                            </div>
                                            <div class="xc-cred-val">${escHtml(d.port)}</div>
                                        </div>
                                    </div>

                                    <!-- Username -->
                                    <div class="col-12 col-md-6">
                                        <div class="xc-cred-tile h-100">
                                            <div class="xc-cred-tile-top">
                                                <div class="xc-cred-label-wrap">
                                                    <div class="xc-cred-icon xc-icon-user">
                                                        <i class="ti tabler-user"></i>
                                                    </div>
                                                    <span class="xc-cred-label">Streaming Username</span>
                                                </div>
                                                <button class="btn btn-xc-icon btn-copy-code" data-code="${escHtml(d.username)}" title="Copy Username">
                                                    <i class="ti tabler-copy"></i>
                                                </button>
                                            </div>
                                            <div class="xc-cred-val">${escHtml(d.username)}</div>
                                        </div>
                                    </div>

                                    <!-- Password -->
                                    <div class="col-12 col-md-6">
                                        <div class="xc-cred-tile h-100">
                                            <div class="xc-cred-tile-top">
                                                <div class="xc-cred-label-wrap">
                                                    <div class="xc-cred-icon xc-icon-pass">
                                                        <i class="ti tabler-key"></i>
                                                    </div>
                                                    <span class="xc-cred-label">Streaming Password</span>
                                                </div>
                                                <div class="xc-cred-actions">
                                                    <button class="btn btn-xc-icon btn-modal-toggle-pw" title="Show / Hide Password">
                                                        <i class="ti tabler-eye"></i>
                                                    </button>
                                                    <button class="btn btn-xc-icon btn-copy-code" data-code="${escHtml(d.password)}" title="Copy Password">
                                                        <i class="ti tabler-copy"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="xc-cred-val modal-pw-val" data-raw="${escHtml(d.password)}">••••••••••••</div>
                                        </div>
                                    </div>
                                </div>

                                <!-- One-Click Direct Stream URI Bar -->
                                <div class="xc-uri-bar">
                                    <div class="xc-uri-label">
                                        <i class="ti tabler-link text-primary"></i>
                                        <span>Stream URI</span>
                                    </div>
                                    <div class="xc-uri-text" title="${escHtml(d.portal_url)}/get.php?username=${encodeURIComponent(d.username)}&password=${encodeURIComponent(d.password)}&type=m3u_plus&output=ts">${escHtml(d.portal_url)}/get.php?username=${encodeURIComponent(d.username)}&password=${encodeURIComponent(d.password)}&type=m3u_plus&output=ts</div>
                                    <button type="button" class="btn btn-xs btn-label-primary px-3 rounded-pill flex-shrink-0 btn-copy-code" data-code="${escHtml(d.portal_url)}/get.php?username=${encodeURIComponent(d.username)}&password=${encodeURIComponent(d.password)}&type=m3u_plus&output=ts" title="Copy Stream Link">
                                        <i class="ti tabler-copy me-1"></i>Copy
                                    </button>
                                </div>

                                <!-- Collapsible QR Code Box -->
                                <div class="reseller-qr-box d-none text-center p-3 mb-3 bg-dark-subtle rounded-3 border border-secondary border-opacity-25">
                                    <div class="xc-qr-box mb-2">
                                        <img src="https://api.qrserver.com/v1/create-qr-code/?size=180x180&data=${encodeURIComponent(d.m3u_hls)}" width="180" height="180" alt="M3U QR Code">
                                    </div>
                                    <small class="text-muted d-block">Scan to load M3U playlist stream directly on Mobile or Smart TV.</small>
                                </div>

                                <!-- Action Buttons Bar -->
                                <div class="xc-action-bar">
                                    <a href="${escHtml(d.m3u_hls)}" class="btn-xc-m3u" target="_blank">
                                        <i class="ti tabler-download fs-5"></i>
                                        <span>Download M3U (HLS)</span>
                                        <span class="btn-subtag">Apple / Android</span>
                                    </a>
                                    <a href="${escHtml(d.m3u_ts)}" class="btn-xc-m3u" target="_blank">
                                        <i class="ti tabler-download fs-5"></i>
                                        <span>Download M3U (TS)</span>
                                        <span class="btn-subtag">Smart TV / MAG</span>
                                    </a>
                                    <button class="btn-xc-copy-m3u btn-copy-code" data-code="${escHtml(d.m3u_hls)}">
                                        <i class="ti tabler-copy fs-5"></i>
                                        <span>Copy M3U Link</span>
                                    </button>
                                    ${d.direct_activate_url ? `
                                        <button class="btn btn-outline-primary btn-copy-code" data-code="${escHtml(d.direct_activate_url)}" title="Copy Activation Portal Link">
                                            <i class="ti tabler-link me-1"></i>Copy Portal Link
                                        </button>
                                        <a href="${escHtml(d.direct_activate_url)}" class="btn btn-outline-light" target="_blank" title="Test Subscriber Activation Portal">
                                            <i class="ti tabler-external-link me-1"></i>Open Portal
                                        </a>
                                    ` : ''}
                                    ${d.web_player_url ? `
                                        <a href="${escHtml(d.web_player_url)}" class="btn-xc-player" target="_blank">
                                            <i class="ti tabler-player-play fs-5"></i>
                                            <span>Launch Web Player</span>
                                        </a>
                                    ` : ''}
                                </div>

                                <!-- IPTV Apps Compatibility Strip -->
                                <div class="xc-apps-strip">
                                    <div class="xc-apps-label">
                                        <i class="ti tabler-devices text-info"></i>
                                        <span>Compatible Players:</span>
                                    </div>
                                    <div class="xc-app-badges">
                                        <span class="xc-app-badge">IPTV Smarters Pro</span>
                                        <span class="xc-app-badge">TiviMate</span>
                                        <span class="xc-app-badge">XCIPTV</span>
                                        <span class="xc-app-badge">IBO Player</span>
                                        <span class="xc-app-badge">VLC Player</span>
                                        <span class="xc-app-badge">OTT Navigator</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            jQuery('#modal-details-body').html(html);
        });
    });

    // Modal Password Toggle & QR Code Handlers
    jQuery(document).on('click', '.btn-modal-toggle-pw', function() {
        const valElem = jQuery(this).closest('.xc-cred-tile').find('.modal-pw-val');
        const icon = jQuery(this).find('i');
        const raw = valElem.data('raw');
        if (valElem.text() === '••••••••••••') {
            valElem.text(raw);
            icon.removeClass('tabler-eye').addClass('tabler-eye-off');
        } else {
            valElem.text('••••••••••••');
            icon.removeClass('tabler-eye-off').addClass('tabler-eye');
        }
    });

    jQuery(document).on('click', '.btn-qr-toggle', function() {
        jQuery(this).closest('.xc-cred-hub').find('.reseller-qr-box').toggleClass('d-none');
    });

    jQuery(document).on('click', '.btn-copy-all-reseller', function() {
        const hub = jQuery(this).closest('.xc-cred-hub');
        const host = hub.data('host');
        const port = hub.data('port');
        const user = hub.data('user');
        const pass = hub.data('pass');
        const m3u = hub.data('m3u');
        const text = [
            '========================================',
            '   STREAMING & XTREAM CODES CREDENTIALS',
            '========================================',
            `Server Host / URL : ${host}`,
            `Server Port       : ${port}`,
            `Username          : ${user}`,
            `Password          : ${pass}`,
            `M3U Playlist Link : ${m3u}`,
            '========================================'
        ].join('\n');
        const btn = jQuery(this);
        const orig = btn.html();
        copyToClipboard(text).then(() => {
            btn.html('<i class="ti tabler-check text-success me-1"></i>Copied All!');
            setTimeout(() => btn.html(orig), 1800);
        });
    });

    // Mass Enable
    jQuery('#btn-mass-enable').on('click', function() {
        execMassAction('mass_enable');
    });

    // Mass Disable
    jQuery('#btn-mass-disable').on('click', function() {
        execMassAction('mass_disable');
    });

    // Mass Reset Device
    jQuery('#btn-mass-reset').on('click', function() {
        execMassAction('mass_reset_device');
    });

    // Single Toggle Code
    tableEl.on('click', '.btn-toggle-code', function() {
        const id = jQuery(this).data('id');
        const currentStatus = jQuery(this).data('status');
        const newAction = (currentStatus == 0) ? 'mass_enable' : 'mass_disable';
        execSingleAction(newAction, [id]);
    });

    // Single Reset Device
    tableEl.on('click', '.btn-reset-code-device', function() {
        const id = jQuery(this).data('id');
        execSingleAction('mass_reset_device', [id]);
    });

    // Single Delete Code
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
    tableEl.on('click', '.btn-delete-code', function() {
        const id = jQuery(this).data('id');
        promptDeleteCode(id);
    });

    function promptDeleteCode(id) {
        selectedIds.clear();
        selectedIds.add(id);
        deleteModal.show();
    }

    // Edit Active Code Modal Logic
    let currentEditCodeId = null;
    const editModal = new bootstrap.Modal(document.getElementById('editCodeModal'));

    function openEditModal(codeId) {
        currentEditCodeId = codeId;
        jQuery('#edit-code-id').val(codeId);

        jQuery.getJSON('./api', { action: 'active_code_details', id: codeId }, function(res) {
            if (!res || !res.data) {
                if (window.xcToast) xcToast('Failed to load code details.', 'error');
                return;
            }
            const d = res.data;
            jQuery('#edit-code-string').val(d.code || '');
            jQuery('#edit-code-status').val(d.status !== undefined ? d.status : 1);
            if (d.package_id) {
                jQuery('#edit-code-package').val(d.package_id);
            }
            jQuery('#edit-code-batch').val(d.batch_name && d.batch_name !== 'None' ? d.batch_name : '');
            jQuery('#edit-code-exp-date').val(d.exp_date_input || '');
            jQuery('#edit-code-max-conn').val(d.max_connections || 1);
            jQuery('#edit-code-mac').val(d.raw_mac || (d.mac !== 'None' ? d.mac : ''));
            jQuery('#edit-code-password').val(d.password || '');

            editModal.show();
        }).fail(function() {
            if (window.xcToast) xcToast('Error fetching code data.', 'error');
        });
    }

    tableEl.on('click', '.btn-edit-code', function() {
        const id = jQuery(this).data('id');
        openEditModal(id);
    });

    jQuery(document).on('click', '.btn-edit-from-details', function() {
        detailsModal.hide();
        if (currentDetailsCodeId) {
            openEditModal(currentDetailsCodeId);
        }
    });

    jQuery(document).on('click', '.btn-delete-from-details', function() {
        detailsModal.hide();
        if (currentDetailsCodeId) {
            promptDeleteCode(currentDetailsCodeId);
        }
    });

    jQuery(document).on('click', '.btn-delete-from-edit', function() {
        editModal.hide();
        if (currentEditCodeId) {
            promptDeleteCode(currentEditCodeId);
        }
    });

    jQuery('#btn-clear-mac').on('click', function() {
        jQuery('#edit-code-mac').val('');
    });

    // Form submit for Edit Code
    jQuery('#form-edit-code').on('submit', function(e) {
        e.preventDefault();
        const submitBtn = jQuery(this).find('.btn-save-code-edit');
        const origText = submitBtn.html();
        submitBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm me-1"></span>Saving...');

        const postData = jQuery(this).serializeArray();
        const dataObj = { action: 'active_code_edit' };
        postData.forEach(item => {
            dataObj[item.name] = item.value;
        });

        jQuery.post('./api', dataObj, function(res) {
            submitBtn.prop('disabled', false).html(origText);
            let data = res;
            if (typeof res === 'string') {
                try { data = JSON.parse(res); } catch(e) {}
            }
            if (data && (data.result || data.status === 'SUCCESS')) {
                if (window.xcToast) {
                    xcToast(data.message || 'Active code updated successfully.', 'success');
                }
                editModal.hide();
                dt.ajax.reload(null, false);
            } else {
                const err = (data && data.message) ? data.message : 'Failed to update code.';
                if (window.xcToast) {
                    xcToast(err, 'error');
                } else {
                    alert(err);
                }
            }
        }).fail(function() {
            submitBtn.prop('disabled', false).html(origText);
            if (window.xcToast) xcToast('Server error while updating code.', 'error');
        });
    });

    // Mass Extend Modal
    const extendModal = new bootstrap.Modal(document.getElementById('extendModal'));
    jQuery('#btn-mass-extend').on('click', function() {
        extendModal.show();
    });
    jQuery('#btn-confirm-extend').on('click', function() {
        const days = jQuery('#extend-days').val();
        execMassAction('mass_extend', { days: days });
        extendModal.hide();
    });

    // Mass Delete Modal
    jQuery('#btn-mass-delete').on('click', function() {
        deleteModal.show();
    });
    jQuery('#btn-confirm-delete').on('click', function() {
        const refund = jQuery('#delete-refund').is(':checked') ? 1 : 0;
        execMassAction('mass_delete', { refund_credits: refund });
        deleteModal.hide();
    });

    function execMassAction(subAction, extra = {}) {
        if (selectedIds.size === 0) return;
        const ids = Array.from(selectedIds);
        executeApiAction(subAction, ids, extra);
    }

    function execSingleAction(subAction, ids, extra = {}) {
        executeApiAction(subAction, ids, extra);
    }

    function executeApiAction(subAction, ids, extra = {}) {
        const postData = Object.assign({
            action: 'active_codes_mass',
            sub_action: subAction,
            ids: JSON.stringify(ids)
        }, extra);

        jQuery.post('./api', postData, function(res) {
            let data = res;
            if (typeof res === 'string') {
                try { data = JSON.parse(res); } catch(e) {}
            }
            if (data && (data.result || data.status === 'SUCCESS')) {
                selectedIds.clear();
                updateFloatingBar();
                dt.ajax.reload(null, false);
                if (window.xcToast) {
                    xcToast(data.message || 'Operation completed successfully.', 'success');
                }
            } else {
                const msg = (data && data.message) ? data.message : 'Action failed.';
                if (window.xcToast) {
                    xcToast(msg, 'error');
                } else {
                    alert(msg);
                }
            }
        });
    }
    });
})(jQuery);
</script>
</body>

</html>

