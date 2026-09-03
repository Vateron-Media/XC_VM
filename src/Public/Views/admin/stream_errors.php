<?php

/**
 * Stream errors (Bootstrap 5). Clean-JSON table pattern: TableController::handleStreamErrors
 * returns structured rows (permission-gated stream link resolved into stream_url)
 * and this page renders the cells client-side via datatables-bs5 columns[].render.
 * Server + date-range filters post extra ajax params.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Domain\Server\ServerRepository;

if (!Authorization::check('adv', 'stream_errors')):
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
    #stream-errors-table td.stream-error-msg {
        max-width: 32rem;
        white-space: normal;
        word-break: break-word;
        overflow-wrap: anywhere;
    }
</style>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('stream_errors'); ?></h5>
    </div>
    <!-- Filters (Bootstrap 5 advanced-search layout: labelled fields in a grid). -->
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-server"><?= $language::get('server'); ?></label>
                <select id="filter-server" class="form-select">
                    <option value=""><?= $language::get('all_servers'); ?></option>
                    <?php foreach (ServerRepository::getStreamingSimple($rPermissions) as $rServer): ?>
                        <option value="<?= (int) $rServer['id']; ?>"><?= htmlspecialchars((string) $rServer['server_name'], ENT_QUOTES); ?></option>
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
        <table id="stream-errors-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th><!-- responsive control (+/-) -->
                    <th><?= $language::get('stream'); ?></th>
                    <th><?= $language::get('server'); ?></th>
                    <th><?= $language::get('error'); ?></th>
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

        var table = jQuery('#stream-errors-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: {
                details: {
                    type: 'column',
                    target: 0
                }
            },
            order: [
                [4, 'desc']
            ],
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'stream_errors';
                    d.server = document.getElementById('filter-server').value;
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
                    data: 'stream_name',
                    responsivePriority: 1,
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
                    data: 'server_name',
                    render: function(d) {
                        return esc(d);
                    }
                },
                {
                    data: 'error',
                    className: 'stream-error-msg',
                    responsivePriority: 3,
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

        document.getElementById('filter-server').addEventListener('change', function() {
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
