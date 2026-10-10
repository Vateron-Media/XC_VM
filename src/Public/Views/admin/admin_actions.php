<?php

/**
 * Admin Actions (Bootstrap 5): the admin action trail (Core\Audit\AdminAudit),
 * from TableController::handleAdminActions as structured rows rendered
 * client-side. Read only: the trail cannot be cleared from the panel.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Util\LayoutRenderer;

if (!Authorization::check('adv', 'admin_audit')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    LayoutRenderer::renderFooter('admin');
    echo '</body></html>';
    return;
endif;
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-1"><?= $language::get('admin_actions'); ?></h5>
        <p class="mb-0 text-body-secondary"><?= $language::get('admin_actions_intro'); ?></p>
    </div>
    <div class="card-datatable table-responsive">
        <table id="admin-actions-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('date'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('ip'); ?></th>
                    <th><?= $language::get('admin_actions_source'); ?></th>
                    <th><?= $language::get('admin_actions_action'); ?></th>
                    <th><?= $language::get('admin_actions_result'); ?></th>
                    <th><?= $language::get('admin_actions_detail'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var esc = function(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : String(s));
            return d.innerHTML.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        };
        var results = {
            1: ['bg-label-success', <?= json_encode($language::get('admin_actions_ok')); ?>],
            0: ['bg-label-danger', <?= json_encode($language::get('admin_actions_failed')); ?>]
        };

        jQuery('#admin-actions-table').DataTable({
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
                    d.id = 'admin_actions';
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
                        return d ? esc(new Date(d * 1000).toLocaleString()) : '';
                    }
                },
                {
                    data: 'username',
                    responsivePriority: 3,
                    render: function(d, t, row) {
                        if (row.user_id > 0) {
                            return '<a href="user?id=' + encodeURIComponent(row.user_id) + '" class="text-body">' + esc(d || row.user_id) + '</a>';
                        }
                        return esc(d);
                    }
                },
                {
                    data: 'ip',
                    className: 'text-nowrap',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'source',
                    className: 'text-center',
                    render: function(d) {
                        return '<span class="badge bg-label-secondary text-uppercase">' + esc(d) + '</span>';
                    }
                },
                {
                    data: 'action',
                    responsivePriority: 1,
                    render: function(d) {
                        return '<code>' + esc(d) + '</code>';
                    }
                },
                {
                    data: 'result',
                    className: 'text-center',
                    render: function(d) {
                        var r = results[d];
                        return r ? '<span class="badge ' + r[0] + '">' + esc(r[1]) + '</span>' : '<span class="text-body-secondary">—</span>';
                    }
                },
                {
                    data: 'detail',
                    orderable: false,
                    render: function(d) {
                        return Object.keys(d || {}).map(function(k) {
                            return '<span class="text-body-secondary">' + esc(k) + '</span>=' + esc(d[k]);
                        }).join(' · ');
                    }
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });
    })();
</script>
</body>

</html>
