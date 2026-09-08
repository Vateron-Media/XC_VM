<?php

/**
 * Mass edit & review of streams (Bootstrap 5). Two modes share one file and one form:
 *  - Selection (no $rImport): a serverSide stream picker (#datatable-mass, d.id=stream_list),
 *    three edit switches (categories/bouquets/epg) + filters. Clicking a row toggles it into
 *    window.rSelected; submit writes the ids to #streams (JSON) and POSTs to ./stream_review.
 *  - Review (isset($rImport)): renders one stream_import_logic.php partial per selected stream
 *    inside the form; submit saves the changes (the save_changes contract of StreamReviewController).
 * Reached full-page in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryService;

?>

<form action="./stream_review" method="POST" id="stream_form">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h4 class="mb-0"><?= $language::get('mass_edit_review') ?> <small id="selected_count"></small></h4>
    </div>

    <?php if (isset($_STATUS) && $_STATUS == STATUS_NO_SOURCES): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <?= $language::get('select_at_least_one_stream') ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card">
        <div class="card-body">
            <?php if (isset($rImport)): ?>
                <input type="hidden" name="save_changes" value="1">
                <input type="hidden" name="save_categories" value="<?= intval($rOptions['categories']) ?>">
                <input type="hidden" name="save_bouquets" value="<?= intval($rOptions['bouquets']) ?>">
                <input type="hidden" name="save_epg" value="<?= intval($rOptions['epg']) ?>">
                <?php
                foreach ($rImport as $rStream) {
                    include 'stream_import_logic.php';
                }
                ?>
                <div class="text-end mt-2">
                    <button type="submit" id="btn-submit" class="btn btn-primary"><?= $language::get('save_changes') ?></button>
                </div>
            <?php else: ?>
                <input type="hidden" name="streams" id="streams" value="">
                <div class="row g-3 mb-4">
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="edit_categories" id="edit_categories" value="1" checked>
                            <label class="form-check-label" for="edit_categories"><?= $language::get('edit_categories') ?></label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="edit_bouquets" id="edit_bouquets" value="1" checked>
                            <label class="form-check-label" for="edit_bouquets"><?= $language::get('edit_bouquets') ?></label>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" role="switch" name="edit_epg" id="edit_epg" value="1" checked>
                            <label class="form-check-label" for="edit_epg"><?= $language::get('edit_epg') ?></label>
                        </div>
                    </div>
                </div>

                <div class="row g-2 align-items-center mb-3">
                    <div class="col-md-3 col-6">
                        <input type="text" class="form-control" id="stream_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_streams_placeholder'), ENT_QUOTES) ?>">
                    </div>
                    <div class="col-md-3 col-6">
                        <select id="category_search" class="form-select">
                            <option value="" selected><?= $language::get('all_categories') ?></option>
                            <?php foreach (CategoryService::getAllByType('live') as $rCategory): ?>
                                <option value="<?= intval($rCategory['id']) ?>" <?= RequestManager::has('category') && RequestManager::get('category') == $rCategory['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 col-6">
                        <select id="stream_filter" class="form-select">
                            <option value=""><?= $language::get('no_filter') ?></option>
                            <option value="1"><?= $language::get('online') ?></option>
                            <option value="2"><?= $language::get('down') ?></option>
                            <option value="3"><?= $language::get('stopped') ?></option>
                            <option value="4"><?= $language::get('starting') ?></option>
                            <option value="5"><?= $language::get('on_demand') ?></option>
                            <option value="6"><?= $language::get('direct') ?></option>
                            <option value="7"><?= $language::get('timeshift') ?></option>
                            <option value="8"><?= $language::get('looping') ?></option>
                            <option value="9"><?= $language::get('has_epg') ?></option>
                            <option value="10"><?= $language::get('no_epg') ?></option>
                        </select>
                    </div>
                    <div class="col-md-2 col-8">
                        <select id="show_entries" class="form-select">
                            <?php foreach (array(10, 25, 50, 250, 500, 1000) as $rShow): ?>
                                <option value="<?= $rShow ?>" <?= $rSettings['default_entries'] == $rShow ? 'selected' : '' ?>><?= $rShow ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1 col-2">
                        <button type="button" class="btn btn-info w-100" onClick="toggleStreams()" title="<?= htmlspecialchars((string) $language::get('select'), ENT_QUOTES) ?>">
                            <i class="icon-base ti tabler-select-all"></i>
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table id="datatable-mass" class="table" style="width:100%">
                        <thead>
                            <tr>
                                <th class="text-center"><?= $language::get('id') ?></th>
                                <th><?= $language::get('stream_name') ?></th>
                                <th><?= $language::get('category') ?></th>
                                <th class="text-center"><?= $language::get('status') ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>

                <div class="text-end mt-3">
                    <button type="submit" id="btn-submit" class="btn btn-primary"><?= $language::get('review') ?></button>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

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
        window.rSelected = window.rSelected || [];
        window.rData = window.rData || [];
        if ($.fn.dataTable) {
            $.fn.dataTable.ext.errMode = 'none';
        }

        <?php if (isset($rImport)): ?>
            // ---- Review mode ----
            window.clearEPG = function(elem) {
                var id = $(elem).data('id');
                if ($('#epg_api_' + id).val()) {
                    $('#modified_' + id).val(1);
                    $('#epg_api_' + id).val('').trigger('change');
                }
            };

            function evaluateChanges() {
                $('.name_input').off('change.sr').on('change.sr', function() {
                    var id = $(this).data('id');
                    $('#modified_' + id).val(1);
                    $('#name_s_' + id).val($(this).val());
                });
                $('.bouquet').off('change.sr').on('change.sr', function() {
                    var id = $(this).data('id');
                    $('#modified_' + id).val(1);
                    $('#bouquets_s_' + id).val(JSON.stringify($('#bouquets_' + id).val()));
                });
                $('.category_id').off('change.sr').on('change.sr', function() {
                    var id = $(this).data('id');
                    $('#modified_' + id).val(1);
                    $('#categories_s_' + id).val(JSON.stringify($('#category_id_' + id).val()));
                });
                $('.epg_api').off('change.sr').on('change.sr', function() {
                    var id = $(this).data('id');
                    var d;
                    if (window.rData[id]) {
                        d = window.rData[id];
                        window.rData[id] = null;
                    } else {
                        d = $('#epg_api_' + id).select2('data')[0];
                    }
                    $('#modified_' + id).val(1);
                    if (d) {
                        $('#clear_epg_' + id).removeClass('btn-secondary').addClass('btn-warning');
                        $('#epg_type_s_' + id).val(d.type);
                        if (d.type == 1) {
                            $('#view_epg_' + id).removeClass('btn-secondary').addClass('btn-success');
                            $('#epg_id_s_' + id).val(0);
                            $('#channel_id_s_' + id).val(d.id);
                        } else {
                            $('#view_epg_' + id).removeClass('btn-success').addClass('btn-secondary');
                            $('#epg_id_s_' + id).val(d.epg_id);
                            $('#channel_id_s_' + id).val(d.id);
                        }
                    } else {
                        $('#clear_epg_' + id).removeClass('btn-warning').addClass('btn-secondary');
                        $('#view_epg_' + id).removeClass('btn-success').addClass('btn-secondary');
                        $('#epg_id_s_' + id).val(0);
                        $('#epg_type_s_' + id).val(0);
                        $('#channel_id_s_' + id).val('');
                    }
                });
            }

            $(function() {
                if ($.fn.select2) {
                    $('.category_id, .bouquet').select2({
                        width: '100%'
                    });
                    $('.epg_api').select2({
                        width: '100%',
                        placeholder: <?= json_encode($language::get('search_epg_api')) ?>,
                        allowClear: true,
                        ajax: {
                            url: './api',
                            dataType: 'json',
                            data: function(params) {
                                return {
                                    search: params.term,
                                    action: 'epglist',
                                    page: params.page
                                };
                            },
                            processResults: function(data, params) {
                                params.page = params.page || 1;
                                return {
                                    results: data.items,
                                    pagination: {
                                        more: (params.page * 100) < data.total_count
                                    }
                                };
                            },
                            cache: true
                        }
                    });
                }
                evaluateChanges();
            });
        <?php else: ?>
            // ---- Selection mode ----
            function updateCount() {
                $('#selected_count').text(window.rSelected.length ? ' - ' + window.rSelected.length + ' selected' : '');
            }
            window.getCategory = function() {
                return $('#category_search').val();
            };
            window.getFilter = function() {
                return $('#stream_filter').val();
            };

            window.toggleStreams = function() {
                var allSelected = true;
                $('#datatable-mass tbody tr').each(function() {
                    if (!$(this).hasClass('selected')) {
                        allSelected = false;
                    }
                });
                $('#datatable-mass tbody tr').each(function() {
                    var id = $(this).find('td:eq(0)').text().trim();
                    if (!id) {
                        return;
                    }
                    if (allSelected) {
                        $(this).removeClass('selected');
                        var i = window.rSelected.indexOf(id);
                        if (i > -1) {
                            window.rSelected.splice(i, 1);
                        }
                    } else if (!$(this).hasClass('selected')) {
                        $(this).addClass('selected');
                        if (window.rSelected.indexOf(id) === -1) {
                            window.rSelected.push(id);
                        }
                    }
                });
                updateCount();
            };

            $(function() {
                if ($.fn.select2) {
                    $('#category_search, #stream_filter, #show_entries').select2({
                        width: '100%'
                    });
                }

                var rTable = $('#datatable-mass').DataTable({
                    processing: true,
                    serverSide: true,
                    searching: true,
                    ordering: false,
                    ajax: {
                        url: './table',
                        data: function(d) {
                            d.id = 'stream_list';
                            d.category = getCategory();
                            d.filter = getFilter();
                        }
                    },
                    columnDefs: [{
                        className: 'dt-center',
                        targets: [0, 3]
                    }],
                    rowCallback: function(row, data) {
                        if ($.inArray(data[0], window.rSelected) !== -1) {
                            $(row).addClass('selected');
                        }
                    },
                    pageLength: <?= (intval($rSettings['default_entries']) ?: 10) ?>,
                    layout: {
                        topStart: 'pageLength',
                        topEnd: null
                    }
                });

                // Row click toggles selection.
                $('#datatable-mass tbody').on('click', 'tr', function() {
                    var id = $(this).find('td:eq(0)').text().trim();
                    if (!id) {
                        return;
                    }
                    if ($(this).hasClass('selected')) {
                        $(this).removeClass('selected');
                        var i = window.rSelected.indexOf(id);
                        if (i > -1) {
                            window.rSelected.splice(i, 1);
                        }
                    } else {
                        $(this).addClass('selected');
                        if (window.rSelected.indexOf(id) === -1) {
                            window.rSelected.push(id);
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
                $('#stream_filter, #category_search').on('change', function() {
                    rTable.ajax.reload(null, false);
                });

                document.getElementById('stream_form').addEventListener('submit', function(e) {
                    if (window.rSelected.length === 0) {
                        e.preventDefault();
                        toast(<?= json_encode($language::get('select_at_least_one_stream')) ?>, 'error');
                        return;
                    }
                    document.getElementById('streams').value = JSON.stringify(window.rSelected);
                    if (window.rSelected.length >= 250 && !window.rConfirmed) {
                        e.preventDefault();
                        var msg = <?= json_encode($language::get('review_streams_confirm')) ?>.replace('%d', window.rSelected.length);
                        var ask = window.xcConfirm ? window.xcConfirm(msg) : Promise.resolve(window.confirm(msg));
                        ask.then(function(ok) {
                            if (ok) {
                                window.rConfirmed = true;
                                document.getElementById('stream_form').submit();
                            }
                        });
                    }
                });
            });
        <?php endif; ?>
    })();
</script>
</body>

</html>