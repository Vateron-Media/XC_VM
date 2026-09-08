<?php

/**
 * Reseller logs (Bootstrap 5). Full-parity port of admin/user_logs.php adapted to
 * the reseller contract: clean-JSON keyed serverSide DataTable
 * (ResellerTableRenderer::handleRegUserLogs emits data-only rows) with the
 * server-composed action text, permission-gated owner link (plus an indirect
 * marker) and the resolved target line/user/mag/enigma link rendered client-side.
 * Read-only log — no per-row actions.
 *
 * Reseller differences vs admin: no permission gate (the reseller nav always shows
 * this page); the reseller (owner) filter is a static select2 built from the
 * reseller's report tree, not an ajax search. The admin action filter is omitted —
 * the reseller handler has no action-filter support. A flatpickr date range is kept.
 */

use XcVm\Core\Http\RequestManager;

// The header stat link deep-links via ?user_id=ID; pre-select that owner.
$rSelectedOwner = RequestManager::has('user_id') ? (string) RequestManager::get('user_id') : '';

$rDirectReports = (array) ($rPermissions['direct_reports'] ?? []);
$rAllReports = (array) ($rPermissions['all_reports'] ?? []);
$rReportUsers = (array) ($rPermissions['users'] ?? []);
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('reseller_logs'); ?></h5>
    </div>
    <!-- Filters (Bootstrap 5 advanced-search layout: labelled fields in a grid). -->
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-sm-6">
                <label class="form-label" for="filter-reseller"><?= $language::get('reseller'); ?></label>
                <select id="filter-reseller" class="form-select">
                    <optgroup label="Global">
                        <option value=""<?= $rSelectedOwner === '' ? ' selected' : ''; ?>><?= $language::get('all'); ?></option>
                        <option value="<?= (int) $rUserInfo['id']; ?>"<?= ($rSelectedOwner !== '' && $rSelectedOwner == $rUserInfo['id']) ? ' selected' : ''; ?>>My Logs</option>
                    </optgroup>
                    <?php if (count($rDirectReports) > 0): ?>
                        <optgroup label="Direct Reports">
                            <?php foreach ($rDirectReports as $rUserID): ?>
                                <option value="<?= (int) $rUserID; ?>"<?= ($rSelectedOwner !== '' && $rSelectedOwner == $rUserID) ? ' selected' : ''; ?>><?= htmlspecialchars((string) ($rReportUsers[$rUserID]['username'] ?? $rUserID), ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                    <?php if (count($rAllReports) > count($rDirectReports)): ?>
                        <optgroup label="Indirect Reports">
                            <?php foreach ($rAllReports as $rUserID): ?>
                                <?php if (!in_array($rUserID, $rDirectReports)): ?>
                                    <option value="<?= (int) $rUserID; ?>"<?= ($rSelectedOwner !== '' && $rSelectedOwner == $rUserID) ? ' selected' : ''; ?>><?= htmlspecialchars((string) ($rReportUsers[$rUserID]['username'] ?? $rUserID), ENT_QUOTES); ?></option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endif; ?>
                </select>
            </div>
            <div class="col-12 col-sm-6">
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
renderUnifiedLayoutFooter('reseller');
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
            lengthMenu: [10, 25, 50, 250, 500, 1000],
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 25); ?>,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'reg_user_logs';
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
                    data: 'owner',
                    render: function(d, t, row) {
                        var body = link(d, row.owner_url);
                        return row.owner_indirect ? body + '<br><small class="text-body-secondary">(indirect)</small>' : body;
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

        // Reseller (owner) filter is a static select2 (report tree), not an ajax search.
        if (jQuery.fn.select2) {
            jQuery('#filter-reseller').select2({
                allowClear: false,
                width: '100%'
            });
        }
        jQuery('#filter-reseller').on('change', function() {
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
