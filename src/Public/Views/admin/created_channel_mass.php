<?php

/**
 * Mass edit created channels (Bootstrap 5). Three tabs: a serverSide selection table
 * (stream_list, only_channels — click a row to select), a details tab whose fields are
 * each gated by an "activate" checkbox (only ticked fields are applied), and a servers
 * tab with a jstree active/offline server tree. On submit the selected ids (streams JSON)
 * plus server_tree_data and the activated fields POST to post.php?action=created_channel_mass.
 * Reached full-page in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('mass_edit_channels'); ?> <small class="text-body-secondary" id="selected_count"></small></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="mass-form">
            <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
            <input type="hidden" name="od_tree_data" id="od_tree_data" value="">
            <input type="hidden" name="streams" id="streams" value="">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#stream-selection" role="tab"><i class="icon-base ti tabler-player-play me-1"></i><?= $language::get('channels'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#stream-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#load-balancing" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
            </ul>
            <div class="tab-content p-4 border rounded">
                <!-- Selection -->
                <div class="tab-pane fade show active" id="stream-selection" role="tabpanel">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-2 col-6"><input type="text" class="form-control" id="stream_search" placeholder="<?= $language::get('search_channels'); ?>"></div>
                        <div class="col-md-3 col-6">
                            <select id="stream_server_id" class="form-select">
                                <option value="" selected><?= $language::get('all_servers'); ?></option>
                                <option value="-1"><?= $language::get('no_servers'); ?></option>
                                <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <select id="category_search" class="form-select">
                                <option value="" selected><?= $language::get('all_categories'); ?></option>
                                <option value="-1"><?= $language::get('no_categories'); ?></option>
                                <?php foreach ($rCategories as $rCategory): ?>
                                    <option value="<?= (int) $rCategory['id']; ?>" <?= (RequestManager::has('category') && RequestManager::get('category') == $rCategory['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="stream_filter" class="form-select">
                                <option value=""><?= $language::get('no_filter'); ?></option>
                                <option value="1"><?= $language::get('online'); ?></option>
                                <option value="2"><?= $language::get('stopped'); ?></option>
                                <option value="3"><?= $language::get('creating'); ?></option>
                                <option value="4"><?= $language::get('transcoding'); ?></option>
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
                                    <th><?= $language::get('stream_name'); ?></th>
                                    <th><?= $language::get('category'); ?></th>
                                    <th><?= $language::get('server'); ?></th>
                                    <th class="text-center"><?= $language::get('status'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <!-- Details -->
                <div class="tab-pane fade" id="stream-details" role="tabpanel">
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="category_id" name="c_category_id"></div>
                        <label class="col-md-3 col-form-label" for="category_id"><?= $language::get('select_categories'); ?></label>
                        <div class="col-md-6">
                            <select disabled name="category_id[]" id="category_id" class="form-select" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                <?php foreach ($rCategories as $rCategory): ?>
                                    <option value="<?= (int) $rCategory['id']; ?>"><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
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
                                <?php foreach (BouquetService::getAllSimple() as $rBouquet): ?>
                                    <option value="<?= (int) $rBouquet['id']; ?>"><?= htmlspecialchars((string) $rBouquet['bouquet_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2">
                            <select disabled name="bouquets_type" id="bouquets_type" class="form-select">
                                <?php foreach (['SET', 'ADD', 'DEL'] as $rType): ?><option value="<?= $rType; ?>"><?= $rType; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="rtmp_output" name="c_rtmp_output"></div>
                        <label class="col-md-3 col-form-label" for="rtmp_output"><?= $language::get('output_rtmp'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled type="checkbox" name="rtmp_output" id="rtmp_output" value="1" class="form-check-input"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="allow_record" name="c_allow_record"></div>
                        <label class="col-md-3 col-form-label" for="allow_record"><?= $language::get('allow_recording'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled type="checkbox" name="allow_record" id="allow_record" value="1" class="form-check-input"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="transcode_profile_id" name="c_transcode_profile_id"></div>
                        <label class="col-md-3 col-form-label" for="transcode_profile_id"><?= $language::get('transcoding_profile'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                                <option selected value="0"><?= $language::get('transcoding_disabled'); ?></option>
                                <?php foreach ($rTranscodeProfiles as $rProfile): ?>
                                    <option value="<?= (int) $rProfile['profile_id']; ?>"><?= htmlspecialchars((string) $rProfile['profile_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <!-- Servers -->
                <div class="tab-pane fade" id="load-balancing" role="tabpanel">
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" data-name="server_tree" class="form-check-input activate" name="c_server_tree" id="c_server_tree"></div>
                        <label class="col-md-3 col-form-label" for="server_tree"><?= $language::get('server_tree'); ?></label>
                        <div class="col-md-8"><div id="server_tree" class="border rounded p-2"></div></div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="server_type"><?= $language::get('server_type'); ?></label>
                        <div class="col-md-2">
                            <select disabled name="server_type" id="server_type" class="form-select">
                                <?php foreach (['SET' => 'SET SERVERS', 'ADD' => 'ADD SELECTED', 'DEL' => 'DELETE SELECTED'] as $rValue => $rType): ?><option value="<?= $rValue; ?>"><?= $rType; ?></option><?php endforeach; ?>
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
                        <label class="col-md-3 col-form-label" for="reencode_on_edit"><?= $language::get('reencode_on_edit'); ?></label>
                        <div class="col-md-2"><div class="form-check form-switch"><input name="reencode_on_edit" id="reencode_on_edit" type="checkbox" value="1" class="form-check-input"></div></div>
                        <label class="col-md-3 col-form-label" for="restart_on_edit"><?= $language::get('restart_on_edit'); ?></label>
                        <div class="col-md-2"><div class="form-check form-switch"><input name="restart_on_edit" id="restart_on_edit" type="checkbox" value="1" class="form-check-input"></div></div>
                    </div>
                </div>
            </div>
            <div class="text-end mt-3"><button type="submit" class="btn btn-primary" name="submit_stream" value="1">Edit Channels</button></div>
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
        if (!$) { return; }
        var toast = window.xcToast || function() {};
        var selected = [];

        function updateCount() { document.getElementById('selected_count').textContent = selected.length ? '— ' + selected.length + ' selected' : ''; }
        window.getCategory = function() { return document.getElementById('category_search').value; };
        window.getServer = function() { return document.getElementById('stream_server_id').value; };
        window.getFilter = function() { return document.getElementById('stream_filter').value; };
        window.toggleStreams = function() {
            var allSelected = true;
            $('#datatable-mass tbody tr').each(function() { if (!$(this).hasClass('table-active')) { allSelected = false; } });
            $('#datatable-mass tbody tr').each(function() {
                var id = $(this).find('td:eq(0)').text().trim();
                if (!id) { return; }
                if (allSelected) { $(this).removeClass('table-active'); var i = selected.indexOf(id); if (i > -1) { selected.splice(i, 1); } }
                else if (!$(this).hasClass('table-active')) { $(this).addClass('table-active'); if (selected.indexOf(id) === -1) { selected.push(id); } }
            });
            updateCount();
        };

        // Selection filters + detail/server selects (select2).
        if ($.fn.select2) {
            $('#stream_server_id, #category_search, #stream_filter, #show_entries').select2({ width: '100%' });
            $('#category_id, #category_id_type, #bouquets, #bouquets_type, #transcode_profile_id').select2({ width: '100%', dropdownParent: $('#stream-details') });
            $('#server_type, #on_demand').select2({ width: '100%', dropdownParent: $('#load-balancing') });
        }

        // Enable/disable a select2-backed field + refresh its widget.
        function toggleField(id, enabled) {
            var e = document.getElementById(id);
            if (e) { e.disabled = !enabled; $(e).trigger('change.select2'); }
        }
        // Activate checkboxes gate their field (switch is just a checkbox now).
        $('.activate').on('change', function() {
            var name = this.getAttribute('data-name');
            var checked = this.checked;
            var t = document.getElementById(name);
            if (t) { t.disabled = !checked; if (t.tagName === 'SELECT') { $(t).trigger('change.select2'); } }
            if (name === 'category_id') { toggleField('category_id_type', checked); }
            if (name === 'bouquets') { toggleField('bouquets_type', checked); }
            if (name === 'server_tree') { toggleField('server_type', checked); toggleField('on_demand', checked); }
        });

        // jstree active/offline server tree.
        function evaluateServers() {
            var cur = $('#on_demand').val();
            $('#on_demand').empty();
            $($('#server_tree').jstree(true).get_json('source', { flat: true })).each(function(i, v) {
                if (v.parent !== '#') { $('#on_demand').append(new Option(v.text, v.id)); }
            });
            $('#on_demand').val(cur).trigger('change');
        }
        $('#server_tree')
            .on('redraw.jstree', function() { evaluateServers(); })
            .on('select_node.jstree', function(e, data) {
                $('#c_server_tree').prop('checked', true).trigger('change');
                if (data.node.id === 'source' || data.node.id === 'offline') { return; }
                var to = (data.node.parent === 'offline') ? 'source' : 'offline';
                $('#server_tree').jstree('move_node', data.node.id, to, to === 'source' ? 'last' : 'first');
            })
            .jstree({
                core: {
                    check_callback: function(op, node, parent) {
                        if (op === 'move_node') {
                            if (node.id === 'offline' || node.id === 'source') { return false; }
                            if (parent.id === '#') { return false; }
                            return true;
                        }
                        return true;
                    },
                    data: <?= json_encode($rServerTree); ?>
                },
                plugins: ['dnd']
            });

        // stream_list is a positional-array handler; keep legacy columnDefs.
        var rTable = $('#datatable-mass').DataTable({
            processing: true, serverSide: true, searchDelay: 250,
            ajax: { url: './table', data: function(d) { d.id = 'stream_list'; d.category = getCategory(); d.filter = getFilter(); d.server = getServer(); d.only_channels = 1; } },
            columnDefs: [{ className: 'text-center', targets: [0, 1, 5] }],
            rowCallback: function(row, data) { if (selected.indexOf(String(data[0])) !== -1) { $(row).addClass('table-active'); } },
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 10); ?>,
            order: [[0, 'desc']],
            layout: { topStart: null, topEnd: null }
        });

        // Row click toggles selection.
        $('#datatable-mass tbody').on('click', 'tr', function() {
            var id = $(this).find('td:eq(0)').text().trim();
            if (!id) { return; }
            if ($(this).hasClass('table-active')) { $(this).removeClass('table-active'); var i = selected.indexOf(id); if (i > -1) { selected.splice(i, 1); } }
            else { $(this).addClass('table-active'); if (selected.indexOf(id) === -1) { selected.push(id); } }
            updateCount();
        });

        $('#stream_search').on('keyup', function() { rTable.search(this.value).draw(); });
        $('#show_entries').on('change', function() { rTable.page.len(parseInt(this.value, 10)).draw(); });
        $('#stream_server_id, #category_search, #stream_filter').on('change', function() { rTable.ajax.reload(null, false); });

        document.getElementById('mass-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!selected.length) { toast('Select at least one stream to edit.', 'warning'); return; }
            document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', { flat: true }));
            document.getElementById('streams').value = JSON.stringify(selected);
            var btn = this.querySelector('button[type="submit"]');
            if (btn) { btn.disabled = true; }
            var fd = new FormData(this);
            fd.append('submit_stream', '1');
            fetch('post.php?action=created_channel_mass', { method: 'POST', body: fd, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.text(); })
                .then(function(txt) {
                    var d; try { d = JSON.parse(txt); } catch (err) { d = { result: false }; }
                    if (d && d.result !== false) { toast('Mass edit applied.', 'success'); setTimeout(function() { location.reload(); }, 800); return; }
                    if (btn) { btn.disabled = false; }
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                })
                .catch(function() { if (btn) { btn.disabled = false; } toast(<?= json_encode($language::get('error_occured')); ?>, 'error'); });
        });
    })();
</script>
</body>

</html>
