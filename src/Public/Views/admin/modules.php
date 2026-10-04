<?php
use XcVm\Core\Util\LayoutRenderer;


/**
 * Modules (Bootstrap 5). Marketplace / ZIP module management: install from store, upload a
 * ZIP, and the installed-modules table with per-module install / update / rollback / renew /
 * enable-disable / uninstall / delete actions. Row actions go to ./api?action=module
 * and the table reloads from ./table?id=modules; only the page-level forms
 * (zip upload, store install, check updates) still POST module_action to the page
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
            </tbody>
        </table>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        'use strict';
        var endpoint = window.location.href.split('#')[0];
        var CHOOSE_FILE = <?= json_encode($language::get('choose_file')); ?>;
        var TOGGLE_FAIL = <?= json_encode($language::get('failed_toggle_module')); ?>;
        var REFRESH_FAIL = <?= json_encode($language::get('modules_refresh_failed')); ?>;
        var WAIT_LABEL = <?= json_encode($language::get('please_wait')); ?>;
        var NOW_ENABLED = <?= json_encode($language::get('module_now_enabled')); ?>;
        var NOW_DISABLED = <?= json_encode($language::get('module_now_disabled')); ?>;
        var TOGGLE_TIMEOUT = <?= json_encode($language::get('module_toggle_timeout')); ?>;
        var CONFIRM_ACTION = <?= json_encode($language::get('module_confirm_action')); ?>;
        // Poll the list until the module's state actually flips, rather than
        // trusting the POST: an action can be applied by a worker other than the
        // one that answered, so "accepted" is not yet "in effect".
        var POLL_MS = 2000;
        var POLL_TRIES = 15;

        function escapeHtml(s) {
            return String(s).replace(/[&<>"']/g, function(c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c];
            });
        }

        function showFlash(type, message) {
            var box = document.getElementById('module-flash');
            var cls = ['success', 'warning', 'danger', 'info'].indexOf(type) !== -1 ? type : 'info';
            if (window.xcToast) {
                window.xcToast(message || '', cls === 'danger' ? 'error' : (cls === 'success' ? 'success' : (cls === 'warning' ? 'warning' : 'info')));
            }
            if (!box) {
                return;
            }
            // No scrollIntoView: a toast already announces the result, and yanking
            // the page mid-click moved the buttons out from under the cursor.
            box.innerHTML = '<div class="alert alert-' + cls + ' alert-dismissible" role="alert">' + escapeHtml(message || '') + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
        }

        function postAction(formData) {
            return fetch(endpoint, {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    credentials: 'same-origin',
                    body: formData
                })
                .then(function(r) {
                    return r.json().catch(function() {
                        return {
                            type: 'danger',
                            message: 'Unexpected server response.'
                        };
                    });
                });
        }
        // Rows come from ./table?id=modules — the same endpoint every other
        // admin table uses — but painted by hand with plain fetch: this page
        // loads no jQuery and no DataTables.
        var lastRows = [];

        function actionButton(sub, cls, label, name, title) {
            return '<button type="button" class="btn btn-sm me-1 ' + cls + ' js-mod" data-sub="' + escapeHtml(sub) +
                '" data-module="' + escapeHtml(name) + '"' + (title ? ' title="' + escapeHtml(title) + '"' : '') +
                '>' + escapeHtml(label) + '</button>';
        }

        function renderStatus(row) {
            var html = '<span class="badge ' + (row.enabled ? 'bg-label-success' : 'bg-label-secondary') + '">' +
                escapeHtml(row.enabled ? 'Enabled' : 'Disabled') + '</span>';
            if (row.warnings && row.warnings.length) {
                html += ' <span class="badge bg-label-warning" title="' + escapeHtml(row.warnings.join(' ')) + '">' +
                    '<i class="icon-base ti tabler-alert-triangle me-1"></i>' +
                    escapeHtml(row.warnings.length === 1 ? 'Issue' : 'Issues') + '</span>';
            }
            return html;
        }

        function renderActions(row) {
            var html = '';
            if (!row.installed) {
                html += actionButton('install', 'btn-primary', 'Install', row.name);
            }
            if (row.update_to) {
                html += actionButton('update', 'btn-info', 'Update to ' + row.update_to, row.name,
                    'New version ' + row.update_to + ' available');
            }
            if (row.source === 'platform' && row.rollback_to) {
                html += actionButton('rollback', 'btn-label-secondary', 'Rollback', row.name,
                    'Roll back to v' + row.rollback_to);
            }
            if (row.source === 'platform') {
                html += actionButton('renew_license', 'btn-label-secondary', 'Renew license', row.name,
                    'Re-issue the per-machine ionCube license');
            }
            html += actionButton(row.enabled ? 'disable' : 'enable',
                row.enabled ? 'btn-warning' : 'btn-success',
                row.enabled ? 'Disable' : 'Enable', row.name);
            if (row.installed) {
                html += actionButton('uninstall', 'btn-danger', 'Uninstall', row.name);
            }
            html += actionButton('delete', 'btn-label-danger', 'Delete', row.name);
            return html;
        }

        // Reload the table and resolve with the rows the server returned, so a
        // caller can read the real post-action state instead of assuming it.
        function paint(rows) {
            var tbody = document.querySelector('#modules-table tbody');
            if (!tbody) {
                return;
            }
            if (!rows.length) {
                tbody.innerHTML = '<tr><td colspan="6" class="text-center text-body-secondary py-4">—</td></tr>';
                return;
            }
            tbody.innerHTML = rows.map(function(row) {
                return '<tr>' +
                    '<td class="fw-medium">' + escapeHtml(row.name) + '</td>' +
                    '<td>' + escapeHtml(row.description || '-') + '</td>' +
                    '<td>' + escapeHtml(row.version || '-') + '</td>' +
                    '<td>' + escapeHtml(row.requires_core || '-') + '</td>' +
                    '<td>' + renderStatus(row) + '</td>' +
                    '<td class="text-end"><div class="btn-group" role="group">' + renderActions(row) + '</div></td>' +
                    '</tr>';
            }).join('');
        }

        // Resolves with the rows the server returned, or null when the list could
        // not be read — the caller must be able to tell "unchanged" from
        // "could not check".
        function reloadTable() {
            return fetch('./table?id=modules&draw=1&start=0&length=1000', {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin'
            }).then(function(r) {
                return r.json();
            }).then(function(json) {
                lastRows = (json && json.data) || [];
                paint(lastRows);
                return lastRows;
            }).catch(function() {
                return null;
            });
        }

        function rowOf(rows, name) {
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].name === name) {
                    return rows[i];
                }
            }
            return null;
        }

        // Reload every POLL_MS until the module leaves `wasEnabled`. Resolves with
        // the row that showed the change, or null on timeout. A module that has
        // vanished (uninstalled elsewhere) counts as settled, not as a hang.
        function waitForToggle(moduleName, wasEnabled, tries) {
            return reloadTable().then(function(rows) {
                // A failed read is "not confirmed yet", not "unchanged" — keep
                // polling rather than reporting a state nothing vouched for.
                if (rows !== null) {
                    var row = rowOf(rows, moduleName);
                    if (row === null || row.enabled !== wasEnabled) {
                        return row;
                    }
                }
                if (tries <= 1) {
                    return null;
                }
                return new Promise(function(resolve) {
                    setTimeout(function() {
                        resolve(waitForToggle(moduleName, wasEnabled, tries - 1));
                    }, POLL_MS);
                });
            });
        }

        // File input + drag & drop.
        var input = document.getElementById('module_zip_input');
        var label = document.getElementById('module_zip_label');
        var uploadBtn = document.getElementById('module_upload_btn');
        var zone = document.getElementById('module-drop-zone');
        if (input) {
            input.addEventListener('change', function() {
                if (label) {
                    label.textContent = this.files[0] ? this.files[0].name : CHOOSE_FILE;
                }
                if (uploadBtn) {
                    uploadBtn.disabled = !this.files.length;
                }
            });
        }
        if (zone) {
            zone.addEventListener('click', function(e) {
                if (input && e.target !== input && e.target.tagName !== 'A') {
                    input.click();
                }
            });
            ['dragover', 'dragenter'].forEach(function(ev) {
                zone.addEventListener(ev, function(e) {
                    e.preventDefault();
                    zone.style.backgroundColor = 'rgba(105,108,255,0.06)';
                });
            });
            ['dragleave', 'drop'].forEach(function(ev) {
                zone.addEventListener(ev, function(e) {
                    e.preventDefault();
                    zone.style.backgroundColor = '';
                });
            });
            zone.addEventListener('drop', function(e) {
                if (input && e.dataTransfer.files.length) {
                    input.files = e.dataTransfer.files;
                    input.dispatchEvent(new Event('change'));
                }
            });
        }

        function resetUploadForm(form) {
            form.reset();
            if (label) {
                label.textContent = CHOOSE_FILE;
            }
            if (uploadBtn) {
                uploadBtn.disabled = true;
            }
        }

        // AJAX submit for every module action form.
        // Page-level forms (zip upload, store install, check updates) still POST to
        // this page: they are not row actions and the upload is multipart.
        document.addEventListener('submit', function(e) {
            var form = e.target.closest('.js-module-form');
            if (!form) {
                return;
            }
            e.preventDefault();
            var confirmMsg = form.getAttribute('data-confirm');
            var _proceed = function() {
                var btn = form.querySelector('[type="submit"]');
                var originalHtml = btn ? btn.innerHTML : '';
                var isUpload = form.id === 'module-upload-form';
                if (btn) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="icon-base ti tabler-loader me-1"></i>' + escapeHtml(WAIT_LABEL);
                }
                postAction(new FormData(form)).then(function(resp) {
                    if (resp.type === 'danger') {
                        showFlash(resp.type, resp.message);
                        return;
                    }
                    if (isUpload) {
                        resetUploadForm(form);
                    }
                    return reloadTable().then(function() {
                        showFlash(resp.type, resp.message);
                    });
                }).catch(function() {
                    showFlash('danger', 'Request failed.');
                }).finally(function() {
                    if (btn) {
                        btn.disabled = false;
                        btn.innerHTML = originalHtml;
                    }
                });
            };
            if (confirmMsg) {
                (window.xcConfirm ? window.xcConfirm(confirmMsg) : Promise.resolve(confirm(confirmMsg))).then(function(ok) {
                    if (ok) {
                        _proceed();
                    }
                });
            } else {
                _proceed();
            }
        });

        // Row actions. Every one goes to ./api?action=module and the outcome is
        // read back off the reloaded table, never inferred from the request: the
        // guard can refuse a disable, and toggling one module changes the
        // dependency warnings on the others.
        document.addEventListener('click', function(e) {
            var btn = e.target.closest('.js-mod');
            if (!btn) {
                return;
            }
            e.preventDefault();
            var name = btn.getAttribute('data-module');
            var sub = btn.getAttribute('data-sub');
            var isToggle = sub === 'enable' || sub === 'disable';
            var wasEnabled = sub === 'disable';

            var run = function() {
                btn.disabled = true;
                btn.innerHTML = '<i class="icon-base ti tabler-loader me-1"></i>' + escapeHtml(WAIT_LABEL);

                fetch('./api?action=module&sub=' + encodeURIComponent(sub) + '&name=' + encodeURIComponent(name), {
                    headers: { 'X-Requested-With': 'XMLHttpRequest' },
                    credentials: 'same-origin'
                }).then(function(r) {
                    return r.json().catch(function() {
                        return { result: false, message: 'Unexpected server response.' };
                    });
                }).then(function(resp) {
                    // A refusal will never change the state, so report it now
                    // rather than poll for the full timeout.
                    if (!resp || resp.result === false) {
                        return reloadTable().then(function() {
                            showFlash('danger', (resp && resp.message) || TOGGLE_FAIL);
                        });
                    }
                    if (!isToggle) {
                        return reloadTable().then(function() {
                            showFlash('success', resp.message || '');
                        });
                    }
                    return waitForToggle(name, wasEnabled, POLL_TRIES).then(function(row) {
                        if (row === null) {
                            showFlash('warning', TOGGLE_TIMEOUT.replace(':name', name));
                            return;
                        }
                        showFlash('success', (row.enabled ? NOW_ENABLED : NOW_DISABLED).replace(':name', name));
                    });
                }).catch(function() {
                    showFlash('danger', TOGGLE_FAIL);
                });
            };

            var confirmMsg = (sub === 'uninstall' || sub === 'delete' || sub === 'rollback')
                ? CONFIRM_ACTION.replace(':action', sub).replace(':name', name)
                : '';
            if (confirmMsg) {
                (window.xcConfirm ? window.xcConfirm(confirmMsg) : Promise.resolve(confirm(confirmMsg))).then(function(ok) {
                    if (ok) {
                        run();
                    }
                });
            } else {
                run();
            }
        });

        reloadTable();
    })();
</script>
