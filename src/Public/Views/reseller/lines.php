<?php

/**
 * Reseller Lines (Bootstrap 5). Full-parity port of admin/lines.php adapted to the
 * reseller contract: clean-JSON keyed serverSide DataTable (ResellerTableRenderer
 * ::handleLines emits data-only rows), with the status badge, online/trial/
 * restreamer dots, connection badges, expiration/last-connection cells and the
 * per-row action dropdown all rendered client-side.
 *
 * Reseller differences vs admin: no bulk toolbar (no reseller `multi` API), no
 * ban/unban (reseller has no admin_enabled control) and no fingerprint; adds the
 * reset-ISP-lock action. Edit navigates to `line?id=…` (the reseller line editor
 * has no iframe-modal shell). Permission gating uses $rPermissions, not
 * Authorization::check. The owner filter is a static select2 built from the
 * reseller's report tree (Global / Direct Reports / Indirect Reports).
 */

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Server\ServerRepository;

if (empty($rPermissions['create_line'])):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('reseller');
    echo '</body></html>';
    return;
endif;

global $db;

$rCanDownload = !empty($rPermissions['allow_download']);
$rCanLive = !empty($rPermissions['reseller_client_connection_logs']);
$rRedis = (bool) SettingsManager::get('redis_handler');
$rSiteUrl = rtrim((string) (ServerRepository::getAll()[SERVER_ID]['site_url'] ?? ''), '/');

// Deep-link owner filter (?owner=ID) — pre-selects the matching static option.
$rSelectedOwner = RequestManager::has('owner') ? (string) RequestManager::get('owner') : '';
$rSelectedFilter = RequestManager::has('filter') ? (int) RequestManager::get('filter') : 0;

// Reseller status filter values handled by ResellerTableRenderer::handleLines.
$rStatusFilters = [1 => 'Active', 2 => 'Disabled', 3 => 'Banned', 4 => 'Expired', 5 => 'Trial'];

$rDirectReports = (array) ($rPermissions['direct_reports'] ?? []);
$rAllReports = (array) ($rPermissions['all_reports'] ?? []);
$rReportUsers = (array) ($rPermissions['users'] ?? []);
?>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><?= $language::get('lines'); ?></h5>
    </div>
    <div class="card-body border-bottom">
        <div class="row g-3">
            <div class="col-12 col-lg-5">
                <label class="form-label" for="filter-search"><?= $language::get('search'); ?></label>
                <input type="text" id="filter-search" class="form-control" autocomplete="off" placeholder="<?= htmlspecialchars((string) $language::get('search_lines'), ENT_QUOTES); ?>">
            </div>
            <div class="col-12 col-sm-6 col-lg-4">
                <label class="form-label" for="filter-reseller"><?= $language::get('owner'); ?></label>
                <select id="filter-reseller" class="form-select">
                    <optgroup label="Global">
                        <option value=""<?= $rSelectedOwner === '' ? ' selected' : ''; ?>><?= $language::get('all'); ?></option>
                        <option value="<?= (int) $rUserInfo['id']; ?>"<?= ($rSelectedOwner !== '' && $rSelectedOwner == $rUserInfo['id']) ? ' selected' : ''; ?>>My Lines</option>
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
            <div class="col-12 col-sm-6 col-lg-3">
                <label class="form-label" for="filter-status"><?= $language::get('status'); ?></label>
                <select id="filter-status" class="form-select">
                    <option value=""><?= $language::get('all'); ?></option>
                    <?php foreach ($rStatusFilters as $rK => $rV): ?>
                        <option value="<?= $rK; ?>" <?= $rSelectedFilter === $rK ? 'selected' : ''; ?>><?= $rV; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
    </div>
    <div class="card-datatable table-responsive">
        <table id="lines-table" class="table" style="width:100%">
            <thead>
                <tr>
                    <th></th>
                    <th><?= $language::get('id'); ?></th>
                    <th><?= $language::get('username'); ?></th>
                    <th><?= $language::get('password'); ?></th>
                    <th><?= $language::get('owner'); ?></th>
                    <th class="text-center"><?= $language::get('status'); ?></th>
                    <th class="text-center"><?= $language::get('online'); ?></th>
                    <th class="text-center"><?= $language::get('trial'); ?></th>
                    <th class="text-center"><?= $language::get('restreamer'); ?></th>
                    <th class="text-center"><?= $language::get('active'); ?></th>
                    <th class="text-center"><?= $language::get('connections'); ?></th>
                    <th class="text-center"><?= $language::get('expiration'); ?></th>
                    <th class="text-center"><?= $language::get('last_connection'); ?></th>
                    <th class="text-center"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody></tbody>
        </table>
    </div>
</div>

<?php if ($rCanDownload): ?>
    <!-- Download Playlist modal -->
    <div class="modal fade" id="downloadModal" tabindex="-1" aria-hidden="true" data-username="" data-password="">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title mb-0"><?= $language::get('download_playlist') ?: 'Download Playlist'; ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label" for="download_type"><?= $language::get('format'); ?></label>
                        <select id="download_type" class="form-select">
                            <option value=""></option>
                            <?php
                            $db->query('SELECT * FROM `output_devices` ORDER BY `device_id` ASC;');
                            foreach ($db->get_rows() as $rDev):
                                $rKey = htmlspecialchars((string) $rDev['device_key'], ENT_QUOTES);
                                $rName = htmlspecialchars((string) $rDev['device_name'], ENT_QUOTES);
                                $rTextAttr = $rDev['copy_text'] ? ' data-text="' . htmlspecialchars(str_replace('"', '\\"', (string) $rDev['copy_text']), ENT_QUOTES) . '"' : '';
                            ?>
                                <optgroup label="<?= $rName; ?>">
                                    <option<?= $rTextAttr; ?> value="<?= $rKey; ?>?output=hls"><?= $rName; ?> - HLS</option>
                                    <option<?= $rTextAttr; ?> value="<?= $rKey; ?>"><?= $rName; ?> - MPEGTS</option>
                                    <option<?= $rTextAttr; ?> value="<?= $rKey; ?>?output=rtmp"><?= $rName; ?> - RTMP</option>
                                </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label" for="output_type"><?= $language::get('limit_output'); ?></label>
                        <select id="output_type" class="form-select" multiple>
                            <option value="live"><?= $language::get('live_streams'); ?></option>
                            <option value="movie"><?= $language::get('movies'); ?></option>
                            <option value="created_live"><?= $language::get('created_channels'); ?></option>
                            <option value="radio_streams"><?= $language::get('radio_stations'); ?></option>
                            <option value="series"><?= $language::get('tv_series'); ?></option>
                        </select>
                    </div>
                    <div class="input-group">
                        <input type="text" class="form-control" id="download_url" value="" readonly>
                        <button class="btn btn-outline-secondary" type="button" id="download_copy"><i class="icon-base ti tabler-copy"></i></button>
                        <button class="btn btn-primary" type="button" id="download_open" disabled><i class="icon-base ti tabler-download"></i></button>
                    </div>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<!-- WhatsApp Renewal modal -->
<div class="modal fade" id="whatsappModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title mb-0"><i class="icon-base ti tabler-brand-whatsapp text-success me-1"></i>WhatsApp Renewal</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <label class="form-label" for="wa_language"><?= $language::get('select_language_sprache_whlen_dil_sein'); ?></label>
                    <select id="wa_language" class="form-select">
                        <option value="de">🇩🇪 Deutsch</option>
                        <option value="en">🇬🇧 English</option>
                        <option value="tr">🇹🇷 Türkçe</option>
                    </select>
                </div>
                <div class="mb-0">
                    <label class="form-label" for="wa_message_preview"><?= $language::get('preview_vorschau_nizleme'); ?></label>
                    <textarea id="wa_message_preview" class="form-control" rows="5" readonly></textarea>
                </div>
                <input type="hidden" id="wa_phone"><input type="hidden" id="wa_username"><input type="hidden" id="wa_expdate"><input type="hidden" id="wa_days">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel'); ?></button>
                <a id="wa_send" href="#" target="_blank" class="btn btn-success"><i class="icon-base ti tabler-brand-whatsapp me-1"></i><?= $language::get('send_via_whatsapp'); ?></a>
            </div>
        </div>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('reseller');
?>
<script>
    (function() {
        var esc = function(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : String(s)); return d.innerHTML; };
        var canDownload = <?= $rCanDownload ? 'true' : 'false'; ?>, canLive = <?= $rCanLive ? 'true' : 'false'; ?>, redis = <?= $rRedis ? 'true' : 'false'; ?>;
        var siteUrl = <?= json_encode($rSiteUrl); ?>;
        var lang = {
            edit: <?= json_encode($language::get('edit')); ?>,
            download: <?= json_encode($language::get('download_playlist') ?: 'Download Playlist'); ?>,
            whatsapp: 'WhatsApp Renewal',
            kill: <?= json_encode($language::get('kill') ?: 'Kill Connections'); ?>,
            resetIsp: 'Reset ISP Lock',
            enable: <?= json_encode($language::get('enable')); ?>,
            disable: <?= json_encode($language::get('disable')); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>,
            confirmDelete: 'Are you sure you want to delete this line?',
            confirmKill: 'Are you sure you want to kill all connections for this line?'
        };
        // Status code -> [bootstrap colour, label].
        var STATUS = { banned: ['danger', 'Banned'], disabled: ['secondary', 'Disabled'], expired: ['warning', 'Expired'], active: ['success', 'Active'] };
        var dot = function(color, title) { return '<i class="icon-base ti tabler-circle-filled text-' + color + '"' + (title ? ' title="' + esc(title) + '"' : '') + '></i>'; };
        var fmtUptime = function(sec) {
            sec = Math.max(0, Math.floor(sec));
            var d = Math.floor(sec / 86400), h = Math.floor(sec / 3600) % 24, m = Math.floor(sec / 60) % 60, s = sec % 60;
            var p = function(n) { return (n < 10 ? '0' : '') + n; };
            return (d > 0 ? d + 'd ' : '') + p(h) + ':' + p(m) + ':' + p(s);
        };

        var table = jQuery('#lines-table').DataTable({
            processing: true,
            serverSide: true,
            responsive: { details: { type: 'column', target: 0 } },
            order: [[1, 'desc']],
            searchDelay: 400,
            lengthMenu: [10, 25, 50, 250, 500, 1000],
            pageLength: <?= (int) ($rSettings['default_entries'] ?: 25); ?>,
            ajax: {
                url: './table',
                data: function(d) { d.id = 'lines'; d.filter = document.getElementById('filter-status').value; d.reseller = jQuery('#filter-reseller').val() || ''; }
            },
            columnDefs: [{ orderable: false, targets: [0, 7, 8, 13].concat(redis ? [6, 9] : []) }],
            columns: [
                { data: null, defaultContent: '', orderable: false, searchable: false, className: 'control', responsivePriority: 2 },
                { data: 'id', className: 'text-center', render: function(d) { return '<a href="line?id=' + encodeURIComponent(d) + '" class="text-body">' + esc(d) + '</a>'; } },
                { data: 'username', responsivePriority: 1, render: function(d, t, row) { return '<a href="line?id=' + encodeURIComponent(row.id) + '" class="text-body fw-medium">' + esc(d) + '</a>'; } },
                { data: 'password', render: esc },
                { data: 'owner_name', render: function(d, t, row) { var body = row.member_id > 0 ? '<a href="user?id=' + encodeURIComponent(row.member_id) + '" class="text-body">' + esc(d || '') + '</a>' : esc(d || ''); return row.indirect ? body + '<br><small class="text-body-secondary">(indirect)</small>' : body; } },
                { data: 'status', className: 'text-center', render: function(d) { var s = STATUS[d] || ['secondary', d]; return '<span class="badge bg-label-' + s[0] + '">' + esc(s[1]) + '</span>'; } },
                { data: 'active_connections', className: 'text-center', render: function(d) { return dot(d > 0 ? 'success' : 'secondary'); } },
                { data: 'trial', className: 'text-center', render: function(d) { return dot(d ? 'warning' : 'secondary'); } },
                { data: 'restreamer', className: 'text-center', render: function(d) { return dot(d ? 'info' : 'secondary'); } },
                {
                    data: 'active_connections',
                    className: 'text-center',
                    render: function(d, t, row) {
                        var badge = '<span class="badge bg-label-' + (d > 0 ? 'info' : 'secondary') + '">' + (d || 0) + '</span>';
                        return (d > 0 && canLive) ? '<a href="live_connections?line=' + encodeURIComponent(row.id) + '">' + badge + '</a>' : badge;
                    }
                },
                { data: 'max_connections', className: 'text-center', render: function(d) { return '<span class="badge bg-label-dark">' + (d == 0 ? '&infin;' : d) + '</span>'; } },
                {
                    data: 'exp_str',
                    className: 'text-center text-nowrap',
                    render: function(d, t, row) {
                        if (!d) { return '<span class="fs-4">&infin;</span>'; }
                        var parts = String(d).split(' ');
                        var body = esc(parts[0]) + (parts[1] ? '<br><small class="text-body-secondary">' + esc(parts[1]) + '</small>' : '');
                        return row.exp_expired ? '<span class="text-danger">' + body + '</span>' : body;
                    }
                },
                {
                    data: 'last_active',
                    className: 'text-nowrap',
                    render: function(d, t, row) {
                        if (row.active_connections > 0 && d) {
                            var name = row.stream_display_name ? '<a href="stream_view?id=' + encodeURIComponent(row.stream_id) + '" class="text-body">' + esc(row.stream_display_name) + '</a>' : '<span class="text-body">#' + esc(row.stream_id) + '</span>';
                            return name + '<br><small class="text-success">Online: ' + fmtUptime(Math.floor(Date.now() / 1000) - d) + '</small>';
                        }
                        if (row.last_str) { var p = String(row.last_str).split(' '); return esc(p[0]) + (p[1] ? '<br><small class="text-body-secondary">' + esc(p[1]) + '</small>' : ''); }
                        return '<span class="text-body-secondary">Never</span>';
                    }
                },
                {
                    data: null,
                    orderable: false,
                    searchable: false,
                    className: 'text-center',
                    render: function(d, t, row) {
                        var items = '';
                        if (row.notes) { items += '<h6 class="dropdown-header text-wrap" style="max-width:18rem">' + esc(row.notes) + '</h6><div class="dropdown-divider"></div>'; }
                        items += '<a class="dropdown-item" href="line?id=' + encodeURIComponent(row.id) + '">' + esc(lang.edit) + '</a>';
                        if (canDownload) { items += '<a class="dropdown-item js-download" href="javascript:void(0);" data-user="' + esc(row.username) + '" data-pass="' + esc(row.password) + '">' + esc(lang.download) + '</a>'; }
                        items += '<a class="dropdown-item js-whatsapp" href="javascript:void(0);" data-user="' + esc(row.username) + '" data-contact="' + esc(row.contact || '') + '" data-expunix="' + esc(row.exp_unix || '') + '"><i class="icon-base ti tabler-brand-whatsapp text-success me-1"></i>' + esc(lang.whatsapp) + '</a>';
                        items += '<div class="dropdown-divider"></div>';
                        if (canLive && row.active_connections > 0) { items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="kill_line" data-id="' + esc(row.id) + '">' + esc(lang.kill) + '</a>'; }
                        if (row.is_isplock) { items += '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="reset_isp" data-id="' + esc(row.id) + '">' + esc(lang.resetIsp) + '</a>'; }
                        items += row.enabled
                            ? '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="disable" data-id="' + esc(row.id) + '">' + esc(lang.disable) + '</a>'
                            : '<a class="dropdown-item js-act" href="javascript:void(0);" data-sub="enable" data-id="' + esc(row.id) + '">' + esc(lang.enable) + '</a>';
                        items += '<a class="dropdown-item text-danger js-act" href="javascript:void(0);" data-sub="delete" data-id="' + esc(row.id) + '">' + esc(lang.del) + '</a>';
                        return '<div class="dropdown"><button class="btn btn-sm btn-icon btn-label-secondary" data-bs-toggle="dropdown" aria-expanded="false"><i class="icon-base ti tabler-dots-vertical"></i></button><div class="dropdown-menu dropdown-menu-end">' + items + '</div></div>';
                    }
                }
            ],
            layout: { topStart: 'pageLength', topEnd: null }
        });

        // Filters. Owner filter is a static select2 (report tree), not an ajax search.
        jQuery('#filter-reseller').select2({ allowClear: false, width: '100%' }).on('change', function() { table.ajax.reload(); });
        document.getElementById('filter-status').addEventListener('change', function() { table.ajax.reload(); });
        var searchTimer;
        document.getElementById('filter-search').addEventListener('keyup', function() {
            var v = this.value;
            clearTimeout(searchTimer);
            searchTimer = setTimeout(function() { table.search(v).draw(); }, 400);
        });

        // Row single actions -> reseller api (action=line&sub=…&user_id=…).
        var rowApi = function(id, sub) {
            return fetch('./api?action=line&sub=' + encodeURIComponent(sub) + '&user_id=' + encodeURIComponent(id), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
                .then(function(r) { return r.json(); })
                .then(function(dt) { if (!dt || dt.result !== true) { throw new Error('fail'); } });
        };
        jQuery('#lines-table tbody').on('click', '.js-act', function() {
            var sub = this.getAttribute('data-sub'), id = this.getAttribute('data-id');
            var go = function() { rowApi(id, sub).then(function() { table.ajax.reload(null, false); }).catch(function() { xcToast(lang.error, 'error'); }); };
            if (sub === 'delete') { xcConfirm(lang.confirmDelete).then(function(ok) { if (ok) { go(); } }); }
            else if (sub === 'kill_line') { xcConfirm(lang.confirmKill).then(function(ok) { if (ok) { go(); } }); }
            else { go(); }
        });

        <?php if ($rCanDownload): ?>
        // Download playlist modal.
        var dlModal = document.getElementById('downloadModal');
        var dlType = document.getElementById('download_type'), outType = document.getElementById('output_type'), dlUrl = document.getElementById('download_url'), dlOpen = document.getElementById('download_open');
        jQuery(dlType).select2({ placeholder: '<?= $language::get('format'); ?>', width: '100%', dropdownParent: jQuery('#downloadModal') });
        jQuery(outType).select2({ placeholder: '<?= $language::get('all'); ?>', width: '100%', closeOnSelect: false, dropdownParent: jQuery('#downloadModal') });
        var buildDownload = function() {
            var key = dlType.value;
            if (!key) { dlUrl.value = ''; dlOpen.disabled = true; return; }
            var u = dlModal.getAttribute('data-username'), p = dlModal.getAttribute('data-password');
            var text = siteUrl + '/playlist/' + u + '/' + p + '/' + decodeURIComponent(key);
            var outs = Array.prototype.filter.call(outType.options, function(o) { return o.selected; }).map(function(o) { return o.value; });
            if (outs.length) { text += (text.indexOf('?output=') !== -1 ? '&' : '?') + 'key=' + outs.join(','); }
            var opt = dlType.options[dlType.selectedIndex];
            if (opt && opt.getAttribute('data-text')) { dlUrl.value = opt.getAttribute('data-text').replace('{DEVICE_LINK}', '"' + text + '"'); dlOpen.disabled = true; }
            else { dlUrl.value = text; dlOpen.disabled = false; }
        };
        jQuery(dlType).on('change', buildDownload);
        jQuery(outType).on('change', buildDownload);
        jQuery('#lines-table tbody').on('click', '.js-download', function() {
            dlModal.setAttribute('data-username', this.getAttribute('data-user'));
            dlModal.setAttribute('data-password', this.getAttribute('data-pass'));
            jQuery(dlType).val('').trigger('change'); jQuery(outType).val(null).trigger('change');
            dlUrl.value = ''; dlOpen.disabled = true;
            bootstrap.Modal.getOrCreateInstance(dlModal).show();
        });
        document.getElementById('download_copy').addEventListener('click', function() { dlUrl.select(); document.execCommand('copy'); });
        dlOpen.addEventListener('click', function() { if (dlUrl.value) { window.open(dlUrl.value); } });
        <?php endif; ?>

        // WhatsApp renewal modal.
        var waModal = document.getElementById('whatsappModal');
        var waMessages = {
            de: "Hallo Lieber {USERNAME},\n\nIhr IPTV Abonnement endet am {EXPDATE} und es sind noch {DAYS} Tage übrig.\n\nMöchten Sie Ihr IPTV Abonnement verlängern?\n\nMit freundlichen Grüßen",
            en: "Hello Dear {USERNAME},\n\nYour IPTV subscription expires on {EXPDATE} and there are {DAYS} days remaining.\n\nWould you like to renew your IPTV subscription?\n\nBest regards",
            tr: "Merhaba Sayın {USERNAME},\n\nIPTV aboneliğiniz {EXPDATE} tarihinde sona eriyor ve {DAYS} gün kaldı.\n\nIPTV aboneliğinizi yenilemek ister misiniz?\n\nSaygılarımızla"
        };
        var waUpdate = function() {
            var msg = waMessages[document.getElementById('wa_language').value]
                .replace('{USERNAME}', document.getElementById('wa_username').value)
                .replace('{EXPDATE}', document.getElementById('wa_expdate').value)
                .replace('{DAYS}', document.getElementById('wa_days').value);
            document.getElementById('wa_message_preview').value = msg;
            var phone = document.getElementById('wa_phone').value.replace(/[^0-9]/g, '');
            document.getElementById('wa_send').setAttribute('href', 'https://wa.me/' + phone + '?text=' + encodeURIComponent(msg));
        };
        document.getElementById('wa_language').addEventListener('change', waUpdate);
        jQuery('#lines-table tbody').on('click', '.js-whatsapp', function() {
            var contact = this.getAttribute('data-contact');
            if (!contact) { xcToast('This line has no WhatsApp number set.', 'warning'); return; }
            var expUnix = parseInt(this.getAttribute('data-expunix'), 10);
            var expDate = expUnix ? new Date(expUnix * 1000) : null;
            var days = 0;
            if (expDate) { days = Math.max(0, Math.ceil((expDate - new Date()) / 86400000)); }
            document.getElementById('wa_phone').value = contact;
            document.getElementById('wa_username').value = this.getAttribute('data-user');
            document.getElementById('wa_expdate').value = expDate ? expDate.toLocaleDateString('de-DE') : 'Never';
            document.getElementById('wa_days').value = days;
            waUpdate();
            bootstrap.Modal.getOrCreateInstance(waModal).show();
        });
    })();
</script>
</body>

</html>
