<?php

/**
 * Client logs (Bootstrap 5). Clean-JSON table pattern: TableController::handleClientLogs
 * returns structured rows (permission-gated links resolved into url fields, the
 * reason label from ClientFilter) and this page renders the cells client-side via
 * datatables-bs5 columns[].render. Reason + date-range filters post extra ajax
 * params; IP whois is wired inline (no legacy listings.js / jBox).
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Enum\ClientFilter;

if (!Authorization::check('adv', 'client_request_log')):
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
    #client-logs-table td.client-ua {
        max-width: 22rem;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
</style>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('client_logs'); ?></h5>
    </div>
    <!-- Filters (Bootstrap 5 advanced-search layout: labelled fields in a grid). -->
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-reason"><?= $language::get('reason'); ?></label>
                <select id="filter-reason" class="form-select">
                    <option value=""><?= $language::get('all_reasons'); ?></option>
                    <?php foreach (ClientFilter::options() as $rFilter => $rFilterName): ?>
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
        <table id="client-logs-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('stream'); ?></th>
                    <th><?= $language::get('reason'); ?></th>
                    <th><?= $language::get('user_agent'); ?></th>
                    <th><?= $language::get('ip'); ?></th>
                    <th><?= $language::get('date'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<!-- Whois lookup -->
<div class="modal fade" id="whoisModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><?= $language::get('login_logs_whois'); ?> — <span id="whois-ip"></span></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body" id="whois-body"></div>
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
        var isLocal = function(ip) {
            return !ip || ip === '127.0.0.1' || ip === '::1';
        };
        var errMsg = <?= json_encode($language::get('error_occured')); ?>;

        var table = jQuery('#client-logs-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [7, 'desc']
            ],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'client_logs';
                    d.filter = document.getElementById('filter-reason').value;
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
                    data: 'username',
                    responsivePriority: 3,
                    render: function(d, t, row) {
                        if (!d) {
                            return '';
                        }
                        return row.user_url ?
                            '<a href="' + esc(row.user_url) + '" class="text-body">' + esc(d) + '</a>' :
                            esc(d);
                    }
                },
                {
                    data: 'stream_name',
                    render: function(d, t, row) {
                        if (!d) {
                            return '';
                        }
                        return row.stream_url ?
                            '<a href="' + esc(row.stream_url) + '" class="text-body">' + esc(d) + '</a>' :
                            esc(d);
                    }
                },
                {
                    data: 'reason',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        var html = '<span class="badge bg-label-secondary">' + esc(d) + '</span>';
                        if (row.extra) {
                            html += ' <i class="icon-base ti tabler-info-circle text-primary align-middle" title="' + esc(row.extra) + '"></i>';
                        }
                        return html;
                    }
                },
                {
                    data: 'user_agent',
                    className: 'client-ua',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'ip',
                    className: 'text-nowrap',
                    render: function(d) {
                        if (isLocal(d)) {
                            return '<span class="text-body-secondary">localhost</span>';
                        }
                        return '<a href="javascript:void(0);" class="text-body js-whois" data-ip="' + esc(d) + '">' + esc(d) + '</a>';
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

        // Reason filter + date range re-query server-side.
        document.getElementById('filter-reason').addEventListener('change', function() {
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

        // Whois lookup (GeoIP / ISP / ASN) in a modal.
        jQuery('#client-logs-table tbody').on('click', '.js-whois', function() {
            var ip = this.getAttribute('data-ip');
            var body = document.getElementById('whois-body');
            document.getElementById('whois-ip').textContent = ip;
            body.innerHTML = '<div class="text-center py-3"><span class="spinner-border" role="status"></span></div>';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('whoisModal')).show();
            fetch('./api?action=ip_whois&isp=1&ip=' + encodeURIComponent(ip), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(w) {
                    var rows = [];
                    var add = function(label, val) {
                        if (val) {
                            rows.push('<dt class="col-4 text-body-secondary">' + esc(label) + '</dt><dd class="col-8">' + esc(val) + '</dd>');
                        }
                    };
                    add(<?= json_encode($language::get('country')); ?>, w && w.country && w.country.names && w.country.names.en);
                    add(<?= json_encode($language::get('city')); ?>, w && w.city && w.city.names && w.city.names.en);
                    add(<?= json_encode($language::get('isp')); ?>, w && w.isp && (w.isp.isp || w.isp.organization));
                    add('ASN', w && w.isp && w.isp.autonomous_system_number);
                    add(<?= json_encode($language::get('type')); ?>, w && w.type);
                    body.innerHTML = rows.length ? '<dl class="row mb-0">' + rows.join('') + '</dl>' :
                        '<div class="text-center text-body-secondary py-2">—</div>';
                })
                .catch(function() {
                    body.innerHTML = '<div class="alert alert-danger mb-0">' + esc(errMsg) + '</div>';
                });
        });
    })();
</script>
</body>

</html>
