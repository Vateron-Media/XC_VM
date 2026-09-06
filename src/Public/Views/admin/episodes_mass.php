<?php

/**
 * Mass edit episodes (Bootstrap 5). Three tabs: a serverSide selection table
 * (episode_list — click a row to select), a details tab whose fields are each gated by
 * an "activate" checkbox (only ticked fields are applied), and a servers tab with a
 * jstree active/offline server tree. On submit the selected ids (streams JSON) plus
 * server_tree_data and the activated fields POST to post.php?action=episodes_mass.
 * Reached full-page in the new-UI shell.
 */

use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\StreamConfigRepository;
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('mass_edit_episodes'); ?> <small class="text-body-secondary" id="selected_count"></small></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="mass-form">
            <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
            <input type="hidden" name="od_tree_data" id="od_tree_data" value="">
            <input type="hidden" name="streams" id="streams" value="">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#stream-selection" role="tab"><i class="icon-base ti tabler-player-play me-1"></i><?= $language::get('episodes'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#stream-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#load-balancing" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
            </ul>
            <div class="tab-content p-4 border rounded">
                <!-- Selection -->
                <div class="tab-pane fade show active" id="stream-selection" role="tabpanel">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-2 col-6"><input type="text" class="form-control" id="stream_search" placeholder="<?= $language::get('search_episodes'); ?>..."></div>
                        <div class="col-md-3 col-6">
                            <select id="series_id" class="form-select">
                                <option value=""><?= $language::get('all_series'); ?></option>
                                <?php foreach ($rSeries as $rSerie): ?>
                                    <option value="<?= (int) $rSerie['id']; ?>"><?= htmlspecialchars((string) $rSerie['title'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-3 col-6">
                            <select id="episode_server_id" class="form-select">
                                <option value="" selected><?= $language::get('all_servers'); ?></option>
                                <option value="-1"><?= $language::get('no_servers'); ?></option>
                                <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                                    <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="filter" class="form-select">
                                <option value="" selected><?= $language::get('no_filter'); ?></option>
                                <option value="1"><?= $language::get('encoded'); ?></option>
                                <option value="2"><?= $language::get('encoding'); ?></option>
                                <option value="3"><?= $language::get('down'); ?></option>
                                <option value="4"><?= $language::get('ready'); ?></option>
                                <option value="5"><?= $language::get('direct'); ?></option>
                                <option value="7"><?= $language::get('transcoding'); ?></option>
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
                                    <th class="text-center"><?= $language::get('image'); ?></th>
                                    <th><?= $language::get('name'); ?></th>
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
                    <p class="text-body-secondary"><?= $language::get('mass_edit_info'); ?></p>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="serie_name" name="c_serie_name"></div>
                        <label class="col-md-3 col-form-label" for="serie_name"><?= $language::get('series_name'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="serie_name" id="serie_name" class="form-select">
                                <?php foreach ($rSeries as $rSerie): ?>
                                    <option value="<?= (int) $rSerie['id']; ?>"><?= htmlspecialchars((string) $rSerie['title'], ENT_QUOTES); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="direct_source" name="c_direct_source"></div>
                        <label class="col-md-3 col-form-label" for="direct_source"><?= $language::get('direct_source'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled name="direct_source" id="direct_source" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="movie_symlink" name="c_movie_symlink"></div>
                        <label class="col-md-3 col-form-label" for="movie_symlink"><?= $language::get('create_symlink'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled name="movie_symlink" id="movie_symlink" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="remove_subtitles" name="c_remove_subtitles"></div>
                        <label class="col-md-3 col-form-label" for="remove_subtitles"><?= $language::get('remove_subtitles'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled name="remove_subtitles" id="remove_subtitles" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="read_native" name="c_read_native"></div>
                        <label class="col-md-3 col-form-label" for="read_native"><?= $language::get('native_frames'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled name="read_native" id="read_native" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="target_container" name="c_target_container"></div>
                        <label class="col-md-3 col-form-label" for="target_container"><?= $language::get('target_container'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="target_container" id="target_container" class="form-select">
                                <?php foreach (['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'] as $rContainer): ?>
                                    <option value="<?= $rContainer; ?>"><?= strtoupper($rContainer); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="transcode_profile_id" name="c_transcode_profile_id"></div>
                        <label class="col-md-3 col-form-label" for="transcode_profile_id"><?= $language::get('transcoding_profile'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                                <option selected value="0"><?= $language::get('transcoding_disabled'); ?></option>
                                <?php foreach (StreamConfigRepository::getTranscodeProfiles() as $rProfile): ?>
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
                        <div class="col-md-8">
                            <div id="server_tree" class="border rounded p-2"></div>
                        </div>
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
                        <label class="col-md-3 col-form-label" for="reencode_on_edit"><?= $language::get('reencode_on_edit'); ?></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch"><input name="reencode_on_edit" id="reencode_on_edit" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                        <label class="col-md-3 col-form-label" for="reprocess_tmdb"><?= $language::get('reprocess_tmdb_data'); ?></label>
                        <div class="col-md-2">
                            <div class="form-check form-switch"><input name="reprocess_tmdb" id="reprocess_tmdb" type="checkbox" value="1" class="form-check-input"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-end mt-3"><button type="submit" class="btn btn-primary" name="submit_stream" value="1"><?= $language::get('edit_episodes'); ?></button></div>
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
        window.getSeries = function() {
            return document.getElementById('series_id').value;
        };
        window.getServer = function() {
            return document.getElementById('episode_server_id').value;
        };
        window.getFilter = function() {
            return document.getElementById('filter').value;
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

        // Selection filters + detail selects (select2).
        if ($.fn.select2) {
            $('#series_id, #episode_server_id, #filter, #show_entries').select2({
                width: '100%'
            });
            $('#serie_name, #target_container, #transcode_profile_id').select2({
                width: '100%',
                dropdownParent: $('#stream-details')
            });
            $('#server_type').select2({
                width: '100%',
                dropdownParent: $('#load-balancing')
            });
        }

        // Activate checkboxes gate their field (switch is just a checkbox now).
        $('.activate').on('change', function() {
            var name = this.getAttribute('data-name');
            var checked = this.checked;
            var t = document.getElementById(name);
            if (t) {
                t.disabled = !checked;
                if (t.tagName === 'SELECT') {
                    $(t).trigger('change.select2');
                }
            }
            if (name === 'server_tree') {
                var st = document.getElementById('server_type');
                if (st) {
                    st.disabled = !checked;
                    $(st).trigger('change.select2');
                }
            }
        });

        // jstree active/offline server tree.
        $('#server_tree')
            .on('select_node.jstree', function(e, data) {
                $('#c_server_tree').prop('checked', true).trigger('change');
                if (data.node.id === 'source' || data.node.id === 'offline') {
                    return;
                }
                var to = (data.node.parent === 'offline') ? 'source' : 'offline';
                $('#server_tree').jstree('move_node', data.node.id, to, to === 'source' ? 'last' : 'first');
            })
            .jstree({
                core: {
                    check_callback: function(op, node, parent) {
                        if (op === 'move_node') {
                            if (node.id === 'offline' || node.id === 'source') {
                                return false;
                            }
                            if (parent.id !== 'offline' && parent.id !== 'source') {
                                return false;
                            }
                            if (parent.id === '#') {
                                return false;
                            }
                            return true;
                        }
                        return true;
                    },
                    data: <?= json_encode($rServerTree); ?>
                },
                plugins: ['dnd']
            });

        // episode_list is a positional-array handler; keep legacy columnDefs.
        var rTable = $('#datatable-mass').DataTable({
            processing: true,
            serverSide: true,
            searchDelay: 250,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'episode_list';
                    d.series = getSeries();
                    d.filter = getFilter();
                    d.server = getServer();
                }
            },
            columnDefs: [{
                    className: 'text-center',
                    targets: [0, 1, 4]
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
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 10); ?>,
            order: [
                [0, 'desc']
            ],
            layout: {
                topStart: null,
                topEnd: null
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
        $('#series_id, #episode_server_id, #filter').on('change', function() {
            rTable.ajax.reload(null, false);
        });

        document.getElementById('mass-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!selected.length) {
                toast(<?= json_encode($language::get('select_at_least_one_episode')); ?>, 'warning');
                return;
            }
            document.getElementById('server_tree_data').value = JSON.stringify($('#server_tree').jstree(true).get_json('source', {
                flat: true
            }));
            document.getElementById('streams').value = JSON.stringify(selected);
            var btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            var fd = new FormData(this);
            fd.append('submit_stream', '1');
            fetch('post.php?action=episodes_mass', {
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