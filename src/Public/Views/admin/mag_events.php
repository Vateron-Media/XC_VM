<?php

/**
 * MAG event logs (Bootstrap 5). Clean-JSON table pattern:
 * TableController::handleMagEvents returns structured rows and this page renders
 * the cells client-side via datatables-bs5 columns[].render. The per-row delete
 * action is wired inline (api?action=mag_event&sub=delete).
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'manage_events')):
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
    #mag-events-table td.mag-event-msg {
        max-width: 32rem;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
</style>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('mag_event_logs'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="mag-events-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('date'); ?></th>
                    <th><?= $language::get('mac'); ?></th>
                    <th><?= $language::get('event'); ?></th>
                    <th><?= $language::get('message'); ?></th>
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
        var lang = {
            del: <?= json_encode($language::get('delete')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };

        var table = jQuery('#mag-events-table').DataTable({
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
                    d.id = 'mag_events';
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
                    render: function(d) {
                        return esc(fmtDate(d));
                    }
                },
                {
                    data: 'mac',
                    className: 'text-nowrap',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'event',
                    render: function(d) {
                        return '<span class="badge bg-label-secondary">' + esc(d) + '</span>';
                    }
                },
                {
                    data: 'msg',
                    className: 'mag-event-msg',
                    responsivePriority: 1,
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        return '<button type="button" class="btn btn-sm btn-icon btn-label-danger js-del" title="' + esc(lang.del) + '" data-id="' + esc(row.id) + '"><i class="icon-base ti tabler-trash"></i></button>';
                    }
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        // Delete a single MAG event.
        jQuery('#mag-events-table tbody').on('click', '.js-del', function() {
            var id = this.getAttribute('data-id');
            if (!id || !confirm(lang.del + '?')) {
                return;
            }
            fetch('./api?action=mag_event&sub=delete&mag_id=' + encodeURIComponent(id), {
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