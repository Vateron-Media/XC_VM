<?php

/**
 * Mass edit movies (Bootstrap 5). Three tabs: a serverSide selection table
 * (movie_list, click a row to select), a details tab whose fields are each gated
 * by an "activate" checkbox (only ticked fields are applied), and a servers tab
 * with a jstree load-balancer tree. On submit the selected ids (streams JSON)
 * plus the activated fields POST to post.php?action=movie_mass. Reached full-page
 * in the new-UI shell.
 */

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Server\ServerRepository;
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('mass_edit_movies'); ?> <small class="text-body-secondary" id="selected_count"></small></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="mass-form">
            <input type="hidden" name="streams" id="streams" value="">
            <input type="hidden" name="server_tree_data" id="server_tree_data" value="">
            <input type="hidden" name="od_tree_data" id="od_tree_data" value="">
            <ul class="nav nav-pills flex-wrap mb-4" role="tablist">
                <li class="nav-item"><button type="button" class="nav-link active" data-bs-toggle="tab" data-bs-target="#movie-selection" role="tab"><i class="icon-base ti tabler-movie me-1"></i><?= $language::get('movies'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#movie-details" role="tab"><i class="icon-base ti tabler-list-details me-1"></i><?= $language::get('details'); ?></button></li>
                <li class="nav-item"><button type="button" class="nav-link" data-bs-toggle="tab" data-bs-target="#movie-servers" role="tab"><i class="icon-base ti tabler-server me-1"></i><?= $language::get('servers'); ?></button></li>
            </ul>
            <div class="tab-content p-4 border rounded">
                <!-- Selection -->
                <div class="tab-pane fade show active" id="movie-selection" role="tabpanel">
                    <div class="row g-2 align-items-center mb-3">
                        <div class="col-md-3 col-6"><input type="text" class="form-control" id="stream_search" placeholder="<?= $language::get('search_movies_placeholder'); ?>"></div>
                        <div class="col-md-3 col-6">
                            <select id="movie_server_id" class="form-select">
                                <option value="" selected><?= $language::get('all_servers'); ?></option>
                                <option value="-1"><?= $language::get('no_servers'); ?></option>
                                <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?><option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 col-6">
                            <select id="category_search" class="form-select">
                                <option value="" selected><?= $language::get('all_categories'); ?></option>
                                <option value="-1"><?= $language::get('no_categories'); ?></option>
                                <?php foreach ($rCategories as $cat): ?><option value="<?= (int) $cat['id']; ?>" <?= (RequestManager::has('category') && RequestManager::get('category') == $cat['id']) ? 'selected' : ''; ?>><?= htmlspecialchars((string) $cat['category_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
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
                                <option value="6"><?= $language::get('no_tmdb_match'); ?></option>
                                <option value="8"><?= $language::get('transcoding'); ?></option>
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
                                    <th><?= $language::get('category'); ?></th>
                                    <th><?= $language::get('servers'); ?></th>
                                    <th class="text-center"><?= $language::get('status'); ?></th>
                                    <th class="text-center"><?= $language::get('tmdb'); ?></th>
                                </tr>
                            </thead>
                            <tbody></tbody>
                        </table>
                    </div>
                </div>
                <!-- Details -->
                <div class="tab-pane fade" id="movie-details" role="tabpanel">
                    <p class="text-body-secondary"><?= $language::get('mass_edit_info'); ?></p>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="category_id" name="c_category_id"></div>
                        <label class="col-md-3 col-form-label" for="category_id"><?= $language::get('select_categories'); ?></label>
                        <div class="col-md-6">
                            <select disabled name="category_id[]" id="category_id" class="form-select" multiple="multiple" data-placeholder="<?= $language::get('choose_placeholder'); ?>">
                                <?php foreach ($rCategories as $cat): ?><option value="<?= (int) $cat['id']; ?>"><?= htmlspecialchars((string) $cat['category_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
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
                                <?php foreach (BouquetService::getAllSimple() as $bouquet): ?><option value="<?= (int) $bouquet['id']; ?>"><?= htmlspecialchars((string) $bouquet['bouquet_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
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
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="direct_proxy" name="c_direct_proxy"></div>
                        <label class="col-md-3 col-form-label" for="direct_proxy"><?= $language::get('direct_stream'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="direct_proxy" id="direct_proxy"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="movie_symlink" name="c_movie_symlink"></div>
                        <label class="col-md-3 col-form-label" for="movie_symlink"><?= $language::get('create_symlink'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="movie_symlink" id="movie_symlink"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="read_native" name="c_read_native"></div>
                        <label class="col-md-3 col-form-label" for="read_native"><?= $language::get('native_frames'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="read_native" id="read_native"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="remove_subtitles" name="c_remove_subtitles"></div>
                        <label class="col-md-3 col-form-label" for="remove_subtitles"><?= $language::get('remove_subtitles'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input disabled type="checkbox" value="1" class="form-check-input" name="remove_subtitles" id="remove_subtitles"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="target_container" name="c_target_container"></div>
                        <label class="col-md-3 col-form-label" for="target_container"><?= $language::get('target_container'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="target_container" id="target_container" class="form-select">
                                <?php foreach (['mp4', 'mkv', 'avi', 'mpg', 'flv', '3gp', 'm4v', 'wmv', 'mov', 'ts'] as $format): ?><option value="<?= $format; ?>"><?= $format; ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1 text-center"><input type="checkbox" class="form-check-input activate" data-name="transcode_profile_id" name="c_transcode_profile_id"></div>
                        <label class="col-md-3 col-form-label" for="transcode_profile_id"><?= $language::get('transcoding_profile'); ?></label>
                        <div class="col-md-8">
                            <select disabled name="transcode_profile_id" id="transcode_profile_id" class="form-select">
                                <option selected value="0"><?= $language::get('transcoding_disabled'); ?></option>
                                <?php foreach ($rTranscodeProfiles as $profile): ?><option value="<?= (int) $profile['profile_id']; ?>"><?= htmlspecialchars((string) $profile['profile_name'], ENT_QUOTES); ?></option><?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>
                <!-- Servers -->
                <div class="tab-pane fade" id="movie-servers" role="tabpanel">
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
                        <label class="col-md-3 col-form-label" for="reencode_on_edit"><?= $language::get('reencode_on_edit'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input type="checkbox" value="1" class="form-check-input" name="reencode_on_edit" id="reencode_on_edit"></div>
                        </div>
                    </div>
                    <div class="row mb-3 align-items-center">
                        <div class="col-md-1"></div>
                        <label class="col-md-3 col-form-label" for="reprocess_tmdb"><?= $language::get('reprocess_tmdb_data'); ?></label>
                        <div class="col-md-8">
                            <div class="form-check form-switch"><input type="checkbox" value="1" class="form-check-input" name="reprocess_tmdb" id="reprocess_tmdb"></div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="text-end mt-3"><button type="submit" class="btn btn-primary" name="submit_stream" value="1"><?= $language::get('edit_movies'); ?></button></div>
        </form>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
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
            return $('#movie_server_id').val();
        }

        function getFilter() {
            return $('#filter').val();
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
            }
        });

        // Load-balancer server tree (jstree).
        if ($.fn.jstree) {
            $('#server_tree').on('select_node.jstree', function(e, data) {
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
                            if (parent.id !== 'offline' && parent.id !== 'source') {
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

        // Client-side render from raw movie_list rows (no server HTML).
        function esc(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : s);
            return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        var VOD_BADGE = {
            0: ['secondary', 'Not Encoded'],
            1: ['success', 'Encoded'],
            2: ['warning', 'Encoding'],
            3: ['primary', 'Direct Source'],
            4: ['danger', 'Down'],
            5: ['info', 'Direct Stream']
        };
        function vodBadge(code) {
            var m = VOD_BADGE[code] || ['secondary', 'Unknown'];
            return '<span class="badge bg-label-' + m[0] + '">' + m[1] + '</span>';
        }
        function movieImage(url) {
            if (!url) {
                return '';
            }
            return '<a href="javascript:void(0);" data-src="resize?maxw=512&maxh=512&url=' + encodeURIComponent(url) +
                '"><img loading="lazy" src="resize?maxh=58&maxw=32&url=' + encodeURIComponent(url) + '"></a>';
        }
        function ratingStars(rating) {
            if (!rating) {
                return '';
            }
            var star = Math.round(rating) / 2;
            var full = Math.floor(star);
            var half = (star - full) > 0;
            var empty = 5 - (full + (half ? 1 : 0));
            var h = '';
            for (var i = 0; i < full; i++) {
                h += "<i class='mdi mdi-star'></i>";
            }
            if (half) {
                h += "<i class='mdi mdi-star-half'></i>";
            }
            for (var j = 0; j < empty; j++) {
                h += "<i class='mdi mdi-star-outline'></i>";
            }
            return h;
        }
        function movieName(row) {
            var year = row.year ? '<strong>' + esc(row.year) + '</strong> &nbsp;' : '';
            return esc(row.stream_display_name) + '<br><span style="font-size:11px;">' + year + ratingStars(row.rating) + '</span>';
        }
        function serverNameCell(name, count) {
            if (!name) {
                return 'No Server Selected';
            }
            var html = esc(name);
            if (count > 1) {
                html += ' &nbsp; <button type="button" class="btn btn-info btn-xs waves-effect waves-light">+ ' +
                    (count - 1) + '</button>';
            }
            return html;
        }
        function tmdbBadge(has) {
            if (has) {
                return '<i class="icon-base ti tabler-circle-check-filled text-success" title="TMDb"></i>';
            }
            return '<i class="icon-base ti tabler-circle-minus text-secondary" title="No TMDb"></i>';
        }
        var rTable = $('#datatable-mass').DataTable({
            serverSide: true,
            searchDelay: 250,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'movie_list';
                    d.category = getCategory();
                    d.filter = getFilter();
                    d.server = getServer();
                }
            },
            columns: [{
                data: 'id',
                className: 'text-center'
            }, {
                data: 'movie_image',
                orderable: false,
                className: 'text-center',
                render: function(d) {
                    return movieImage(d);
                }
            }, {
                data: 'stream_display_name',
                render: function(d, t, row) {
                    return movieName(row);
                }
            }, {
                data: 'category',
                render: function(d) {
                    return esc(d);
                }
            }, {
                data: 'server_name',
                render: function(d, t, row) {
                    return serverNameCell(d, row.server_count);
                }
            }, {
                data: 'status',
                className: 'text-center',
                render: function(d) {
                    return vodBadge(d);
                }
            }, {
                data: 'has_tmdb',
                orderable: false,
                className: 'text-center',
                render: function(d) {
                    return tmdbBadge(d);
                }
            }],
            rowCallback: function(row, data) {
                if (selected.indexOf(String(data.id)) !== -1) {
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
                topStart: null, // page size is driven by the toolbar #show_entries selector
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
        $('#category_search, #movie_server_id, #filter').on('change', function() {
            rTable.ajax.reload(null, false);
        });

        document.getElementById('mass-form').addEventListener('submit', function(e) {
            e.preventDefault();
            if (!selected.length) {
                toast(<?= json_encode($language::get('select_at_least_one_movie')); ?>, 'warning');
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
            fetch('post.php?action=movie_mass', {
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