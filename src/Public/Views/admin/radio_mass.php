<?php

/**
 * Mass edit radio stations (Bootstrap 5). Three tabs: a serverSide selection
 * table (radio_list, click a row to select), a details tab whose fields are each
 * gated by an "activate" checkbox, and a servers tab with a jstree load-balancer
 * tree. On submit the selected ids (streams JSON) plus the activated fields POST
 * to post.php?action=radio_mass. Reached full-page in the new-UI shell.
 *
 * Field set mirrors RadioService::massEdit (category_id/bouquets/direct_source/
 * server tree) — the previous legacy form's inputs (allow_comments, listeners,
 * load_balancer, …) never matched the handler and are dropped.
 */

use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('mass_edit_stations'); ?> <small class="text-body-secondary" id="selected_count"></small></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="mass-form">
            <input type="hidden" name="streams" id="streams" value="">
            <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
            <input type="hidden" name="od_tree_data" id="od_tree_data" value="">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#radio-selection" role="tab"><i class="icon-base ti tabler-broadcast me-1"></i><?= $language::get('stations'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#radio-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#radio-servers" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
            </ul>
            <div class="tab-content p-4 border rounded">
                <!-- Selection -->
                <div class="tab-pane fade show active" id="radio-selection" role="tabpanel">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-3 col-6"><input type="text" class="form-control" id="stream_search" placeholder="<?= $language::get('search_stations'); ?>"></div>
                        <div class="col-md-3 col-6">
                            <select id="station_server_id" class="form-select">
                                <option value="" selected><?= $language::get('all_servers'); ?></option>
                                <option value="-1"><?= $language::get('no_servers'); ?></option>
                                <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?><option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="category_search" class="form-select">
                                <option value="" selected><?= $language::get('all_categories'); ?></option>
                                <option value="-1"><?= $language::get('no_categories'); ?></option>
                                <?php foreach ($rCategories as $rCategory): ?><option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="station_filter" class="form-select">
                                <option value="" selected><?= $language::get('no_filter'); ?></option>
                                <option value="1"><?= $language::get('online'); ?></option>
                                <option value="2"><?= $language::get('down'); ?></option>
                                <option value="3"><?= $language::get('stopped'); ?></option>
                                <option value="4"><?= $language::get('starting'); ?></option>
                                <option value="5"><?= $language::get('on_demand'); ?></option>
                                <option value="6"><?= $language::get('direct'); ?></option>
                            </select>
                        </div>
                        <div class="col-md-1 col-8">
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
                                    <th class="text-center"><?= $language::get('icon'); ?></th>
                                    <th><?= $language::get('station_name'); ?></th>
                                    <th><?= $language::get('category'); ?></th>
                                    <th><?= $language::get('servers'); ?></th>
                                    <th class="text-center"><?= $language::get('status'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <!-- Details -->
                <div class="tab-pane fade" id="radio-details" role="tabpanel">
                    <p class="text-body-secondary"><?= $language::get('to_mass_edit_any_of_the_below'); ?></p>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="category_id" name="c_category_id"></div>
                        <label class="col-md-3 col-form-label" for="category_id"><?= $language::get('select_categories'); ?></label>
                        <div class="col-md-6">
                            <select disabled name="category_id[]" id="category_id" class="form-select" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder'); ?>">
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
                            <select disabled name="bouquets[]" id="bouquets" class="form-select" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder'); ?>">
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
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="direct_source" name="c_direct_source"></div>
                        <label class="col-md-3 col-form-label" for="direct_source"><?= $language::get('direct_source'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="direct_source" id="direct_source"></div>
                        </div>
                    </div>
                </div>
                <!-- Servers -->
                <div class="tab-pane fade" id="radio-servers" role="tabpanel">
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="server_tree" name="c_server_tree" id="c_server_tree"></div>
                        <label class="col-md-3 col-form-label" for="server_tree"><?= $language::get('server_tree'); ?></label>
                        <div class="col-md-8">
                            <div id="server_tree"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="server_type"><?= $language::get('server_type'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="server_type" id="server_type" class="form-select">
                                <?php foreach (['SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED'] as $rValue => $rLabel): ?><option value="<?= $rValue; ?>"><?= $rLabel; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="on_demand"><?= $language::get('on_demand_servers'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="on_demand[]" id="on_demand" class="form-select" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                <?php foreach ($rServers as $rServer): ?><option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="restart_on_edit"><?= $language::get('restart_on_edit'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input type="checkbox" value="1" class="form-check-input" name="restart_on_edit" id="restart_on_edit"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-end mt-3"><button type="submit" class="btn btn-primary" name="submit_stream" value="1"><?= $language::get('edit_stations') ?: 'Edit Stations'; ?></button></div>
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

        function getCategory() {
            return $('#category_search').val();
        }

        function getServer() {
            return $('#station_server_id').val();
        }

        function getFilter() {
            return $('#station_filter').val();
        }

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

        function evaluateServers() {
            if (!$.fn.jstree) {
                return;
            }
            var rOVal = $('#on_demand').val();
            $('#on_demand').empty();
            $($('#server_tree').jstree(true).get_json('source', {
                flat: true
            })).each(function(index, value) {
                if (value.parent !== '#') {
                    $('#on_demand').append(new Option(value.text, value.id));
                }
            });
            $('#on_demand').val(rOVal).trigger('change');
        }

        if ($.fn.select2) {
            $('#mass-form select').select2({
                width: '100%'
            });
        }

        // Activate checkboxes enable/disable their field (+ companion selects).
        $('.activate').on('change', function() {
            var name = $(this).data('name');
            var on = $(this).is(':checked');
            $('#' + name).prop('disabled', !on);
            if (name === 'category_id') {
                $('#category_id_type').prop('disabled', !on);
            }
            if (name === 'bouquets') {
                $('#bouquets_type').prop('disabled', !on);
            }
            if (name === 'server_tree') {
                $('#server_type').prop('disabled', !on);
                $('#on_demand').prop('disabled', !on);
            }
        });

        // Load-balancer server tree (jstree).
        if ($.fn.jstree) {
            $('#server_tree').on('redraw.jstree', function() {
                evaluateServers();
            }).on('select_node.jstree', function(e, data) {
                $('#c_server_tree').prop('checked', true).trigger('change');
                if (data.node.parent === 'offline') {
                    $('#server_tree').jstree('move_node', data.node.id, '#source', 'last');
                } else {
                    $('#server_tree').jstree('move_node', data.node.id, '#offline', 'first');
                }
            }).jstree({
                core: {
                    check_callback: function(op, node, parent) {
                        if (op === 'move_node') {
                            if (node.id === 'offline' || node.id === 'source') {
                                return false;
                            }
                            if (parent.id === '#') {
                                return false;
                            }
                            return true;
                        }
                    },
                    data: <?= json_encode($rServerTree ?: []); ?>
                },
                plugins: ['dnd']
            });
        }

        var rTable = $('#datatable-mass').DataTable({
            processing: true,
            serverSide: true,
            searchDelay: 250,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'radio_list';
                    d.category = getCategory();
                    d.filter = getFilter();
                    d.server = getServer();
                }
            },
            columnDefs: [{
                    className: 'text-center',
                    targets: [0, 1, 5]
                },
                {
                    orderable: false,
                    targets: [1]
                }
            ],
            rowCallback: function(row, data) {
                if (selected.indexOf(String(data[0])) !== -1) {
                    $(row).addClass('table-active');
                }
            },
            drawCallback: function() {
                $('#datatable-mass a').removeAttr('href');
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
        $('#category_search, #station_server_id, #station_filter').on('change', function() {
            rTable.ajax.reload(null, false);
        });

        document.getElementById('mass-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!selected.length) {
                toast(<?= json_encode($language::get('select_at_least_one_station') ?: 'Select at least one station to edit.'); ?>, 'warning');
                return;
            }
            document.getElementById('streams').value = JSON.stringify(selected);
            if ($.fn.jstree) {
                document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', {
                    flat: true
                }));
            }
            var btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            var fd = new FormData(this);
            fd.append('submit_stream', '1');
            fetch('post.php?action=radio_mass', {
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