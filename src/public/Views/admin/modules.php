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
                    <h4 class="page-title">Modules</h4>
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
                        <h5 class="card-title">Upload Module ZIP</h5>
                        <p class="text-muted">Upload a module ZIP package and install it immediately.</p>
                        <form action="#" method="POST" enctype="multipart/form-data" class="mb-4">
                            <input type="hidden" name="module_action" value="upload_install" />
                            <div class="form-row align-items-center">
                                <div class="col-md-8 mb-2">
                                    <input type="file" class="form-control" name="module_zip" accept=".zip" required>
                                </div>
                                <div class="col-md-4 mb-2 text-right">
                                    <button type="submit" class="btn btn-primary">Upload & Install</button>
                                </div>
                            </div>
                        </form>

                        <div class="table-responsive">
                            <table class="table table-striped table-borderless mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Description</th>
                                        <th>Version</th>
                                        <th>Requires Core</th>
                                        <th>Status</th>
                                        <th class="text-right">Actions</th>
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
                                                    <?php if (!empty($module['enabled'])): ?>
                                                        <span class="badge badge-success">Enabled</span>
                                                    <?php else: ?>
                                                        <span class="badge badge-secondary">Disabled</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-right">
                                                    <div class="btn-group" role="group">
                                                        <form action="#" method="POST" class="mr-1">
                                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                            <input type="hidden" name="module_action" value="install">
                                                            <button type="submit" class="btn btn-sm btn-primary">Install</button>
                                                        </form>

                                                        <form action="#" method="POST" class="mr-1">
                                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                            <input type="hidden" name="module_action" value="update">
                                                            <button type="submit" class="btn btn-sm btn-info">Update</button>
                                                        </form>

                                                        <?php if (!empty($module['enabled'])): ?>
                                                            <form action="#" method="POST" class="mr-1">
                                                                <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                                <input type="hidden" name="module_action" value="disable">
                                                                <button type="submit" class="btn btn-sm btn-warning">Disable</button>
                                                            </form>
                                                        <?php else: ?>
                                                            <form action="#" method="POST" class="mr-1">
                                                                <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                                <input type="hidden" name="module_action" value="enable">
                                                                <button type="submit" class="btn btn-sm btn-success">Enable</button>
                                                            </form>
                                                        <?php endif; ?>

                                                        <form action="#" method="POST">
                                                            <input type="hidden" name="module_name" value="<?= htmlspecialchars($module['name']) ?>">
                                                            <input type="hidden" name="module_action" value="uninstall">
                                                            <button type="submit" class="btn btn-sm btn-danger">Uninstall</button>
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

<?php if (!isset($__viewMode)): ?>
<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<?php endif; ?>
