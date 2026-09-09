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
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('panel_errors'); ?></h5>
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

        // Download JSON — wired to the topbar "Download log" button (the shell
        // renders the panel_logs action buttons; the endpoint removes the logs
        // after export). Clear Logs is handled generically in footer.php via the
        // shared #btn-clear-logs / #xcClearLogsModal wiring (data-log-type=panel_logs).
        var confirmText = <?= json_encode($language::get('clear_confirm')); ?>;
        var errText = <?= json_encode($language::get('error_occured')); ?>;
        var notify = function(msg) {
            if (window.xcToast) {
                window.xcToast(msg, 'error');
            } else {
                alert(msg);
            }
        };
        var dlBtn = document.getElementById('btn-download-log');
        if (dlBtn) dlBtn.addEventListener('click', function() {
            var proceed = window.xcConfirm ? window.xcConfirm(confirmText) : Promise.resolve(window.confirm(confirmText));
            proceed.then(function(ok) {
                if (!ok) {
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
                        notify(errText);
                    });
            });
        });
    })();
</script>
</body>

</html>