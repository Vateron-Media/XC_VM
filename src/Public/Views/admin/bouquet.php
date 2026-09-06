<?php

/**
 * Add / edit Bouquet (Bootstrap 5). A details tab (bouquet name) plus Streams / Movies /
 * Series / Radios picker tabs (each a serverSide DataTable hitting ./table) and a client-side
 * Review tab pre-populated with the already-selected items. The picker tabs are shown for BOTH
 * add and edit — the save handler (BouquetService::process) accepts bouquet_data on create too,
 * so a new bouquet can be populated in one pass. Selected ids are collected into #bouquet_data
 * (JSON) on submit and posted to post.php?action=bouquet. Reached full-page in the new-UI shell.
 */

$rIsEdit = isset($rBouquetArr['id']);
$rPageLen = (int) ($rSettings['default_entries'] ?? 10) ?: 10;
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h4 class="mb-0"><?= $rIsEdit ? $language::get('edit_bouquet') : $language::get('add_bouquet'); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="bouquet_form">
            <?php if ($rIsEdit): ?>
                <input type="hidden" name="edit" value="<?= (int) $rBouquetArr['id']; ?>">
            <?php endif; ?>
            <input type="hidden" id="bouquet_data" name="bouquet_data" value="">

            <ul class="nav nav-tabs flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-streams" role="tab"><i class="icon-base ti tabler-player-play me-1"></i><?= $language::get('streams'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-movies" role="tab"><i class="icon-base ti tabler-movie me-1"></i><?= $language::get('movies'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-series" role="tab"><i class="icon-base ti tabler-device-tv me-1"></i><?= $language::get('series'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-radios" role="tab"><i class="icon-base ti tabler-radio me-1"></i><?= $language::get('radio'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-review" role="tab"><i class="icon-base ti tabler-list-check me-1"></i><?= $language::get('review'); ?></button></li>
            </ul>

            <div class="tab-content p-0">
                <!-- Details -->
                <div class="tab-pane fade show active" id="tab-details" role="tabpanel">
                    <div class="row mb-3">
                        <label class="col-md-3 col-form-label" for="bouquet_name"><?= $language::get('bouquet_name'); ?></label>
                        <div class="col-md-9"><input type="text" class="form-control" id="bouquet_name" name="bouquet_name" value="<?= isset($rBouquetArr['bouquet_name']) ? htmlspecialchars((string) $rBouquetArr['bouquet_name'], ENT_QUOTES) : ''; ?>" required></div>
                    </div>
                </div>

                <!-- Streams -->
                <div class="tab-pane fade" id="tab-streams" role="tabpanel">
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="category_id"><?= $language::get('category_name'); ?></label>
                            <div class="col-md-9">
                                <select id="category_id" class="form-select">
                                    <option value="" selected><?= $language::get('all_categories'); ?></option>
                                    <?php foreach ($liveCategories as $rCategory): ?>
                                        <option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="stream_search"><?= $language::get('search'); ?></label>
                            <div class="col-md-9"><input type="text" class="form-control" id="stream_search" value=""></div>
                        </div>
                        <div class="table-responsive mb-3">
                            <table id="datatable-stream" class="table" style="width:100%">
                                <thead>
                                    <tr>
                                        <th class="text-center"><?= $language::get('id'); ?></th>
                                        <th><?= $language::get('stream_name'); ?></th>
                                        <th><?= $language::get('category'); ?></th>
                                        <th class="text-center"><?= $language::get('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="text-end"><button type="button" onClick="toggleBouquets('datatable-stream')" class="btn btn-label-primary"><?= $language::get('toggle_page'); ?></button></div>
                    </div>

                    <!-- Movies -->
                    <div class="tab-pane fade" id="tab-movies" role="tabpanel">
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="category_idv"><?= $language::get('category_name'); ?></label>
                            <div class="col-md-9">
                                <select id="category_idv" class="form-select">
                                    <option value="" selected><?= $language::get('all_categories'); ?></option>
                                    <?php foreach ($movieCategories as $rCategory): ?>
                                        <option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="vod_search"><?= $language::get('search'); ?></label>
                            <div class="col-md-9"><input type="text" class="form-control" id="vod_search" value=""></div>
                        </div>
                        <div class="table-responsive mb-3">
                            <table id="datatable-movies" class="table" style="width:100%">
                                <thead>
                                    <tr>
                                        <th class="text-center"><?= $language::get('id'); ?></th>
                                        <th><?= $language::get('vod_name'); ?></th>
                                        <th><?= $language::get('category'); ?></th>
                                        <th class="text-center"><?= $language::get('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="text-end"><button type="button" onClick="toggleBouquets('datatable-movies')" class="btn btn-label-primary"><?= $language::get('toggle_page'); ?></button></div>
                    </div>

                    <!-- Series -->
                    <div class="tab-pane fade" id="tab-series" role="tabpanel">
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="category_ids"><?= $language::get('category_name'); ?></label>
                            <div class="col-md-9">
                                <select id="category_ids" class="form-select">
                                    <option value="" selected><?= $language::get('all_categories'); ?></option>
                                    <?php foreach ($seriesCategories as $rCategory): ?>
                                        <option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="series_search"><?= $language::get('search'); ?></label>
                            <div class="col-md-9"><input type="text" class="form-control" id="series_search" value=""></div>
                        </div>
                        <div class="table-responsive mb-3">
                            <table id="datatable-series" class="table" style="width:100%">
                                <thead>
                                    <tr>
                                        <th class="text-center"><?= $language::get('id'); ?></th>
                                        <th><?= $language::get('series_name'); ?></th>
                                        <th><?= $language::get('category'); ?></th>
                                        <th class="text-center"><?= $language::get('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="text-end"><button type="button" onClick="toggleBouquets('datatable-series')" class="btn btn-label-primary"><?= $language::get('toggle_page'); ?></button></div>
                    </div>

                    <!-- Radios -->
                    <div class="tab-pane fade" id="tab-radios" role="tabpanel">
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="category_idr"><?= $language::get('category_name'); ?></label>
                            <div class="col-md-9">
                                <select id="category_idr" class="form-select">
                                    <option value="" selected><?= $language::get('all_categories'); ?></option>
                                    <?php foreach ($radioCategories as $rCategory): ?>
                                        <option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-3">
                            <label class="col-md-3 col-form-label" for="radios_search"><?= $language::get('search'); ?></label>
                            <div class="col-md-9"><input type="text" class="form-control" id="radios_search" value=""></div>
                        </div>
                        <div class="table-responsive mb-3">
                            <table id="datatable-radios" class="table" style="width:100%">
                                <thead>
                                    <tr>
                                        <th class="text-center"><?= $language::get('id'); ?></th>
                                        <th><?= $language::get('station_name'); ?></th>
                                        <th><?= $language::get('category'); ?></th>
                                        <th class="text-center"><?= $language::get('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody></tbody>
                            </table>
                        </div>
                        <div class="text-end"><button type="button" onClick="toggleBouquets('datatable-radios')" class="btn btn-label-primary"><?= $language::get('toggle_page'); ?></button></div>
                    </div>

                    <!-- Review -->
                    <div class="tab-pane fade" id="tab-review" role="tabpanel">
                        <div class="table-responsive mb-3">
                            <table id="datatable-review" class="table" style="width:100%">
                                <thead>
                                    <tr>
                                        <th class="text-center"><?= $language::get('id'); ?></th>
                                        <th><?= $language::get('type'); ?></th>
                                        <th><?= $language::get('display_name'); ?></th>
                                        <th class="text-center"><?= $language::get('actions'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($rBouquetChannels as $rChannel): ?>
                                        <tr id="stream-<?= (int) $rChannel; ?>">
                                            <td class="text-center"><?= (int) $rChannel; ?></td>
                                            <td><?= $language::get('stream'); ?></td>
                                            <td><?= htmlspecialchars((string) ($rNames[$rChannel] ?? ''), ENT_QUOTES); ?></td>
                                            <td class="text-center"><button type="button" class="btn-remove btn btn-sm btn-icon btn-label-warning" onClick="toggleBouquet(<?= (int) $rChannel; ?>, 'stream');"><i class="icon-base ti tabler-minus"></i></button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($rBouquetMovies as $rChannel): ?>
                                        <tr id="movies-<?= (int) $rChannel; ?>">
                                            <td class="text-center"><?= (int) $rChannel; ?></td>
                                            <td><?= $language::get('movies'); ?></td>
                                            <td><?= htmlspecialchars((string) ($rNames[$rChannel] ?? ''), ENT_QUOTES); ?></td>
                                            <td class="text-center"><button type="button" class="btn-remove btn btn-sm btn-icon btn-label-warning" onClick="toggleBouquet(<?= (int) $rChannel; ?>, 'movies');"><i class="icon-base ti tabler-minus"></i></button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($rBouquetRadios as $rChannel): ?>
                                        <tr id="radios-<?= (int) $rChannel; ?>">
                                            <td class="text-center"><?= (int) $rChannel; ?></td>
                                            <td><?= $language::get('radios'); ?></td>
                                            <td><?= htmlspecialchars((string) ($rNames[$rChannel] ?? ''), ENT_QUOTES); ?></td>
                                            <td class="text-center"><button type="button" class="btn-remove btn btn-sm btn-icon btn-label-warning" onClick="toggleBouquet(<?= (int) $rChannel; ?>, 'radios');"><i class="icon-base ti tabler-minus"></i></button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php foreach ($rBouquetSeries as $rChannel): ?>
                                        <tr id="series-<?= (int) $rChannel; ?>">
                                            <td class="text-center"><?= (int) $rChannel; ?></td>
                                            <td><?= $language::get('series'); ?></td>
                                            <td><?= htmlspecialchars((string) ($rSeriesNames[$rChannel] ?? ''), ENT_QUOTES); ?></td>
                                            <td class="text-center"><button type="button" class="btn-remove btn btn-sm btn-icon btn-label-warning" onClick="toggleBouquet(<?= (int) $rChannel; ?>, 'series');"><i class="icon-base ti tabler-minus"></i></button></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
            </div>

            <div class="d-flex justify-content-end gap-2 mt-4">
                <button type="button" id="bouquet-next" class="btn btn-label-secondary"><?= $language::get('next'); ?><i class="icon-base ti tabler-chevron-right ms-1"></i></button>
                <button type="submit" name="submit_bouquet" class="btn btn-primary"><?= $rIsEdit ? $language::get('edit') : $language::get('add'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    // Selected-item state, seeded from the bouquet being edited/duplicated (empty on add).
    var rBouquet = {
        stream: <?= json_encode(array_map('intval', $rBouquetChannels)); ?>,
        movies: <?= json_encode(array_map('intval', $rBouquetMovies)); ?>,
        series: <?= json_encode(array_map('intval', $rBouquetSeries)); ?>,
        radios: <?= json_encode(array_map('intval', $rBouquetRadios)); ?>
    };

    function ucwords(str) {
        return (str + '').replace(/^([a-z])|\s+([a-z])/g, function($1) {
            return $1.toUpperCase();
        });
    }

    // Client-side render of a picker row's add/remove buttons (both hidden; createdRow
    // reveals the correct one). The handlers return data only — no button HTML.
    function pickerButtons(rID, rType) {
        return '<div class="btn-group">' +
            '<button data-id="' + rID + '" data-type="' + rType + '" type="button" style="display:none" class="btn-remove btn btn-sm btn-icon btn-label-warning" onClick="toggleBouquet(' + rID + ', \'' + rType + '\');"><i class="icon-base ti tabler-minus"></i></button>' +
            '<button data-id="' + rID + '" data-type="' + rType + '" type="button" style="display:none" class="btn-add btn btn-sm btn-icon btn-label-success" onClick="toggleBouquet(' + rID + ', \'' + rType + '\');"><i class="icon-base ti tabler-plus"></i></button>' +
            '</div>';
    }

    // Add / remove an item from the review DataTable and flip its add/remove buttons.
    function toggleBouquet(rID, rType, rDraw) {
        if (rDraw === undefined) { rDraw = true; }
        var $ = window.jQuery;
        var rIndex = rBouquet[rType].indexOf(parseInt(rID));
        if (rIndex > -1) {
            rBouquet[rType] = $.grep(rBouquet[rType], function(rValue) {
                return parseInt(rValue) != parseInt(rID);
            });
            $('#' + rType + '-' + rID).find('.btn-add').show();
            $('#' + rType + '-' + rID).find('.btn-remove').hide();
            delRow(rID, rType);
        } else {
            rBouquet[rType].push(parseInt(rID));
            $('#' + rType + '-' + rID).find('.btn-remove').show();
            $('#' + rType + '-' + rID).find('.btn-add').hide();
            addRow(rID, rType);
        }
        if (rDraw) {
            $('#datatable-review').DataTable().draw(false);
        }
    }

    function addRow(rID, rType) {
        var $ = window.jQuery;
        var rTable = $('#datatable-review').DataTable();
        var rName = $($('#' + rType + '-' + rID).find('td')[1]).text();
        var rButton = '<button type="button" class="btn-remove btn btn-sm btn-icon btn-label-warning" onClick="toggleBouquet(' + rID + ', \'' + rType + '\');"><i class="icon-base ti tabler-minus"></i></button>';
        // The row id is applied on draw via the review table's createdRow (the node
        // does not exist yet when adds are batched with draw deferred).
        rTable.row.add([rID, ucwords(rType), rName, rButton]);
    }

    function delRow(rID, rType) {
        var $ = window.jQuery;
        $('#datatable-review').DataTable().row('#' + rType + '-' + rID).remove();
    }

    // Toggle every visible last-cell button in a picker table (select/deselect page).
    function toggleBouquets(rPage) {
        var $ = window.jQuery;
        $('#' + rPage + ' tr').each(function() {
            $(this).find('td:last-child button').filter(':visible').each(function() {
                toggleBouquet($(this).data('id'), $(this).data('type'), false);
            });
        });
        $('#datatable-review').DataTable().draw(false);
    }

    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var toast = window.xcToast || function() {};
        $.fn.dataTable.ext.errMode = 'none';

        $(function() {
            // Picker-table factory: serverSide rows are positional arrays
                // [id, name, category, buttonsHtml]; createdRow flips add/remove per rBouquet.
                function buildPicker(rTableId, rTypeKey, rDataId, rCategorySel) {
                    return $('#' + rTableId).DataTable({
                        processing: true,
                        serverSide: true,
                        info: false,
                        lengthChange: false,
                        pageLength: <?= $rPageLen; ?>,
                        ajax: {
                            url: './table',
                            data: function(d) {
                                d.id = rDataId;
                                d.category_id = $(rCategorySel).val();
                            }
                        },
                        columnDefs: [
                            { className: 'text-center', targets: 0 },
                            { targets: 3, data: 0, orderable: false, className: 'text-center', render: function(rID) { return pickerButtons(rID, rTypeKey); } }
                        ],
                        createdRow: function(row, data) {
                            $(row).attr('id', rTypeKey + '-' + data[0]);
                            if (rBouquet[rTypeKey].indexOf(parseInt(data[0])) > -1) {
                                $(row).find('.btn-remove').show();
                            } else {
                                $(row).find('.btn-add').show();
                            }
                        },
                        layout: { topStart: 'pageLength' }
                    });
                }

                var rStreamTable = buildPicker('datatable-stream', 'stream', 'bouquets_streams', '#category_id');
                var rMoviesTable = buildPicker('datatable-movies', 'movies', 'bouquets_vod', '#category_idv');
                var rSeriesTable = buildPicker('datatable-series', 'series', 'bouquets_series', '#category_ids');
                var rRadiosTable = buildPicker('datatable-radios', 'radios', 'bouquets_radios', '#category_idr');

                $('#datatable-review').DataTable({
                    info: false,
                    lengthChange: false,
                    pageLength: <?= $rPageLen; ?>,
                    columnDefs: [{ className: 'text-center', targets: [0, 1, 3] }],
                    createdRow: function(row, data) {
                        // data[1] is the ucwords type (Stream/Movies/Series/Radios); the row id
                        // keys back to rBouquet and lets delRow('#type-id') find it.
                        $(row).attr('id', ('' + data[1]).toLowerCase() + '-' + data[0]);
                    },
                    layout: { topStart: 'pageLength', topEnd: 'search' }
                });

                if ($.fn.select2) { $('#category_id, #category_idv, #category_ids, #category_idr').select2({ width: '100%' }); }

                // Category filter reloads the matching table; the per-tab search input
                // drives that table's client-side search().
                $('#category_id').on('select2:select change', function() { rStreamTable.ajax.reload(null, false); });
                $('#stream_search').on('keyup', function() { rStreamTable.search($(this).val()).draw(); });
                $('#category_idv').on('select2:select change', function() { rMoviesTable.ajax.reload(null, false); });
                $('#vod_search').on('keyup', function() { rMoviesTable.search($(this).val()).draw(); });
                $('#category_ids').on('select2:select change', function() { rSeriesTable.ajax.reload(null, false); });
                $('#series_search').on('keyup', function() { rSeriesTable.search($(this).val()).draw(); });
                $('#category_idr').on('select2:select change', function() { rRadiosTable.ajax.reload(null, false); });
                $('#radios_search').on('keyup', function() { rRadiosTable.search($(this).val()).draw(); });

                // "Next" advances to the following tab; hide it once the last tab is active.
                var rTabs = $('#bouquet_form .nav-link').toArray();
                function syncNext() {
                    var rActive = rTabs.findIndex(function(t) { return t.classList.contains('active'); });
                    $('#bouquet-next').toggle(rActive > -1 && rActive < rTabs.length - 1);
                }
                $('#bouquet-next').on('click', function() {
                    var rActive = rTabs.findIndex(function(t) { return t.classList.contains('active'); });
                    if (rActive > -1 && rActive < rTabs.length - 1) { rTabs[rActive + 1].click(); }
                });
                $('#bouquet_form .nav-link').on('shown.bs.tab', syncNext);
                syncNext();

            $('#bouquet_form').on('submit', function(e) {
                e.preventDefault();
                $('#bouquet_data').val(JSON.stringify(rBouquet));
                if ($('#bouquet_name').val().length === 0) {
                    toast(<?= json_encode($language::get('enter_a_bouquet_name')); ?>, 'error');
                    return;
                }
                var rButtons = this.querySelectorAll('button[type="submit"]');
                rButtons.forEach(function(b) { b.disabled = true; });
                fetch('post.php?action=bouquet', { method: 'POST', body: new FormData(this), headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                    .then(function(r) { return r.text(); })
                    .then(function(txt) {
                        var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                        if (d && d.result) {
                            if (window.parent !== window) { window.parent.postMessage('xcModalSaved', '*'); }
                            else { window.location = 'bouquets'; }
                            return;
                        }
                        rButtons.forEach(function(b) { b.disabled = false; });
                        toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                    })
                    .catch(function() {
                        rButtons.forEach(function(b) { b.disabled = false; });
                        toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                    });
            });
        });
    })();
</script>
</body>

</html>
