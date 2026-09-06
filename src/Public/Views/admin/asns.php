<?php

/**
 * Blocked ASNs (Bootstrap 5). Clean-JSON table pattern: TableController::handleAsns
 * returns structured rows and this page renders the cells client-side via
 * datatables-bs5 columns[].render (country flag, status badge, block/allow
 * toggle). Type + status filters post extra ajax params.
 */

use XcVm\Core\Auth\Authorization;

if (!Authorization::check('adv', 'block_isps')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rTypes = ['isp' => 'isp', 'hosting' => 'hosting', 'education' => 'education', 'business' => 'business'];
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('blocked_asns'); ?></h5>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-type"><?= $language::get('type'); ?></label>
                <select id="filter-type" class="form-select">
                    <option value=""><?= $language::get('all_types'); ?></option>
                    <?php foreach ($rTypes as $rValue => $rKey): ?>
                        <option value="<?= htmlspecialchars($rValue, ENT_QUOTES); ?>"><?= $language::get($rKey); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-status"><?= $language::get('status'); ?></label>
                <select id="filter-status" class="form-select">
                    <option value=""><?= $language::get('no_filter'); ?></option>
                    <option value="1"><?= $language::get('blocked_btn'); ?></option>
                    <option value="0"><?= $language::get('allowed'); ?></option>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="asns-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('asn'); ?></th>
                    <th><?= $language::get('isp'); ?></th>
                    <th><?= $language::get('domain'); ?></th>
                    <th><?= $language::get('country'); ?></th>
                    <th><?= $language::get('num_ips'); ?></th>
                    <th><?= $language::get('type'); ?></th>
                    <th><?= $language::get('status'); ?></th>
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
        var lang = {
            blocked: <?= json_encode($language::get('blocked_btn')); ?>,
            allowed: <?= json_encode($language::get('allowed')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>
        };

        var table = jQuery('#asns-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [5, 'desc']
            ],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'asns';
                    d.type = document.getElementById('filter-type').value;
                    d.filter = document.getElementById('filter-status').value;
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
                    data: 'asn',
                    className: 'text-nowrap',
                    responsivePriority: 1
                },
                {
                    data: 'isp'
                },
                {
                    data: 'domain'
                },
                {
                    data: 'country',
                    className: 'text-center',
                    render: function(d) {
                        return d ? '<img loading="lazy" src="assets/img/countries/' + esc(d) + '.png" alt="' + esc(d) + '">' : '';
                    }
                },
                {
                    data: 'num_ips',
                    className: 'text-center',
                    render: function(d) {
                        return (d == null ? '' : Number(d).toLocaleString());
                    }
                },
                {
                    data: 'type',
                    className: 'text-center'
                },
                {
                    data: 'blocked',
                    className: 'text-center',
                    render: function(d) {
                        return d ?
                            '<span class="badge bg-label-danger">' + esc(lang.blocked) + '</span>' :
                            '<span class="badge bg-label-success">' + esc(lang.allowed) + '</span>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        return row.blocked ?
                            '<button type="button" class="btn btn-sm btn-icon btn-label-success js-toggle" data-sub="allow" data-id="' + esc(row.id) + '"><i class="icon-base ti tabler-check"></i></button>' :
                            '<button type="button" class="btn btn-sm btn-icon btn-label-danger js-toggle" data-sub="block" data-id="' + esc(row.id) + '"><i class="icon-base ti tabler-ban"></i></button>';
                    }
                }
            ],
            layout: {
                topStart: 'pageLength',
                topEnd: 'search'
            }
        });

        document.getElementById('filter-type').addEventListener('change', function() {
            table.ajax.reload();
        });
        document.getElementById('filter-status').addEventListener('change', function() {
            table.ajax.reload();
        });

        jQuery('#asns-table tbody').on('click', '.js-toggle', function() {
            var id = this.getAttribute('data-id');
            var sub = this.getAttribute('data-sub');
            fetch('./api?action=asn&sub=' + encodeURIComponent(sub) + '&id=' + encodeURIComponent(id), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                })
                .then(function(d) {
                    if (!d || d.result !== true) {
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