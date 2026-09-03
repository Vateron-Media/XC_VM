<?php

/**
 * On-Demand scanner (Bootstrap 5). Clean-JSON table pattern: the data gathering in
 * TableController::handleOndemand (with the up/down check aggregation) is
 * unchanged; only the row shape is now a structured JSON object. This page
 * renders the cells client-side via datatables-bs5 columns[].render. Server /
 * category / status filters post extra ajax params.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;

if (!Authorization::check('adv', 'streams')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('ondemand_scanner'); ?></h5>
    </div>
    <!-- Filters (Bootstrap 5 advanced-search layout: labelled fields in a grid). -->
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-server"><?= $language::get('server'); ?></label>
                <select id="filter-server" class="form-select">
                    <option value=""><?= $language::get('all_servers'); ?></option>
                    <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                        <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-category"><?= $language::get('category'); ?></label>
                <select id="filter-category" class="form-select">
                    <option value=""><?= $language::get('all_categories'); ?></option>
                    <?php foreach (CategoryService::getAllByType('live') as $rCatId => $rCat): ?>
                        <option value="<?= (int) $rCatId; ?>"><?= htmlspecialchars((string) $rCat['category_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-status"><?= $language::get('status'); ?></label>
                <select id="filter-status" class="form-select">
                    <option value=""><?= $language::get('no_filter'); ?></option>
                    <option value="1"><?= $language::get('ready'); ?></option>
                    <option value="2"><?= $language::get('down'); ?></option>
                    <option value="3"><?= $language::get('not_scanned'); ?></option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="ondemand-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('icon'); ?></th>
                    <th><?= $language::get('stream'); ?></th>
                    <th><?= $language::get('server'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('response'); ?></th>
                    <th><?= $language::get('stream_info'); ?></th>
                    <th><?= $language::get('last_scanned'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
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
        var lang = {
            ready: <?= json_encode($language::get('ready')); ?>,
            down: <?= json_encode($language::get('down')); ?>,
            notScanned: <?= json_encode($language::get('not_scanned')); ?>,
            never: <?= json_encode($language::get('never') ?: 'Never'); ?>,
            noInfo: <?= json_encode($language::get('no_information') ?: 'No information available'); ?>
        };

        var table = jQuery('#ondemand-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [1, 'asc']
            ],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'ondemand';
                    d.server = document.getElementById('filter-server').value;
                    d.category = document.getElementById('filter-category').value;
                    d.filter = document.getElementById('filter-status').value;
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
                    render: function(d, t, row) {
                        return '<a href="' + esc(row.stream_url) + '" class="text-body">' + esc(d) + '</a>';
                    }
                },
                {
                    data: 'icon',
                    orderable: false,
                    render: function(d) {
                        if (!d) {
                            return '';
                        }
                        return '<img loading="lazy" src="resize?maxw=96&maxh=32&url=' + encodeURIComponent(d) + '" alt="">';
                    }
                },
                {
                    data: 'stream_name',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        var html = '<a href="' + esc(row.stream_url) + '" class="text-body fw-medium">' + esc(d) + '</a>';
                        if (row.category) {
                            html += '<br><small class="text-body-secondary">' + esc(row.category) + '</small>';
                        }
                        return html;
                    }
                },
                {
                    data: 'server_name',
                    render: function(d, t, row) {
                        if (!d) {
                            return '<span class="text-body-secondary">—</span>';
                        }
                        return row.server_url ? '<a href="' + esc(row.server_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                    }
                },
                {
                    data: 'status',
                    className: 'text-center text-nowrap',
                    render: function(d, t, row) {
                        var cls, title;
                        if (d == null) {
                            cls = 'text-secondary';
                            title = lang.notScanned;
                        } else if (d === 1) {
                            cls = 'text-success';
                            title = lang.ready;
                        } else {
                            cls = 'text-danger';
                            title = row.errors ? row.errors : lang.down;
                        }
                        var sq = '<i class="icon-base ti tabler-square-filled ' + cls + '" title="' + esc(title) + '"></i>';
                        var checks = '<span class="badge bg-label-secondary ms-1">' + (row.up_checks || 0) +
                            ' &uarr; ' + (row.down_checks || 0) + ' &darr;</span>';
                        return sq + ' ' + checks;
                    }
                },
                {
                    data: 'response',
                    className: 'text-center text-nowrap',
                    render: function(d) {
                        return d ? (Number(d).toLocaleString() + ' ms') : '--';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    render: function(d, t, row) {
                        if (!row.resolution && !row.video_codec && !row.audio_codec && !row.fps) {
                            return '<small class="text-body-secondary">' + esc(lang.noInfo) + '</small>';
                        }
                        return '<div class="d-flex flex-wrap gap-1">' +
                            '<span class="badge bg-label-primary">' + esc(row.resolution ? row.resolution + 'p' : 'N/A') + '</span>' +
                            '<span class="badge bg-label-info">' + esc(row.video_codec || 'N/A') + '</span>' +
                            '<span class="badge bg-label-success">' + esc(row.audio_codec || 'N/A') + '</span>' +
                            '<span class="badge bg-label-secondary">' + esc(row.fps ? row.fps + ' FPS' : 'N/A') + '</span>' +
                            '</div>';
                    }
                },
                {
                    data: 'last_check',
                    className: 'text-nowrap',
                    render: function(d) {
                        return d ? esc(fmtDate(d)) : lang.never;
                    }
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        document.getElementById('filter-server').addEventListener('change', function() {
            table.ajax.reload();
        });
        document.getElementById('filter-category').addEventListener('change', function() {
            table.ajax.reload();
        });
        document.getElementById('filter-status').addEventListener('change', function() {
            table.ajax.reload();
        });
    })();
</script>
</body>

</html>
