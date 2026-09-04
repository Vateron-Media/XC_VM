<?php

/**
 * Modules (Bootstrap 5). Marketplace / ZIP module management: install from store, upload a
 * ZIP, and the installed-modules table with per-module install / update / rollback / renew /
 * enable-disable / uninstall / delete actions. Every action POSTs module_action to the page
 * itself (ModulesController) and gets back a JSON flash; the table body is re-fetched and
 * swapped in place. Reached full-page in the new-UI shell.
 */
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= $language::get('modules'); ?></h4>
</div>

<?php if (!empty($moduleFlash)): ?>
    <div class="alert alert-<?= htmlspecialchars($moduleFlash['type'], ENT_QUOTES); ?> alert-dismissible" role="alert">
        <?= htmlspecialchars($moduleFlash['message'], ENT_QUOTES); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div id="module-flash"></div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="icon-base ti tabler-building-store me-1"></i>Install from store</h5>
    </div>
    <div class="card-body">
        <p class="text-body-secondary mb-2">Paste the module <strong>slug</strong> from the platform store page. The panel always installs the <strong>latest</strong> version with this server's install_id; if the module manifest targets LB, MAIN distributes it to all load balancers automatically. A failed install is rolled back automatically; use the <strong>Rollback</strong> button in the table to revert a store module to its previous version.</p>
        <p class="text-body-secondary mb-3"><small><i class="icon-base ti tabler-info-circle me-1"></i>Set the <strong>Modules API Key</strong> under <a href="settings#api">Settings → API</a> before installing.</small></p>
        <form action="#" method="POST" class="js-module-form">
            <input type="hidden" name="module_action" value="platform_install">
            <div class="row g-2">
                <div class="col-md-9"><input type="text" class="form-control" name="module_slug" placeholder="module-slug" required></div>
                <div class="col-md-3"><button type="submit" class="btn btn-primary w-100"><i class="icon-base ti tabler-download me-1"></i>Install latest</button></div>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4">
    <div class="card-header">
        <h5 class="card-title mb-0"><i class="icon-base ti tabler-package me-1"></i>Upload Module ZIP</h5>
    </div>
    <div class="card-body">
        <form action="#" method="POST" enctype="multipart/form-data" class="js-module-form" id="module-upload-form">
            <input type="hidden" name="module_action" value="upload_install">
            <div class="p-4 border border-dashed rounded text-center" id="module-drop-zone" style="border-width:2px !important;cursor:pointer;transition:background-color .2s">
                <i class="icon-base ti tabler-cloud-upload d-block mb-2" style="font-size:2.5rem"></i>
                <p class="text-body-secondary mb-2">Drag &amp; drop a <code>.zip</code> or <code>.tar.gz</code> module here or click to browse</p>
                <div class="mx-auto" style="max-width:400px">
                    <input type="file" class="form-control" name="module_zip" id="module_zip_input" accept=".zip,.tar.gz,.tgz,.tar" required>
                    <div class="text-body-secondary small mt-1" id="module_zip_label"><?= $language::get('choose_file'); ?></div>
                </div>
            </div>
            <div class="text-end mt-3">
                <button type="submit" class="btn btn-primary" id="module_upload_btn" disabled><i class="icon-base ti tabler-upload me-1"></i><?= $language::get('upload_andamp_install'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
        <h5 class="card-title mb-0"><i class="icon-base ti tabler-apps me-1"></i>Installed modules</h5>
        <form action="#" method="POST" class="mb-0 js-module-form">
            <input type="hidden" name="module_action" value="check_updates">
            <button type="submit" class="btn btn-sm btn-label-primary" title="Query each installed module's update source now and refresh the Update buttons (also runs weekly by cron)"><i class="icon-base ti tabler-refresh me-1"></i>Check for updates</button>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table mb-0" id="modules-table">
            <thead>
                <tr>
                    <th><?= $language::get('name'); ?></th>
                    <th><?= $language::get('description'); ?></th>
                    <th><?= $language::get('version'); ?></th>
                    <th><?= $language::get('requires_core'); ?></th>
                    <th><?= $language::get('status'); ?></th>
                    <th class="text-end"><?= $language::get('actions'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($modules)): ?>
                    <tr><td colspan="6" class="text-center text-body-secondary">No modules found.</td></tr>
                <?php else: ?>
                    <?php foreach ($modules as $module): ?>
                        <tr>
                            <td class="fw-medium"><?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?></td>
                            <td><?= htmlspecialchars((string) ($module['description'] ?: '-'), ENT_QUOTES); ?></td>
                            <td><?= htmlspecialchars((string) ($module['version'] ?: '-'), ENT_QUOTES); ?></td>
                            <td><?= htmlspecialchars((string) ($module['requires_core'] ?: '-'), ENT_QUOTES); ?></td>
                            <td>
                                <span class="badge module-status-badge <?= !empty($module['enabled']) ? 'bg-label-success' : 'bg-label-secondary'; ?>" data-module="<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>"><?= !empty($module['enabled']) ? 'Enabled' : 'Disabled'; ?></span>
                                <?php if (!empty($module['dependency_warnings'])): ?>
                                    <span class="badge bg-label-warning module-dep-warning" data-module="<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>" title="<?= htmlspecialchars(implode(' ', $module['dependency_warnings']), ENT_QUOTES); ?>"><i class="icon-base ti tabler-alert-triangle me-1"></i><?= count($module['dependency_warnings']) === 1 ? 'Dependency issue' : 'Dependency issues'; ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end">
                                <div class="btn-group" role="group">
                                    <?php if (($module['installed_version'] ?? '') === ''): ?>
                                        <form action="#" method="POST" class="me-1 js-module-form">
                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>">
                                            <input type="hidden" name="module_action" value="install">
                                            <button type="submit" class="btn btn-sm btn-primary">Install</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php $rAvailable = ($module['available_version'] ?? '') !== '' ? (string) $module['available_version'] : (string) ($module['version'] ?? ''); ?>
                                    <?php if (($module['installed_version'] ?? '') !== '' && version_compare($rAvailable, (string) $module['installed_version'], '>')): ?>
                                        <form action="#" method="POST" class="me-1 js-module-form">
                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>">
                                            <input type="hidden" name="module_action" value="update">
                                            <button type="submit" class="btn btn-sm btn-info" title="New version <?= htmlspecialchars($rAvailable, ENT_QUOTES); ?> available">Update to <?= htmlspecialchars($rAvailable, ENT_QUOTES); ?></button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (($module['source'] ?? '') === 'platform' && !empty($module['previous_version'])): ?>
                                        <form action="#" method="POST" class="me-1 js-module-form" data-confirm="Roll back <?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?> to version <?= htmlspecialchars((string) $module['previous_version'], ENT_QUOTES); ?>?">
                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>">
                                            <input type="hidden" name="module_action" value="platform_rollback">
                                            <button type="submit" class="btn btn-sm btn-label-secondary" title="Roll back to v<?= htmlspecialchars((string) $module['previous_version'], ENT_QUOTES); ?>"><i class="icon-base ti tabler-history me-1"></i>Rollback</button>
                                        </form>
                                    <?php endif; ?>
                                    <?php if (($module['source'] ?? '') === 'platform'): ?>
                                        <form action="#" method="POST" class="me-1 js-module-form">
                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>">
                                            <input type="hidden" name="module_action" value="renew_license">
                                            <button type="submit" class="btn btn-sm btn-label-secondary" title="Re-issue the per-machine ionCube license (use if the module fails to load with a license error)"><i class="icon-base ti tabler-key me-1"></i>Renew license</button>
                                        </form>
                                    <?php endif; ?>
                                    <button type="button" class="btn btn-sm me-1 module-toggle-btn <?= !empty($module['enabled']) ? 'btn-warning' : 'btn-success'; ?>" data-module="<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>" data-enabled="<?= !empty($module['enabled']) ? '1' : '0'; ?>"><?= !empty($module['enabled']) ? 'Disable' : 'Enable'; ?></button>
                                    <?php if (($module['installed_version'] ?? '') !== ''): ?>
                                        <form action="#" method="POST" class="me-1 js-module-form" data-confirm="<?= htmlspecialchars($language::get('confirm_uninstall_module', [':name' => $module['name']]), ENT_QUOTES); ?>">
                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>">
                                            <input type="hidden" name="module_action" value="uninstall">
                                            <button type="submit" class="btn btn-sm btn-danger">Uninstall</button>
                                        </form>
                                    <?php endif; ?>
                                    <form action="#" method="POST" class="js-module-form" data-confirm="Delete module '<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>' completely — remove its files and drop its tables? A bundled module returns on the next panel update.">
                                        <input type="hidden" name="module_name" value="<?= htmlspecialchars((string) $module['name'], ENT_QUOTES); ?>">
                                        <input type="hidden" name="module_action" value="delete">
                                        <button type="submit" class="btn btn-sm btn-label-danger">Delete</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
    (function () {
        'use strict';
        var endpoint = window.location.href.split('#')[0];
        var CHOOSE_FILE = <?= json_encode($language::get('choose_file')); ?>;
        var TOGGLE_FAIL = <?= json_encode($language::get('failed_toggle_module')); ?>;

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; });
        }
        function showFlash(type, message) {
            var box = document.getElementById('module-flash');
            var cls = ['success', 'warning', 'danger', 'info'].indexOf(type) !== -1 ? type : 'info';
            if (window.xcToast) { window.xcToast(message || '', cls === 'danger' ? 'error' : (cls === 'success' ? 'success' : (cls === 'warning' ? 'warning' : 'info'))); }
            if (!box) { return; }
            box.innerHTML = '<div class="alert alert-' + cls + ' alert-dismissible" role="alert">' + escapeHtml(message || '') + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
            box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }
        function postAction(formData) {
            return fetch(endpoint, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' }, credentials: 'same-origin', body: formData })
                .then(function (r) { return r.json().catch(function () { return { type: 'danger', message: 'Unexpected server response.' }; }); });
        }
        function refreshTable() {
            return fetch(endpoint, { credentials: 'same-origin' }).then(function (r) { return r.text(); }).then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var fresh = doc.querySelector('#modules-table tbody');
                var current = document.querySelector('#modules-table tbody');
                if (fresh && current) { current.innerHTML = fresh.innerHTML; }
            }).catch(function () {});
        }

        // File input + drag & drop.
        var input = document.getElementById('module_zip_input');
        var label = document.getElementById('module_zip_label');
        var uploadBtn = document.getElementById('module_upload_btn');
        var zone = document.getElementById('module-drop-zone');
        if (input) {
            input.addEventListener('change', function () {
                if (label) { label.textContent = this.files[0] ? this.files[0].name : CHOOSE_FILE; }
                if (uploadBtn) { uploadBtn.disabled = !this.files.length; }
            });
        }
        if (zone) {
            zone.addEventListener('click', function (e) { if (input && e.target !== input && e.target.tagName !== 'A') { input.click(); } });
            ['dragover', 'dragenter'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.style.backgroundColor = 'rgba(105,108,255,0.06)'; }); });
            ['dragleave', 'drop'].forEach(function (ev) { zone.addEventListener(ev, function (e) { e.preventDefault(); zone.style.backgroundColor = ''; }); });
            zone.addEventListener('drop', function (e) { if (input && e.dataTransfer.files.length) { input.files = e.dataTransfer.files; input.dispatchEvent(new Event('change')); } });
        }
        function resetUploadForm(form) { form.reset(); if (label) { label.textContent = CHOOSE_FILE; } if (uploadBtn) { uploadBtn.disabled = true; } }

        // AJAX submit for every module action form.
        document.addEventListener('submit', function (e) {
            var form = e.target.closest('.js-module-form');
            if (!form) { return; }
            e.preventDefault();
            var confirmMsg = form.getAttribute('data-confirm');
            var _proceed = function () {
                var btn = form.querySelector('[type="submit"]');
                var originalHtml = btn ? btn.innerHTML : '';
                var isUpload = form.id === 'module-upload-form';
                if (btn) { btn.disabled = true; btn.innerHTML = '<i class="icon-base ti tabler-loader me-1"></i>…'; }
                postAction(new FormData(form)).then(function (resp) {
                    showFlash(resp.type, resp.message);
                    if (resp.type !== 'danger') { if (isUpload) { resetUploadForm(form); } return refreshTable(); }
                }).catch(function () { showFlash('danger', 'Request failed.'); }).finally(function () {
                    if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
                });
            };
            if (confirmMsg) { (window.xcConfirm ? window.xcConfirm(confirmMsg) : Promise.resolve(confirm(confirmMsg))).then(function (ok) { if (ok) { _proceed(); } }); }
            else { _proceed(); }
        });

        // Enable/disable toggle (optimistic in-place update).
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.module-toggle-btn');
            if (!btn) { return; }
            e.preventDefault();
            var moduleName = btn.getAttribute('data-module');
            var isEnabled = btn.getAttribute('data-enabled') === '1';
            btn.disabled = true;
            btn.innerHTML = '<i class="icon-base ti tabler-loader me-1"></i>…';
            var fd = new FormData();
            fd.append('module_name', moduleName);
            fd.append('module_action', isEnabled ? 'disable' : 'enable');
            postAction(fd).then(function (resp) {
                if (resp && resp.type === 'danger') { btn.disabled = false; btn.innerHTML = isEnabled ? 'Disable' : 'Enable'; showFlash('danger', resp.message || 'Operation failed.'); return; }
                var nowEnabled = !isEnabled;
                btn.setAttribute('data-enabled', nowEnabled ? '1' : '0');
                btn.className = 'btn btn-sm me-1 module-toggle-btn ' + (nowEnabled ? 'btn-warning' : 'btn-success');
                btn.innerHTML = nowEnabled ? 'Disable' : 'Enable';
                btn.disabled = false;
                var badge = document.querySelector('.module-status-badge[data-module="' + moduleName + '"]');
                if (badge) { badge.className = 'badge module-status-badge ' + (nowEnabled ? 'bg-label-success' : 'bg-label-secondary'); badge.textContent = nowEnabled ? 'Enabled' : 'Disabled'; }
                if (resp && resp.message) { showFlash(resp.type || 'success', resp.message); }
            }).catch(function () { btn.disabled = false; btn.innerHTML = isEnabled ? 'Disable' : 'Enable'; showFlash('danger', TOGGLE_FAIL); });
        });
    })();
</script>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
