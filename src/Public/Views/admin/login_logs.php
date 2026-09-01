<?php

/**
 * Login logs (Vuexy). Clean-JSON table pattern: TableController::handleLoginLogs
 * returns structured rows and this page renders the cells client-side via
 * datatables-bs5 columns[].render — no server-rendered HTML, no positional
 * columns. IP whois lookup and the block-IP action are wired inline (no legacy
 * listings.js / jBox).
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'login_logs')):
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
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('login_logs'); ?></h5>
    </div>
    <div class="card-datatable table-responsive">
        <table id="login-logs-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('date'); ?></th>
                    <th><?= $language::get('type'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('access_code'); ?></th>
                    <th><?= $language::get('ip'); ?></th>
                    <th><?= $language::get('actions'); ?></th>
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

        var lang = {
            block: <?= json_encode($language::get('login_logs_block')); ?>,
            blocked: <?= json_encode($language::get('login_logs_blocked')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };

        var table = jQuery('#login-logs-table').DataTable({
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
                    d.id = 'login_logs';
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
                    data: 'type',
                    className: 'text-center',
                    render: function(d) {
                        return '<span class="badge bg-label-secondary text-uppercase">' + esc(d) + '</span>';
                    }
                },
                {
                    data: 'status',
                    className: 'text-center',
                    render: function(d) {
                        var s = (d == null ? '' : String(d)).toLowerCase();
                        var cls = 'bg-label-secondary';
                        if (s.indexOf('fail') > -1 || s.indexOf('error') > -1 || s.indexOf('denied') > -1 || s.indexOf('block') > -1) {
                            cls = 'bg-label-danger';
                        } else if (s.indexOf('success') > -1 || s.indexOf('ok') > -1 || s.indexOf('grant') > -1 || s.indexOf('allow') > -1) {
                            cls = 'bg-label-success';
                        }
                        return '<span class="badge ' + cls + '">' + esc(d) + '</span>';
                    }
                },
                {
                    data: 'username',
                    responsivePriority: 3,
                    render: function(d, t, row) {
                        if (!d) {
                            return '';
                        }
                        if (row.user_id > 0) {
                            return '<a href="user?id=' + encodeURIComponent(row.user_id) + '" class="text-body">' + esc(d) + '</a>';
                        }
                        return esc(d);
                    }
                },
                {
                    data: 'code',
                    render: function(d) {
                        return d ? esc(d) : '';
                    }
                },
                {
                    data: 'login_ip',
                    className: 'text-nowrap',
                    render: function(d) {
                        if (isLocal(d)) {
                            return '<span class="text-body-secondary">localhost</span>';
                        }
                        return '<a href="javascript:void(0);" class="text-body js-whois" data-ip="' + esc(d) + '">' + esc(d) + '</a>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        if (isLocal(row.login_ip)) {
                            return '<button type="button" class="btn btn-sm btn-icon btn-label-secondary" disabled><i class="icon-base ti tabler-hammer"></i></button>';
                        }
                        if (row.blocked) {
                            return '<button type="button" class="btn btn-sm btn-icon btn-label-secondary" title="' + esc(lang.blocked) + '" disabled><i class="icon-base ti tabler-hammer"></i></button>';
                        }
                        return '<button type="button" class="btn btn-sm btn-icon btn-label-danger js-block" title="' + esc(lang.block) + '" data-ip="' + esc(row.login_ip) + '"><i class="icon-base ti tabler-hammer"></i></button>';
                    }
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        // Block a brute-forcing IP.
        jQuery('#login-logs-table tbody').on('click', '.js-block', function() {
            var ip = this.getAttribute('data-ip');
            if (!ip || !confirm(<?= json_encode($language::get('login_logs_block_confirm')); ?>)) {
                return;
            }
            fetch('./api?action=mysql_syslog&sub=block&ip=' + encodeURIComponent(ip), {
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

        // Whois lookup (GeoIP / ISP / ASN) in a modal.
        jQuery('#login-logs-table tbody').on('click', '.js-whois', function() {
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
                    body.innerHTML = '<div class="alert alert-danger mb-0">' + esc(lang.error) + '</div>';
                });
        });
    })();
</script>
</body>

</html>
