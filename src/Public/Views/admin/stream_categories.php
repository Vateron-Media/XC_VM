<?php

/**
 * Stream categories ordering (Bootstrap 5). Four tabs (streams / movies / series /
 * radio); each lists $rMainCategories[$tabID] as a drag-to-reorder list. The order
 * is serialized to a hidden "categories" field ([{id}, …], the format CategoryService
 * expects) and POSTed to post.php?action=stream_categories. Reordering uses native
 * HTML5 drag-and-drop (the legacy jQuery-Nestable is gone). Delete goes through
 * ./api?action=category&sub=delete; the TMDb genre import through
 * post.php?action=import_tmdb_categories. Reached full-page in the new-UI shell.
 */

use XcVm\Core\Auth\Authorization;

$rCanEdit = Authorization::check('adv', 'edit_cat');
$rTabs = [
    1 => ['streams', 'tabler-player-play'],
    2 => ['movies', 'tabler-movie'],
    3 => ['series', 'tabler-device-tv'],
    4 => ['radio', 'tabler-radio'],
];
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('categories'); ?></h5>
        <?php if ($rCanEdit): ?>
            <button type="button" class="btn btn-sm btn-label-primary" id="import-tmdb"><i class="icon-base ti tabler-download me-1"></i>Import TMDB Categories</button>
        <?php endif; ?>
    </div>
    <div class="card-body">
        <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
            <?php foreach ($rTabs as $tabID => $rTab): ?>
                <li class="nav-item">
                    <button type="button" class="nav-link <?= $tabID === 1 ? 'active' : ''; ?>" data-bs-toggle="tab" data-bs-target="#category-order-<?= $tabID; ?>" role="tab">
                        <i class="icon-base ti <?= $rTab[1]; ?> me-1"></i><?= $language::get($rTab[0]); ?>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
        <div class="tab-content p-0">
            <?php foreach ($rTabs as $tabID => $rTab): ?>
                <div class="tab-pane fade <?= $tabID === 1 ? 'show active' : ''; ?>" id="category-order-<?= $tabID; ?>" role="tabpanel">
                    <form method="POST" id="stream_categories_form-<?= $tabID; ?>">
                        <input type="hidden" id="categories_input-<?= $tabID; ?>" name="categories" value="">
                        <p class="text-body-secondary small">
                            <i class="icon-base ti tabler-grip-vertical"></i> Drag a category up or down to re-order it, then click <b>Save Changes</b>.
                        </p>
                        <ol class="list-group xc-sortable mb-4" id="category_order-<?= $tabID; ?>" style="list-style:none;padding-left:0">
                            <?php foreach ($rMainCategories[$tabID] as $rCategory): ?>
                                <li class="list-group-item d-flex align-items-center category-<?= (int) $rCategory['id']; ?>" data-id="<?= (int) $rCategory['id']; ?>" draggable="true">
                                    <i class="icon-base ti tabler-grip-vertical text-body-secondary me-3" style="cursor:grab"></i>
                                    <span class="flex-grow-1">
                                        <?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?>
                                        <?php if ($rCategory['is_adult']): ?>
                                            <span class="badge bg-label-danger ms-1">18+</span>
                                        <?php endif; ?>
                                    </span>
                                    <?php if ($rCanEdit): ?>
                                        <span class="btn-group btn-group-sm">
                                            <a href="stream_category?id=<?= (int) $rCategory['id']; ?>" class="btn btn-icon btn-label-secondary"><i class="icon-base ti tabler-pencil"></i></a>
                                            <button type="button" class="btn btn-icon btn-label-danger js-del" data-id="<?= (int) $rCategory['id']; ?>"><i class="icon-base ti tabler-trash"></i></button>
                                        </span>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                        <?php if ($rCanEdit): ?>
                            <div class="text-end">
                                <button type="submit" class="btn btn-primary">Save Changes</button>
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
        var toast = window.xcToast || function() {};

        function confirmSwal(text) {
            if (window.xcConfirm) {
                return window.xcConfirm(text);
            }
            return Promise.resolve(window.confirm(text));
        }

        // Native HTML5 drag-and-drop reorder for a flat list (replaces jQuery-Nestable).
        function initSortable(list) {
            var dragEl = null;
            list.addEventListener('dragstart', function(e) {
                if (e.target.closest('button, a')) {
                    e.preventDefault();
                    return;
                }
                var li = e.target.closest('li');
                if (!li) {
                    return;
                }
                dragEl = li;
                li.classList.add('opacity-50');
                e.dataTransfer.effectAllowed = 'move';
            });
            list.addEventListener('dragend', function() {
                if (dragEl) {
                    dragEl.classList.remove('opacity-50');
                }
                dragEl = null;
            });
            list.addEventListener('dragover', function(e) {
                e.preventDefault();
                if (!dragEl) {
                    return;
                }
                var after = null,
                    closest = -Infinity;
                var items = list.querySelectorAll('li:not(.opacity-50)');
                for (var i = 0; i < items.length; i++) {
                    var box = items[i].getBoundingClientRect();
                    var offset = e.clientY - box.top - box.height / 2;
                    if (offset < 0 && offset > closest) {
                        closest = offset;
                        after = items[i];
                    }
                }
                if (after == null) {
                    list.appendChild(dragEl);
                } else {
                    list.insertBefore(dragEl, after);
                }
            });
        }

        [1, 2, 3, 4].forEach(function(tab) {
            var list = document.getElementById('category_order-' + tab);
            if (list) {
                initSortable(list);
            }
            var form = document.getElementById('stream_categories_form-' + tab);
            if (!form) {
                return;
            }
            form.addEventListener('submit', function(e) {
                e.preventDefault();
                var order = [].map.call(list.querySelectorAll('li'), function(li) {
                    return {
                        id: parseInt(li.getAttribute('data-id'), 10)
                    };
                });
                document.getElementById('categories_input-' + tab).value = JSON.stringify(order);
                var btn = form.querySelector('button[type="submit"]');
                if (btn) {
                    btn.disabled = true;
                }
                fetch('post.php?action=stream_categories', {
                        method: 'POST',
                        body: new FormData(form),
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.text();
                    })
                    .then(function(txt) {
                        var d;
                        try {
                            d = JSON.parse(txt);
                        } catch (err) {
                            d = {
                                result: false
                            };
                        }
                        if (btn) {
                            btn.disabled = false;
                        }
                        if (d && d.result !== false) {
                            toast('Categories re-ordered.');
                        } else {
                            toast(errText, 'error');
                        }
                    })
                    .catch(function() {
                        if (btn) {
                            btn.disabled = false;
                        }
                        toast(errText, 'error');
                    });
            });
        });

        // Delete a category.
        document.querySelectorAll('.js-del').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var id = this.getAttribute('data-id');
                confirmSwal('Delete this category? All attached streams will be uncategorised.').then(function(ok) {
                    if (!ok) {
                        return;
                    }
                    fetch('./api?action=category&sub=delete&category_id=' + encodeURIComponent(id), {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(d) {
                            if (!d || d.result !== true) {
                                throw new Error('fail');
                            }
                            var row = document.querySelector('.category-' + id);
                            if (row) {
                                row.remove();
                            }
                            toast('Category deleted.');
                        })
                        .catch(function() {
                            toast(errText, 'error');
                        });
                });
            });
        });

        // Import TMDb genre categories.
        var imp = document.getElementById('import-tmdb');
        if (imp) {
            imp.addEventListener('click', function() {
                confirmSwal('Import TMDB genre categories?').then(function(ok) {
                    if (!ok) {
                        return;
                    }
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