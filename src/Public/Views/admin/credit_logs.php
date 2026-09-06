<?php

/**
 * Credit logs (Bootstrap 5). Clean-JSON table pattern: TableController::handleCreditsLog
 * returns structured rows (permission-gated owner/target links resolved into url
 * fields) and this page renders the cells client-side via datatables-bs5
 * columns[].render. Reseller (select2 ajax) + date-range filters post extra
 * ajax params.
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'credits_log')):
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
        <h5 class="card-title mb-0"><?= $language::get('credit_logs'); ?></h5>
    </div>
    <!-- Filters (Bootstrap 5 advanced-search layout: labelled fields in a grid). -->
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-reseller"><?= $language::get('reseller'); ?></label>
                <select id="filter-reseller" class="form-select"></select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-range"><?= $language::get('dates'); ?></label>
                <input type="text" id="filter-range" class="form-control flatpickr-range" placeholder="<?= $language::get('all_dates'); ?>" autocomplete="off">
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="credit-logs-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('owner'); ?></th>
                    <th><?= $language::get('target'); ?></th>
                    <th><?= $language::get('amount'); ?></th>
                    <th><?= $language::get('reason'); ?></th>
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
        var userLink = function(name, url) {
            if (!name) {
                return '';
            }
            return url ? '<a href="' + esc(url) + '" class="text-body">' + esc(name) + '</a>' : esc(name);
        };

        var table = jQuery('#credit-logs-table').DataTable({
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
                    d.id = 'credits_log';
                    d.reseller = jQuery('#filter-reseller').val() || '';
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
                    data: 'id',
                    className: 'text-nowrap'
                },
                {
                    data: 'owner_username',
                    render: function(d, t, row) {
                        return userLink(d, row.owner_url);
                    }
                },
                {
                    data: 'target_username',
                    responsivePriority: 3,
                    render: function(d, t, row) {
                        return userLink(d, row.target_url);
                    }
                },
                {
                    data: 'amount',
                    className: 'text-center',
                    render: function(d) {
                        return (d == null ? '' : Number(d).toLocaleString());
                    }
                },
                {
                    data: 'reason',
                    responsivePriority: 1,
                    render: function(d) {
                        return esc(d);
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

        // Date range filter.
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