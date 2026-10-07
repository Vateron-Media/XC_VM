<?php
use XcVm\Core\Util\LayoutRenderer;


/**
 * Modules (Bootstrap 5): the installed modules and the official store.
 *
 * Everything goes through ModuleAjaxController: `module_status` (the modules'
 * state and the background job), `module` (enable/disable at once, every other
 * action queued as a background job), `module_upload` and `module_store`.
 * The page draws only what those answer — never the state it asked for — and
 * polls `module_status` while a job runs.
 */
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('modules'); ?></h4>
</div>

<ul class="nav nav-tabs" role="tablist">
    <li class="nav-item" role="presentation">
        <button class="nav-link active" id="tab-installed" data-bs-toggle="tab" data-bs-target="#pane-installed" type="button" role="tab" aria-controls="pane-installed" aria-selected="true">
            <i class="icon-base ti tabler-apps me-1"></i><?= $language::get('module_tab_installed'); ?>
        </button>
    </li>
    <li class="nav-item" role="presentation">
        <button class="nav-link" id="tab-store" data-bs-toggle="tab" data-bs-target="#pane-store" type="button" role="tab" aria-controls="pane-store" aria-selected="false">
            <i class="icon-base ti tabler-building-store me-1"></i><?= $language::get('module_tab_store'); ?>
        </button>
    </li>
</ul>

<div class="tab-content p-0 pt-4">
    <div class="tab-pane fade show active" id="pane-installed" role="tabpanel" aria-labelledby="tab-installed">
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <input type="search" class="form-control form-control-sm me-auto" id="module-filter" style="max-width:260px" placeholder="<?= htmlspecialchars($language::get('module_filter'), ENT_QUOTES); ?>" aria-label="<?= htmlspecialchars($language::get('module_filter'), ENT_QUOTES); ?>">
                <button type="button" class="btn btn-sm btn-label-primary js-job-button" id="module-check-updates" title="<?= htmlspecialchars($language::get('module_check_updates_hint'), ENT_QUOTES); ?>">
                    <i class="icon-base ti tabler-refresh me-1"></i><?= $language::get('module_check_updates'); ?>
                </button>
                <button type="button" class="btn btn-sm btn-primary js-job-button" data-bs-toggle="modal" data-bs-target="#module-upload-modal">
                    <i class="icon-base ti tabler-upload me-1"></i><?= $language::get('module_upload'); ?>
                </button>
            </div>
            <div class="alert alert-info d-flex align-items-center gap-2 mb-0 rounded-0 d-none" id="module-job" role="status" aria-live="polite">
                <span class="spinner-border spinner-border-sm" aria-hidden="true"></span>
                <span id="module-job-text"></span>
            </div>
            <div class="card-datatable table-responsive">
                <table class="table mb-0" id="modules-table">
                    <thead>
                        <tr>
                            <th><?= $language::get('name'); ?></th>
                            <th class="d-none d-lg-table-cell"><?= $language::get('description'); ?></th>
                            <th class="d-none d-md-table-cell"><?= $language::get('version'); ?></th>
                            <th><?= $language::get('status'); ?></th>
                            <th class="text-end"><?= $language::get('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td colspan="5" class="text-center py-4"><span class="spinner-border spinner-border-sm" aria-hidden="true"></span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="tab-pane fade" id="pane-store" role="tabpanel" aria-labelledby="tab-store">
        <?php if (empty($hasStoreKey)): ?>
            <div class="alert alert-warning"><?= $language::get('module_store_no_key'); ?> <a href="settings#api"><?= $language::get('module_store_settings_link'); ?></a></div>
        <?php endif; ?>
        <div class="card">
            <div class="card-header d-flex flex-wrap align-items-center gap-2">
                <div class="input-group input-group-sm" style="max-width:300px">
                    <span class="input-group-text"><i class="icon-base ti tabler-search"></i></span>
                    <input type="search" class="form-control" id="store-filter" placeholder="<?= htmlspecialchars($language::get('module_store_search'), ENT_QUOTES); ?>" aria-label="<?= htmlspecialchars($language::get('module_store_search'), ENT_QUOTES); ?>">
                </div>
                <span class="small text-body-secondary me-auto d-none d-md-inline"><?= $language::get('module_store_hint'); ?></span>
                <button type="button" class="btn btn-sm btn-label-secondary" id="store-refresh">
                    <i class="icon-base ti tabler-refresh me-1"></i><?= $language::get('refresh'); ?>
                </button>
            </div>
            <div class="table-responsive">
                <table class="table mb-0" id="store-table">
                    <thead>
                        <tr>
                            <th class="" data-sort="name"><button type="button" class="btn btn-link btn-sm p-0 text-reset fw-semibold text-uppercase text-nowrap js-store-sort" data-sort="name"><?= $language::get('name'); ?><i class="icon-base ti tabler-selector ms-1"></i></button></th>
                            <th class="d-none d-md-table-cell" data-sort="version"><button type="button" class="btn btn-link btn-sm p-0 text-reset fw-semibold text-uppercase text-nowrap js-store-sort" data-sort="version"><?= $language::get('version'); ?><i class="icon-base ti tabler-selector ms-1"></i></button></th>
                            <th class="d-none d-lg-table-cell"><?= $language::get('module_store_environment'); ?></th>
                            <th class="" data-sort="price"><button type="button" class="btn btn-link btn-sm p-0 text-reset fw-semibold text-uppercase text-nowrap js-store-sort" data-sort="price"><?= $language::get('module_store_price'); ?><i class="icon-base ti tabler-selector ms-1"></i></button></th>
                            <th class="" data-sort="status"><button type="button" class="btn btn-link btn-sm p-0 text-reset fw-semibold text-uppercase text-nowrap js-store-sort" data-sort="status"><?= $language::get('status'); ?><i class="icon-base ti tabler-selector ms-1"></i></button></th>
                            <th class="text-end"><?= $language::get('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
            <div class="card-footer d-flex flex-wrap align-items-center gap-2">
                <span class="small text-body-secondary me-auto" id="store-info"></span>
                <div class="btn-group btn-group-sm" role="group">
                    <button type="button" class="btn btn-label-secondary" id="store-prev" aria-label="<?= htmlspecialchars($language::get('module_page_prev'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-chevron-left"></i></button>
                    <button type="button" class="btn btn-label-secondary" id="store-next" aria-label="<?= htmlspecialchars($language::get('module_page_next'), ENT_QUOTES); ?>"><i class="icon-base ti tabler-chevron-right"></i></button>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="module-upload-modal" tabindex="-1" aria-labelledby="module-upload-title" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <form class="modal-content" id="module-upload-form" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title" id="module-upload-title"><?= $language::get('module_upload'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <label class="d-block p-4 border border-dashed rounded text-center mb-0" id="module-drop-zone" for="module-zip-input" style="border-width:2px !important;cursor:pointer;transition:background-color .2s">
                    <i class="icon-base ti tabler-cloud-upload d-block mb-2" style="font-size:2.5rem"></i>
                    <span class="d-block text-body-secondary mb-2"><?= $language::get('module_upload_hint'); ?></span>
                    <span class="d-block small text-truncate" id="module-zip-label"><?= $language::get('choose_file'); ?></span>
                </label>
                <input type="file" class="d-none" name="module_zip" id="module-zip-input" accept=".zip,.tar.gz,.tgz,.tar">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-label-secondary" data-bs-dismiss="modal"><?= $language::get('cancel'); ?></button>
                <button type="submit" class="btn btn-primary" id="module-upload-submit" disabled><i class="icon-base ti tabler-upload me-1"></i><?= $language::get('upload_andamp_install'); ?></button>
            </div>
        </form>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        'use strict';
        var T = <?= json_encode([
            'enabled' => $language::get('module_status_enabled'),
            'disabled' => $language::get('module_status_disabled'),
            'not_installed' => $language::get('module_status_not_installed'),
            'installing' => $language::get('module_status_installing'),
            'failed' => $language::get('module_status_failed'),
            'install' => $language::get('module_action_install'),
            'update_to' => $language::get('module_action_update_to'),
            'enable' => $language::get('enable'),
            'disable' => $language::get('disable'),
            'rollback' => $language::get('module_action_rollback'),
            'renew_license' => $language::get('module_action_renew_license'),
            'uninstall' => $language::get('module_action_uninstall'),
            'delete' => $language::get('delete'),
            'more' => $language::get('module_more_actions'),
            'issues' => $language::get('module_issues'),
            'confirm' => $language::get('module_confirm_action'),
            'job' => $language::get('module_job_running'),
            'check_updates' => $language::get('module_check_updates'),
            'error' => $language::get('module_request_failed'),
            'none' => $language::get('module_none'),
            'choose_file' => $language::get('choose_file'),
            'free' => $language::get('module_store_free'),
            'purchased' => $language::get('module_store_purchased'),
            'installed_v' => $language::get('module_store_installed'),
            'store_empty' => $language::get('module_store_empty'),
            'store_loading' => $language::get('please_wait'),
            'store_range' => $language::get('module_store_range'),
            'paid' => $language::get('module_store_paid'),
            'buy' => $language::get('module_store_buy'),
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

        var POLL_MS = 1500;
        var STORE_URL = <?= json_encode($storeUrl ?? 'https://www.xcvm.tech'); ?>;
        var rows = [];
        var job = null;
        var watchedJob = null; // id of a job this page waits on, to announce its end once
        var pollTimer = null;
        var toggling = {}; // module name → true while its enable/disable is in flight
        var storeLoaded = false;
        var tbody = document.querySelector('#modules-table tbody');
        var filterBox = document.getElementById('module-filter');

        function esc(s) {
            return String(s == null ? '' : s).replace(/[&<>"']/g, function(c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c];
            });
        }

        function fill(text, map) {
            return String(text).replace(/:(\w+)/g, function(m, k) {
                return map[k] != null ? map[k] : m;
            });
        }

        function toast(msg, type) {
            if (window.xcToast) {
                window.xcToast(msg, type);
            }
        }

        // One request to an admin-ajax action; resolves with its JSON, or a
        // {result:false} carrying a readable message when the answer was not JSON.
        function api(action, params, body) {
            var url = './api?action=' + encodeURIComponent(action) + (params ? '&' + new URLSearchParams(params).toString() : '');
            return fetch(url, {
                method: body ? 'POST' : 'GET',
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: body || undefined
            }).then(function(r) {
                return r.json();
            }).catch(function() {
                return {
                    result: false,
                    message: T.error
                };
            });
        }

        function jobRunning() {
            return !!job && (job.status === 'queued' || job.status === 'running');
        }

        // ---- installed modules ----

        function statusBadge(row) {
            var cls = {
                enabled: 'bg-label-success',
                disabled: 'bg-label-secondary',
                not_installed: 'bg-label-info',
                installing: 'bg-label-warning',
                failed: 'bg-label-danger'
            } [row.status] || 'bg-label-secondary';
            var html = '<span class="badge ' + cls + '">' + esc(T[row.status] || row.status) + '</span>';
            if (row.warnings && row.warnings.length) {
                html += ' <span class="badge bg-label-warning" title="' + esc(row.warnings.join(' ')) + '"><i class="icon-base ti tabler-alert-triangle me-1"></i>' + esc(T.issues) + '</span>';
            }
            return html;
        }

        // The one button a module most likely needs, then the rest in a menu.
        function actionsOf(row) {
            var list = [];
            var installed = row.installed !== '';
            if (!installed || row.status === 'failed') {
                list.push({ sub: 'install', label: T.install, cls: 'btn-primary' });
            }
            if (row.update_to) {
                list.push({ sub: 'update', label: fill(T.update_to, { version: row.update_to }), cls: 'btn-info' });
            }
            if (installed && row.status === 'enabled') {
                list.push({ sub: 'disable', label: T.disable, cls: 'btn-label-warning' });
            } else if (installed && row.status === 'disabled') {
                list.push({ sub: 'enable', label: T.enable, cls: 'btn-label-success' });
            }
            if (row.source === 'platform' && row.rollback_to) {
                list.push({ sub: 'rollback', label: T.rollback + ' (v' + row.rollback_to + ')', confirm: true });
            }
            if (row.source === 'platform') {
                list.push({ sub: 'renew_license', label: T.renew_license });
            }
            if (installed) {
                list.push({ sub: 'uninstall', label: T.uninstall, danger: true, confirm: true });
            }
            list.push({ sub: 'delete', label: T.delete, danger: true, confirm: true });
            return list;
        }

        function actionsHtml(row) {
            var busy = toggling[row.name] || (jobRunning() && job.target === row.name);
            if (busy) {
                return '<span class="spinner-border spinner-border-sm text-primary" role="status" aria-label="' + esc(T.store_loading) + '"></span>';
            }
            var list = actionsOf(row);
            var lock = jobRunning() ? ' disabled' : '';
            var first = list[0];
            var attrs = function(a) {
                return ' data-sub="' + esc(a.sub) + '" data-module="' + esc(row.name) + '"' + (a.confirm ? ' data-confirm="' + esc(a.label) + '"' : '');
            };
            var html = '<div class="btn-group">';
            html += '<button type="button" class="btn btn-sm ' + (first.cls || (first.danger ? 'btn-label-danger' : 'btn-label-secondary')) + ' js-mod"' + attrs(first) + (first.sub === 'enable' || first.sub === 'disable' ? '' : lock) + '>' + esc(first.label) + '</button>';
            if (list.length > 1) {
                html += '<button type="button" class="btn btn-sm btn-label-secondary dropdown-toggle dropdown-toggle-split" data-bs-toggle="dropdown" aria-expanded="false"><span class="visually-hidden">' + esc(T.more) + '</span></button><ul class="dropdown-menu dropdown-menu-end">';
                list.slice(1).forEach(function(a) {
                    var off = a.sub === 'enable' || a.sub === 'disable' ? '' : lock;
                    html += '<li><button type="button" class="dropdown-item js-mod' + (a.danger ? ' text-danger' : '') + '"' + attrs(a) + off + '>' + esc(a.label) + '</button></li>';
                });
                html += '</ul>';
            }
            return html + '</div>';
        }

        function paint() {
            var needle = filterBox.value.trim().toLowerCase();
            var shown = rows.filter(function(r) {
                return !needle || (r.name + ' ' + r.description).toLowerCase().indexOf(needle) !== -1;
            });
            if (!shown.length) {
                tbody.innerHTML = '<tr><td colspan="5" class="text-center text-body-secondary py-4">' + esc(T.none) + '</td></tr>';
                return;
            }
            tbody.innerHTML = shown.map(function(r) {
                var version = r.installed || r.version || '-';
                return '<tr>' +
                    '<td><div class="fw-medium">' + esc(r.name) + '</div><div class="small text-body-secondary d-lg-none">' + esc(r.description) + '</div></td>' +
                    '<td class="d-none d-lg-table-cell">' + esc(r.description || '-') + '</td>' +
                    '<td class="d-none d-md-table-cell text-nowrap">' + esc(version) + (r.requires_core ? '<div class="small text-body-secondary">core ' + esc(r.requires_core) + '</div>' : '') + '</td>' +
                    '<td class="text-nowrap">' + statusBadge(r) + '</td>' +
                    '<td class="text-end text-nowrap">' + actionsHtml(r) + '</td>' +
                    '</tr>';
            }).join('');
        }

        function paintJob() {
            var box = document.getElementById('module-job');
            var running = jobRunning();
            box.classList.toggle('d-none', !running);
            if (running) {
                var what = job.action === 'check_updates' ? T.check_updates : (job.action === 'upload_install' ? '' : job.target);
                document.getElementById('module-job-text').textContent = fill(T.job, { action: job.action.replace('_', ' '), name: what });
            }
            document.querySelectorAll('.js-job-button').forEach(function(b) {
                b.disabled = running;
            });
            document.querySelectorAll('.js-store-install').forEach(function(b) {
                b.disabled = running || b.dataset.locked === '1';
            });
        }

        // Take an answer that carries the modules and/or the job, and redraw.
        function take(resp) {
            if (resp.rows) {
                rows = resp.rows;
            }
            if ('job' in resp) {
                job = resp.job;
            }
            // A job this page waited on has ended: say how, once.
            if (job && watchedJob === job.id && !jobRunning()) {
                watchedJob = null;
                toast(job.message || job.status, job.status === 'done' ? 'success' : 'error');
                if (storeLoaded) {
                    loadStore(false);
                }
            }
            if (jobRunning() && watchedJob === null) {
                watchedJob = job.id; // picked up a job started elsewhere / before a reload
            }
            paint();
            paintJob();
            schedulePoll();
        }

        function schedulePoll() {
            clearTimeout(pollTimer);
            if (jobRunning()) {
                pollTimer = setTimeout(refresh, POLL_MS);
            }
        }

        function refresh() {
            return api('module_status').then(function(resp) {
                if (resp.result === false) {
                    toast(resp.message || T.error, 'error');
                    schedulePoll();
                    return;
                }
                take(resp);
            });
        }

        function post(sub, name) {
            var body = new FormData();
            body.append('sub', sub);
            body.append('name', name || '');
            return api('module', null, body);
        }

        function runAction(sub, name) {
            if (sub === 'enable' || sub === 'disable') {
                toggling[name] = true;
                paint();
                post(sub, name).then(function(resp) {
                    delete toggling[name];
                    toast(resp.message || T.error, resp.result === false ? 'error' : 'success');
                    take(resp);
                });
                return;
            }
            post(sub, name).then(function(resp) {
                if (resp.result === false) {
                    toast(resp.message || T.error, 'error');
                    take(resp);
                    return;
                }
                watchedJob = resp.job.id;
                take(resp);
            });
        }

        tbody.addEventListener('click', function(e) {
            var btn = e.target.closest('.js-mod');
            if (!btn || btn.disabled) {
                return;
            }
            var sub = btn.dataset.sub;
            var name = btn.dataset.module;
            if (!btn.dataset.confirm || !window.xcConfirm) {
                runAction(sub, name);
                return;
            }
            window.xcConfirm(fill(T.confirm, { action: btn.dataset.confirm.toLowerCase(), name: name })).then(function(ok) {
                if (ok) {
                    runAction(sub, name);
                }
            });
        });

        filterBox.addEventListener('input', paint);

        document.getElementById('module-check-updates').addEventListener('click', function() {
            runAction('check_updates', '');
        });

        // ---- upload ----

        var zipInput = document.getElementById('module-zip-input');
        var dropZone = document.getElementById('module-drop-zone');
        var uploadForm = document.getElementById('module-upload-form');

        function chosen() {
            var f = zipInput.files && zipInput.files[0];
            document.getElementById('module-zip-label').textContent = f ? f.name : T.choose_file;
            document.getElementById('module-upload-submit').disabled = !f;
        }
        zipInput.addEventListener('change', chosen);
        ['dragenter', 'dragover'].forEach(function(ev) {
            dropZone.addEventListener(ev, function(e) {
                e.preventDefault();
                dropZone.classList.add('bg-label-primary');
            });
        });
        ['dragleave', 'drop'].forEach(function(ev) {
            dropZone.addEventListener(ev, function(e) {
                e.preventDefault();
                dropZone.classList.remove('bg-label-primary');
            });
        });
        dropZone.addEventListener('drop', function(e) {
            if (e.dataTransfer && e.dataTransfer.files.length) {
                zipInput.files = e.dataTransfer.files;
                chosen();
            }
        });
        uploadForm.addEventListener('submit', function(e) {
            e.preventDefault();
            var submit = document.getElementById('module-upload-submit');
            submit.disabled = true;
            api('module_upload', null, new FormData(uploadForm)).then(function(resp) {
                if (resp.result === false) {
                    toast(resp.message || T.error, 'error');
                    chosen();
                    return;
                }
                bootstrap.Modal.getOrCreateInstance(document.getElementById('module-upload-modal')).hide();
                uploadForm.reset();
                chosen();
                watchedJob = resp.job.id;
                take(resp);
            });
        });

        // ---- store ----

        // Rendered a page at a time: the store can list thousands of modules.
        var STORE_PAGE = 50;
        var storeBody = document.querySelector('#store-table tbody');
        var storeFilter = document.getElementById('store-filter');
        var storeAll = [];
        var storePage = 0;

        function priceText(m) {
            return m.price > 0 ? '$' + Number(m.price).toFixed(2) : T.free;
        }

        function storeRow(m) {
            var badge = {
                free: '<span class="badge bg-label-success">' + esc(T.free) + '</span>',
                purchased: '<span class="badge bg-label-primary">' + esc(T.purchased) + '</span>',
                paid: '<span class="badge bg-label-warning">' + esc(T.paid) + '</span>'
            } [m.badge] || '';
            var state = m.installed_version ? ' <span class="badge bg-label-secondary">' + esc(fill(T.installed_v, { version: m.installed_version })) + '</span>' : '';
            var action;
            if (m.badge === 'paid' && !m.installed_version) {
                // Not bought: the store's page sells it; Install would be refused (not_entitled).
                action = '<a class="btn btn-sm btn-label-primary text-nowrap" target="_blank" rel="noopener" href="' + esc(STORE_URL + '/extensions/' + encodeURIComponent(m.slug)) + '"><i class="icon-base ti tabler-shopping-cart me-1"></i>' + esc(T.buy) + '</a>';
            } else {
                var locked = m.installed_version && !m.update_to;
                var label = m.update_to ? fill(T.update_to, { version: m.update_to }) : (locked ? fill(T.installed_v, { version: m.installed_version }) : T.install);
                action = '<button type="button" class="btn btn-sm text-nowrap ' + (locked ? 'btn-label-secondary' : 'btn-primary') + ' js-store-install" data-slug="' + esc(m.slug) + '" data-locked="' + (locked ? '1' : '0') + '"' + (locked || jobRunning() ? ' disabled' : '') + '>' + esc(label) + '</button>';
            }
            return '<tr>' +
                '<td><div class="fw-medium">' + esc(m.name) + '</div><div class="small text-body-secondary"><code>' + esc(m.slug) + '</code><span class="d-md-none"> · v' + esc(m.version) + '</span></div></td>' +
                '<td class="d-none d-md-table-cell text-nowrap">' + esc(m.version) + (m.compatibility ? '<div class="small text-body-secondary">core ' + esc(m.compatibility) + '</div>' : '') + '</td>' +
                '<td class="d-none d-lg-table-cell">' + esc(m.environment || '-') + '</td>' +
                '<td class="text-nowrap">' + esc(priceText(m)) + '</td>' +
                '<td class="text-nowrap">' + badge + state + '</td>' +
                '<td class="text-end">' + action + '</td>' +
                '</tr>';
        }

        // Sorting: a column and a direction; the Status column orders what can
        // be done first (an update, then installed, bought, free, for sale).
        var storeSort = { key: 'name', dir: 1 };
        var STATUS_ORDER = { update: 0, installed: 1, purchased: 2, free: 3, paid: 4 };

        function statusRank(m) {
            return STATUS_ORDER[m.update_to ? 'update' : (m.installed_version ? 'installed' : m.badge)];
        }

        function versionCmp(a, b) {
            var x = String(a).split('.'), y = String(b).split('.');
            for (var i = 0; i < Math.max(x.length, y.length); i++) {
                var d = (parseInt(x[i], 10) || 0) - (parseInt(y[i], 10) || 0);
                if (d) {
                    return d;
                }
            }
            return 0;
        }

        function storeCompare(a, b) {
            var d = 0;
            if (storeSort.key === 'version') {
                d = versionCmp(a.version, b.version);
            } else if (storeSort.key === 'price') {
                d = a.price - b.price;
            } else if (storeSort.key === 'status') {
                d = statusRank(a) - statusRank(b);
            }
            return (d || a.name.localeCompare(b.name, undefined, { sensitivity: 'base' })) * storeSort.dir;
        }

        function paintSort() {
            document.querySelectorAll('#store-table th[data-sort]').forEach(function(th) {
                var on = th.dataset.sort === storeSort.key;
                th.setAttribute('aria-sort', on ? (storeSort.dir > 0 ? 'ascending' : 'descending') : 'none');
                th.querySelector('i').className = 'icon-base ti ms-1 ' + (on ? (storeSort.dir > 0 ? 'tabler-sort-ascending' : 'tabler-sort-descending') : 'tabler-selector');
            });
        }

        function storeMessage(html) {
            storeBody.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-5">' + html + '</td></tr>';
            document.getElementById('store-info').textContent = '';
            document.getElementById('store-prev').disabled = true;
            document.getElementById('store-next').disabled = true;
        }

        function paintStore() {
            var needle = storeFilter.value.trim().toLowerCase();
            var shown = storeAll.filter(function(m) {
                return !needle || (m.name + ' ' + m.slug + ' ' + m.environment).toLowerCase().indexOf(needle) !== -1;
            }).sort(storeCompare);
            paintSort();
            if (!shown.length) {
                storeMessage(esc(storeAll.length ? T.none : T.store_empty));
                return;
            }
            var pages = Math.ceil(shown.length / STORE_PAGE);
            storePage = Math.min(storePage, pages - 1);
            var from = storePage * STORE_PAGE;
            var to = Math.min(from + STORE_PAGE, shown.length);
            storeBody.innerHTML = shown.slice(from, to).map(storeRow).join('');
            document.getElementById('store-info').textContent = fill(T.store_range, { from: from + 1, to: to, total: shown.length });
            document.getElementById('store-prev').disabled = storePage === 0;
            document.getElementById('store-next').disabled = storePage >= pages - 1;
        }

        function loadStore(refreshCache) {
            storeLoaded = true;
            storeMessage('<span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>' + esc(T.store_loading));
            api('module_store', refreshCache ? { refresh: 1 } : null).then(function(resp) {
                if (resp.result === false) {
                    storeMessage(esc(resp.message || T.error));
                    return;
                }
                storeAll = resp.modules;
                paintStore();
            });
        }

        document.querySelector('#store-table thead').addEventListener('click', function(e) {
            var btn = e.target.closest('.js-store-sort');
            if (!btn) {
                return;
            }
            storeSort.dir = storeSort.key === btn.dataset.sort ? -storeSort.dir : 1;
            storeSort.key = btn.dataset.sort;
            storePage = 0;
            paintStore();
        });
                storeFilter.addEventListener('input', function() {
            storePage = 0;
            paintStore();
        });
        document.getElementById('store-prev').addEventListener('click', function() {
            storePage--;
            paintStore();
        });
        document.getElementById('store-next').addEventListener('click', function() {
            storePage++;
            paintStore();
        });
        document.getElementById('tab-store').addEventListener('shown.bs.tab', function() {
            if (!storeLoaded) {
                loadStore(false);
            }
        });
        document.getElementById('store-refresh').addEventListener('click', function() {
            loadStore(true);
        });
        storeBody.addEventListener('click', function(e) {
            var btn = e.target.closest('.js-store-install');
            if (!btn || btn.disabled) {
                return;
            }
            btn.disabled = true;
            runAction('store_install', btn.dataset.slug);
        });

        refresh();
    })();
</script>
</body>

</html>
