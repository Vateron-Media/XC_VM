<?php

/**
 * Admin Active Codes Management (Bootstrap 5)
 *
 * Server-side table: TableController::handleActiveCodes() answers ./table with a
 * clean keyed payload; DataTables requests it (paging / filtering / search on the
 * server) and the cell markup is built client-side by the render functions below.
 * Reseller / status / batch / package filters, floating bulk toolbar, and the
 * server-rendered voucher-details modal are wired in the script.
 */

?>

<div class="card mb-4">
    <div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">
        <h5 class="card-title mb-0"><i class="ti tabler-key text-primary me-2"></i><?= $language::get('ac_active_codes_management') ?></h5>
        <div class="d-flex gap-2">
            <a href="active_codes_batch" class="btn btn-sm btn-label-secondary">
                <i class="ti tabler-folders me-1"></i><?= $language::get('batch_manager') ?>
            </a>
            <a href="active_codes_mass" class="btn btn-sm btn-label-info">
                <i class="ti tabler-adjustments me-1"></i><?= $language::get('ac_mass_edit') ?>
            </a>
            <a href="active_code" class="btn btn-sm btn-primary">
                <i class="ti tabler-plus me-1"></i><?= $language::get('generate_codes') ?>
            </a>
        </div>
    </div>

    <!-- Filter Bar -->
    <div class="card-body border-bottom bg-light-subtle">
        <div class="row g-3">
            <div class="col-6 col-md-3">
                <label class="form-label small text-uppercase fw-semibold" for="filter-reseller"><?= $language::get('reseller') ?></label>
                <select id="filter-reseller" class="form-select">
                    <option value="0"><?= $language::get('ac_all_resellers') ?></option>
                    <?php foreach ($resellers as $res): ?>
                        <option value="<?= (int) $res['id']; ?>"><?= htmlspecialchars((string) $res['username'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-uppercase fw-semibold" for="filter-status"><?= $language::get('status') ?></label>
                <select id="filter-status" class="form-select">
                    <option value="0"><?= $language::get('ac_all_statuses') ?></option>
                    <option value="1"><?= $language::get('ac_ready_stock_unused') ?></option>
                    <option value="2"><?= $language::get('ac_active_streaming') ?></option>
                    <option value="3"><?= $language::get('expired') ?></option>
                    <option value="4"><?= $language::get('disabled') ?></option>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-uppercase fw-semibold" for="filter-batch"><?= $language::get('ac_batch') ?></label>
                <select id="filter-batch" class="form-select">
                    <option value=""><?= $language::get('ac_all_batches') ?></option>
                    <?php foreach ($batches as $b): ?>
                        <option value="<?= htmlspecialchars((string) $b['batch_name'], ENT_QUOTES); ?>"><?= htmlspecialchars((string) $b['batch_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-6 col-md-3">
                <label class="form-label small text-uppercase fw-semibold" for="filter-package"><?= $language::get('package') ?></label>
                <select id="filter-package" class="form-select">
                    <option value="0"><?= $language::get('ac_all_packages') ?></option>
                    <?php foreach ($rPackages as $pkg): ?>
                        <option value="<?= (int) $pkg['id']; ?>"><?= htmlspecialchars((string) $pkg['package_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>

    <div class="card-datatable table-responsive">
        <table id="admin-active-codes-table" class="table table-hover" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th style="width: 35px;"><input type="checkbox" id="select-all" class="form-check-input"></th>
                    <th><?= $language::get('ac_code') ?></th>
                    <th><?= $language::get('ac_batch') ?></th>
                    <th><?= $language::get('package') ?></th>
                    <th><?= $language::get('ac_owner_reseller') ?></th>
                    <th><?= $language::get('status') ?></th>
                    <th><?= $language::get('expiration') ?></th>
                    <th><?= $language::get('ac_subscriber') ?></th>
                    <th><?= $language::get('ac_device_lock') ?></th>
                    <th><?= $language::get('ac_created') ?></th>
                    <th class="text-center"><?= $language::get('actions') ?></th>
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
        <span class="small fw-semibold"><?= $language::get('ac_codes_selected') ?></span>
    </div>
    <div class="vr bg-secondary opacity-50 my-1"></div>
    <div class="d-flex flex-wrap gap-2 ms-auto">
        <button type="button" class="btn btn-sm btn-success" id="btn-mass-enable"><i class="ti tabler-check me-1"></i><?= $language::get('enable') ?></button>
        <button type="button" class="btn btn-sm btn-warning" id="btn-mass-disable"><i class="ti tabler-ban me-1"></i><?= $language::get('disable') ?></button>
        <button type="button" class="btn btn-sm btn-info" id="btn-mass-extend"><i class="ti tabler-calendar-plus me-1"></i><?= $language::get('ac_extend') ?></button>
        <button type="button" class="btn btn-sm btn-secondary" id="btn-mass-reset"><i class="ti tabler-device-desktop-off me-1"></i><?= $language::get('ac_reset_device') ?></button>
        <button type="button" class="btn btn-sm btn-danger" id="btn-mass-delete"><i class="ti tabler-trash me-1"></i><?= $language::get('delete') ?></button>
        <button type="button" class="btn btn-sm btn-outline-light" id="btn-mass-cancel"><i class="ti tabler-x"></i></button>
    </div>
</div>

<!-- Code Details Modal -->
<div class="modal fade" id="codeDetailsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header border-bottom">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="ti tabler-key text-primary"></i>
                    <span><?= $language::get('ac_activation_code_voucher_details') ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <div id="modal-details-loading" class="text-center py-5">
                    <div class="spinner-border text-primary" role="status"></div>
                    <div class="mt-2 text-muted"><?= $language::get('ac_loading_voucher_details') ?></div>
                </div>
                <div id="modal-details-content"></div>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('close') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Extend Days Modal -->
<div class="modal fade" id="extendModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title"><?= $language::get('ac_extend_active_codes') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <label class="form-label" for="extend-days"><?= $language::get('ac_number_of_days') ?></label>
                <input type="number" id="extend-days" class="form-control" value="30" min="1" max="365">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel') ?></button>
                <button type="button" class="btn btn-primary" id="btn-confirm-extend"><?= $language::get('ac_extend') ?></button>
            </div>
        </div>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header border-bottom">
                <h5 class="modal-title text-danger"><i class="ti tabler-alert-triangle me-2"></i><?= $language::get('ac_delete_codes') ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <p><?= $language::get('ac_delete_codes_confirm') ?></p>
            </div>
            <div class="modal-footer border-top">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel') ?></button>
                <button type="button" class="btn btn-danger" id="btn-confirm-delete"><?= $language::get('delete') ?></button>
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
renderUnifiedLayoutFooter('admin');
?>
<script>
(function($) {
    'use strict';
    $(function() {
        const tableEl = $('#admin-active-codes-table');
        let selectedIds = new Set();

        // Cell renderers (server returns a clean keyed payload; badges / status /
        // actions are built here).
        function escHtml(s) {
            if (s === null || s === undefined) return '';
            return String(s).replace(/[&<>"']/g, function(c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[c];
            });
        }
        function renderCheckbox(d, type, row) {
            return '<input type="checkbox" class="form-check-input row-select" value="' + row.id + '">';
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
        function renderCreator(d) {
            return '<span class="badge bg-label-dark"><i class="ti tabler-user me-1"></i>' + escHtml(d) + '</span>';
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
            return '<span class="text-primary">' + (row.exp_unix ? escHtml(row.exp_str) : 'Never') + '</span>' +
                (row.exp_unix ? ' <small class="text-muted">(' + row.remaining_days + 'd)</small>' : '');
        }
        function renderSubscriber(d) {
            return d ? '<span class="fw-semibold">' + escHtml(d) + '</span>' : '<span class="text-muted fst-italic">Auto-assigned</span>';
        }
        function renderMac(d) {
            return d ? '<span class="badge bg-label-dark font-monospace">' + escHtml(d) + '</span>' : '<span class="text-muted">-</span>';
        }
        function renderActions(d, type, row) {
            const id = row.id, status = row.status;
            const enable = (status == 0);
            return '<div class="d-inline-block text-nowrap">' +
                '<button class="btn btn-sm btn-icon btn-label-secondary me-1 btn-view-code" data-id="' + id + '" title="View Details"><i class="ti tabler-eye"></i></button>' +
                '<button class="btn btn-sm btn-icon ' + (enable ? 'btn-label-success' : 'btn-label-warning') + ' me-1 btn-toggle-code" data-id="' + id + '" data-status="' + status + '" title="' + (enable ? 'Enable' : 'Disable') + '"><i class="ti ' + (enable ? 'tabler-check' : 'tabler-ban') + '"></i></button>' +
                '<button class="btn btn-sm btn-icon btn-label-secondary me-1 btn-reset-code-device" data-id="' + id + '" title="Reset Device Lock"><i class="ti tabler-device-desktop-off"></i></button>' +
                '<button class="btn btn-sm btn-icon btn-label-danger btn-delete-code" data-id="' + id + '" title="Delete Code"><i class="ti tabler-trash"></i></button>' +
                '</div>';
        }

        const dt = tableEl.DataTable({
            processing: true,
            serverSide: true,
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [[10, 'desc']],
            pageLength: 25,
            lengthMenu: [10, 25, 50, 100],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            },
            ajax: {
                url: './table',
                type: 'POST',
                data: function(d) {
                    d.id = 'active_codes';
                    d.reseller = $('#filter-reseller').val();
                    d.filter = $('#filter-status').val();
                    d.batch = $('#filter-batch').val();
                    d.package = $('#filter-package').val();
                }
            },
            columns: [
                { data: null, className: 'control', orderable: false, searchable: false, defaultContent: '' },
                { data: null, orderable: false, searchable: false, render: renderCheckbox },
                { data: 'code', render: renderCode },
                { data: 'batch', render: renderBatch },
                { data: null, render: renderPackage },
                { data: 'creator', render: renderCreator },
                { data: null, render: renderStatus },
                { data: null, render: renderExpiry },
                { data: 'sub_username', render: renderSubscriber },
                { data: 'mac', render: renderMac },
                { data: 'created_str', render: escHtml },
                { data: null, orderable: false, searchable: false, render: renderActions }
            ],
            drawCallback: function() {
                updateFloatingBar();
                tableEl.find('.row-select').each(function() {
                    if (selectedIds.has(this.value)) {
                        this.checked = true;
                    }
                });
            }
        });

        $('#filter-reseller, #filter-status, #filter-batch, #filter-package').on('change', function() {
            dt.ajax.reload();
        });

        // Row selection + floating mass-action bar
        tableEl.on('change', '.row-select', function() {
            if (this.checked) {
                selectedIds.add(this.value);
            } else {
                selectedIds.delete(this.value);
            }
            updateFloatingBar();
        });

        $('#select-all').on('change', function() {
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
            const count = selectedIds.size;
            $('#selected-count').text(count);
            const bar = $('#mass-action-bar');
            if (count > 0) {
                bar.removeClass('d-none').addClass('d-flex');
            } else {
                bar.removeClass('d-flex').addClass('d-none');
                $('#select-all').prop('checked', false);
            }
        }

        $('#btn-mass-cancel').on('click', function() {
            selectedIds.clear();
            tableEl.find('.row-select').prop('checked', false);
            updateFloatingBar();
        });

        // Clipboard copy (delegated)
        function copyToClipboard(text) {
            if (navigator.clipboard && window.isSecureContext) {
                return navigator.clipboard.writeText(text);
            }
            return new Promise(function(resolve, reject) {
                try {
                    const textarea = document.createElement('textarea');
                    textarea.value = String(text);
                    textarea.style.position = 'fixed';
                    textarea.style.left = '-9999px';
                    textarea.style.top = '0';
                    textarea.setAttribute('readonly', '');
                    // Append inside the open modal (if any) so Bootstrap's focus
                    // trap does not steal focus and clear the selection before copy.
                    const host = document.querySelector('.modal.show') || document.body;
                    host.appendChild(textarea);
                    textarea.focus();
                    textarea.select();
                    textarea.setSelectionRange(0, textarea.value.length);
                    const ok = document.execCommand('copy');
                    host.removeChild(textarea);
                    (ok && textarea.value.length > 0) ? resolve() : reject(new Error('copy command failed'));
                } catch (err) {
                    reject(err);
                }
            });
        }

        $(document).on('click', '.btn-copy-code', function(e) {
            e.preventDefault();
            const btn = $(this);
            const code = btn.data('code');
            const icon = btn.find('i');
            const orig = icon.attr('class');
            copyToClipboard(code).then(function() {
                icon.attr('class', 'ti tabler-check text-success');
                setTimeout(function() { icon.attr('class', orig); }, 1500);
            }).catch(function() {
                prompt('Copy to clipboard:', code);
            });
        });

        // View details modal — body HTML rendered server-side and injected
        const detailsModal = new bootstrap.Modal(document.getElementById('codeDetailsModal'));
        $(document).on('click', '.btn-view-code', function() {
            const id = $(this).data('id');
            $('#modal-details-content').empty();
            $('#modal-details-loading').removeClass('d-none');
            detailsModal.show();
            $.get('./api', { action: 'active_code_details', id: id })
                .done(function(html) {
                    $('#modal-details-loading').addClass('d-none');
                    $('#modal-details-content').html(html);
                })
                .fail(function() {
                    $('#modal-details-loading').addClass('d-none');
                    xcToast('Error loading details', 'error');
                    detailsModal.hide();
                });
        });

        // Mass + single actions
        $('#btn-mass-enable').on('click', function() { execMassAction('mass_enable'); });
        $('#btn-mass-disable').on('click', function() { execMassAction('mass_disable'); });
        $('#btn-mass-reset').on('click', function() { execMassAction('mass_reset_device'); });

        tableEl.on('click', '.btn-toggle-code', function() {
            const id = $(this).data('id');
            const action = ($(this).data('status') == 0) ? 'mass_enable' : 'mass_disable';
            executeApiAction(action, [id]);
        });

        tableEl.on('click', '.btn-reset-code-device', function() {
            executeApiAction('mass_reset_device', [$(this).data('id')]);
        });

        const deleteModal = new bootstrap.Modal(document.getElementById('deleteConfirmModal'));
        tableEl.on('click', '.btn-delete-code', function() {
            selectedIds.clear();
            selectedIds.add(String($(this).data('id')));
            deleteModal.show();
        });

        const extendModal = new bootstrap.Modal(document.getElementById('extendModal'));
        $('#btn-mass-extend').on('click', function() { extendModal.show(); });
        $('#btn-confirm-extend').on('click', function() {
            execMassAction('mass_extend', { days: $('#extend-days').val() });
            extendModal.hide();
        });

        $('#btn-mass-delete').on('click', function() { deleteModal.show(); });
        $('#btn-confirm-delete').on('click', function() {
            execMassAction('mass_delete');
            deleteModal.hide();
        });

        function execMassAction(subAction, extra) {
            if (selectedIds.size === 0) {
                return;
            }
            executeApiAction(subAction, Array.from(selectedIds), extra);
        }

        function executeApiAction(subAction, ids, extra) {
            const postData = Object.assign({
                action: 'multi',
                type: 'active_code',
                sub: subAction,
                ids: JSON.stringify(ids)
            }, extra || {});
            $.post('./api', postData, function(res) {
                let data = res;
                if (typeof res === 'string') {
                    try { data = JSON.parse(res); } catch (e) {}
                }
                if (data && data.result) {
                    selectedIds.clear();
                    updateFloatingBar();
                    dt.ajax.reload(null, false);
                } else {
                    xcToast((data && data.message) || 'Action failed.', 'error');
                }
            });
        }
    });
})(jQuery);
</script>
</body>

</html>
