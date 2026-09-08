<?php

/**
 * Mass edit series (Bootstrap 5). Two tabs: a serverSide selection table
 * (series_list, array rows — click a row to select), and a details tab whose
 * category/bouquet fields are each gated by an "activate" checkbox (only ticked
 * fields are applied). On submit the selected ids (series JSON) plus the
 * activated fields POST to post.php?action=series_mass. Reached full-page in the
 * new-UI shell.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('mass_edit_series'); ?> <small class="text-body-secondary" id="selected_count"></small></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="mass-form">
            <input type="hidden" name="series" id="series" value="">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#series-selection" role="tab"><i class="icon-base ti tabler-device-tv me-1"></i><?= $language::get('series'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#series-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
            </ul>
            <div class="tab-content p-4 border rounded">
                <!-- Selection -->
                <div class="tab-pane fade show active" id="series-selection" role="tabpanel">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-5 col-6"><input type="text" class="form-control" id="stream_search" placeholder="<?= $language::get('search_series_placeholder'); ?>"></div>
                        <div class="col-md-4 col-6">
                            <select id="category_search" class="form-select">
                                <option value="" selected><?= $language::get('all_categories'); ?></option>
                                <option value="-1"><?= $language::get('no_tmdb_match'); ?></option>
                                <option value="-2"><?= $language::get('no_categories'); ?></option>
                                <?php foreach ($rCategories as $rCategory): ?><option value="<?= (int) $rCategory['id']; ?>" <?= (RequestManager::has('category') && RequestManager::get('category') == $rCategory['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-8">
                            <select id="show_entries" class="form-select">
                                <?php foreach ([10, 25, 50, 250, 500, 1000] as $rShow): ?><option value="<?= $rShow; ?>" <?= $rSettings['default_entries'] == $rShow ? 'selected' : ''; ?>><?= $rShow; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-1 col-4"><button type="button" class="btn btn-info w-100" onclick="toggleStreams()" title="<?= $language::get('select'); ?>"><i class="icon-base ti tabler-select-all"></i></button></div>
                    </div>
                    <div class="table-responsive">
                        <table id="datatable-mass" class="table" style="width:100%">
                            <thead>
                                <tr>
                                    <th class="text-center"><?= $language::get('id'); ?></th>
                                    <th class="text-center"><?= $language::get('image'); ?></th>
                                    <th><?= $language::get('name'); ?></th>
                                    <th><?= $language::get('category'); ?></th>
                                    <th class="text-center"><?= $language::get('seasons'); ?></th>
                                    <th class="text-center"><?= $language::get('episodes'); ?></th>
                                    <th class="text-center"><?= $language::get('tmdb'); ?></th>
                                    <th class="text-center"><?= $language::get('first_aired'); ?></th>
                                    <th class="text-center"><?= $language::get('last_updated'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <!-- Details -->
                <div class="tab-pane fade" id="series-details" role="tabpanel">
                    <p class="text-body-secondary"><?= $language::get('to_mass_edit_any_of_the_below'); ?></p>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="category_id" name="c_category_id"></div>
                        <label class="col-md-3 col-form-label" for="category_id"><?= $language::get('select_categories'); ?></label>
                        <div class="col-md-6">
                            <select disabled name="category_id[]" id="category_id" class="form-select" multiple>
                                <?php foreach ($rCategories as $rCategory): ?><option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select disabled name="category_id_type" id="category_id_type" class="form-select">
                                <?php foreach (['SET', 'ADD', 'DEL'] as $rType): ?><option value="<?= $rType; ?>"><?= $rType; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="bouquets" name="c_bouquets"></div>
                        <label class="col-md-3 col-form-label" for="bouquets"><?= $language::get('select_bouquets'); ?></label>
                        <div class="col-md-6">
                            <select disabled name="bouquets[]" id="bouquets" class="form-select" multiple>
                                <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?><option value="<?= (int) $rBouquet['id']; ?>"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select disabled name="bouquets_type" id="bouquets_type" class="form-select">
                                <?php foreach (['SET', 'ADD', 'DEL'] as $rType): ?><option value="<?= $rType; ?>"><?= $rType; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="reprocess_tmdb"><?= $language::get('reprocess_tmdb_data'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="reprocess_tmdb" name="reprocess_tmdb" value="1"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-end mt-3"><button type="submit" class="btn btn-primary" name="submit_series" value="1"><?= $language::get('edit_series'); ?></button></div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        var toast = window.xcToast || function() {};
        var selected = [];

        function updateCount() {
            document.getElementById('selected_count').textContent = selected.length ? '— ' + selected.length + ' selected' : '';
        }
        window.getCategory = function() {
            return document.getElementById('category_search').value;
        };
        window.toggleStreams = function() {
            var allSelected = true;
            $('#datatable-mass tbody tr').each(function() {
                if (!$(this).hasClass('table-active')) {
                    allSelected = false;
                }
            });
            $('#datatable-mass tbody tr').each(function() {
                var id = $(this).find('td:eq(0)').text().trim();
                if (!id) {
                    return;
                }
                if (allSelected) {
                    $(this).removeClass('table-active');
                    var i = selected.indexOf(id);
                    if (i > -1) {
                        selected.splice(i, 1);
                    }
                } else if (!$(this).hasClass('table-active')) {
                    $(this).addClass('table-active');
                    if (selected.indexOf(id) === -1) {
                        selected.push(id);
                    }
                }
            });
            updateCount();
        };

        // select2 for the selection filters and the details multi-selects.
        if ($.fn.select2) {
            $('#category_search, #show_entries').select2({
                width: '100%'
            });
            $('#category_id, #bouquets').select2({
                width: '100%',
                dropdownParent: $('#series-details')
            });
            $('#category_id_type, #bouquets_type').select2({
                width: '100%',
                dropdownParent: $('#series-details')
            });
        }

        // Activate checkboxes enable/disable their field (and its companion type select).
        var companions = {
            category_id: 'category_id_type',
            bouquets: 'bouquets_type'
        };
        $('.activate').on('change', function() {
            var name = this.getAttribute('data-name');
            [name, companions[name]].forEach(function(id) {
                if (!id) {
                    return;
                }
                var el = document.getElementById(id);
                if (!el) {
                    return;
                }
                el.disabled = !this.checked;
                if ($(el).hasClass('select2-hidden-accessible')) {
                    $(el).prop('disabled', !this.checked).trigger('change.select2');
                }
            }, this);
        });

        // array-based handler (series_list) → columnDefs by index, no columns map.
        var rTable = $('#datatable-mass').DataTable({
            processing: true,
            serverSide: true,
            searchDelay: 250,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'series_list';
                    d.category = getCategory();
                }
            },
            columnDefs: [{
                    className: 'text-center',
                    targets: [0, 1, 4, 5, 6, 7, 8]
                },
                {
                    orderable: false,
                    targets: [1, 6]
                }
            ],
            rowCallback: function(row, data) {
                if (selected.indexOf(String(data[0])) !== -1) {
                    $(row).addClass('table-active');
                }
            },
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 10); ?>,
            order: [
                [0, 'desc']
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        // Row click toggles selection.
        $('#datatable-mass tbody').on('click', 'tr', function() {
            var id = $(this).find('td:eq(0)').text().trim();
            if (!id) {
                return;
            }
            if ($(this).hasClass('table-active')) {
                $(this).removeClass('table-active');
                var i = selected.indexOf(id);
                if (i > -1) {
                    selected.splice(i, 1);
                }
            } else {
                $(this).addClass('table-active');
                if (selected.indexOf(id) === -1) {
                    selected.push(id);
                }
            }
            updateCount();
        });

        $('#stream_search').on('keyup', function() {
            rTable.search(this.value).draw();
        });
        $('#show_entries').on('change', function() {
            rTable.page.len(parseInt(this.value, 10)).draw();
        });
        $('#category_search').on('change', function() {
            rTable.ajax.reload(null, false);
        });

        document.getElementById('mass-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!selected.length) {
                toast(<?= json_encode($language::get('select_at_least_one_series')); ?>, 'warning');
                return;
            }
            document.getElementById('series').value = JSON.stringify(selected);
            var btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            var fd = new FormData(this);
            fd.append('submit_series', '1');
            fetch('post.php?action=series_mass', {
                    method: 'POST',
                    body: fd,
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
                    if (d && d.result !== false) {
                        toast('Mass edit applied.', 'success');
                        setTimeout(function() {
                            location.reload();
                        }, 800);
                        return;
                    }
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                })
                .catch(function() {
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                });
        });
    })();
</script>
</body>

</html>