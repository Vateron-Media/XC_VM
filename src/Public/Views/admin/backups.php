<?php

/**
 * Backups (Bootstrap 5). Dropbox / automatic-backup settings form + the backups list.
 * The table uses the clean-JSON pattern: TableController::handleBackups resolves
 * each backup's local presence and remote (Dropbox) upload state server-side
 * (yes / no / error / uploading) and this page renders the status dots, size and
 * restore/delete actions client-side. Settings save posts to post.php?action=backups
 * (the legacy PostController path); backup/restore/delete hit ./api?action=backup.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Backup\BackupService;

if (!Authorization::check('adv', 'database')):
?>
    <div class="alert alert-danger text-center" role="alert"><?= $language::get('dashboard_no_permissions'); ?></div>
<?php
    require_once __DIR__ . '/../layouts/footer.php';
    renderUnifiedLayoutFooter('admin');
    echo '</body></html>';
    return;
endif;

$rDropboxBroken = strlen((string) $rSettings['dropbox_token']) > 0 && !BackupService::checkRemoteConnection();
$rBackupTypes = ['off' => 'Off', 'hourly' => 'Hourly', 'daily' => 'Daily', 'weekly' => 'Weekly', 'monthly' => 'Monthly'];
?>

<div class="row g-4">
    <div class="col-12">
        <div class="alert alert-info" role="alert">
            Backups will not contain any logs, restoring a database will therefore clear all of your logs.<br>
            If you want to keep your logs you should manually create your own backups.
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <h5 class="card-title mb-0"><?= $language::get('backups'); ?> — Settings</h5>
            </div>
            <div class="card-body">
                <?php if ($rDropboxBroken): ?>
                    <div class="alert alert-danger" role="alert">
                        Could not access your Dropbox through the API key provided above. Please generate a new one or check that your key is correct.
                    </div>
                <?php endif; ?>
                <form id="backup-settings" autocomplete="off">
                    <div class="row g-3">
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="automatic_backups">Automatic Backups
                                <i class="icon-base ti tabler-help text-body-secondary" title="<?= htmlspecialchars((string) $language::get('generate_full_sql_backups_periodically'), ENT_QUOTES); ?>"></i></label>
                            <select name="automatic_backups" id="automatic_backups" class="form-select">
                                <?php foreach ($rBackupTypes as $rType => $rText): ?>
                                    <option value="<?= $rType; ?>" <?= $rSettings['automatic_backups'] == $rType ? 'selected' : ''; ?>><?= $rText; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="backups_to_keep">Local Backups to Keep
                                <i class="icon-base ti tabler-help text-body-secondary" title="<?= htmlspecialchars((string) $language::get('enter_0_for_unlimited_oldest_will_be_deleted'), ENT_QUOTES); ?>"></i></label>
                            <input type="text" inputmode="numeric" class="form-control" id="backups_to_keep" name="backups_to_keep" value="<?= htmlspecialchars((string) ($rSettings['backups_to_keep'] ?: 0), ENT_QUOTES); ?>">
                        </div>
                        <div class="col-12 col-md-6 d-flex align-items-end">
                            <div class="form-check form-switch mb-2">
                                <input name="dropbox_remote" id="dropbox_remote" type="checkbox" class="form-check-input" value="1" <?= $rSettings['dropbox_remote'] ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="dropbox_remote">Dropbox Backups
                                    <i class="icon-base ti tabler-help text-body-secondary" title="<?= htmlspecialchars((string) $language::get('once_a_local_backup_is_tooltip'), ENT_QUOTES); ?>"></i></label>
                            </div>
                        </div>
                        <div class="col-12 col-md-6">
                            <label class="form-label" for="dropbox_keep">Dropbox Backups to Keep
                                <i class="icon-base ti tabler-help text-body-secondary" title="<?= htmlspecialchars((string) $language::get('enter_0_for_unlimited_oldest_will_be_deleted'), ENT_QUOTES); ?>"></i></label>
                            <input type="text" inputmode="numeric" class="form-control" id="dropbox_keep" name="dropbox_keep" value="<?= htmlspecialchars((string) ($rSettings['dropbox_keep'] ?: 0), ENT_QUOTES); ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label" for="dropbox_token">Dropbox Token
                                <i class="icon-base ti tabler-help text-body-secondary" title="<?= htmlspecialchars((string) $language::get('create_an_application_in_the_tooltip'), ENT_QUOTES); ?>"></i></label>
                            <input type="text" class="form-control" id="dropbox_token" name="dropbox_token" value="<?= htmlspecialchars((string) $rSettings['dropbox_token'], ENT_QUOTES); ?>">
                        </div>
                    </div>
                    <div class="d-flex flex-wrap gap-2 mt-4">
                        <button type="submit" class="btn btn-primary" id="save-settings"><?= $language::get('save_changes'); ?></button>
                        <button type="button" class="btn btn-label-info" id="create-backup"><i class="icon-base ti tabler-database-plus me-1"></i><?= $language::get('create_backup_now'); ?></button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="col-12">
        <div class="card">
            <div class="card-datatable table-responsive">
                <table id="backups-table" class="table" style="width:100%">
                    <thead>
                        <tr>
                            <th><?= $language::get('date'); ?></th>
                            <th><?= $language::get('filename'); ?></th>
                            <th><?= $language::get('filesize'); ?></th>
                            <th class="text-center"><?= $language::get('local'); ?></th>
                            <th class="text-center"><?= $language::get('dropbox'); ?></th>
                            <th class="text-center"><?= $language::get('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
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
        var esc = function(s) {
            var d = document.createElement('div');
            d.textContent = (s == null ? '' : String(s));
            return d.innerHTML;
        };
        var dot = function(color, title) {
            return '<i class="icon-base ti tabler-square-rounded-filled text-' + color + '"' + (title ? ' title="' + esc(title) + '"' : '') + '></i>';
        };
        var lang = {
            restore: <?= json_encode($language::get('restore') ?: 'Restore'); ?>,
            del: <?= json_encode($language::get('delete')); ?>,
            error: <?= json_encode($language::get('error_occured')); ?>,
            confirmDelete: 'Are you sure you want to delete this backup?',
            confirmRestore: 'Are you sure you want to restore from this backup? This will erase your current database.',
            saved: 'Settings saved.',
            creating: 'Creating backup in background, this may take a few minutes.',
            deleted: 'Backup successfully deleted.'
        };

        var table = jQuery('#backups-table').DataTable({
            processing: true,
            serverSide: true,
            paging: false,
            searching: false,
            ordering: false,
            info: false,
            responsive: true,
            ajax: {
                url: './table',
                data: function(d) {
                    d.id = 'backups';
                }
            },
            columns: [{
                    data: 'date'
                },
                {
                    data: 'filename',
                    render: esc
                },
                {
                    data: 'size',
                    className: 'text-nowrap'
                },
                {
                    data: 'local',
                    className: 'text-center',
                    render: function(d) {
                        return dot(d ? 'success' : 'secondary');
                    }
                },
                {
                    data: 'remote',
                    className: 'text-center',
                    render: function(d, t, row) {
                        if (d === 'yes') {
                            return dot('success');
                        }
                        if (d === 'error') {
                            return dot('danger', row.remote_msg || 'Upload error');
                        }
                        if (d === 'uploading') {
                            return dot('warning', 'Uploading…');
                        }
                        return dot('secondary');
                    }
                },
                {
                    data: 'filename',
                    orderable: false,
                    className: 'text-center text-nowrap',
                    render: function(d) {
                        return '<button type="button" class="btn btn-sm btn-icon btn-label-secondary js-restore" data-id="' + esc(d) + '" title="' + esc(lang.restore) + '"><i class="icon-base ti tabler-database-import"></i></button> ' +
                            '<button type="button" class="btn btn-sm btn-icon btn-label-danger js-delete" data-id="' + esc(d) + '" title="' + esc(lang.del) + '"><i class="icon-base ti tabler-trash"></i></button>';
                    }
                }
            ],
            layout: {
                topStart: null,
                topEnd: null,
                bottomStart: null,
                bottomEnd: null
            }
        });

        var apiCall = function(id, sub) {
            return fetch('./api?action=backup&sub=' + encodeURIComponent(sub) + '&filename=' + encodeURIComponent(id), {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.json();
                });
        };

        jQuery('#backups-table tbody').on('click', '.js-delete', function() {
            var id = this.getAttribute('data-id');
            window.xcConfirm(lang.confirmDelete).then(function(ok) {
                if (!ok) {
                    return;
                }
                apiCall(id, 'delete').then(function(dt) {
                    if (!dt || dt.result !== true) {
                        throw new Error('fail');
                    }
                    table.ajax.reload(null, false);
                }).catch(function() {
                    alert(lang.error);
                });
            });
        });
        jQuery('#backups-table tbody').on('click', '.js-restore', function() {
            var id = this.getAttribute('data-id');
            window.xcConfirm(lang.confirmRestore).then(function(ok) {
                if (!ok) {
                    return;
                }
                apiCall(id, 'restore').then(function(dt) {
                    if (!dt || dt.result !== true) {
                        throw new Error('fail');
                    }
                    table.ajax.reload(null, false);
                }).catch(function() {
                    alert(lang.error);
                });
            });
        });

        document.getElementById('create-backup').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            apiCall('', 'backup').then(function(dt) {
                if (!dt || dt.result !== true) {
                    throw new Error('fail');
                }
                alert(lang.creating);
                setTimeout(function() {
                    btn.disabled = false;
                    table.ajax.reload(null, false);
                }, 2000);
            }).catch(function() {
                btn.disabled = false;
                alert(lang.error);
            });
        });

        // Settings save — mirrors legacy submitForm (POST FormData to post.php?action=backups).
        document.getElementById('backup-settings').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = document.getElementById('save-settings');
            btn.disabled = true;
            fetch('post.php?action=backups', {
                    method: 'POST',
                    body: new FormData(e.target),
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(txt) {
                    var dt;
                    try {
                        dt = JSON.parse(txt);
                    } catch (err) {
                        dt = {
                            result: false
                        };
                    }
                    btn.disabled = false;
                    alert(dt && dt.result !== false ? lang.saved : lang.error);
                })
                .catch(function() {
                    btn.disabled = false;
                    alert(lang.error);
                });
        });
    })();
</script>
</body>

</html>