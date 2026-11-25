<?php

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Util\LayoutRenderer;

if (!Authorization::check('adv', 'database')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    LayoutRenderer::renderFooter('admin');
    echo '</body></html>';
    return;
endif;
?>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-1">Module Backups</h5>
        <p class="text-body-secondary mb-0">Created before module tables are removed. Kept until you delete them.</p>
    </div>
    <div class="table-responsive">
        <table class="table align-middle">
            <thead><tr><th>Module</th><th>Created</th><th>Tables</th><th>Size</th><th>Checksum</th><th class="text-end">Actions</th></tr></thead>
            <tbody>
            <?php if ($backups === []): ?>
                <tr><td colspan="6" class="text-center text-body-secondary py-4">No module backups.</td></tr>
            <?php endif; ?>
            <?php foreach ($backups as $backup): ?>
                <tr>
                    <td><strong><?= htmlspecialchars((string) $backup['module_title'], ENT_QUOTES); ?></strong><br><small class="text-body-secondary"><?= htmlspecialchars((string) $backup['module_name'], ENT_QUOTES); ?> · <?= htmlspecialchars((string) $backup['version'], ENT_QUOTES); ?> · <?= htmlspecialchars((string) $backup['kind'], ENT_QUOTES); ?></small><br><small class="font-monospace" title="<?= htmlspecialchars((string) $backup['hash_id'], ENT_QUOTES); ?>">hash <?= htmlspecialchars(substr((string) $backup['hash_id'], 0, 12), ENT_QUOTES); ?>…</small></td>
                    <td class="text-nowrap"><?= date('Y-m-d H:i:s', (int) $backup['created_at']); ?></td>
                    <td><?= htmlspecialchars(implode(', ', $backup['tables']), ENT_QUOTES); ?></td>
                    <td class="text-nowrap"><?= number_format((int) $backup['size'] / 1024, 1); ?> KiB</td>
                    <td><span class="badge bg-label-<?= $backup['verified'] ? 'success' : 'danger'; ?>"><?= $backup['verified'] ? 'Verified' : 'Invalid'; ?></span><br><small class="font-monospace" title="<?= htmlspecialchars((string) $backup['checksum'], ENT_QUOTES); ?>"><?= htmlspecialchars(substr((string) $backup['checksum'], 0, 12), ENT_QUOTES); ?>…</small></td>
                    <td class="text-end text-nowrap">
                        <a class="btn btn-sm btn-icon btn-label-secondary" href="./api?action=module_backup_download&amp;id=<?= rawurlencode((string) $backup['id']); ?>" title="Download"><i class="icon-base ti tabler-download"></i></a>
                        <button type="button" class="btn btn-sm btn-icon btn-label-warning js-module-backup" data-action="restore" data-id="<?= htmlspecialchars((string) $backup['id'], ENT_QUOTES); ?>" <?= $backup['verified'] ? '' : 'disabled'; ?> title="Restore"><i class="icon-base ti tabler-database-import"></i></button>
                        <button type="button" class="btn btn-sm btn-icon btn-label-danger js-module-backup" data-action="delete" data-id="<?= htmlspecialchars((string) $backup['id'], ENT_QUOTES); ?>" title="Delete"><i class="icon-base ti tabler-trash"></i></button>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php LayoutRenderer::renderFooter('admin'); ?>
<script>
document.querySelectorAll('.js-module-backup').forEach(function(button) {
    button.addEventListener('click', function() {
        var action = button.dataset.action;
        var prompt = action === 'restore'
            ? 'Restore this module backup? A safety backup of the current module tables will be created first.'
            : 'Delete this module backup permanently?';
        window.xcConfirm(prompt).then(function(ok) {
            if (!ok) return;
            var body = new FormData();
            body.append('id', button.dataset.id);
            fetch('./api?action=module_backup_' + action, {
                method: 'POST', headers: {'X-Requested-With': 'XMLHttpRequest'}, body: body
            }).then(function(response) { return response.json(); }).then(function(data) {
                if (data.result) window.location.reload();
                else window.xcAlert(data.message || 'Module backup action failed.');
            });
        });
    });
});
</script>
