<?php

/**
 * Series (Bootstrap 5). Clean-JSON table pattern: TableController::handleSeries returns
 * structured rows (category resolved server-side) and this page renders the cells
 * client-side via datatables-bs5 columns[].render. Establishes the management-
 * table bulk-select pattern: a checkbox column + select-all + a bulk toolbar that
 * posts action=multi&type=series. Row actions (add/view episodes, edit modal,
 * delete) and the category filter are wired inline.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Stream\CategoryService;

if (!Authorization::check('adv', 'series') && !Authorization::check('adv', 'mass_sedits')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rCanEditSeries = Authorization::check('adv', 'edit_series');
$rCanEpisodes = Authorization::check('adv', 'episodes');
$rCanAddEpisode = Authorization::check('adv', 'add_episode');
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('series'); ?></h5>
        <div id="bulk-bar" class="d-none align-items-center gap-2">
            <span class="text-body-secondary"><span id="bulk-count">0</span></span>
            <?php if ($rCanEditSeries): ?>
                <button type="button" class="btn btn-sm btn-label-danger" id="bulk-delete"><i class="icon-base ti tabler-trash me-1"></i><?= $language::get('delete_selected'); ?></button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-category"><?= $language::get('category'); ?></label>
                <select id="filter-category" class="form-select">
                    <option value=""><?= $language::get('all_categories'); ?></option>
                    <option value="-1"><?= $language::get('no_tmdb'); ?></option>
                    <option value="-2"><?= $language::get('no_category'); ?></option>
                    <?php foreach (CategoryService::getAllByType('series') as $rCatId => $rCat): ?>
                        <option value="<?= (int) $rCatId; ?>"><?= htmlspecialchars((string) $rCat['category_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="series-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><input type="checkbox" class="form-check-input" id="check-all"></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('cover'); ?></th>
                    <th><?= $language::get('title'); ?></th>
                    <th><?= $language::get('category'); ?></th>
                    <th><?= $language::get('latest_season'); ?></th>
                    <th><?= $language::get('episode_count'); ?></th>
                    <th>TMDB</th>
                    <th><?= $language::get('release_date'); ?></th>
                    <th><?= $language::get('last_modified'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- Edit (loads the edit form in a modal) -->
<div class="modal fade" id="editModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('edit'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-0">
                <iframe id="edit-frame" src="about:blank" style="width:100%;height:70vh;border:0"></iframe>
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
        var esc = function(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : String(s));
            return d.innerHTML;
        };
        var fmtDate = function(ts) {
            return ts ? new Date(ts * 1000).toLocaleString() : '';
        };
        var canEditSeries = <?= $rCanEditSeries ? 'true' : 'false'; ?>;
        var canEpisodes = <?= $rCanEpisodes ? 'true' : 'false'; ?>;
        var canAddEpisode = <?= $rCanAddEpisode ? 'true' : 'false'; ?>;
        var lang = {
            add: <?= json_encode($language::get('add_episodes')); ?>,
            view: <?= json_encode($language::get('view_episodes')); ?>,
            edit: <?= json_encode($language::get('edit')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            selected: <?= json_encode($language::get('selected')); ?>,
            never: <?= json_encode($language::get('never') ?: 'Never'); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>,
            delConfirm: <?= json_encode($language::get('login_logs_block_confirm')); ?>
        };
        var selected = {};

        var stars = function(rating) {
            if (!rating) {
                return '';
            }
            var v = Math.round(rating) / 2,
                full = Math.floor(v),
                half = (v - full) > 0,
                empty = 5 - full - (half ? 1 : 0),
                h = '';
            for (var i = 0; i < full; i++) {
                h += '<i class="icon-base ti tabler-star-filled text-warning"></i>';
            }
            if (half) {
                h += '<i class="icon-base ti tabler-star-half-filled text-warning"></i>';
            }
            for (var j = 0; j < empty; j++) {
                h += '<i class="icon-base ti tabler-star text-body-secondary"></i>';
            }
            return h;
        };

        var updateBulk = function() {
            var n = Object.keys(selected).length;
            document.getElementById('bulk-count').textContent = n + ' ' + lang.selected;
            document.getElementById('bulk-bar').classList.toggle('d-none', n === 0);
            document.getElementById('bulk-bar').classList.toggle('d-flex', n > 0);
        };

        var table = jQuery('#series-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [2, 'desc']
            ],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'series';
                    d.category = document.getElementById('filter-category').value;
                }
            },
            columns: [{
                    data: null,
                    defaultContent: '',
                    orderable: false,
                    searchable: false,
                    className: 'control',
                    responsivePriority: 2
                },
                {
                    data: 'id',
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d) {
                        return '<input type="checkbox" class="form-check-input row-check" data-id="' + esc(d) + '"' + (selected[d] ? ' checked' : '') + '>';
                    }
                },
                {
                    data: 'id',
                    className: 'text-center',
                    render: function(d) {
                        return canEpisodes ? '<a href="serie?id=' + encodeURIComponent(d) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                    }
                },
                {
                    data: 'cover',
                    orderable: false,
                    render: function(d) {
                        return d ? '<a href="resize?maxw=512&maxh=512&url=' + encodeURIComponent(d) + '" target="_blank"><img loading="lazy" src="resize?maxh=58&maxw=32&url=' + encodeURIComponent(d) + '" alt=""></a>' : '';
                    }
                },
                {
                    data: 'title',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        var inner = '<span class="fw-medium">' + esc(d) + '</span>';
                        var sub = (row.year ? '<strong>' + esc(row.year) + '</strong> ' : '') + stars(row.rating);
                        var body = inner + (sub ? '<br><small>' + sub + '</small>' : '');
                        return canEpisodes ? '<a href="serie?id=' + encodeURIComponent(row.id) + '" class="text-body">' + body + '</a>' : body;
                    }
                },
                {
                    data: 'category'
                },
                {
                    data: 'latest_season',
                    className: 'text-center',
                    render: function(d) {
                        return '<span class="badge bg-label-' + (d > 0 ? 'info' : 'secondary') + '">' + (d || 0) + '</span>';
                    }
                },
                {
                    data: 'episode_count',
                    className: 'text-center',
                    render: function(d, t, row) {
                        var b = '<span class="badge bg-label-' + (d > 0 ? 'info' : 'secondary') + '">' + (d || 0) + '</span>';
                        return (d > 0 && canEpisodes) ? '<a href="episodes?series=' + encodeURIComponent(row.id) + '">' + b + '</a>' : b;
                    }
                },
                {
                    data: 'tmdb',
                    className: 'text-center',
                    render: function(d) {
                        return d ? '<i class="icon-base ti tabler-circle-check text-success"></i>' : '<i class="icon-base ti tabler-circle-minus text-body-secondary"></i>';
                    }
                },
                {
                    data: 'release_date',
                    className: 'text-nowrap',
                    render: function(d) {
                        return esc(d || '');
                    }
                },
                {
                    data: 'last_modified',
                    className: 'text-nowrap',
                    render: function(d) {
                        return d ? esc(fmtDate(d)) : lang.never;
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var items = '';
                        if (canAddEpisode) {
                            items += '<a class="dropdown-item" href="episode?sid=' + encodeURIComponent(row.id) + '">' + esc(lang.add) + '</a>';
                        }
                        if (canEpisodes) {
                            items += '<a class="dropdown-item" href="episodes?series=' + encodeURIComponent(row.id) + '">' + esc(lang.view) + '</a>';
                        }
                        if (canEditSeries) {
                            items += '<a class="dropdown-item js-edit" href="javascript:void(0);" data-id="' + esc(row.id) + '">' + esc(lang.edit) + '</a>';
                            items += '<a class="dropdown-item text-danger js-del" href="javascript:void(0);" data-id="' + esc(row.id) + '">' + esc(lang.del) + '</a>';
                        }
                        if (!items) {
                            return '';
                        }
                        return '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' + items + '</div></div>';
                    }
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        document.getElementById('filter-category').addEventListener('change', function() {
            table.ajax.reload();
        });

        // Bulk selection.
        jQuery('#series-table tbody').on('change', '.row-check', function() {
            var id = this.getAttribute('data-id');
            if (this.checked) {
                selected[id] = true;
            } else {
                delete selected[id];
            }
            updateBulk();
        });
        document.getElementById('check-all').addEventListener('change', function() {
            var on = this.checked;
            jQuery('#series-table tbody .row-check').each(function() {
                this.checked = on;
                var id = this.getAttribute('data-id');
                if (on) {
                    selected[id] = true;
                } else {
                    delete selected[id];
                }
            });
            updateBulk();
        });
        table.on('draw', function() {
            document.getElementById('check-all').checked = false;
        });
        var bulkDel = document.getElementById('bulk-delete');
        if (bulkDel) {
            bulkDel.addEventListener('click', function() {
                var ids = Object.keys(selected);
                if (!ids.length) {
                    return;
                }
                window.xcConfirm(lang.del + ' (' + ids.length + ')?').then(function(ok) {
                    if (!ok) {
                        return;
                    }
                    fetch('./api?action=multi&type=series&sub=delete&ids=' + encodeURIComponent(JSON.stringify(ids)), {
                            headers: {
                                'X-Requested-With': 'XMLHttpRequest'
                            }
                        })
                        .then(function(r) {
                            return r.json();
                        })
                        .then(function(dt) {
                            if (!dt || dt.result !== true) {
                                throw new Error('fail');
                            }
                            selected = {};
                            updateBulk();
                            table.ajax.reload(null, false);
                        })
                        .catch(function() {
                            alert(lang.error);
                        });
                });
            });
        }

        // Single delete.
        jQuery('#series-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            window.xcConfirm(lang.del + '?').then(function(ok) {
                if (!ok) {
                    return;
                }
                fetch('./api?action=series&sub=delete&series_id=' + encodeURIComponent(id), {
                        headers: {
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    })
                    .then(function(r) {
                        return r.json();
                    })
                    .then(function(dt) {
                        if (!dt || dt.result !== true) {
                            throw new Error('fail');
                        }
                        table.ajax.reload(null, false);
                    })
                    .catch(function() {
                        alert(lang.error);
                    });
            });
        });

        // Edit modal.
        var editModal = document.getElementById('editModal');
        jQuery('#series-table tbody').on('click', '.js-edit', function() {
            document.getElementById('edit-frame').src = 'serie?id=' + encodeURIComponent(this.getAttribute('data-id')) + '&modal=1';
            bootstrap.Modal.getOrCreateInstance(editModal).show();
        });
        editModal.addEventListener('hidden.bs.modal', function() {
            document.getElementById('edit-frame').src = 'about:blank';
            table.ajax.reload(null, false);
        });
    })();
</script>
</body>

</html>