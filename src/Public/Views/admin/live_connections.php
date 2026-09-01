<?php

/**
 * Live connections (Vuexy). Clean-JSON table pattern: the data gathering in
 * TableController::handleLiveConnections (Redis or MySQL backed) is unchanged;
 * only the row shape is now a structured JSON object. This page renders the
 * cells client-side via datatables-bs5 columns[].render (divergence colour,
 * live duration, country flag, IP whois) and periodically reloads for a live
 * view. Kill wired inline (api?action=line_activity&sub=kill).
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Server\ServerRepository;

if (!Authorization::check('adv', 'live_connections')):
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
        <h5 class="card-title mb-0"><?= $language::get('live_connections'); ?></h5>
    </div>
    <!-- Filters (Vuexy advanced-search layout: labelled fields in a grid). -->
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-user"><?= $language::get('line'); ?></label>
                <select id="filter-user" class="form-select"></select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-stream"><?= $language::get('stream'); ?></label>
                <select id="filter-stream" class="form-select"></select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-server"><?= $language::get('server'); ?></label>
                <select id="filter-server" class="form-select">
                    <option value=""><?= $language::get('all_servers'); ?></option>
                    <?php foreach (ServerRepository::getAll() as $rServer): ?>
                        <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-type"><?= $language::get('type'); ?></label>
                <select id="filter-type" class="form-select">
                    <option value=""><?= $language::get('all_connections'); ?></option>
                    <option value="1"><?= $language::get('direct'); ?></option>
                    <option value="2"><?= $language::get('mag_devices'); ?></option>
                    <option value="3"><?= $language::get('enigma_devices'); ?></option>
                    <option value="4"><?= $language::get('trial'); ?></option>
                    <option value="5"><?= $language::get('restreamer'); ?></option>
                    <option value="6"><?= $language::get('stalker'); ?></option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="live-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('divergence'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('stream'); ?></th>
                    <th><?= $language::get('server'); ?></th>
                    <th><?= $language::get('player'); ?></th>
                    <th><?= $language::get('isp'); ?></th>
                    <th><?= $language::get('ip'); ?></th>
                    <th><?= $language::get('duration'); ?></th>
                    <th><?= $language::get('container'); ?></th>
                    <th><?= $language::get('restreamer'); ?></th>
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
        var isLocal = function(ip) {
            return !ip || ip === '127.0.0.1' || ip === '::1';
        };
        var pad = function(n) {
            return (n < 10 ? '0' : '') + n;
        };
        var fmtDuration = function(startTs, isRestreamer) {
            var sec = Math.max(0, Math.floor(Date.now() / 1000) - (startTs || 0));
            var colour = 'success',
                txt;
            if (sec >= 86400) {
                txt = pad(Math.floor(sec / 86400)) + 'd ' + pad(Math.floor(sec / 3600) % 24) + 'h';
                colour = 'danger';
            } else if (sec >= 3600) {
                txt = pad(Math.floor(sec / 3600)) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm';
                if (sec > 14400) {
                    colour = 'warning';
                }
            } else {
                txt = pad(Math.floor(sec / 60) % 60) + 'm ' + pad(sec % 60) + 's';
            }
            if (isRestreamer) {
                colour = 'success';
            }
            return '<span class="badge bg-label-' + colour + '">' + esc(txt) + '</span>';
        };
        var lang = {
            kill: <?= json_encode($language::get('kill')); ?>,
            fingerprint: <?= json_encode($language::get('fingerprint')); ?>,
            killed: <?= json_encode($language::get('connection_has_been_killed')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };

        var table = jQuery('#live-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [9, 'desc']
            ],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'live_connections';
                    d.user_id = jQuery('#filter-user').val() || '';
                    d.stream_id = jQuery('#filter-stream').val() || '';
                    d.server_id = document.getElementById('filter-server').value;
                    d.filter = document.getElementById('filter-type').value;
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
                    data: 'activity_id',
                    visible: false
                },
                {
                    data: 'divergence',
                    className: 'text-center',
                    render: function(d) {
                        var pct = 100 - (d || 0);
                        var cls = d <= 50 ? 'text-success' : (d <= 80 ? 'text-warning' : 'text-danger');
                        return '<i class="icon-base ti tabler-square-filled ' + cls + '" title="' + pct + '%"></i>';
                    }
                },
                {
                    data: 'user_label',
                    responsivePriority: 1,
                    render: function(d, t, row) {
                        if (!d) {
                            return '';
                        }
                        return row.user_url ? '<a href="' + esc(row.user_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                    }
                },
                {
                    data: 'stream_name',
                    responsivePriority: 3,
                    render: function(d, t, row) {
                        if (!d) {
                            return '';
                        }
                        return row.stream_url ? '<a href="' + esc(row.stream_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                    }
                },
                {
                    data: 'server_name',
                    render: function(d, t, row) {
                        var html = row.server_url ? '<a href="' + esc(row.server_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d);
                        if (row.proxy_via) {
                            html += '<br><small class="text-body-secondary">(via ' + esc(row.proxy_via) + ')</small>';
                        }
                        return html;
                    }
                },
                {
                    data: 'player'
                },
                {
                    data: 'isp'
                },
                {
                    data: 'user_ip',
                    className: 'text-nowrap',
                    render: function(d, t, row) {
                        var flag = row.country ? '<img loading="lazy" class="me-1" src="assets/old/images/countries/' + esc(row.country) + '.png" alt="">' : '';
                        if (isLocal(d)) {
                            return flag + '<span class="text-body-secondary">' + esc(d || '') + '</span>';
                        }
                        return flag + '<a href="javascript:void(0);" class="text-body js-whois" data-ip="' + esc(d) + '">' + esc(d) + '</a>';
                    }
                },
                {
                    data: 'date_start',
                    className: 'text-center',
                    render: function(d, t, row) {
                        return fmtDuration(d, row.is_restreamer);
                    }
                },
                {
                    data: 'container',
                    className: 'text-center'
                },
                {
                    data: 'is_restreamer',
                    className: 'text-center',
                    render: function(d) {
                        return d ? '<i class="icon-base ti tabler-square-filled text-info"></i>' :
                            '<i class="icon-base ti tabler-square-filled text-body-secondary"></i>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center text-nowrap',
                    render: function(d, t, row) {
                        var html = '<button type="button" class="btn btn-sm btn-icon btn-label-danger js-kill" title="' + esc(lang.kill) + '" data-uuid="' + esc(row.uuid) + '"><i class="icon-base ti tabler-hammer"></i></button>';
                        if (row.can_fingerprint && window.modalFingerprint) {
                            html += ' <button type="button" class="btn btn-sm btn-icon btn-label-secondary js-fp" title="' + esc(lang.fingerprint) + '" data-user="' + esc(row.user_id) + '"><i class="icon-base ti tabler-fingerprint"></i></button>';
                        }
                        return html;
                    }
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        // select2 ajax filters (line + stream).
        function select2ajax(sel, action, placeholder) {
            if (!jQuery.fn.select2) {
                return;
            }
            jQuery(sel).select2({
                width: '100%',
                allowClear: true,
                placeholder: placeholder,
                ajax: {
                    url: './api',
                    dataType: 'json',
                    delay: 250,
                    data: function(params) {
                        return {
                            search: params.term,
                            action: action,
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
        select2ajax('#filter-user', 'userlist', <?= json_encode($language::get('line')); ?>);
        select2ajax('#filter-stream', 'streamlist', <?= json_encode($language::get('stream')); ?>);
        document.getElementById('filter-server').addEventListener('change', function() {
            table.ajax.reload();
        });
        document.getElementById('filter-type').addEventListener('change', function() {
            table.ajax.reload();
        });

        // Kill a connection.
        jQuery('#live-table tbody').on('click', '.js-kill', function() {
            var uuid = this.getAttribute('data-uuid');
            if (!uuid) {
                return;
            }
            fetch('./api?action=line_activity&sub=kill&pid=' + encodeURIComponent(uuid), {
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
        jQuery('#live-table tbody').on('click', '.js-fp', function() {
            if (window.modalFingerprint) {
                window.modalFingerprint(this.getAttribute('data-user'), 'user');
            }
        });

        // Live refresh every 5s (pause while a modal is open).
        setInterval(function() {
            if (!document.querySelector('.modal.show')) {
                table.ajax.reload(null, false);
            }
        }, 5000);

        // Whois lookup (GeoIP / ISP / ASN) in a modal.
        jQuery('#live-table tbody').on('click', '.js-whois', function() {
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
