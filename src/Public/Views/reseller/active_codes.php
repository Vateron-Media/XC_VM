<?php

/**
 * Reseller Active Codes (Bootstrap 5)
 *
 * Full-parity management table for Smart Activation Codes with delayed countdown
 * (Stock Mode), instant clipboard copy, live modal details, and floating mass actions bar.
 */

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
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Close</button>
            </div>
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
                <div class="form-check p-3 bg-light rounded-3 border">
                    <input class="form-check-input" type="checkbox" id="delete-refund" checked>
                    <label class="form-check-label fw-semibold" for="delete-refund">
                        Refund credits for unactivated stock codes
                    </label>
                    <div class="form-text small text-muted">Any codes that have not yet been activated by a client will have their purchase credits refunded to your balance.</div>
                </div>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete">Delete Codes</button>
            </div>
        </div>
    </div>
</div>

<style>
.badge-pulse {
    animation: pulse-green 2s infinite;
}
@keyframes pulse-green {
    0% { box-shadow: 0 0 0 0 rgba(40, 199, 111, 0.7); }
    70% { box-shadow: 0 0 0 8px rgba(40, 199, 111, 0); }
    100% { box-shadow: 0 0 0 0 rgba(40, 199, 111, 0); }
}
</style>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
(function($) {
    'use strict';
    $(function() {
        const tableEl = $('#active-codes-table');
    let selectedIds = new Set();

    // Escape helpers + cell renderers (server now returns a clean keyed payload;
    // all badges / actions are built client-side).
    function escHtml(s) {
        if (s === null || s === undefined) return '';
        return String(s).replace(/[&<>"']/g, function(c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
        });
    }
    function renderCheckbox(d, type, row) {
        const code = escHtml(row.code);
        return '<input type="checkbox" class="form-check-input row-select" value="' + row.id + '" data-code="' + code + '">';
    }
    function renderCode(d, type, row) {
        const code = escHtml(row.code);
        return '<div class="d-flex align-items-center gap-2">' +
            '<code class="fw-bold font-monospace text-primary fs-6 user-select-all">' + code + '</code>' +
            '<button type="button" class="btn btn-sm btn-icon btn-outline-secondary btn-copy-code" data-code="' + code + '" title="Copy Code"><i class="ti tabler-copy"></i></button>' +
            '</div>';
    }
    function renderBatch(d) {
        return '<span class="badge bg-label-secondary font-monospace">' + escHtml(d) + '</span>';
    }
    function renderPackage(d, type, row) {
        let html = '<span class="badge bg-label-info">' + escHtml(row.package_name) + '</span>';
        if (row.is_trial) html += ' <span class="badge bg-label-warning ms-1">Trial</span>';
        return html;
    }
    function renderStatus(d, type, row) {
        if (row.status === 0) return '<span class="badge bg-label-danger"><i class="ti tabler-ban me-1"></i>Disabled</span>';
        if (row.status === 1) return '<span class="badge bg-label-success badge-pulse"><i class="ti tabler-sparkles me-1"></i>Ready (Stock)</span>';
        if (row.exp_expired) return '<span class="badge bg-label-secondary"><i class="ti tabler-clock-off me-1"></i>Expired</span>';
        return '<span class="badge bg-label-primary"><i class="ti tabler-player-play me-1"></i>Active</span>';
    }
    function renderExpiry(d, type, row) {
        if (row.status === 0) return '<span class="text-muted fst-italic">Suspended</span>';
        if (row.status === 1) return '<span class="text-muted"><i class="ti tabler-snowflake me-1"></i>Frozen</span>';
        if (row.exp_expired) return '<span class="text-danger fw-semibold">' + escHtml(row.exp_str) + '</span>';
        return '<span class="text-primary">' + (row.exp_unix ? escHtml(row.exp_str) : 'Never') + '</span> <small class="text-muted">(' + row.remaining_days + 'd)</small>';
    }
    function renderSubscriber(d) {
        return d ? '<span class="fw-semibold">' + escHtml(d) + '</span>' : '<span class="text-muted fst-italic">Auto-assigned</span>';
    }
    function renderMac(d) {
        return d ? '<span class="badge bg-label-dark font-monospace">' + escHtml(d) + '</span>' : '<span class="text-muted">-</span>';
    }
    function renderActions(d, type, row) {
        const id = row.id, status = row.status;
        return '<div class="d-inline-block text-nowrap">' +
            '<button class="btn btn-sm btn-icon btn-text-secondary rounded-pill btn-view-code" data-id="' + id + '" title="View Details"><i class="ti tabler-eye"></i></button>' +
            '<button class="btn btn-sm btn-icon btn-text-secondary rounded-pill btn-toggle-code" data-id="' + id + '" data-status="' + status + '" title="' + (status == 0 ? 'Enable' : 'Disable') + '"><i class="ti ' + (status == 0 ? 'tabler-check text-success' : 'tabler-ban text-warning') + '"></i></button>' +
            '<button class="btn btn-sm btn-icon btn-text-secondary rounded-pill btn-reset-code-device" data-id="' + id + '" title="Reset Device Lock"><i class="ti tabler-device-desktop-off"></i></button>' +
            '<button class="btn btn-sm btn-icon btn-text-secondary rounded-pill btn-delete-code" data-id="' + id + '" title="Delete Code"><i class="ti tabler-trash text-danger"></i></button>' +
            '</div>';
    }

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
        columns: [
            { data: null, orderable: false, searchable: false, render: renderCheckbox },
            { data: 'code', render: renderCode },
            { data: 'batch', render: renderBatch },
            { data: null, render: renderPackage },
            { data: null, render: renderStatus },
            { data: null, render: renderExpiry },
            { data: 'sub_username', render: renderSubscriber },
            { data: 'mac', render: renderMac },
            { data: 'created_str', render: escHtml },
            { data: null, orderable: false, searchable: false, render: renderActions }
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
    const detailsModal = new bootstrap.Modal(document.getElementById('codeDetailsModal'));
    jQuery(document).on('click', '.btn-view-code', function() {
        const id = jQuery(this).data('id');
        jQuery('#modal-details-body').html(`
            <div class="text-center py-5">
                <div class="spinner-border text-primary" role="status"></div>
                <div class="mt-2 text-muted">Loading voucher details...</div>
            </div>
        `);
        detailsModal.show();

        jQuery.getJSON('./api', { action: 'active_code_details', id: id }, function(res) {
            if (!res.result) {
                jQuery('#modal-details-body').html(`<div class="alert alert-danger">${res.message || 'Error loading details'}</div>`);
                return;
            }
            const d = res.data;
            const html = `
                <div class="row g-4">
                    <div class="col-12 col-md-6">
                        <div class="p-3 bg-light-subtle rounded-3 border">
                            <label class="small text-muted text-uppercase fw-semibold d-block">Activation Code</label>
                            <div class="d-flex align-items-center justify-content-between mt-1">
                                <span class="fs-4 fw-bold font-monospace text-primary">${d.code}</span>
                                <button class="btn btn-sm btn-outline-primary btn-copy-code" data-code="${d.code}"><i class="ti tabler-copy me-1"></i>Copy</button>
                            </div>
                        </div>
                    </div>
                    <div class="col-12 col-md-6">
                        <div class="p-3 bg-light-subtle rounded-3 border">
                            <label class="small text-muted text-uppercase fw-semibold d-block">Current Status</label>
                            <div class="mt-1">
                                <span class="badge ${d.status === 1 ? 'bg-success' : (d.status === 2 ? 'bg-primary' : 'bg-danger')} fs-6 px-3 py-2">
                                    ${d.status_text}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="col-12 col-md-6">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Package:</span>
                                <span class="fw-semibold">${d.package_name} ${d.is_trial ? '<span class="badge bg-warning ms-1">Trial</span>' : ''}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Batch:</span>
                                <span class="font-monospace">${d.batch_name}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Max Connections:</span>
                                <span class="fw-semibold">${d.max_connections}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Device / MAC Lock:</span>
                                <span class="font-monospace">${d.mac}</span>
                            </li>
                        </ul>
                    </div>

                    <div class="col-12 col-md-6">
                        <ul class="list-group list-group-flush">
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Countdown Expiry:</span>
                                <span class="fw-semibold ${d.status === 1 ? 'text-info' : 'text-primary'}">${d.exp_date}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">First Activated:</span>
                                <span>${d.activated_at}</span>
                            </li>
                            <li class="list-group-item d-flex justify-content-between px-0">
                                <span class="text-muted">Created:</span>
                                <span>${d.created_at}</span>
                            </li>
                        </ul>
                    </div>

                    <div class="col-12">
                        <div class="card bg-dark text-white border-0 shadow-sm">
                            <div class="card-body">
                                <h6 class="card-title text-white d-flex align-items-center gap-2 mb-3">
                                    <i class="ti tabler-device-tv text-warning"></i>Xtream Codes & Streaming Credentials
                                </h6>
                                <div class="row g-2 text-start">
                                    <div class="col-12 col-md-6">
                                        <small class="text-secondary d-block">Username</small>
                                        <div class="font-monospace fw-semibold">${d.username}</div>
                                    </div>
                                    <div class="col-12 col-md-6">
                                        <small class="text-secondary d-block">Password</small>
                                        <div class="font-monospace fw-semibold">${d.password}</div>
                                    </div>
                                    <div class="col-12 col-md-8">
                                        <small class="text-secondary d-block">Server URL</small>
                                        <div class="font-monospace small">${d.portal_url}</div>
                                    </div>
                                    <div class="col-12 col-md-4">
                                        <small class="text-secondary d-block">Port</small>
                                        <div class="font-monospace">${d.port}</div>
                                    </div>
                                </div>
                                <hr class="border-secondary my-3">
                                <div class="d-flex flex-wrap gap-2">
                                    <a href="${d.m3u_hls}" class="btn btn-sm btn-outline-light" target="_blank">
                                        <i class="ti tabler-download me-1"></i>Download M3U (HLS)
                                    </a>
                                    <a href="${d.m3u_ts}" class="btn btn-sm btn-outline-light" target="_blank">
                                        <i class="ti tabler-download me-1"></i>Download M3U (TS)
                                    </a>
                                    <button class="btn btn-sm btn-outline-info btn-copy-code" data-code="${d.m3u_hls}">
                                        <i class="ti tabler-copy me-1"></i>Copy M3U
                                    </button>
                                    ${d.direct_activate_url ? `
                                        <button class="btn btn-sm btn-primary btn-copy-code" data-code="${d.direct_activate_url}">
                                            <i class="ti tabler-link me-1"></i>Copy Activation Portal Link
                                        </button>
                                        <a href="${d.direct_activate_url}" class="btn btn-sm btn-outline-light" target="_blank" title="Test Subscriber Activation Portal">
                                            <i class="ti tabler-external-link me-1"></i>Open Portal
                                        </a>
                                    ` : ''}
                                    ${d.web_player_url ? `
                                        <a href="${d.web_player_url}" class="btn btn-sm btn-warning text-dark fw-bold" target="_blank">
                                            <i class="ti tabler-player-play me-1"></i>Launch Web Player
                                        </a>
                                    ` : ''}
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            `;
            jQuery('#modal-details-body').html(html);
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
    tableEl.on('click', '.btn-delete-code', function() {
        const id = jQuery(this).data('id');
        selectedIds.clear();
        selectedIds.add(id);
        deleteModal.show();
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
    const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
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
            if (data.result) {
                selectedIds.clear();
                updateFloatingBar();
                dt.ajax.reload(null, false);
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

