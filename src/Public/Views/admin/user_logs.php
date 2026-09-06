<?php

/**
 * Reseller logs (Bootstrap 5). Clean-JSON table pattern:
 * TableController::handleRegUserLogs returns structured rows (server-composed
 * action text, permission-gated owner link, resolved target line/user/mag/enigma
 * or a deleted-info fallback) and this page renders the cells client-side via
 * datatables-bs5 columns[].render. Reseller / action / date-range filters post
 * extra ajax params.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Enum\ResellerAction;

if (!Authorization::check('adv', 'reg_userlog')):
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
        <h5 class="card-title mb-0"><?= $language::get('reseller_logs'); ?></h5>
    </div>
    <!-- Filters (Bootstrap 5 advanced-search layout: labelled fields in a grid). -->
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-reseller"><?= $language::get('reseller'); ?></label>
                <select id="filter-reseller" class="form-select"></select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-action"><?= $language::get('action'); ?></label>
                <select id="filter-action" class="form-select">
                    <option value=""><?= $language::get('all_actions'); ?></option>
                    <?php foreach (ResellerAction::options() as $rFilter => $rFilterName): ?>
                        <option value="<?= htmlspecialchars((string) $rFilter, ENT_QUOTES); ?>"><?= htmlspecialchars((string) $rFilterName, ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-range"><?= $language::get('dates'); ?></label>
                <input type="text" id="filter-range" class="form-control flatpickr-range" placeholder="<?= $language::get('all_dates'); ?>" autocomplete="off">
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="user-logs-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('owner'); ?></th>
                    <th><?= $language::get('target'); ?></th>
                    <th><?= $language::get('action'); ?></th>
                    <th><?= $language::get('cost'); ?></th>
                    <th><?= $language::get('credits_after'); ?></th>
                    <th><?= $language::get('date'); ?></th>
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

        var table = jQuery('#user-logs-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [6, 'desc']
            ],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'reg_user_logs';
                    d.reseller = jQuery('#filter-reseller').val() || '';
                    d.filter = document.getElementById('filter-action').value;
                    d.range = document.getElementById('filter-range').value;
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
                    data: 'owner',
                    render: function(d, t, row) {
                        return link(d, row.owner_url);
                    }
                },
                {
                    data: 'line_label',
                    responsivePriority: 3,
                    render: function(d, t, row) {
                        return link(d, row.line_url);
                    }
                },
                {
                    data: 'text',
                    responsivePriority: 1,
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'cost',
                    className: 'text-center',
                    render: function(d) {
                        return (d == null ? '' : Number(d).toLocaleString());
                    }
                },
                {
                    data: 'credits_after',
                    className: 'text-center',
                    render: function(d) {
                        return (d == null ? '' : Number(d).toLocaleString());
                    }
                },
                {
                    data: 'date',
                    className: 'text-nowrap',
                    render: function(d) {
                        return esc(fmtDate(d));
                    }
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        // Reseller filter (server-side searchable list via select2 ajax).
        if (jQuery.fn.select2) {
            jQuery('#filter-reseller').select2({
                width: '100%',
                allowClear: true,
                placeholder: <?= json_encode($language::get('reseller')); ?>,
                ajax: {
                    url: './api',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            search: params.term,
                            action: 'reguserlist',
                            page: params.page
                        };
                    },
                    processResults: function(data, params) {
                        params.page = params.page || 1;
                        return {
                            results: data.items,
                            pagination: {
                                more: (params.page * 100) < data.total_count
                            }
                        };
                    },
                    cache: true
                }
            }).on('change', function() {
                table.ajax.reload();
            });
        }

        document.getElementById('filter-action').addEventListener('change', function() {
            table.ajax.reload();
        });
        if (window.flatpickr) {
            flatpickr('#filter-range', {
                mode: 'range',
                dateFormat: 'Y-m-d',
                onChange: function(dates) {
                    if (dates.length === 2 || dates.length === 0) {
                        table.ajax.reload();
                    }
                }
            });
        } else {
            document.getElementById('filter-range').addEventListener('change', function() {
                table.ajax.reload();
            });
        }
    })();
</script>
</body>

</html>