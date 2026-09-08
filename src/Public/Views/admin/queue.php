<?php

/**
 * Encoding queue (Bootstrap 5). Clean-JSON table pattern: TableController::handleQueue
 * returns structured rows (permission-gated stream/server links resolved into url
 * fields, plus position + in_progress flag) and this page renders the cells
 * client-side via datatables-bs5 columns[].render. Stop/delete actions wired
 * inline (api?action=queue&sub=stop|delete).
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'movies') && !Authorization::check('adv', 'episodes') && !Authorization::check('adv', 'series')):
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
        <h5 class="card-title mb-0"><?= $language::get('encoding_queue'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="queue-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('position'); ?></th>
                    <th><?= $language::get('stream'); ?></th>
                    <th><?= $language::get('server'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('added'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
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
        var link = function(name, url) {
            if (!name) {
                return '';
            }
            return url ? '<a href="' + esc(url) + '" class="text-body">' + esc(name) + '</a>' : esc(name);
        };
        var lang = {
            inProgress: <?= json_encode($language::get('in_progress')); ?>,
            queued: <?= json_encode($language::get('queued')); ?>,
            stop: <?= json_encode($language::get('stop')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };

        var table = jQuery('#queue-table').DataTable({
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
                    d.id = 'queue';
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
                    data: 'position',
                    className: 'text-center'
                },
                {
                    data: 'stream_name',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        return link(d, row.stream_url);
                    }
                },
                {
                    data: 'server_name',
                    render: function(d, t, row) {
                        return link(d, row.server_url);
                    }
                },
                {
                    data: 'in_progress',
                    className: 'text-center',
                    render: function(d) {
                        return d ?
                            '<span class="badge bg-label-info text-uppercase">' + esc(lang.inProgress) + '</span>' :
                            '<span class="badge bg-label-secondary">' + esc(lang.queued) + '</span>';
                    }
                },
                {
                    data: 'added',
                    className: 'text-nowrap',
                    render: function(d) {
                        return esc(fmtDate(d));
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        if (row.in_progress) {
                            return '<button type="button" class="btn btn-sm btn-icon btn-label-warning js-act" data-sub="stop" title="' + esc(lang.stop) + '" data-id="' + esc(row.id) + '"><i class="icon-base ti tabler-player-stop"></i></button>';
                        }
                        return '<button type="button" class="btn btn-sm btn-icon btn-label-danger js-act" data-sub="delete" title="' + esc(lang.del) + '" data-id="' + esc(row.id) + '"><i class="icon-base ti tabler-trash"></i></button>';
                    }
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        jQuery('#queue-table tbody').on('click', '.js-act', function() {
            var id = this.getAttribute('data-id');
            var sub = this.getAttribute('data-sub');
            if (!id || !confirm((sub === 'stop' ? lang.stop : lang.del) + '?')) {
                return;
            }
            fetch('./api?action=queue&sub=' + encodeURIComponent(sub) + '&id=' + encodeURIComponent(id), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    if (!data || data.result !== true) {
                        throw new Error('fail');
                    }
                    table.ajax.reload(null, false);
                })
                .catch(function() {
                    alert(lang.error);
                });
        });
    })();
</script>
</body>

</html>