<?php if (!isset($__viewMode)): ?>
    <?php include 'session.php'; ?>
    <?php include 'functions.php'; ?>

    <?php if (!PageAuthorization::checkPermissions()) {
        AdminHelpers::goHome();
    } ?>

    <?php
    $_TITLE = 'Modules';
    require_once __DIR__ . '/../layouts/admin.php';
    renderUnifiedLayoutHeader('admin');

    $manager = new ModuleManager();
    $modules = $manager->listModules();
    $moduleFlash = null;
    ?>
<?php endif; ?>

<div class="wrapper boxed-layout-ext" <?php if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
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

        <div class="row">
            <div class="col-xl-12">
                <div class="card">
                    <div class="card-body">
                        <h5 class="card-title"><i class="mdi mdi-package-variant-closed mr-1"></i> Upload Module ZIP</h5>
                        <form action="#" method="POST" enctype="multipart/form-data" class="mb-4">
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
                            <table class="table table-striped table-borderless mb-0">
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
                                                        <form action="#" method="POST" class="mr-1">
                                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                            <input type="hidden" name="module_action" value="install">
                                                            <button type="submit" class="btn btn-sm btn-primary"></i>Install</button>
                                                        </form>

                                                        <form action="#" method="POST" class="mr-1">
                                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                            <input type="hidden" name="module_action" value="update">
                                                            <button type="submit" class="btn btn-sm btn-info"></i>Update</button>
                                                        </form>

                                                        <button type="button"
                                                            class="btn btn-sm mr-1 module-toggle-btn <?= !empty($module['enabled']) ? 'btn-warning' : 'btn-success' ?>"
                                                            data-module="<?= htmlspecialchars($module['name']) ?>"
                                                            data-enabled="<?= !empty($module['enabled']) ? '1' : '0' ?>">
                                                            <?= !empty($module['enabled']) ? 'Disable' : 'Enable' ?>
                                                        </button>

                                                        <form action="#" method="POST" onsubmit="return confirm('<?= $language::get('confirm_uninstall_module', [':name' => htmlspecialchars($module['name'])]) ?>');">
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
    (function() {
        var input = document.getElementById('module_zip_input');
        var label = input.nextElementSibling;
        var btn = document.getElementById('module_upload_btn');
        var zone = document.getElementById('module-drop-zone');
        input.addEventListener('change', function() {
            var name = this.files[0] ? this.files[0].name : 'Choose file...';
            label.textContent = name;
            btn.disabled = !this.files.length;
        });
        zone.addEventListener('click', function(e) {
            if (e.target === zone || e.target.closest('.mdi, p, small')) input.click();
        });
        ['dragover', 'dragenter'].forEach(function(ev) {
            zone.addEventListener(ev, function(e) {
                e.preventDefault();
                zone.style.backgroundColor = 'rgba(0,123,255,0.06)';
            });
        });
        ['dragleave', 'drop'].forEach(function(ev) {
            zone.addEventListener(ev, function(e) {
                e.preventDefault();
                zone.style.backgroundColor = '';
            });
        });
        zone.addEventListener('drop', function(e) {
            if (e.dataTransfer.files.length) {
                input.files = e.dataTransfer.files;
                input.dispatchEvent(new Event('change'));
            }
        });
    })();
    document.addEventListener('click', function(e) {
        var btn = e.target.closest('.module-toggle-btn');
        if (!btn) return;
        e.preventDefault();

        var moduleName = btn.getAttribute('data-module');
        var isEnabled = btn.getAttribute('data-enabled') === '1';
        var newAction = isEnabled ? 'disable' : 'enable';

        btn.disabled = true;
        btn.innerHTML = '<i class="mdi mdi-loading mdi-spin mr-1"></i>...';

        var formData = new FormData();
        formData.append('module_name', moduleName);
        formData.append('module_action', newAction);

        var xhr = new XMLHttpRequest();
        xhr.open('POST', window.location.href.split('#')[0]);
        xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        xhr.onload = function() {
            var resp;
            try {
                resp = JSON.parse(xhr.responseText);
            } catch (ex) {
                resp = null;
            }

            if (resp && resp.type === 'danger') {
                btn.disabled = false;
                btn.innerHTML = (isEnabled ? 'Disable' : 'Enable');
                alert(resp.message || 'Operation failed.');
                return;
            }

            var nowEnabled = !isEnabled;
            btn.setAttribute('data-enabled', nowEnabled ? '1' : '0');
            btn.className = 'btn btn-sm mr-1 module-toggle-btn ' + (nowEnabled ? 'btn-warning' : 'btn-success');
            btn.innerHTML = (nowEnabled ? 'Disable' : 'Enable');
            btn.disabled = false;

            var badge = document.querySelector('.module-status-badge[data-module="' + moduleName + '"]');
            if (badge) {
                badge.className = 'badge module-status-badge ' + (nowEnabled ? 'badge-success' : 'badge-secondary');
                badge.textContent = nowEnabled ? 'Enabled' : 'Disabled';
            }
        };
        xhr.onerror = function() {
            btn.disabled = false;
            btn.innerHTML = (isEnabled ? 'Disable' : 'Enable');
            alert('<?= $language::get('failed_toggle_module') ?>');
        };
        xhr.send(formData);
    });
</script>

<?php if (!isset($__viewMode)): ?>
    <?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    ?>
<?php endif; ?>