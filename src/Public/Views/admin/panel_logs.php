<?php

/**
 * Panel logs (Bootstrap 5). First page on the clean-JSON table pattern: the ./table
 * endpoint (TableController::handlePanelLogs) returns structured rows and this
 * page renders the cells client-side via datatables-bs5 columns[].render — no
 * server-rendered HTML, no positional columns.
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'panel_logs')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;
?>

<style>
    /* Keep the free-form log message from stretching the table: cap its width and wrap. */
    #panel-logs-table td.panel-log-msg {
        max-width: 32rem;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
</style>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('panel_errors'); ?></h5>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-sm btn-label-secondary" id="btn-download-log">
                <i class="icon-base ti tabler-download me-1"></i><?= $language::get('panel_logs_download'); ?>
            </button>
            <button type="button" class="btn btn-sm btn-label-danger" id="btn-clear-logs">
                <i class="icon-base ti tabler-trash me-1"></i><?= $language::get('clear_logs'); ?>
            </button>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="panel-logs-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('date'); ?></th>
                    <th><?= $language::get('server'); ?></th>
                    <th><?= $language::get('type'); ?></th>
                    <th><?= $language::get('message'); ?></th>
                    <th><?= $language::get('line'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- Clear logs by date range -->
<div class="modal fade" id="clearLogsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('clear_logs'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row g-3">
                    <div class="col-6">
                        <label class="form-label" for="clear_from"><?= $language::get('from'); ?></label>
                        <input type="text" class="form-control" id="clear_from" autocomplete="off">
                    </div>
                    <div class="col-6">
                        <label class="form-label" for="clear_to"><?= $language::get('to'); ?></label>
                        <input type="text" class="form-control" id="clear_to" autocomplete="off">
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="clear_logs_confirm"><?= $language::get('clear_logs'); ?></button>
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

        var table = jQuery('#panel-logs-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [1, 'desc']
            ],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'panel_logs';
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
                    data: 'date',
                    className: 'text-nowrap',
                    responsivePriority: 1,
                    render: function(d) {
                        return esc(fmtDate(d));
                    }
                },
                {
                    data: 'server_name',
                    render: function(d, t, row) {
                        return '<a href="server_view?id=' + encodeURIComponent(row.server_id) + '" class="text-body">' + esc(d) + '</a>';
                    }
                },
                {
                    data: 'type',
                    className: 'text-center',
                    render: function(d) {
                        return '<span class="badge bg-label-secondary text-uppercase">' + esc(d) + '</span>';
                    }
                },
                {
                    data: 'message',
                    className: 'panel-log-msg',
                    responsivePriority: 3,
                    render: function(d, t, row) {
                        var m = esc(d);
                        if (row.extra) {
                            m += '<br><small class="text-body-secondary">' + esc(row.extra) + '</small>';
                        }
                        return m;
                    }
                },
                {
                    data: 'line',
                    className: 'text-center'
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        // Clear logs by date range.
        var fpOpts = {
            dateFormat: 'Y-m-d',
            allowInput: true
        };
        if (window.flatpickr) {
            flatpickr('#clear_from', fpOpts);
            flatpickr('#clear_to', fpOpts);
        }
        document.getElementById('btn-clear-logs').addEventListener('click', function() {
            bootstrap.Modal.getOrCreateInstance(document.getElementById('clearLogsModal')).show();
        });
        document.getElementById('clear_logs_confirm').addEventListener('click', function() {
            var from = document.getElementById('clear_from').value;
            var to = document.getElementById('clear_to').value;
            fetch('./api?action=clear_logs&type=panel_logs&from=' + encodeURIComponent(from) + '&to=' + encodeURIComponent(to), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .catch(function() {
                    /* ignore */
                })
                .finally(function() {
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('clearLogsModal')).hide();
                    table.ajax.reload(null, false);
                });
        });

        // Download JSON (the endpoint removes the logs after export).
        document.getElementById('btn-download-log').addEventListener('click', function() {
            if (!confirm(<?= json_encode($language::get('clear_confirm')); ?>)) {
                return;
            }
            fetch('./api?action=download_panel_logs', {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(data) {
                    var blob = new Blob([JSON.stringify(data.data || [], null, 2)], {
                        type: 'application/json'
                    });
                    var url = URL.createObjectURL(blob);
                    var a = document.createElement('a');
                    a.href = url;
                    a.download = 'panel_logs.json';
                    document.body.appendChild(a);
                    a.click();
                    document.body.removeChild(a);
                    URL.revokeObjectURL(url);
                    table.ajax.reload(null, false);
                })
                .catch(function() {
                    alert(<?= json_encode($language::get('error_occured')); ?>);
                });
        });
    })();
</script>
</body>

</html>