<?php

/**
 * Stream categories ordering (Bootstrap 5). Four tabs (streams / movies / series /
 * radio); each lists $rMainCategories[$tabID] as a drag-to-reorder list. The order
 * is serialized to a hidden "categories" field ([{id}, …], the format CategoryService
 * expects) and POSTed to post.php?action=stream_categories. Reordering uses native
 * HTML5 drag-and-drop with real-time re-indexing, live search filtering, and modern UI cards.
 */

use XcVm\Core\Auth\Authorization;

$rCanEdit = Authorization::check('adv', 'edit_cat');
$rTabs = [
    1 => ['streams', 'tabler-player-play', 'primary'],
    2 => ['movies',  'tabler-movie',       'info'],
    3 => ['series',  'tabler-device-tv',   'warning'],
    4 => ['radio',   'tabler-radio',       'success'],
];
?>

<div class="card shadow-sm border-0">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-3 py-3 border-bottom">
        <div class="d-flex align-items-center gap-3">
            <div class="avatar avatar-md">
                <span class="avatar-initial rounded-circle bg-label-primary shadow-xs">
                    <i class="icon-base ti tabler-category fs-4"></i>
                </span>
            </div>
            <div>
                <h5 class="card-title mb-0"><?= $language::get('categories'); ?></h5>
                <p class="text-body-secondary small mb-0">Organize and sort category display hierarchy across subscriber playlists.</p>
            </div>
        </div>
        <?php if ($rCanEdit): ?>
            <div class="d-flex gap-2">
                <a href="stream_category" class="btn btn-sm btn-primary waves-effect d-flex align-items-center gap-1 shadow-xs">
                    <i class="icon-base ti tabler-plus fs-6"></i>
                    <span><?= $language::get('add_category'); ?></span>
                </a>
                <button type="button" class="btn btn-sm btn-label-primary waves-effect d-flex align-items-center gap-1" id="import-tmdb">
                    <i class="icon-base ti tabler-download fs-6"></i>
                    <span>Import TMDB Categories</span>
                </button>
            </div>
        <?php endif; ?>
    </div>

    <div class="card-body pt-4">
        <!-- Navigation Tabs with Dynamic Counters -->
        <ul class="nav nav-pills flex-wrap mb-4 stream-cat-nav-pills gap-2" role="tablist">
            <?php foreach ($rTabs as $tabID => $rTab): 
                $tabCount = count($rMainCategories[$tabID] ?? []);
            ?>
                <li class="nav-item">
                    <button type="button" class="nav-link d-flex align-items-center gap-2 <?= $tabID === 1 ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#category-order-<?= $tabID; ?>" role="tab">
                        <i class="icon-base ti <?= $rTab[1]; ?> fs-5"></i>
                        <span class="fw-medium"><?= $language::get($rTab[0]); ?></span>
                        <span class="badge rounded-pill bg-label-<?= $rTab[2]; ?> stream-cat-count-pill" id="tab-pill-<?= $tabID; ?>"><?= $tabCount; ?></span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="tab-content p-0">
            <?php foreach ($rTabs as $tabID => $rTab): 
                $items = $rMainCategories[$tabID] ?? [];
                $totalCount = count($items);
            ?>
                <div class="tab-pane fade <?= $tabID === 1 ? 'show active' : ''; ?>" id="category-order-<?= $tabID; ?>" role="tabpanel">
                    <form method="POST" id="stream_categories_form-<?= $tabID; ?>" class="stream-cat-form">
                        <input type="hidden" id="categories_input-<?= $tabID; ?>" name="categories" value="">

                        <!-- Interactive Control Bar -->
                        <div class="stream-cat-control-bar card shadow-none border mb-3">
                            <div class="card-body p-3 d-flex flex-wrap align-items-center justify-content-between gap-3">
                                <div class="d-flex align-items-center gap-2 flex-grow-1" style="max-width: 480px;">
                                    <div class="input-group input-group-merge shadow-xs">
                                        <span class="input-group-text border-end-0 bg-transparent"><i class="icon-base ti tabler-search text-body-secondary"></i></span>
                                        <input type="text" class="form-control form-control-sm border-start-0 js-cat-search" data-tab="<?= $tabID; ?>" placeholder="Search by category name or #ID..." autocomplete="off">
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-clear-search d-none" data-tab="<?= $tabID; ?>" title="Clear filter"><i class="icon-base ti tabler-x"></i></button>
                                    </div>
                                </div>

                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <span class="badge bg-label-secondary d-flex align-items-center gap-1 py-2 px-3">
                                        <i class="icon-base ti tabler-list-numbers"></i>
                                        <span id="cat-counter-<?= $tabID; ?>"><?= $totalCount; ?></span>
                                        <span>categories</span>
                                    </span>
                                    <span class="badge bg-label-warning d-none js-unsaved-badge" id="unsaved-badge-<?= $tabID; ?>" style="padding: 0.55rem 0.85rem;">
                                        <i class="icon-base ti tabler-alert-circle me-1"></i>Unsaved Reorder
                                    </span>
                                    <?php if ($rCanEdit): ?>
                                        <button type="submit" class="btn btn-sm btn-primary d-flex align-items-center gap-1 js-save-btn shadow-xs" id="save-btn-top-<?= $tabID; ?>">
                                            <i class="icon-base ti tabler-device-floppy"></i>
                                            <span>Save Changes</span>
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <!-- Sleek Instructions Banner -->
                        <div class="stream-cat-hint-box alert alert-primary d-flex align-items-center justify-content-between p-3 mb-4 rounded-3 border-0">
                            <div class="d-flex align-items-center gap-3">
                                <div class="avatar avatar-sm flex-shrink-0">
                                    <span class="avatar-initial rounded-circle bg-primary text-white shadow-xs">
                                        <i class="icon-base ti tabler-arrows-sort fs-6"></i>
                                    </span>
                                </div>
                                <div class="small">
                                    <span class="fw-semibold d-block text-primary">Custom Playlist Sorting</span>
                                    <span class="text-body-secondary">Grab any row by the grip handle to re-order the broadcast hierarchy, then click <b>Save Changes</b> to apply.</span>
                                </div>
                            </div>
                            <div class="d-none d-md-flex align-items-center gap-2 text-body-secondary small">
                                <span class="badge bg-white text-dark shadow-xs border px-2 py-1"><kbd style="background:transparent;color:inherit;font-size:11px;padding:0">Drag & Drop</kbd></span>
                            </div>
                        </div>

                        <!-- Sortable Card List -->
                        <div class="stream-cat-list-wrapper position-relative">
                            <ol class="list-group xc-sortable stream-cat-sortable mb-4" id="category_order-<?= $tabID; ?>">
                                <?php 
                                $catIdx = 1;
                                foreach ($items as $rCategory): 
                                    $catId = (int) $rCategory['id'];
                                    $catName = (string) $rCategory['category_name'];
                                    $isAdult = (int) $rCategory['is_adult'];
                                ?>
                                    <li class="list-group-item stream-cat-item d-flex align-items-center justify-content-between gap-3 category-<?= $catId; ?>" 
                                        data-id="<?= $catId; ?>" 
                                        data-name="<?= htmlspecialchars(mb_strtolower($catName), ENT_QUOTES); ?>"
                                        data-order="<?= $catIdx; ?>"
                                        draggable="true">
                                        
                                        <!-- Left Side: Grip, Position Badge, Category Icon, Title & Tags -->
                                        <div class="d-flex align-items-center gap-3 flex-grow-1 min-w-0">
                                            <!-- Drag Handle -->
                                            <div class="stream-cat-grip" title="Drag to reorder">
                                                <i class="icon-base ti tabler-grip-vertical"></i>
                                            </div>

                                            <!-- Order Badge -->
                                            <span class="stream-cat-order-pill font-monospace" data-role="pos-badge">#<?= $catIdx; ?></span>

                                            <!-- Visual Avatar Icon -->
                                            <div class="stream-cat-avatar">
                                                <span class="avatar-initial rounded-circle bg-label-<?= $rTab[2]; ?> shadow-xs">
                                                    <i class="icon-base ti <?= $rTab[1]; ?>"></i>
                                                </span>
                                            </div>

                                            <!-- Name & Badges -->
                                            <div class="stream-cat-meta text-truncate">
                                                <span class="stream-cat-title fw-semibold text-heading d-block text-truncate" title="<?= htmlspecialchars($catName, ENT_QUOTES); ?>">
                                                    <?= htmlspecialchars($catName, ENT_QUOTES); ?>
                                                </span>
                                                <div class="stream-cat-tags d-flex align-items-center gap-1 mt-1">
                                                    <span class="badge bg-label-secondary font-monospace" style="font-size: 10.5px; padding: 2px 7px;">ID: #<?= $catId; ?></span>
                                                    <?php if ($isAdult): ?>
                                                        <span class="badge bg-label-danger d-flex align-items-center gap-1 shadow-xs" style="font-size: 10.5px; padding: 2px 7px;">
                                                            <i class="icon-base ti tabler-shield-lock" style="font-size: 11px;"></i>18+
                                                        </span>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- Right Side: Edit & Delete Actions -->
                                        <?php if ($rCanEdit): ?>
                                            <div class="d-flex align-items-center gap-2 flex-shrink-0">
                                                <a href="stream_category?id=<?= $catId; ?>" class="btn btn-icon btn-sm btn-label-secondary waves-effect stream-action-btn" title="Edit Category">
                                                    <i class="icon-base ti tabler-pencil"></i>
                                                </a>
                                                <button type="button" class="btn btn-icon btn-sm btn-label-danger js-del waves-effect stream-action-btn" data-id="<?= $catId; ?>" data-tab="<?= $tabID; ?>" title="Delete Category">
                                                    <i class="icon-base ti tabler-trash"></i>
                                                </button>
                                            </div>
                                        <?php endif; ?>
                                    </li>
                                <?php 
                                    $catIdx++;
                                endforeach; 
                                ?>
                            </ol>

                            <!-- Empty Filter State -->
                            <div class="stream-cat-empty-search text-center py-5 d-none" id="empty-search-<?= $tabID; ?>">
                                <div class="avatar avatar-xl mx-auto mb-3">
                                    <span class="avatar-initial rounded-circle bg-label-secondary"><i class="icon-base ti tabler-search-off fs-1"></i></span>
                                </div>
                                <h6 class="mb-1 text-heading">No categories found</h6>
                                <p class="text-body-secondary small mb-3">No categories match your search filter.</p>
                                <button type="button" class="btn btn-sm btn-outline-primary js-reset-search" data-tab="<?= $tabID; ?>">
                                    <i class="icon-base ti tabler-refresh me-1"></i>Reset Filter
                                </button>
                            </div>
                        </div>

                        <!-- Bottom Save Bar -->
                        <?php if ($rCanEdit): ?>
                            <div class="stream-cat-bottom-bar d-flex flex-wrap align-items-center justify-content-between p-3 rounded-3 mt-3 gap-2">
                                <span class="text-body-secondary small d-flex align-items-center gap-1">
                                    <i class="icon-base ti tabler-info-circle text-primary"></i>
                                    <span>Changes will be instantly propagated to active streams and subscriber feeds upon saving.</span>
                                </span>
                                <button type="submit" class="btn btn-primary d-flex align-items-center gap-2 px-4 shadow-sm js-save-btn" id="save-btn-bottom-<?= $tabID; ?>">
                                    <i class="icon-base ti tabler-device-floppy"></i>
                                    <span>Save Changes</span>
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var toast = window.xcToast || function(msg, type) { alert(msg); };

        function confirmSwal(text) {
            if (window.xcConfirm) {
                return window.xcConfirm(text);
            }
            return Promise.resolve(window.confirm(text));
        }

        // Re-index position badges and update modified state
        function updateOrderBadges(list, tabId) {
            var items = list.querySelectorAll('.stream-cat-item');
            var idx = 1;
            items.forEach(function(item) {
                var badge = item.querySelector('[data-role="pos-badge"]');
                if (badge) {
                    badge.textContent = '#' + idx;
                }
                item.setAttribute('data-order', idx);
                idx++;
            });

            // Mark unsaved state
            var unsavedBadge = document.getElementById('unsaved-badge-' + tabId);
            if (unsavedBadge) {
                unsavedBadge.classList.remove('d-none');
                unsavedBadge.classList.add('d-flex');
            }
            var topBtn = document.getElementById('save-btn-top-' + tabId);
            var btmBtn = document.getElementById('save-btn-bottom-' + tabId);
            if (topBtn) topBtn.classList.add('btn-pulse');
            if (btmBtn) btmBtn.classList.add('btn-pulse');
        }

        // Native HTML5 drag-and-drop with clean visual targets
        function initSortable(list, tabId) {
            var dragEl = null;

            list.addEventListener('dragstart', function(e) {
                if (e.target.closest('button, a, input')) {
                    e.preventDefault();
                    return;
                }
                var li = e.target.closest('.stream-cat-item');
                if (!li) {
                    return;
                }
                dragEl = li;
                li.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', li.getAttribute('data-id'));
            });

            list.addEventListener('dragend', function() {
                if (dragEl) {
                    dragEl.classList.remove('is-dragging');
                }
                list.querySelectorAll('.stream-cat-item').forEach(function(el) {
                    el.classList.remove('drop-target-above', 'drop-target-below');
                });
                dragEl = null;
                updateOrderBadges(list, tabId);
            });

            list.addEventListener('dragover', function(e) {
                e.preventDefault();
                if (!dragEl) return;

                var targetLi = e.target.closest('.stream-cat-item');
                if (!targetLi || targetLi === dragEl) return;

                var box = targetLi.getBoundingClientRect();
                var offset = e.clientY - box.top;
                var isAbove = offset < box.height / 2;

                list.querySelectorAll('.stream-cat-item').forEach(function(el) {
                    el.classList.remove('drop-target-above', 'drop-target-below');
                });

                if (isAbove) {
                    targetLi.classList.add('drop-target-above');
                    list.insertBefore(dragEl, targetLi);
                } else {
                    targetLi.classList.add('drop-target-below');
                    list.insertBefore(dragEl, targetLi.nextSibling);
                }
            });
        }

        // Real-time Search and Filter per Tab
        function initSearch(tabId) {
            var input = document.querySelector('.js-cat-search[data-tab="' + tabId + '"]');
            var clearBtn = document.querySelector('.js-clear-search[data-tab="' + tabId + '"]');
            var list = document.getElementById('category_order-' + tabId);
            var emptyState = document.getElementById('empty-search-' + tabId);
            var counterEl = document.getElementById('cat-counter-' + tabId);
            if (!input || !list) return;

            var allItems = Array.from(list.querySelectorAll('.stream-cat-item'));
            var totalCount = allItems.length;

            function doFilter() {
                var query = input.value.trim().toLowerCase();
                var visibleCount = 0;

                if (query.length > 0) {
                    if (clearBtn) clearBtn.classList.remove('d-none');
                } else {
                    if (clearBtn) clearBtn.classList.add('d-none');
                }

                allItems.forEach(function(item) {
                    var name = item.getAttribute('data-name') || '';
                    var id = item.getAttribute('data-id') || '';
                    var matches = !query || name.includes(query) || id === query || ('#' + id) === query;

                    if (matches) {
                        item.classList.remove('d-none');
                        visibleCount++;
                    } else {
                        item.classList.add('d-none');
                    }
                });

                if (counterEl) {
                    counterEl.textContent = query ? (visibleCount + ' of ' + totalCount) : totalCount;
                }

                if (emptyState) {
                    if (visibleCount === 0 && totalCount > 0) {
                        emptyState.classList.remove('d-none');
                    } else {
                        emptyState.classList.add('d-none');
                    }
                }
            }

            input.addEventListener('input', doFilter);

            if (clearBtn) {
                clearBtn.addEventListener('click', function() {
                    input.value = '';
                    doFilter();
                    input.focus();
                });
            }

            var resetBtn = document.querySelector('.js-reset-search[data-tab="' + tabId + '"]');
            if (resetBtn) {
                resetBtn.addEventListener('click', function() {
                    input.value = '';
                    doFilter();
                    input.focus();
                });
            }
        }

        // Initialize tabs
        [1, 2, 3, 4].forEach(function(tab) {
            var list = document.getElementById('category_order-' + tab);
            if (list) {
                initSortable(list, tab);
                initSearch(tab);
            }

            var form = document.getElementById('stream_categories_form-' + tab);
            if (!form) return;

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var order = [].map.call(list.querySelectorAll('.stream-cat-item'), function(li) {
                    return {
                        id: parseInt(li.getAttribute('data-id'), 10)
                    };
                });
                document.getElementById('categories_input-' + tab).value = JSON.stringify(order);

                var saveBtns = form.querySelectorAll('.js-save-btn');
                saveBtns.forEach(function(b) { b.disabled = true; });

                fetch('post.php?action=stream_categories', {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d;
                    try {
                        d = JSON.parse(txt);
                    } catch (err) {
                        d = { result: false };
                    }
                    saveBtns.forEach(function(b) { b.disabled = false; b.classList.remove('btn-pulse'); });
                    if (d && d.result !== false) {
                        var unsaved = document.getElementById('unsaved-badge-' + tab);
                        if (unsaved) {
                            unsaved.classList.add('d-none');
                            unsaved.classList.remove('d-flex');
                        }
                        toast('Categories re-ordered successfully.');
                    } else {
                        toast(errText, 'error');
                    }
                })
                .catch(function() {
                    saveBtns.forEach(function(b) { b.disabled = false; });
                    toast(errText, 'error');
                });
            });
        });

        // Delete a category
        document.querySelectorAll('.js-del').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                var tabId = this.getAttribute('data-tab');

                confirmSwal('Delete this category? All attached streams will become uncategorised.').then(function(ok) {
                    if (!ok) return;

                    fetch('./api?action=category&sub=delete&category_id=' + encodeURIComponent(id), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (!d || d.result !== true) {
                            throw new Error('fail');
                        }
                        var row = document.querySelector('.category-' + id);
                        if (row) {
                            row.remove();
                        }
                        // Update counters
                        if (tabId) {
                            var list = document.getElementById('category_order-' + tabId);
                            if (list) {
                                var remaining = list.querySelectorAll('.stream-cat-item').length;
                                var pill = document.getElementById('tab-pill-' + tabId);
                                var counter = document.getElementById('cat-counter-' + tabId);
                                if (pill) pill.textContent = remaining;
                                if (counter) counter.textContent = remaining;
                                updateOrderBadges(list, tabId);
                            }
                        }
                        toast('Category deleted.');
                    })
                    .catch(function() {
                        toast(errText, 'error');
                    });
                });
            });
        });

        // Import TMDb genre categories
        var imp = document.getElementById('import-tmdb');
        if (imp) {
            imp.addEventListener('click', function() {
                confirmSwal('Import TMDB genre categories?').then(function(ok) {
                    if (!ok) return;
                    imp.disabled = true;
                    fetch('post.php?action=import_tmdb_categories', {
                        method: 'POST',
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function() {
                        toast('Categories will be added automatically.');
                        setTimeout(function() {
                            location.reload();
                        }, 900);
                    })
                    .catch(function() {
                        imp.disabled = false;
                        toast(errText, 'error');
                    });
                });
            });
        }
    })();
</script>
</body>

</html>