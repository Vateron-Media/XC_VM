<div class="wrapper boxed-layout-ext" <?php 
use XcVm\Core\Http\Request;if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
                                            echo ' style="display: none;"';
                                        } ?>>
    <div class="container-fluid">
        <div class="row">
            <div class="col-12">
                <div class="page-title-box">
                    <div class="page-title-right">
                        <?php include 'topbar.php'; ?>
                    </div>
                    <h4 class="page-title"><?= $language::get('modules') ?></h4>
                </div>
            </div>
        </div>

        <?php if (!empty($moduleFlash)): ?>
            <div class="alert alert-<?= htmlspecialchars($moduleFlash['type']) ?> alert-dismissible fade show" role="alert">
                <button type="button" class="close" data-dismiss="alert" aria-label="Close">
                    <span aria-hidden="true">&times;</span>
                </button>
                <?= htmlspecialchars($moduleFlash['message']) ?>
            </div>
        <?php endif; ?>

        <!-- AJAX flash messages are injected here -->
        <div id="module-flash"></div>

        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><i class="mdi mdi-store mr-1"></i> Install from store</h5>
                        <p class="text-muted mb-2">Paste the module <strong>slug</strong> from the platform store page. The panel always installs the <strong>latest</strong> version with this server's install_id; if the module manifest targets LB, MAIN distributes it to all load balancers automatically. A failed install is rolled back automatically; use the <strong>Rollback</strong> button in the table to revert a store module to its previous version.</p>
                        <p class="text-muted mb-2"><small><i class="mdi mdi-information-outline mr-1"></i>Set the <strong>Modules API Key</strong> under <a href="settings#api">Settings → API</a> before installing.</small></p>
                        <form action="#" method="POST" class="mb-4 js-module-form">
                            <input type="hidden" name="module_action" value="platform_install" />
                            <div class="form-row">
                                <div class="col-md-9 mb-2">
                                    <input type="text" class="form-control" name="module_slug" placeholder="module-slug" required>
                                </div>
                                <div class="col-md-3 mb-2 text-right">
                                    <button type="submit" class="btn btn-primary btn-block">
                                        <i class="mdi mdi-download mr-1"></i> Install latest
                                    </button>
                                </div>
                            </div>
                        </form>

                        <h5 class="card-title"><i class="mdi mdi-package-variant-closed mr-1"></i> Upload Module ZIP</h5>
                        <form action="#" method="POST" enctype="multipart/form-data" class="mb-4 js-module-form" id="module-upload-form">
                            <input type="hidden" name="module_action" value="upload_install" />
                            <div class="p-3 border border-dashed rounded text-center" id="module-drop-zone" style="border-style: dashed !important; border-width: 2px !important; cursor: pointer; transition: background-color 0.2s;">
                                <i class="mdi mdi-cloud-upload-outline d-block mb-2" style="font-size: 2.5rem; color: #6c757d;"></i>
                                <p class="text-muted mb-2">Drag & drop a <code>.zip</code> module here or click to browse</p>
                                <div class="custom-file mx-auto" style="max-width: 400px;">
                                    <input type="file" class="custom-file-input" name="module_zip" id="module_zip_input" accept=".zip" required>
                                    <label class="custom-file-label" for="module_zip_input" data-browse="Browse"><?= $language::get('choose_file') ?></label>
                                </div>
                                <small class="text-muted d-block mt-2">Only <code>.zip</code> packages are accepted</small>
                            </div>
                            <div class="text-right mt-3">
                                <button type="submit" class="btn btn-primary" id="module_upload_btn" disabled>
                                    <i class="mdi mdi-upload mr-1"></i> <?= $language::get('upload_andamp_install') ?>
                                </button>
                            </div>
                        </form>
                        <div class="table-responsive">
                            <table class="table table-striped table-borderless mb-0" id="modules-table">
                                <thead>
                                    <tr>
                                        <th><?= $language::get('name') ?></th>
                                        <th><?= $language::get('description') ?></th>
                                        <th><?= $language::get('version') ?></th>
                                        <th><?= $language::get('requires_core') ?></th>
                                        <th><?= $language::get('status') ?></th>
                                        <th class="text-right"><?= $language::get('actions') ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($modules)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center text-muted">No modules found.</td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($modules as $module): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($module['name']) ?></strong></td>
                                                <td><?= htmlspecialchars($module['description'] ?: '-') ?></td>
                                                <td><?= htmlspecialchars($module['version'] ?: '-') ?></td>
                                                <td><?= htmlspecialchars($module['requires_core'] ?: '-') ?></td>
                                                <td>
                                                    <span class="badge module-status-badge <?= !empty($module['enabled']) ? 'badge-success' : 'badge-secondary' ?>"
                                                        data-module="<?= htmlspecialchars($module['name']) ?>">
                                                        <?= !empty($module['enabled']) ? 'Enabled' : 'Disabled' ?>
                                                    </span>
                                                </td>
                                                <td class="text-right">
                                                    <div class="btn-group" role="group">
                                                        <form action="#" method="POST" class="mr-1 js-module-form">
                                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                            <input type="hidden" name="module_action" value="install">
                                                            <button type="submit" class="btn btn-sm btn-primary"></i>Install</button>
                                                        </form>

                                                        <form action="#" method="POST" class="mr-1 js-module-form">
                                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                            <input type="hidden" name="module_action" value="update">
                                                            <button type="submit" class="btn btn-sm btn-info"></i>Update</button>
                                                        </form>

                                                        <?php if (($module['source'] ?? '') === 'platform' && !empty($module['previous_version'])): ?>
                                                            <form action="#" method="POST" class="mr-1 js-module-form"
                                                                  data-confirm="Roll back <?= htmlspecialchars($module['name'], ENT_QUOTES) ?> to version <?= htmlspecialchars($module['previous_version'], ENT_QUOTES) ?>?">
                                                                <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                                <input type="hidden" name="module_action" value="platform_rollback">
                                                                <button type="submit" class="btn btn-sm btn-secondary"
                                                                        title="Roll back to v<?= htmlspecialchars($module['previous_version']) ?>">
                                                                    <i class="mdi mdi-history mr-1"></i>Rollback
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>

                                                        <?php if (($module['source'] ?? '') === 'platform'): ?>
                                                            <form action="#" method="POST" class="mr-1 js-module-form">
                                                                <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                                <input type="hidden" name="module_action" value="renew_license">
                                                                <button type="submit" class="btn btn-sm btn-outline-secondary"
                                                                        title="Re-issue the per-machine ionCube license (use if the module fails to load with a license error)">
                                                                    <i class="mdi mdi-key-outline mr-1"></i>Renew license
                                                                </button>
                                                            </form>
                                                        <?php endif; ?>

                                                        <button type="button"
                                                            class="btn btn-sm mr-1 module-toggle-btn <?= !empty($module['enabled']) ? 'btn-warning' : 'btn-success' ?>"
                                                            data-module="<?= htmlspecialchars($module['name']) ?>"
                                                            data-enabled="<?= !empty($module['enabled']) ? '1' : '0' ?>">
                                                            <?= !empty($module['enabled']) ? 'Disable' : 'Enable' ?>
                                                        </button>

                                                        <form action="#" method="POST" class="js-module-form"
                                                              data-confirm="<?= htmlspecialchars($language::get('confirm_uninstall_module', [':name' => $module['name']]), ENT_QUOTES) ?>">
                                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                            <input type="hidden" name="module_action" value="uninstall">
                                                            <button type="submit" class="btn btn-sm btn-danger"></i>Uninstall</button>
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
                </div>
            </div>
        </div>
    </div>
</div>

<script>
    (function () {
        'use strict';

        var endpoint = window.location.href.split('#')[0];
        var CHOOSE_FILE = <?= json_encode($language::get('choose_file')) ?>;
        var TOGGLE_FAIL = <?= json_encode($language::get('failed_toggle_module')) ?>;

        // ---- shared helpers ---------------------------------------------------

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function (c) {
                return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
            });
        }

        function showFlash(type, message) {
            var box = document.getElementById('module-flash');
            var cls = ['success', 'warning', 'danger', 'info'].indexOf(type) !== -1 ? type : 'info';
            if (!box) { window.alert(message); return; }
            box.innerHTML =
                '<div class="alert alert-' + cls + ' alert-dismissible fade show" role="alert">' +
                '<button type="button" class="close" data-dismiss="alert" aria-label="Close">' +
                '<span aria-hidden="true">&times;</span></button>' +
                escapeHtml(message || '') + '</div>';
            box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        // POST an action to the controller; resolves with its JSON flash.
        function postAction(formData) {
            return fetch(endpoint, {
                method: 'POST',
                headers: { 'X-Requested-With': 'XMLHttpRequest' },
                credentials: 'same-origin',
                body: formData
            }).then(function (r) {
                return r.json().catch(function () {
                    return { type: 'danger', message: 'Unexpected server response.' };
                });
            });
        }

        // Re-fetch the page and swap just the modules table body so install/
        // update/uninstall/rollback reflect new state without a full reload.
        function refreshTable() {
            return fetch(endpoint, { credentials: 'same-origin' })
                .then(function (r) { return r.text(); })
                .then(function (html) {
                    var doc = new DOMParser().parseFromString(html, 'text/html');
                    var fresh = doc.querySelector('#modules-table tbody');
                    var current = document.querySelector('#modules-table tbody');
                    if (fresh && current) { current.innerHTML = fresh.innerHTML; }
                })
                .catch(function () { /* keep stale table; flash already shown */ });
        }

        // ---- drag & drop / file label ----------------------------------------

        var input = document.getElementById('module_zip_input');
        var label = input ? input.nextElementSibling : null;
        var uploadBtn = document.getElementById('module_upload_btn');
        var zone = document.getElementById('module-drop-zone');

        if (input) {
            input.addEventListener('change', function () {
                if (label) { label.textContent = this.files[0] ? this.files[0].name : CHOOSE_FILE; }
                if (uploadBtn) { uploadBtn.disabled = !this.files.length; }
            });
        }
        if (zone) {
            zone.addEventListener('click', function (e) {
                if (input && (e.target === zone || e.target.closest('.mdi, p, small'))) { input.click(); }
            });
            ['dragover', 'dragenter'].forEach(function (ev) {
                zone.addEventListener(ev, function (e) { e.preventDefault(); zone.style.backgroundColor = 'rgba(0,123,255,0.06)'; });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                zone.addEventListener(ev, function (e) { e.preventDefault(); zone.style.backgroundColor = ''; });
            });
            zone.addEventListener('drop', function (e) {
                if (input && e.dataTransfer.files.length) {
                    input.files = e.dataTransfer.files;
                    input.dispatchEvent(new Event('change'));
                }
            });
        }

        function resetUploadForm(form) {
            form.reset();
            if (label) { label.textContent = CHOOSE_FILE; }
            if (uploadBtn) { uploadBtn.disabled = true; }
        }

        // ---- AJAX submit for every module action form ------------------------
        // (submit bubbles, so delegation covers table rows re-rendered by refresh)

        document.addEventListener('submit', function (e) {
            var form = e.target.closest('.js-module-form');
            if (!form) { return; }
            e.preventDefault();

            var confirmMsg = form.getAttribute('data-confirm');
            if (confirmMsg && !window.confirm(confirmMsg)) { return; }

            var btn = form.querySelector('[type="submit"]');
            var originalHtml = btn ? btn.innerHTML : '';
            var isUpload = form.id === 'module-upload-form';
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="mdi mdi-loading mdi-spin mr-1"></i>...';
            }

            postAction(new FormData(form)).then(function (resp) {
                showFlash(resp.type, resp.message);
                if (resp.type !== 'danger') {
                    if (isUpload) { resetUploadForm(form); }
                    return refreshTable();
                }
            }).catch(function () {
                showFlash('danger', 'Request failed.');
            }).finally(function () {
                // Row forms get replaced by refreshTable(); restoring a detached
                // button is harmless. Top-of-page forms keep their button.
                if (btn) { btn.disabled = false; btn.innerHTML = originalHtml; }
            });
        });

        // ---- enable/disable toggle (optimistic in-place update) --------------

        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.module-toggle-btn');
            if (!btn) { return; }
            e.preventDefault();

            var moduleName = btn.getAttribute('data-module');
            var isEnabled = btn.getAttribute('data-enabled') === '1';
            var prevHtml = btn.innerHTML;

            btn.disabled = true;
            btn.innerHTML = '<i class="mdi mdi-loading mdi-spin mr-1"></i>...';

            var fd = new FormData();
            fd.append('module_name', moduleName);
            fd.append('module_action', isEnabled ? 'disable' : 'enable');

            postAction(fd).then(function (resp) {
                if (resp && resp.type === 'danger') {
                    btn.disabled = false;
                    btn.innerHTML = isEnabled ? 'Disable' : 'Enable';
                    showFlash('danger', resp.message || 'Operation failed.');
                    return;
                }

                var nowEnabled = !isEnabled;
                btn.setAttribute('data-enabled', nowEnabled ? '1' : '0');
                btn.className = 'btn btn-sm mr-1 module-toggle-btn ' + (nowEnabled ? 'btn-warning' : 'btn-success');
                btn.innerHTML = nowEnabled ? 'Disable' : 'Enable';
                btn.disabled = false;

                var badge = document.querySelector('.module-status-badge[data-module="' + moduleName + '"]');
                if (badge) {
                    badge.className = 'badge module-status-badge ' + (nowEnabled ? 'badge-success' : 'badge-secondary');
                    badge.textContent = nowEnabled ? 'Enabled' : 'Disabled';
                }
                if (resp && resp.message) { showFlash(resp.type || 'success', resp.message); }
            }).catch(function () {
                btn.disabled = false;
                btn.innerHTML = isEnabled ? 'Disable' : 'Enable';
                showFlash('danger', TOGGLE_FAIL);
            });
        });
    })();
</script>

    <?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    ?>
