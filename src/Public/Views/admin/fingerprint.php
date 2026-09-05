<?php

/**
 * Fingerprint stream (Bootstrap 5). A modal wizard: pick an active live stream, then
 * overlay a fingerprint (activity id / username / custom message) on its connections.
 * The stream picker (stream_unique) and the live-connections activity table both hit
 * ./table; activation posts to ./api?action=fingerprint. Opened as an iframe modal
 * (?modal=1) from the streams table.
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryService;

$rRedis = (bool) SettingsManager::get('redis_handler');
$rPageLen = (int) ($rSettings['default_entries'] ?? 10) ?: 10;
$rSelCategory = RequestManager::has('category') ? (string) RequestManager::get('category') : '';
?>

<?php if (!isset($_GET['modal'])): ?>
    <div class="d-flex align-items-center mb-4"><h4 class="mb-0"><?= $language::get('fingerprint_stream'); ?></h4></div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <ul class="nav nav-tabs" role="tablist">
            <li class="nav-item"><button type="button" class="nav-link active" id="fp-stream-tab" data-bs-toggle="tab" data-bs-target="#tab-fp-stream"><i class="icon-base ti tabler-player-play me-1"></i><?= $language::get('stream'); ?></button></li>
            <li class="nav-item"><button type="button" class="nav-link disabled" id="fp-activity-tab" data-bs-toggle="tab" data-bs-target="#tab-fp-activity" disabled><i class="icon-base ti tabler-users me-1"></i><?= $language::get('activity'); ?></button></li>
        </ul>

        <div class="tab-content p-4 border border-top-0 rounded-bottom">
            <!-- Stream selection -->
            <div class="tab-pane fade show active" id="tab-fp-stream" role="tabpanel">
                <div class="row g-2 mb-3">
                    <div class="col-md-6"><input type="text" class="form-control" id="stream_search" value="" placeholder="<?= htmlspecialchars((string) $language::get('search_streams'), ENT_QUOTES); ?>"></div>
                    <div class="col-md-6">
                        <select id="category_search" class="form-select">
                            <option value="" selected><?= $language::get('all_categories'); ?></option>
                            <?php foreach (CategoryService::getAllByType('live') as $rCategory): ?>
                                <option value="<?= (int) $rCategory['id']; ?>" <?= $rSelCategory === (string) $rCategory['id'] ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rCategory['category_name'], ENT_QUOTES); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="datatable-md1" class="table" style="width:100%">
                        <thead>
                            <tr>
                                <th class="text-center"><?= $language::get('id'); ?></th>
                                <th><?= $language::get('stream_name'); ?></th>
                                <th><?= $language::get('category'); ?></th>
                                <th class="text-center"><?= $language::get('clients'); ?></th>
                                <th class="text-center"></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>

            <!-- Activity -->
            <div class="tab-pane fade" id="tab-fp-activity" role="tabpanel">
                <div class="alert alert-warning" role="alert"><?= $language::get('warning_fingerprint'); ?></div>
                <div class="row g-2 align-items-end mb-3" id="filter_selection">
                    <div class="col-md-3">
                        <label class="form-label" for="fingerprint_type"><?= $language::get('type'); ?></label>
                        <select id="fingerprint_type" class="form-select">
                            <option value="1"><?= $language::get('activity_id'); ?></option>
                            <option value="2"><?= $language::get('username'); ?></option>
                            <option value="3"><?= $language::get('message'); ?></option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="font_size"><?= $language::get('size'); ?></label>
                        <input type="text" class="form-control text-center" id="font_size" value="36">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label" for="font_color"><?= $language::get('colour'); ?></label>
                        <input type="color" class="form-control form-control-color w-100" id="font_color" value="#ffffff">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label" for="position_x"><?= $language::get('position'); ?></label>
                        <div class="d-flex gap-2">
                            <input type="text" class="form-control text-center" id="position_x" value="10" placeholder="X">
                            <input type="text" class="form-control text-center" id="position_y" value="10" placeholder="Y">
                        </div>
                    </div>
                    <div class="col-md-2">
                        <button type="button" class="btn btn-primary w-100" id="fp-activate"><i class="icon-base ti tabler-fingerprint me-1"></i><?= $language::get('fingerprint'); ?></button>
                    </div>
                    <div class="col-12" id="custom_message_div" style="display:none">
                        <input type="text" class="form-control" id="custom_message" value="" placeholder="<?= htmlspecialchars((string) $language::get('custom_message'), ENT_QUOTES); ?>">
                    </div>
                </div>
                <div class="table-responsive">
                    <table id="datatable-md2" class="table" style="width:100%">
                        <thead>
                            <tr>
                                <th><?= $language::get('username'); ?></th>
                                <th><?= $language::get('stream'); ?></th>
                                <th class="text-center"><?= $language::get('ip'); ?></th>
                                <th class="text-center"><?= $language::get('duration'); ?></th>
                                <th class="text-center"><?= $language::get('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) { return; }
        var toast = window.xcToast || function() {};
        $.fn.dataTable.ext.errMode = 'none';

        var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
        var isLocal = function(ip) { return !ip || ip === '127.0.0.1' || ip === '::1'; };
        var pad = function(n) { return (n < 10 ? '0' : '') + n; };
        var fmtDuration = function(startTs, isRestreamer) {
            var sec = Math.max(0, Math.floor(Date.now() / 1000) - (startTs || 0));
            var colour = 'success', txt;
            if (sec >= 86400) { txt = pad(Math.floor(sec / 86400)) + 'd ' + pad(Math.floor(sec / 3600) % 24) + 'h'; colour = 'danger'; }
            else if (sec >= 3600) { txt = pad(Math.floor(sec / 3600)) + 'h ' + pad(Math.floor(sec / 60) % 60) + 'm'; if (sec > 14400) { colour = 'warning'; } }
            else { txt = pad(Math.floor(sec / 60) % 60) + 'm ' + pad(sec % 60) + 's'; }
            if (isRestreamer) { colour = 'success'; }
            return '<span class="badge bg-label-' + colour + '">' + esc(txt) + '</span>';
        };
        var lang = {
            killed: <?= json_encode($language::get('connection_has_been_killed')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>,
            success: <?= json_encode($language::get('fingerprint_success')); ?>,
            fail: <?= json_encode($language::get('fingerprint_fail')); ?>,
            kill: <?= json_encode($language::get('kill')); ?>
        };

        var rStreamID = -1;

        if ($.fn.select2) { $('#category_search, #fingerprint_type').select2({ width: '100%' }); }

        // ----- stream picker (data-only rows; select button rendered here) -----
        var md1 = $('#datatable-md1').DataTable({
            processing: true,
            serverSide: true,
            <?= $rRedis ? 'paging: false,' : 'pageLength: ' . $rPageLen . ', lengthMenu: [10, 25, 50, 250, 500, 1000],'; ?>
            order: [[<?= $rRedis ? '1' : '3'; ?>, '<?= $rRedis ? 'asc' : 'desc'; ?>']],
            ajax: {
                url: './table',
                data: function(d) { d.id = 'stream_unique'; d.category = $('#category_search').val(); }
            },
            columns: [
                { data: 0, className: 'text-center' },
                { data: 1 },
                { data: 2 },
                { data: 3, className: 'text-center', orderable: false },
                { data: 0, orderable: false, searchable: false, className: 'text-center', render: function(rID) {
                    return '<button type="button" class="btn btn-sm btn-icon btn-label-info js-select" data-id="' + esc(rID) + '"><i class="icon-base ti tabler-fingerprint"></i></button>';
                } }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });
        $('#stream_search').on('keyup', function() { md1.search($(this).val()).draw(); });
        $('#category_search').on('select2:select change', function() { md1.ajax.reload(null, false); });

        // ----- activity table (clean-JSON live_connections rows) -----
        var md2 = $('#datatable-md2').DataTable({
            processing: true,
            serverSide: true,
            searchDelay: 250,
            pageLength: <?= $rPageLen; ?>,
            lengthMenu: [10, 25, 50, 250, 500, 1000],
            order: [[3, 'desc']],
            ajax: {
                url: './table',
                data: function(d) { d.id = 'live_connections'; d.stream_id = rStreamID; d.fingerprint = true; }
            },
            columns: [
                { data: 'user_label', render: function(d, t, row) { if (!d) { return ''; } return row.user_url ? '<a href="' + esc(row.user_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d); } },
                { data: 'stream_name', render: function(d, t, row) { if (!d) { return ''; } return row.stream_url ? '<a href="' + esc(row.stream_url) + '" class="text-body">' + esc(d) + '</a>' : esc(d); } },
                { data: 'user_ip', className: 'text-center text-nowrap', render: function(d, t, row) {
                    var flag = row.country ? '<img loading="lazy" class="me-1" src="assets/old/images/countries/' + esc(row.country) + '.png" alt="">' : '';
                    return flag + esc(d || '');
                } },
                { data: 'date_start', className: 'text-center', render: function(d, t, row) { return fmtDuration(d, row.is_restreamer); } },
                { data: null, orderable: false, searchable: false, className: 'text-center', render: function(d, t, row) {
                    return '<button type="button" class="btn btn-sm btn-icon btn-label-danger js-kill" title="' + esc(lang.kill) + '" data-uuid="' + esc(row.uuid) + '"><i class="icon-base ti tabler-hammer"></i></button>';
                } }
            ],
            layout: { topStart: 'pageLength', topEnd: 'search' }
        });

        // A stream is chosen: enable + open the activity tab and load its connections.
        $('#datatable-md1 tbody').on('click', '.js-select', function() {
            rStreamID = this.getAttribute('data-id');
            $('#fp-activity-tab').removeClass('disabled').prop('disabled', false);
            bootstrap.Tab.getOrCreateInstance(document.getElementById('fp-activity-tab')).show();
            $('#filter_selection').show();
            md2.ajax.reload(null, false);
        });
        // Returning to the stream tab clears the selection.
        $('#fp-stream-tab').on('shown.bs.tab', function() {
            rStreamID = -1;
            $('#fp-activity-tab').addClass('disabled').prop('disabled', true);
            md1.ajax.reload(null, false);
        });

        $('#fingerprint_type').on('change', function() {
            document.getElementById('custom_message_div').style.display = (this.value === '3') ? '' : 'none';
        });
        var digitsOnly = function(id) {
            var el = document.getElementById(id);
            if (el) { el.addEventListener('input', function() { this.value = this.value.replace(/[^\d]/g, ''); }); }
        };
        digitsOnly('font_size');
        digitsOnly('position_x');
        digitsOnly('position_y');

        // Apply the fingerprint overlay to the selected stream.
        document.getElementById('fp-activate').addEventListener('click', function() {
            var rArray = {
                id: rStreamID,
                font_size: $('#font_size').val(),
                font_color: $('#font_color').val(),
                message: '',
                type: $('#fingerprint_type').val(),
                xy_offset: ''
            };
            if (rArray.type === '3') { rArray.message = $('#custom_message').val(); }
            if (($('#position_x').val() >= 0) && ($('#position_y').val() >= 0)) {
                rArray.xy_offset = $('#position_x').val() + 'x' + $('#position_y').val();
            }
            if (!(rArray.font_size > 0) || !rArray.font_color || (!rArray.message && rArray.type === '3') || !rArray.xy_offset) {
                toast(lang.fail, 'error');
                return;
            }
            fetch('./api?action=fingerprint&data=' + encodeURIComponent(JSON.stringify(rArray)), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(d) { toast(d && d.result ? lang.success : lang.error, d && d.result ? 'success' : 'error'); md2.ajax.reload(null, false); })
                .catch(function() { toast(lang.error, 'error'); });
        });

        // Kill a single connection.
        $('#datatable-md2 tbody').on('click', '.js-kill', function() {
            var uuid = this.getAttribute('data-uuid');
            fetch('./api?action=line_activity&sub=kill&pid=' + encodeURIComponent(uuid), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(d) { toast(d && d.result ? lang.killed : lang.error, d && d.result ? 'success' : 'error'); md2.ajax.reload(null, false); })
                .catch(function() { toast(lang.error, 'error'); });
        });
    })();
</script>
</body>

</html>
