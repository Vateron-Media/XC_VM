<?php

/**
 * Category Templates Management View (Admin).
 *
 * Displays modern cards for all category templates, live filtering,
 * owner filtering, template creation modal, clone action, bulk line application,
 * and system template toggles.
 */

$rCurrentUser = $currentUser ?? ($GLOBALS['rAdminUserInfo'] ?? ($GLOBALS['rUserInfo'] ?? []));
$rIsAdmin = !empty($isAdmin);
$rTemplates = $templates ?? [];
$rOwners = $owners ?? [];
$rSelectedOwner = $selectedOwner ?? null;
$rSearch = $search ?? '';
?>

<div class="container-xxl flex-grow-1 container-p-y">
    <!-- Page Header -->
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-3 mb-4">
        <div>
            <h4 class="fw-bold mb-1 d-flex align-items-center gap-2">
                <i class="icon-base ti tabler-layout-grid text-primary fs-3"></i>
                <span><?= $language::get('category_templates'); ?></span>
                <span class="badge bg-label-primary rounded-pill fs-7"><?= count($rTemplates); ?> <?= $language::get('templates'); ?></span>
            </h4>
            <p class="text-muted mb-0">
                <?= $language::get('category_templates_desc'); ?>
            </p>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn btn-primary d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                <i class="icon-base ti tabler-plus"></i>
                <span><?= $language::get('create_category_template'); ?></span>
            </button>
        </div>
    </div>

    <!-- Filter & Search Bar -->
    <div class="card mb-4 border-0 shadow-sm">
        <div class="card-body p-3">
            <form method="GET" action="category_templates" id="filterForm" class="row g-3 align-items-center">
                <div class="col-12 col-md-5">
                    <div class="input-group input-group-merge">
                        <span class="input-group-text"><i class="icon-base ti tabler-search"></i></span>
                        <input type="text" name="search" id="liveSearchInput" class="form-control" placeholder="<?= $language::get('search_templates'); ?>" value="<?= htmlspecialchars((string)$rSearch, ENT_QUOTES); ?>">
                    </div>
                </div>
                <?php if ($rIsAdmin): ?>
                    <div class="col-12 col-md-4">
                        <select name="owner_id" id="ownerFilterSelect" class="form-select" onchange="this.form.submit()">
                            <option value="">-- <?= $language::get('filter_by_owner'); ?> --</option>
                            <?php foreach ($rOwners as $owner): ?>
                                <option value="<?= (int)$owner['id']; ?>" <?= ($rSelectedOwner !== null && (int)$rSelectedOwner === (int)$owner['id']) ? 'selected' : ''; ?>>
                                    <?= htmlspecialchars((string)$owner['username'], ENT_QUOTES); ?> (ID: <?= (int)$owner['id']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-12 col-md-3 d-flex gap-2">
                    <button type="submit" class="btn btn-label-secondary w-100">
                        <i class="icon-base ti tabler-filter me-1"></i> <?= $language::get('filter'); ?>
                    </button>
                    <?php if (!empty($rSearch) || !empty($rSelectedOwner)): ?>
                        <a href="category_templates" class="btn btn-outline-secondary" title="<?= $language::get('clear_filter'); ?>">
                            <i class="icon-base ti tabler-x"></i>
                        </a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>

    <!-- Templates Grid -->
    <div class="row g-4" id="templatesContainer">
        <?php if (empty($rTemplates)): ?>
            <div class="col-12 text-center py-5">
                <div class="avatar avatar-xl bg-label-secondary mx-auto mb-3" style="width:72px;height:72px;">
                    <i class="icon-base ti tabler-folder-off fs-1 text-muted"></i>
                </div>
                <h5 class="mb-1 text-muted"><?= $language::get('no_templates_found'); ?></h5>
                <p class="text-body-secondary mb-3"><?= $language::get('no_templates_desc'); ?></p>
                <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createTemplateModal">
                    <i class="icon-base ti tabler-plus me-1"></i> <?= $language::get('create_template_now'); ?>
                </button>
            </div>
        <?php else: ?>
            <?php foreach ($rTemplates as $tmpl): ?>
                <?php
                $tmplId = (int)$tmpl['id'];
                $isSystem = (int)$tmpl['is_system'] === 1;
                $isShared = (int)$tmpl['is_shared'] === 1;
                $isOwner = (int)$tmpl['owner_id'] === (int)($rCurrentUser['id'] ?? 0);
                $canModify = $rIsAdmin || ($isOwner && !$isSystem);
                ?>
                <div class="col-12 col-md-6 col-xl-4 template-card-wrapper" data-name="<?= strtolower(htmlspecialchars((string)$tmpl['name'], ENT_QUOTES)); ?>" data-owner="<?= strtolower(htmlspecialchars((string)($tmpl['owner_name'] ?? ''), ENT_QUOTES)); ?>">
                    <div class="card h-100 border-0 shadow-sm template-card <?= $isSystem ? 'border-system-template' : ''; ?>">
                        <div class="card-body d-flex flex-column justify-content-between p-4">
                            <!-- Card Header & Badges -->
                            <div>
                                <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                                    <h5 class="card-title mb-0 fw-bold text-truncate" title="<?= htmlspecialchars((string)$tmpl['name'], ENT_QUOTES); ?>">
                                        <?= htmlspecialchars((string)$tmpl['name'], ENT_QUOTES); ?>
                                    </h5>
                                    <div class="d-flex flex-wrap gap-1 align-items-center">
                                        <?php if ($isSystem): ?>
                                            <span class="badge bg-label-info d-flex align-items-center gap-1" title="<?= $language::get('system_template_desc'); ?>">
                                                <i class="icon-base ti tabler-world fs-7"></i> <?= $language::get('system'); ?>
                                            </span>
                                        <?php elseif ($isShared): ?>
                                            <span class="badge bg-label-warning d-flex align-items-center gap-1" title="<?= $language::get('shared_desc'); ?>">
                                                <i class="icon-base ti tabler-share fs-7"></i> <?= $language::get('shared_with_subresellers'); ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-label-secondary d-flex align-items-center gap-1" title="<?= $language::get('private_template'); ?>">
                                                <i class="icon-base ti tabler-lock fs-7"></i> <?= $language::get('private_template'); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <!-- Owner, Date & Connected Subscribers Meta -->
                                <div class="text-body-secondary small mb-3 d-flex flex-wrap align-items-center gap-3">
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <i class="icon-base ti tabler-user fs-7"></i>
                                        <strong><?= htmlspecialchars((string)($tmpl['owner_name'] ?? 'System'), ENT_QUOTES); ?></strong>
                                    </span>
                                    <span class="d-inline-flex align-items-center gap-1">
                                        <i class="icon-base ti tabler-calendar fs-7"></i>
                                        <?= date('Y-m-d H:i', strtotime($tmpl['created_at'])); ?>
                                    </span>
                                    <span class="badge bg-label-primary d-inline-flex align-items-center gap-1" title="Connected Subscribers">
                                        <i class="icon-base ti tabler-users fs-7"></i>
                                        <strong><?= (int)($tmpl['subscriber_count'] ?? 0); ?></strong> <?= $language::get('users') ?? 'Subscribers'; ?>
                                    </span>
                                </div>

                                <!-- Category Metrics & Distribution Widget -->
                                <?php
                                $cLive   = (int)($tmpl['live_count'] ?? 0);
                                $cVod    = (int)($tmpl['vod_count'] ?? 0);
                                $cSeries = (int)($tmpl['series_count'] ?? 0);
                                $cRadio  = (int)($tmpl['radio_count'] ?? 0);
                                $cTotal  = $cLive + $cVod + $cSeries + $cRadio;

                                $pctLive   = $cTotal > 0 ? round(($cLive / $cTotal) * 100, 1) : 0;
                                $pctVod    = $cTotal > 0 ? round(($cVod / $cTotal) * 100, 1) : 0;
                                $pctSeries = $cTotal > 0 ? round(($cSeries / $cTotal) * 100, 1) : 0;
                                $pctRadio  = $cTotal > 0 ? round(($cRadio / $cTotal) * 100, 1) : 0;
                                ?>
                                <div class="p-2 rounded-3 bg-body-tertiary border mb-4">
                                    <div class="row g-2 mb-2">
                                        <div class="col-6 col-sm-3">
                                            <div class="d-flex align-items-center gap-2 p-2 rounded bg-card border h-100">
                                                <span class="badge bg-label-info p-2 rounded-2">
                                                    <i class="icon-base ti tabler-device-tv fs-6"></i>
                                                </span>
                                                <div class="text-truncate">
                                                    <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.65rem; letter-spacing: 0.5px;"><?= $language::get('live_categories'); ?></small>
                                                    <span class="fs-6 fw-bold text-heading"><?= $cLive; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 col-sm-3">
                                            <div class="d-flex align-items-center gap-2 p-2 rounded bg-card border h-100">
                                                <span class="badge bg-label-success p-2 rounded-2">
                                                    <i class="icon-base ti tabler-movie fs-6"></i>
                                                </span>
                                                <div class="text-truncate">
                                                    <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.65rem; letter-spacing: 0.5px;"><?= $language::get('movie_categories'); ?></small>
                                                    <span class="fs-6 fw-bold text-heading"><?= $cVod; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 col-sm-3">
                                            <div class="d-flex align-items-center gap-2 p-2 rounded bg-card border h-100">
                                                <span class="badge bg-label-warning p-2 rounded-2">
                                                    <i class="icon-base ti tabler-clapperboard fs-6"></i>
                                                </span>
                                                <div class="text-truncate">
                                                    <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.65rem; letter-spacing: 0.5px;"><?= $language::get('series_categories'); ?></small>
                                                    <span class="fs-6 fw-bold text-heading"><?= $cSeries; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-6 col-sm-3">
                                            <div class="d-flex align-items-center gap-2 p-2 rounded bg-card border h-100">
                                                <span class="badge bg-label-danger p-2 rounded-2">
                                                    <i class="icon-base ti tabler-radio fs-6"></i>
                                                </span>
                                                <div class="text-truncate">
                                                    <small class="text-muted d-block text-uppercase fw-semibold" style="font-size: 0.65rem; letter-spacing: 0.5px;"><?= $language::get('radio_categories'); ?></small>
                                                    <span class="fs-6 fw-bold text-heading"><?= $cRadio; ?></span>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <!-- Visual Distribution Bar & Total -->
                                    <div class="d-flex align-items-center gap-2 pt-1 px-1">
                                        <div class="progress flex-grow-1 bg-body rounded-pill border" style="height: 6px;" title="Live: <?= $cLive; ?> (<?= $pctLive; ?>%) | Movies: <?= $cVod; ?> (<?= $pctVod; ?>%) | Series: <?= $cSeries; ?> (<?= $pctSeries; ?>%) | Radio: <?= $cRadio; ?> (<?= $pctRadio; ?>%)">
                                            <?php if ($cTotal > 0): ?>
                                                <?php if ($pctLive > 0): ?><div class="progress-bar bg-info" role="progressbar" style="width: <?= $pctLive; ?>%"></div><?php endif; ?>
                                                <?php if ($pctVod > 0): ?><div class="progress-bar bg-success" role="progressbar" style="width: <?= $pctVod; ?>%"></div><?php endif; ?>
                                                <?php if ($pctSeries > 0): ?><div class="progress-bar bg-warning" role="progressbar" style="width: <?= $pctSeries; ?>%"></div><?php endif; ?>
                                                <?php if ($pctRadio > 0): ?><div class="progress-bar bg-danger" role="progressbar" style="width: <?= $pctRadio; ?>%"></div><?php endif; ?>
                                            <?php else: ?>
                                                <div class="progress-bar bg-secondary opacity-25" role="progressbar" style="width: 100%"></div>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge bg-label-secondary text-body-secondary fw-semibold py-1 px-2 rounded-pill" style="font-size: 0.7rem;">
                                            <?= $cTotal; ?> <?= $language::get('categories') ?? 'Categories'; ?>
                                        </span>
                                    </div>
                                </div>
                            </div>

                            <!-- Operations Footer -->
                            <div class="pt-3 border-top d-flex flex-wrap align-items-center justify-content-between gap-2">
                                <a href="category_template?id=<?= $tmplId; ?>" class="btn btn-sm btn-primary d-flex align-items-center gap-1 flex-grow-1 justify-content-center">
                                    <i class="icon-base ti tabler-pencil fs-7"></i>
                                    <span><?= $language::get('edit_template'); ?></span>
                                </a>

                                <button type="button" class="btn btn-sm btn-label-success js-btn-apply-all" data-id="<?= $tmplId; ?>" data-name="<?= htmlspecialchars((string)$tmpl['name'], ENT_QUOTES); ?>" title="<?= $language::get('apply_to_all_desc'); ?>">
                                    <i class="icon-base ti tabler-users-group fs-7"></i>
                                    <span><?= $language::get('apply_to_all_subscribers'); ?></span>
                                </button>

                                <div class="dropdown">
                                    <button type="button" class="btn btn-sm btn-label-secondary btn-icon dropdown-toggle hide-arrow shadow-none" data-bs-toggle="dropdown" data-bs-boundary="viewport" data-bs-offset="0,6" aria-expanded="false">
                                        <i class="icon-base ti tabler-dots-vertical"></i>
                                    </button>
                                    <ul class="dropdown-menu dropdown-menu-end shadow">
                                        <li>
                                            <a class="dropdown-item d-flex align-items-center gap-2 js-btn-clone py-2 px-3" href="javascript:void(0);" data-id="<?= $tmplId; ?>" data-name="<?= htmlspecialchars((string)$tmpl['name'], ENT_QUOTES); ?>">
                                                <i class="icon-base ti tabler-copy text-primary fs-6"></i>
                                                <span><?= $language::get('clone_template'); ?></span>
                                            </a>
                                        </li>
                                        <?php if ($rIsAdmin): ?>
                                            <li>
                                                <a class="dropdown-item d-flex align-items-center gap-2 js-btn-toggle-system py-2 px-3" href="javascript:void(0);" data-id="<?= $tmplId; ?>" data-system="<?= $isSystem ? '0' : '1'; ?>">
                                                    <i class="icon-base ti tabler-world-share text-info fs-6"></i>
                                                    <span><?= $isSystem ? 'Unmark System Template' : 'Set as System Template'; ?></span>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                        <?php if ($canModify): ?>
                                            <li><hr class="dropdown-divider my-1"></li>
                                            <li>
                                                <a class="dropdown-item d-flex align-items-center gap-2 text-danger js-btn-delete py-2 px-3" href="javascript:void(0);" data-id="<?= $tmplId; ?>" data-name="<?= htmlspecialchars((string)$tmpl['name'], ENT_QUOTES); ?>">
                                                    <i class="icon-base ti tabler-trash fs-6"></i>
                                                    <span><?= $language::get('delete_template'); ?></span>
                                                </a>
                                            </li>
                                        <?php endif; ?>
                                    </ul>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Modal: Create Template -->
<div class="modal fade" id="createTemplateModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header border-bottom">
                <h5 class="modal-title d-flex align-items-center gap-2">
                    <i class="icon-base ti tabler-layout-grid-add text-primary"></i>
                    <span><?= $language::get('create_category_template'); ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="createTemplateForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold" for="new_template_name"><?= $language::get('template_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="new_template_name" name="name" placeholder="<?= $language::get('enter_template_name'); ?>" required autofocus>
                    </div>
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" id="new_is_shared" name="is_shared" value="1">
                        <label class="form-check-label" for="new_is_shared">
                            <?= $language::get('share_with_subresellers'); ?>
                        </label>
                    </div>
                    <?php if ($rIsAdmin): ?>
                        <div class="form-check form-switch mb-3">
                            <input class="form-check-input" type="checkbox" id="new_is_system" name="is_system" value="1">
                            <label class="form-check-label text-info fw-semibold" for="new_is_system">
                                <i class="icon-base ti tabler-world me-1"></i> <?= $language::get('set_as_system_template'); ?>
                            </label>
                        </div>
                    <?php endif; ?>
                    <div class="alert alert-info py-2 px-3 small mb-0 d-flex align-items-center gap-2">
                        <i class="icon-base ti tabler-info-circle fs-5 flex-shrink-0"></i>
                        <span>Default server categories will be loaded automatically into the template editor.</span>
                    </div>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel'); ?></button>
                    <button type="submit" class="btn btn-primary d-flex align-items-center gap-1" id="btnSubmitCreate">
                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        <span><?= $language::get('create'); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal: Apply to All Lines -->
<div class="modal fade" id="applyAllModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow">
            <div class="modal-header bg-label-success border-bottom">
                <h5 class="modal-title d-flex align-items-center gap-2 text-success">
                    <i class="icon-base ti tabler-users-group"></i>
                    <span><?= $language::get('apply_to_all_subscribers'); ?></span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="applyAllForm">
                <input type="hidden" name="template_id" id="apply_template_id" value="">
                <div class="modal-body">
                    <div class="alert alert-warning py-2 px-3 small mb-3">
                        <i class="icon-base ti tabler-alert-triangle me-1"></i>
                        <strong>Notice:</strong> <?= $language::get('action_cannot_be_undone'); ?>
                    </div>
                    <p class="mb-2">
                        <?= $language::get('template'); ?>: <strong id="apply_template_name_display" class="text-primary"></strong>
                    </p>
                    <?php if ($rIsAdmin): ?>
                        <div class="mb-3">
                            <label class="form-label small fw-semibold" for="apply_target_reseller">Apply to specific reseller lines (Optional):</label>
                            <select name="target_reseller_id" id="apply_target_reseller" class="form-select form-select-sm">
                                <option value="">-- Owner's Lines Only --</option>
                                <?php foreach ($rOwners as $owner): ?>
                                    <option value="<?= (int)$owner['id']; ?>"><?= htmlspecialchars((string)$owner['username'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>
                </div>
                <div class="modal-footer border-top">
                    <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel'); ?></button>
                    <button type="submit" class="btn btn-success d-flex align-items-center gap-1" id="btnSubmitApplyAll">
                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        <span><?= $language::get('apply_template'); ?></span>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>

<script>
(function() {
    'use strict';

    var toast = window.xcToast || function(type, msg) {
        if (typeof Swal !== 'undefined') {
            Swal.fire({
                icon: type === 'error' ? 'error' : 'success',
                title: msg,
                timer: 3000,
                showConfirmButton: false,
                toast: true,
                position: 'top-end'
            });
        } else {
            alert(msg);
        }
    };

    function confirmAction(title, text) {
        if (typeof Swal !== 'undefined') {
            return Swal.fire({
                title: title,
                text: text,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#7367f0',
                cancelButtonColor: '#82868b',
                confirmButtonText: 'Yes, proceed',
                cancelButtonText: 'Cancel'
            }).then(function(res) { return res.isConfirmed; });
        }
        return Promise.resolve(window.confirm(text || title));
    }

    // Ensure template card dropdowns pop above adjacent cards without being clipped
    document.querySelectorAll('#templatesContainer .dropdown').forEach(function(dd) {
        dd.addEventListener('show.bs.dropdown', function() {
            var card = this.closest('.template-card');
            var wrapper = this.closest('.template-card-wrapper');
            if (card) card.classList.add('dropdown-active');
            if (wrapper) wrapper.classList.add('dropdown-active');
        });
        dd.addEventListener('hidden.bs.dropdown', function() {
            var card = this.closest('.template-card');
            var wrapper = this.closest('.template-card-wrapper');
            if (card) card.classList.remove('dropdown-active');
            if (wrapper) wrapper.classList.remove('dropdown-active');
        });
    });

    // Live Instant Client-side Search
    var searchInput = document.getElementById('liveSearchInput');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            var val = this.value.toLowerCase().trim();
            var cards = document.querySelectorAll('.template-card-wrapper');
            cards.forEach(function(card) {
                var name = card.getAttribute('data-name') || '';
                var owner = card.getAttribute('data-owner') || '';
                if (name.includes(val) || owner.includes(val)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }

    // Create Template Form Submission
    var createForm = document.getElementById('createTemplateForm');
    if (createForm) {
        createForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('btnSubmitCreate');
            var spinner = btn.querySelector('.spinner-border');
            btn.disabled = true;
            spinner.classList.remove('d-none');

            var formData = new FormData(this);

            fetch('./api?action=category_template_create', {
                method: 'POST',
                body: formData,
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btn.disabled = false;
                spinner.classList.add('d-none');
                if (data.result && data.id) {
                    toast('success', data.message || 'Template created successfully.');
                    window.location.href = 'category_template?id=' + data.id;
                } else {
                    toast('error', data.message || 'Failed to create template.');
                }
            })
            .catch(function() {
                btn.disabled = false;
                spinner.classList.add('d-none');
                toast('error', 'Connection error to server.');
            });
        });
    }

    // Clone Template Action
    document.querySelectorAll('.js-btn-clone').forEach(function(el) {
        el.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var name = this.getAttribute('data-name');
            confirmAction('Clone Template', 'Do you want to create a new copy of "' + name + '" for your account?').then(function(confirmed) {
                if (!confirmed) return;

                fetch('./api?action=category_template_clone&id=' + encodeURIComponent(id), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.result && data.id) {
                        toast('success', data.message || 'Template cloned successfully.');
                        setTimeout(function() { window.location.reload(); }, 700);
                    } else {
                        toast('error', data.message || 'Failed to clone template.');
                    }
                })
                .catch(function() { toast('error', 'Connection error to server.'); });
            });
        });
    });

    // Toggle System Template (Admin Only)
    document.querySelectorAll('.js-btn-toggle-system').forEach(function(el) {
        el.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var sys = this.getAttribute('data-system');

            fetch('./api?action=category_template_toggle_system&id=' + encodeURIComponent(id) + '&is_system=' + encodeURIComponent(sys), {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                if (data.result) {
                    toast('success', data.message || 'System template status updated successfully.');
                    setTimeout(function() { window.location.reload(); }, 600);
                } else {
                    toast('error', data.message || 'Failed to update system template status.');
                }
            })
            .catch(function() { toast('error', 'Connection error to server.'); });
        });
    });

    // Delete Template Action
    document.querySelectorAll('.js-btn-delete').forEach(function(el) {
        el.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var name = this.getAttribute('data-name');

            confirmAction('Delete Template', 'Are you sure you want to delete template "' + name + '"? This action cannot be undone.').then(function(confirmed) {
                if (!confirmed) return;

                fetch('./api?action=category_template_delete&id=' + encodeURIComponent(id), {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(res) { return res.json(); })
                .then(function(data) {
                    if (data.result) {
                        toast('success', data.message || 'Template deleted successfully.');
                        setTimeout(function() { window.location.reload(); }, 600);
                    } else {
                        toast('error', data.message || 'Failed to delete template.');
                    }
                })
                .catch(function() { toast('error', 'Connection error to server.'); });
            });
        });
    });

    // Open Apply to All Modal
    document.querySelectorAll('.js-btn-apply-all').forEach(function(el) {
        el.addEventListener('click', function() {
            var id = this.getAttribute('data-id');
            var name = this.getAttribute('data-name');

            document.getElementById('apply_template_id').value = id;
            document.getElementById('apply_template_name_display').textContent = name;

            var modal = new bootstrap.Modal(document.getElementById('applyAllModal'));
            modal.show();
        });
    });

    // Submit Apply to All Form
    var applyForm = document.getElementById('applyAllForm');
    if (applyForm) {
        applyForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('btnSubmitApplyAll');
            var spinner = btn.querySelector('.spinner-border');
            btn.disabled = true;
            spinner.classList.remove('d-none');

            var templateId = document.getElementById('apply_template_id').value;
            var targetReseller = document.getElementById('apply_target_reseller') ? document.getElementById('apply_target_reseller').value : '';

            var url = './api?action=category_template_apply_all&id=' + encodeURIComponent(templateId);
            if (targetReseller) {
                url += '&target_reseller_id=' + encodeURIComponent(targetReseller);
            }

            fetch(url, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btn.disabled = false;
                spinner.classList.add('d-none');
                var modalEl = document.getElementById('applyAllModal');
                var modal = bootstrap.Modal.getInstance(modalEl);
                if (modal) modal.hide();

                if (data.result) {
                    toast('success', data.message || 'Template applied successfully.');
                } else {
                    toast('error', data.message || 'Failed to apply template.');
                }
            })
            .catch(function() {
                btn.disabled = false;
                spinner.classList.add('d-none');
                toast('error', 'Connection error to server.');
            });
        });
    }
})();
</script>
