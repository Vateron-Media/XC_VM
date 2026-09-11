<?php

/**
 * Category Template Editor View (Admin).
 *
 * Full drag-and-drop interactive sorting, custom renaming, visibility toggling,
 * bulk actions, and multi-tab management (Live TV, Movies, Series).
 */

$rCurrentUser = $currentUser ?? ($GLOBALS['rAdminUserInfo'] ?? ($GLOBALS['rUserInfo'] ?? []));
$rIsAdmin = !empty($isAdmin);
$rTemplate = $template ?? [];
$rCategories = $categories ?? ['live' => [], 'movie' => [], 'series' => []];

$tmplId = (int)($rTemplate['id'] ?? 0);
$tmplName = $rTemplate['name'] ?? '';
$isSystem = (int)($rTemplate['is_system'] ?? 0) === 1;
$isShared = (int)($rTemplate['is_shared'] ?? 0) === 1;
?>

<div class="container-xxl flex-grow-1 container-p-y">
    <!-- Sticky Top Toolbar -->
    <div class="card mb-4 border-0 shadow-sm sticky-top" style="top: 1rem; z-index: 1020; backdrop-filter: blur(10px); background: rgba(var(--bs-card-bg-rgb), 0.92);">
        <div class="card-body p-3">
            <div class="d-flex flex-wrap justify-content-between align-items-center gap-3">
                <!-- Left: Back & Template Name -->
                <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 650px;">
                    <a href="category_templates" class="btn btn-label-secondary btn-icon flex-shrink-0" title="<?= $language::get('back_to_templates'); ?>">
                        <i class="icon-base ti tabler-arrow-left"></i>
                    </a>
                    <div class="flex-grow-1">
                        <div class="input-group input-group-merge">
                            <span class="input-group-text"><i class="icon-base ti tabler-pencil text-primary"></i></span>
                            <input type="text" id="templateNameInput" class="form-control fw-bold fs-6" value="<?= htmlspecialchars((string)$tmplName, ENT_QUOTES); ?>" placeholder="<?= $language::get('enter_template_name'); ?>">
                        </div>
                    </div>
                </div>

                <!-- Right: Options, Subscribers Badge & Save Button -->
                <div class="d-flex flex-wrap align-items-center gap-3">
                    <span class="badge bg-label-primary d-flex align-items-center gap-1 py-2 px-3 border border-primary border-opacity-25" id="templateSubscribersBadge" title="Subscribers currently attached to this template">
                        <i class="icon-base ti tabler-users fs-6"></i>
                        <span><strong id="templateSubscribersCount"><?= (int)($rTemplate['subscriber_count'] ?? 0); ?></strong> <?= $language::get('users') ?? 'Subscribers'; ?></span>
                    </span>

                    <div class="form-check form-switch mb-0">
                        <input class="form-check-input" type="checkbox" id="templateIsShared" <?= $isShared ? 'checked' : ''; ?>>
                        <label class="form-check-label small fw-semibold" for="templateIsShared"><?= $language::get('share_with_subresellers'); ?></label>
                    </div>

                    <?php if ($rIsAdmin): ?>
                        <div class="form-check form-switch mb-0">
                            <input class="form-check-input" type="checkbox" id="templateIsSystem" <?= $isSystem ? 'checked' : ''; ?>>
                            <label class="form-check-label small fw-semibold text-info" for="templateIsSystem"><?= $language::get('system_template'); ?></label>
                        </div>
                    <?php endif; ?>

                    <button type="button" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" id="btnSaveTemplate">
                        <span class="spinner-border spinner-border-sm d-none" role="status" aria-hidden="true"></span>
                        <i class="icon-base ti tabler-device-floppy"></i>
                        <span id="btnSaveText"><?= $language::get('save_changes'); ?></span>
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Main Editor Tabs & Content -->
    <div class="card border-0 shadow-sm">
        <div class="card-header border-bottom p-0">
            <ul class="nav nav-tabs nav-fill border-0" id="editorTabs" role="tablist">
                <li class="nav-item">
                    <button class="nav-link active py-3 d-flex align-items-center justify-content-center gap-2" id="tab-btn-live" data-bs-toggle="tab" data-bs-target="#tab-live" type="button" role="tab">
                        <i class="icon-base ti tabler-device-tv text-info fs-5"></i>
                        <span class="fw-bold"><?= $language::get('live_categories'); ?></span>
                        <span class="badge bg-label-info rounded-pill ms-1" id="count-live"><?= count($rCategories['live']); ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link py-3 d-flex align-items-center justify-content-center gap-2" id="tab-btn-movie" data-bs-toggle="tab" data-bs-target="#tab-movie" type="button" role="tab">
                        <i class="icon-base ti tabler-movie text-success fs-5"></i>
                        <span class="fw-bold"><?= $language::get('movie_categories'); ?></span>
                        <span class="badge bg-label-success rounded-pill ms-1" id="count-movie"><?= count($rCategories['movie']); ?></span>
                    </button>
                </li>
                <li class="nav-item">
                    <button class="nav-link py-3 d-flex align-items-center justify-content-center gap-2" id="tab-btn-series" data-bs-toggle="tab" data-bs-target="#tab-series" type="button" role="tab">
                        <i class="icon-base ti tabler-clapperboard text-warning fs-5"></i>
                        <span class="fw-bold"><?= $language::get('series_categories'); ?></span>
                        <span class="badge bg-label-warning rounded-pill ms-1" id="count-series"><?= count($rCategories['series']); ?></span>
                    </button>
                </li>
            </ul>
        </div>

        <div class="card-body p-4">
            <div class="tab-content p-0" id="editorTabContent">
                <?php
                $tabs = [
                    'live'   => ['label' => $language::get('live_categories'), 'color' => 'info', 'icon' => 'tabler-device-tv'],
                    'movie'  => ['label' => $language::get('movie_categories'), 'color' => 'success', 'icon' => 'tabler-movie'],
                    'series' => ['label' => $language::get('series_categories'), 'color' => 'warning', 'icon' => 'tabler-clapperboard']
                ];
                ?>

                <?php foreach ($tabs as $secKey => $secConfig): ?>
                    <?php
                    $items = $rCategories[$secKey] ?? [];
                    $isActive = ($secKey === 'live');
                    ?>
                    <div class="tab-pane fade <?= $isActive ? 'show active' : ''; ?>" id="tab-<?= $secKey; ?>" role="tabpanel">
                        <!-- Quick Actions Bar for Tab -->
                        <div class="bg-body-tertiary rounded p-3 mb-3 border">
                            <div class="row g-2 align-items-center justify-content-between">
                                <div class="col-12 col-md-5">
                                    <div class="input-group input-group-merge input-group-sm">
                                        <span class="input-group-text"><i class="icon-base ti tabler-search"></i></span>
                                        <input type="text" class="form-control js-tab-search" data-target="list-<?= $secKey; ?>" placeholder="<?= $language::get('quick_search_categories'); ?>">
                                    </div>
                                </div>
                                <div class="col-12 col-md-7 d-flex flex-wrap justify-content-md-end gap-2">
                                    <button type="button" class="btn btn-sm btn-label-secondary js-btn-show-all" data-target="list-<?= $secKey; ?>" title="<?= $language::get('show_category'); ?>">
                                        <i class="icon-base ti tabler-eye me-1 text-success"></i> <?= $language::get('show'); ?>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-label-secondary js-btn-hide-all" data-target="list-<?= $secKey; ?>" title="<?= $language::get('hide_category'); ?>">
                                        <i class="icon-base ti tabler-eye-off me-1 text-danger"></i> <?= $language::get('hidden'); ?>
                                    </button>
                                    <button type="button" class="btn btn-sm btn-label-secondary js-btn-reset-names" data-target="list-<?= $secKey; ?>" title="<?= $language::get('reset_order'); ?>">
                                        <i class="icon-base ti tabler-rotate-clockwise me-1 text-info"></i> <?= $language::get('reset_order'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Sortable Category Items List -->
                        <div class="category-sortable-list" id="list-<?= $secKey; ?>" data-section="<?= $secKey; ?>">
                            <?php if (empty($items)): ?>
                                <div class="alert alert-secondary text-center py-4 mb-0">
                                    <i class="icon-base ti tabler-alert-circle fs-4 mb-2 d-block"></i>
                                    <?= $language::get('no_results'); ?>
                                </div>
                            <?php else: ?>
                                <?php foreach ($items as $index => $item): ?>
                                    <?php
                                    $catId = (int)$item['category_id'];
                                    $isVisible = (int)$item['is_visible'] === 1;
                                    $sortOrder = (int)$item['sort_order'];
                                    $origName = $item['original_name'] ?? '';
                                    $customName = $item['custom_name'] ?? '';
                                    $isAdult = (int)($item['is_adult'] ?? 0) === 1;
                                    ?>
                                    <div class="category-item-row p-2 mb-2 rounded border bg-card d-flex align-items-center gap-2 <?= $isVisible ? '' : 'is-hidden-category opacity-60'; ?>"
                                         data-id="<?= $catId; ?>"
                                         data-type="<?= $secKey; ?>"
                                         data-visible="<?= $isVisible ? '1' : '0'; ?>"
                                         data-orig-name="<?= strtolower(htmlspecialchars((string)$origName, ENT_QUOTES)); ?>"
                                         draggable="true">

                                        <!-- Drag Grip Handle -->
                                        <div class="drag-handle text-muted px-1" title="<?= $language::get('drag_to_reorder'); ?>" style="cursor: grab;">
                                            <i class="icon-base ti tabler-grip-vertical fs-5"></i>
                                        </div>

                                        <!-- Order Sequence Number Badge -->
                                        <div class="order-badge-wrapper flex-shrink-0" style="min-width: 42px;">
                                            <span class="badge bg-label-primary rounded px-2 py-1 js-order-badge fs-7">#<?= $sortOrder; ?></span>
                                        </div>

                                        <!-- Original Name Display -->
                                        <div class="category-orig-name flex-grow-1 d-flex align-items-center gap-1" style="min-width: 200px;">
                                            <span class="fw-semibold text-truncate js-orig-name-text" title="<?= htmlspecialchars((string)$origName, ENT_QUOTES); ?>">
                                                <?= htmlspecialchars((string)$origName, ENT_QUOTES); ?>
                                            </span>
                                            <?php if ($isAdult): ?>
                                                <span class="badge bg-label-danger ms-1" style="font-size: 0.65rem;">18+</span>
                                            <?php endif; ?>
                                        </div>

                                        <!-- Custom Alias Name Input -->
                                        <div class="category-custom-input flex-grow-1" style="min-width: 240px;">
                                            <input type="text"
                                                   class="form-control form-control-sm cat-custom-name-input"
                                                   placeholder="<?= $language::get('custom_category_name'); ?>..."
                                                   value="<?= htmlspecialchars((string)$customName, ENT_QUOTES); ?>"
                                                   title="<?= $language::get('custom_category_name'); ?>">
                                        </div>

                                        <!-- Quick Move Buttons (Up / Down) -->
                                        <div class="btn-group btn-group-sm flex-shrink-0">
                                            <button type="button" class="btn btn-icon btn-label-secondary js-btn-move-top" title="<?= $language::get('move_up'); ?>">
                                                <i class="icon-base ti tabler-arrow-bar-to-up fs-7"></i>
                                            </button>
                                            <button type="button" class="btn btn-icon btn-label-secondary js-btn-move-up" title="<?= $language::get('move_up'); ?>">
                                                <i class="icon-base ti tabler-chevron-up fs-7"></i>
                                            </button>
                                            <button type="button" class="btn btn-icon btn-label-secondary js-btn-move-down" title="<?= $language::get('move_down'); ?>">
                                                <i class="icon-base ti tabler-chevron-down fs-7"></i>
                                            </button>
                                            <button type="button" class="btn btn-icon btn-label-secondary js-btn-move-bottom" title="<?= $language::get('move_down'); ?>">
                                                <i class="icon-base ti tabler-arrow-bar-to-down fs-7"></i>
                                            </button>
                                        </div>

                                        <!-- Visibility Toggle Button -->
                                        <div class="flex-shrink-0">
                                            <button type="button"
                                                    class="btn btn-sm <?= $isVisible ? 'btn-label-success' : 'btn-label-danger'; ?> js-btn-toggle-vis d-flex align-items-center gap-1"
                                                    title="<?= $isVisible ? $language::get('hide_category') : $language::get('show_category'); ?>"
                                                    style="min-width: 82px;">
                                                <i class="icon-base ti <?= $isVisible ? 'tabler-eye' : 'tabler-eye-off'; ?> fs-7"></i>
                                                <span class="js-vis-text"><?= $isVisible ? $language::get('visible') : $language::get('hidden'); ?></span>
                                            </button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
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

    var templateId = <?= $tmplId; ?>;

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

    // Recalculate # numbers in a container
    function renumberList(container) {
        var rows = container.querySelectorAll('.category-item-row');
        var count = 1;
        rows.forEach(function(row) {
            var badge = row.querySelector('.js-order-badge');
            if (badge) badge.textContent = '#' + count;
            row.setAttribute('data-order', count);
            count++;
        });
    }

    // Toggle row visibility state
    function toggleRowVisibility(row, forceState) {
        var isVis = (forceState !== undefined) ? forceState : (row.getAttribute('data-visible') === '1' ? false : true);
        var btn = row.querySelector('.js-btn-toggle-vis');
        var icon = btn.querySelector('i');
        var text = btn.querySelector('.js-vis-text');

        if (isVis) {
            row.setAttribute('data-visible', '1');
            row.classList.remove('is-hidden-category', 'opacity-60');
            btn.classList.remove('btn-label-danger');
            btn.classList.add('btn-label-success');
            icon.className = 'icon-base ti tabler-eye fs-7';
            text.textContent = 'Visible';
            btn.setAttribute('title', 'Hide Category');
        } else {
            row.setAttribute('data-visible', '0');
            row.classList.add('is-hidden-category', 'opacity-60');
            btn.classList.remove('btn-label-success');
            btn.classList.add('btn-label-danger');
            icon.className = 'icon-base ti tabler-eye-off fs-7';
            text.textContent = 'Hidden';
            btn.setAttribute('title', 'Show Category');
        }
    }

    // Setup interactive handlers for each list
    ['live', 'movie', 'series'].forEach(function(sec) {
        var list = document.getElementById('list-' + sec);
        if (!list) return;

        // Click delegation for buttons
        list.addEventListener('click', function(e) {
            var row = e.target.closest('.category-item-row');
            if (!row) return;

            // Visibility Toggle
            if (e.target.closest('.js-btn-toggle-vis')) {
                toggleRowVisibility(row);
                return;
            }

            // Move Up
            if (e.target.closest('.js-btn-move-up')) {
                var prev = row.previousElementSibling;
                while (prev && (prev.style.display === 'none' || !prev.classList.contains('category-item-row'))) {
                    prev = prev.previousElementSibling;
                }
                if (prev && prev.classList.contains('category-item-row')) {
                    list.insertBefore(row, prev);
                    renumberList(list);
                }
                return;
            }

            // Move Down
            if (e.target.closest('.js-btn-move-down')) {
                var next = row.nextElementSibling;
                while (next && (next.style.display === 'none' || !next.classList.contains('category-item-row'))) {
                    next = next.nextElementSibling;
                }
                if (next && next.classList.contains('category-item-row')) {
                    list.insertBefore(row, next.nextElementSibling);
                    renumberList(list);
                }
                return;
            }

            // Move to Top
            if (e.target.closest('.js-btn-move-top')) {
                var first = list.firstElementChild;
                while (first && (first.style.display === 'none' || !first.classList.contains('category-item-row'))) {
                    first = first.nextElementSibling;
                }
                if (first && first !== row) {
                    list.insertBefore(row, first);
                    renumberList(list);
                }
                return;
            }

            // Move to Bottom
            if (e.target.closest('.js-btn-move-bottom')) {
                list.appendChild(row);
                renumberList(list);
                return;
            }
        });

        // HTML5 Drag & Drop
        var draggedEl = null;

        list.addEventListener('dragstart', function(e) {
            var row = e.target.closest('.category-item-row');
            if (!row || e.target.closest('input, button, a')) {
                return;
            }
            draggedEl = row;
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', '');
            row.classList.add('dragging', 'border-primary');
        });

        list.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            if (!draggedEl) return;

            var targetRow = e.target.closest('.category-item-row');
            if (targetRow && targetRow !== draggedEl) {
                var rect = targetRow.getBoundingClientRect();
                var relY = e.clientY - rect.top;
                if (relY < rect.height / 2) {
                    list.insertBefore(draggedEl, targetRow);
                } else {
                    list.insertBefore(draggedEl, targetRow.nextSibling);
                }
            }
        });

        list.addEventListener('drop', function(e) {
            e.preventDefault();
            if (draggedEl) {
                draggedEl.classList.remove('dragging', 'border-primary');
                draggedEl = null;
                renumberList(list);
            }
        });

        list.addEventListener('dragend', function(e) {
            if (draggedEl) {
                draggedEl.classList.remove('dragging', 'border-primary');
                draggedEl = null;
                renumberList(list);
            }
        });
    });

    // Tab Search Filters
    document.querySelectorAll('.js-tab-search').forEach(function(input) {
        input.addEventListener('input', function() {
            var targetId = this.getAttribute('data-target');
            var container = document.getElementById(targetId);
            if (!container) return;

            var val = this.value.toLowerCase().trim();
            var rows = container.querySelectorAll('.category-item-row');
            rows.forEach(function(row) {
                var name = row.getAttribute('data-orig-name') || '';
                var customInput = row.querySelector('.cat-custom-name-input');
                var customVal = customInput ? customInput.value.toLowerCase().trim() : '';

                if (name.includes(val) || customVal.includes(val)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    });

    // Show All in Tab
    document.querySelectorAll('.js-btn-show-all').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var container = document.getElementById(targetId);
            if (!container) return;
            container.querySelectorAll('.category-item-row').forEach(function(row) {
                toggleRowVisibility(row, true);
            });
        });
    });

    // Hide All in Tab
    document.querySelectorAll('.js-btn-hide-all').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var container = document.getElementById(targetId);
            if (!container) return;
            container.querySelectorAll('.category-item-row').forEach(function(row) {
                toggleRowVisibility(row, false);
            });
        });
    });

    // Reset Custom Names in Tab
    document.querySelectorAll('.js-btn-reset-names').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var targetId = this.getAttribute('data-target');
            var container = document.getElementById(targetId);
            if (!container) return;
            container.querySelectorAll('.cat-custom-name-input').forEach(function(input) {
                input.value = '';
            });
        });
    });

    // Save Template
    var btnSave = document.getElementById('btnSaveTemplate');
    if (btnSave) {
        btnSave.addEventListener('click', function() {
            var nameInput = document.getElementById('templateNameInput');
            var name = nameInput ? nameInput.value.trim() : '';
            if (!name) {
                toast('error', 'Please enter a template name.');
                if (nameInput) nameInput.focus();
                return;
            }

            var isShared = document.getElementById('templateIsShared') && document.getElementById('templateIsShared').checked ? 1 : 0;
            var isSystem = document.getElementById('templateIsSystem') && document.getElementById('templateIsSystem').checked ? 1 : 0;

            // Collect all categories across the 3 tabs
            var allCategories = [];
            ['live', 'movie', 'series'].forEach(function(sec) {
                var container = document.getElementById('list-' + sec);
                if (!container) return;
                var rows = container.querySelectorAll('.category-item-row');
                var order = 1;
                rows.forEach(function(row) {
                    var cid = row.getAttribute('data-id');
                    var isVis = row.getAttribute('data-visible') === '1' ? 1 : 0;
                    var customInput = row.querySelector('.cat-custom-name-input');
                    var customName = customInput ? customInput.value.trim() : '';

                    allCategories.push({
                        category_id: parseInt(cid, 10),
                        category_type: sec,
                        sort_order: order++,
                        is_visible: isVis,
                        custom_name: customName
                    });
                });
            });

            var payload = {
                id: templateId,
                name: name,
                is_shared: isShared,
                is_system: isSystem,
                categories: allCategories
            };

            var spinner = btnSave.querySelector('.spinner-border');
            var icon = btnSave.querySelector('.icon-base');
            btnSave.disabled = true;
            if (spinner) spinner.classList.remove('d-none');
            if (icon) icon.classList.add('d-none');

            fetch('./api?action=category_template_save', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(payload)
            })
            .then(function(res) { return res.json(); })
            .then(function(data) {
                btnSave.disabled = false;
                if (spinner) spinner.classList.add('d-none');
                if (icon) icon.classList.remove('d-none');

                if (data.result) {
                    toast('success', data.message || 'Template saved successfully.');
                } else {
                    toast('error', data.message || 'Failed to save template.');
                }
            })
            .catch(function() {
                btnSave.disabled = false;
                if (spinner) spinner.classList.add('d-none');
                if (icon) icon.classList.remove('d-none');
                toast('error', 'Connection error while saving template.');
            });
        });
    }
})();
</script>
