<?php

/**
 * The Backups page's off-site part (included by backups.php): the S3 and
 * SFTP targets each backup is copied to, the recovery bundle's passphrase,
 * and the weekly restore test (Domain\Backup). Saves through the admin Ajax
 * actions backup_target_save / _delete / _test, backup_bundle_passphrase and
 * backup_verify_now.
 */

use XcVm\Domain\Backup\BackupTargets;
use XcVm\Domain\Backup\BackupVerifier;
use XcVm\Domain\Backup\RecoveryBundle;

$rTargets = array_map([BackupTargets::class, 'forPage'], BackupTargets::all());
$rBundleOn = RecoveryBundle::passphrase() !== null;
$rVerify = BackupVerifier::last((string) ($rSettings['backup_verify'] ?? ''));
$rTargetFields = [
    'endpoint' => $language::get('backup_s3_endpoint'), 'region' => $language::get('backup_s3_region'), 'bucket' => $language::get('backup_s3_bucket'), 'prefix' => $language::get('backup_s3_prefix'),
    'access_key' => $language::get('backup_s3_access_key'), 'secret_key' => $language::get('backup_s3_secret_key'), 'path_style' => $language::get('backup_s3_path_style'),
    'host' => $language::get('backup_sftp_host'), 'port' => $language::get('backup_sftp_port'), 'username' => $language::get('backup_sftp_username'), 'password' => $language::get('backup_sftp_password'),
    'private_key' => $language::get('backup_sftp_private_key'), 'path' => $language::get('backup_sftp_path'), 'fingerprint' => $language::get('backup_sftp_fingerprint'),
];
?>
<div class="col-12">
    <div class="card">
        <div class="card-header">
            <h5 class="card-title mb-1"><?= $language::get('backup_targets'); ?></h5>
            <p class="mb-0 text-body-secondary"><?= $language::get('backup_targets_intro'); ?></p>
        </div>
        <div class="card-body">
            <?php if ($rTargets !== []): ?>
                <div class="table-responsive mb-6">
                    <table class="table table-sm">
                        <thead>
                            <tr>
                                <th><?= $language::get('name'); ?></th>
                                <th><?= $language::get('type'); ?></th>
                                <th><?= $language::get('backup_target_keep'); ?></th>
                                <th><?= $language::get('status'); ?></th>
                                <th class="text-end"><?= $language::get('actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($rTargets as $rTarget): ?>
                                <tr>
                                    <td><?= htmlspecialchars($rTarget['name'], ENT_QUOTES); ?></td>
                                    <td><?= strtoupper(htmlspecialchars($rTarget['type'], ENT_QUOTES)); ?></td>
                                    <td><?= $rTarget['keep'] > 0 ? (int) $rTarget['keep'] : $language::get('backup_target_keep_all'); ?></td>
                                    <td><span class="badge <?= $rTarget['enabled'] ? 'bg-label-success' : 'bg-label-secondary'; ?>"><?= $language::get($rTarget['enabled'] ? 'enabled' : 'disabled'); ?></span></td>
                                    <td class="text-end text-nowrap">
                                        <button type="button" class="btn btn-sm btn-label-info" data-target-test="<?= (int) $rTarget['id']; ?>"><?= $language::get('alert_test'); ?></button>
                                        <button type="button" class="btn btn-sm btn-label-primary" data-target-edit="<?= htmlspecialchars((string) json_encode($rTarget), ENT_QUOTES); ?>"><?= $language::get('edit'); ?></button>
                                        <button type="button" class="btn btn-sm btn-label-danger" data-target-delete="<?= (int) $rTarget['id']; ?>"><?= $language::get('delete'); ?></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <form id="target-form" class="row g-3" autocomplete="off">
                <input type="hidden" name="id" value="0">
                <div class="col-md-3">
                    <label class="form-label" for="target-type"><?= $language::get('type'); ?></label>
                    <select class="form-select" id="target-type" name="type">
                        <option value="s3">S3</option>
                        <option value="sftp">SFTP</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label" for="target-name"><?= $language::get('name'); ?></label>
                    <input type="text" class="form-control" id="target-name" name="name" maxlength="64" required>
                </div>
                <div class="col-md-2">
                    <label class="form-label" for="target-keep"><?= $language::get('backup_target_keep'); ?></label>
                    <input type="number" class="form-control" id="target-keep" name="keep" min="0" max="1000" value="14">
                </div>
                <div class="col-md-3 d-flex align-items-end">
                    <div class="form-check form-switch mb-2">
                        <input class="form-check-input" type="checkbox" id="target-enabled" name="enabled" value="1" checked>
                        <label class="form-check-label" for="target-enabled"><?= $language::get('enabled'); ?></label>
                    </div>
                </div>
                <?php foreach (BackupTargets::FIELDS as $rType => $rFields): ?>
                    <?php foreach ($rFields as $rField => $rSecret): ?>
                        <div class="<?= $rField === 'private_key' ? 'col-12' : 'col-md-4'; ?>" data-target-field="<?= $rType; ?>">
                            <?php if ($rField === 'path_style'): ?>
                                <div class="form-check form-switch mt-md-6">
                                    <input class="form-check-input" type="checkbox" id="target-s3-path_style" data-name="path_style" value="1">
                                    <label class="form-check-label" for="target-s3-path_style"><?= htmlspecialchars($rTargetFields[$rField], ENT_QUOTES); ?></label>
                                </div>
                            <?php else: ?>
                                <label class="form-label" for="target-<?= $rType . '-' . $rField; ?>"><?= htmlspecialchars($rTargetFields[$rField], ENT_QUOTES); ?></label>
                                <?php if ($rField === 'private_key'): ?>
                                    <textarea class="form-control font-monospace" rows="3" id="target-<?= $rType . '-' . $rField; ?>" data-name="<?= $rField; ?>" placeholder="<?= htmlspecialchars($language::get('backup_sftp_private_key_hint'), ENT_QUOTES); ?>"></textarea>
                                <?php else: ?>
                                    <input type="<?= $rSecret ? 'password' : 'text'; ?>" class="form-control" id="target-<?= $rType . '-' . $rField; ?>" data-name="<?= $rField; ?>"
                                        <?= $rSecret ? 'placeholder="' . htmlspecialchars($language::get('alert_secret_kept'), ENT_QUOTES) . '"' : ''; ?>
                                        <?= ['endpoint' => 'placeholder="https://s3.amazonaws.com"', 'region' => 'placeholder="us-east-1"', 'port' => 'value="22"', 'path' => 'placeholder="/backups/xc_vm"', 'fingerprint' => 'placeholder="' . htmlspecialchars($language::get('backup_sftp_fingerprint_hint'), ENT_QUOTES) . '"'][$rField] ?? ''; ?>>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endforeach; ?>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><?= $language::get('save'); ?></button>
                    <button type="button" class="btn btn-label-secondary d-none" id="target-cancel"><?= $language::get('cancel'); ?></button>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="col-md-6">
    <div class="card h-100">
        <div class="card-header">
            <h5 class="card-title mb-1"><?= $language::get('backup_bundle'); ?></h5>
            <p class="mb-0 text-body-secondary"><?= $language::get('backup_bundle_intro'); ?></p>
        </div>
        <div class="card-body">
            <p><span class="badge <?= $rBundleOn ? 'bg-label-success' : 'bg-label-secondary'; ?>"><?= $language::get($rBundleOn ? 'backup_bundle_on' : 'backup_bundle_off'); ?></span></p>
            <form id="bundle-form" class="row g-3" autocomplete="off">
                <div class="col-12">
                    <label class="form-label" for="bundle-passphrase"><?= $language::get('backup_bundle_passphrase'); ?></label>
                    <input type="password" class="form-control" id="bundle-passphrase" name="passphrase" minlength="<?= RecoveryBundle::MIN_LENGTH; ?>" autocomplete="new-password">
                    <div class="form-text"><?= $language::get('backup_bundle_passphrase_hint'); ?></div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary"><?= $language::get('save'); ?></button>
                    <?php if ($rBundleOn): ?><button type="button" class="btn btn-label-danger" id="bundle-clear"><?= $language::get('backup_bundle_turn_off'); ?></button><?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<div class="col-md-6">
    <div class="card h-100">
        <div class="card-header">
            <h5 class="card-title mb-1"><?= $language::get('backup_verify'); ?></h5>
            <p class="mb-0 text-body-secondary"><?= $language::get('backup_verify_intro'); ?></p>
        </div>
        <div class="card-body">
            <?php if ($rVerify === null): ?>
                <p class="text-body-secondary"><?= $language::get('backup_verify_never'); ?></p>
            <?php else: ?>
                <p>
                    <span class="badge <?= ['ok' => 'bg-label-success', 'failed' => 'bg-label-danger'][$rVerify['state']] ?? 'bg-label-secondary'; ?>"><?= htmlspecialchars($rVerify['state'], ENT_QUOTES); ?></span>
                    <?= date('Y-m-d H:i', (int) $rVerify['time']); ?>
                    <?= $rVerify['file'] !== '' ? '· ' . htmlspecialchars($rVerify['file'], ENT_QUOTES) : ''; ?>
                    <?= $rVerify['state'] === 'ok' ? '· ' . htmlspecialchars($language::get('backup_verify_counts', ['{tables}' => (string) $rVerify['tables'], '{lines}' => (string) $rVerify['lines']]), ENT_QUOTES) : ''; ?>
                </p>
                <?php if ($rVerify['error'] !== ''): ?><p class="text-danger"><?= htmlspecialchars($rVerify['error'], ENT_QUOTES); ?></p><?php endif; ?>
            <?php endif; ?>
            <button type="button" class="btn btn-label-primary" id="verify-now"><?= $language::get('backup_verify_now'); ?></button>
        </div>
    </div>
</div>

<script>
    (function() {
        var toast = window.xcToast || function(text) {
            window.alert(text);
        };
        var errors = <?= json_encode([
            'name' => $language::get('alert_error_name'),
            'endpoint' => $language::get('backup_error_endpoint'),
            'bucket' => $language::get('backup_error_bucket'),
            'region' => $language::get('backup_error_region'),
            'access_key' => $language::get('backup_error_access_key'),
            'host' => $language::get('alert_error_host'),
            'username' => $language::get('backup_error_username'),
            'private_key' => $language::get('backup_error_private_key'),
            'path' => $language::get('backup_error_path'),
            'error' => $language::get('error_occured'),
        ]); ?>;
        var form = document.getElementById('target-form');

        function post(action, fd) {
            return fetch('./api?action=' + action, {
                method: 'POST',
                body: fd,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(r) {
                return r.json();
            });
        }

        function showType() {
            var type = form.elements.type.value;
            form.querySelectorAll('[data-target-field]').forEach(function(el) {
                el.classList.toggle('d-none', el.getAttribute('data-target-field') !== type);
            });
        }
        form.elements.type.addEventListener('change', showType);
        showType();

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var fd = new FormData(form);
            var type = form.elements.type.value;
            fd.set('type', type);
            form.querySelectorAll('[data-target-field="' + type + '"] [data-name]').forEach(function(el) {
                if (el.type === 'checkbox') {
                    if (el.checked) {
                        fd.append(el.getAttribute('data-name'), '1');
                    }
                } else {
                    fd.append(el.getAttribute('data-name'), el.value);
                }
            });
            post('backup_target_save', fd).then(function(d) {
                if (d && d.result === true) {
                    window.location.reload();
                    return;
                }
                toast(errors[d && d.error] || errors.error, 'error');
            }).catch(function() {
                toast(errors.error, 'error');
            });
        });

        // Edit: the form takes the target; its secrets come back masked and are kept unless typed again.
        document.querySelectorAll('[data-target-edit]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var target = JSON.parse(btn.getAttribute('data-target-edit'));
                form.elements.id.value = target.id;
                form.elements.type.value = target.type;
                form.elements.type.disabled = true;
                form.elements.name.value = target.name;
                form.elements.keep.value = target.keep;
                form.elements.enabled.checked = target.enabled === 1;
                form.querySelectorAll('[data-target-field="' + target.type + '"] [data-name]').forEach(function(el) {
                    var value = target.config[el.getAttribute('data-name')] || '';
                    if (el.type === 'checkbox') {
                        el.checked = value === '1';
                    } else {
                        el.value = value;
                    }
                });
                showType();
                document.getElementById('target-cancel').classList.remove('d-none');
                form.scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
        document.getElementById('target-cancel').addEventListener('click', function() {
            window.location.reload();
        });

        document.querySelectorAll('[data-target-test]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var fd = new FormData();
                fd.append('id', btn.getAttribute('data-target-test'));
                btn.disabled = true;
                post('backup_target_test', fd).then(function(d) {
                    btn.disabled = false;
                    toast(d && d.result === true ? <?= json_encode($language::get('backup_target_works')); ?> : (d && d.error) || errors.error, d && d.result === true ? 'success' : 'error');
                }).catch(function() {
                    btn.disabled = false;
                    toast(errors.error, 'error');
                });
            });
        });

        document.querySelectorAll('[data-target-delete]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!window.confirm(<?= json_encode($language::get('backup_target_delete_confirm')); ?>)) {
                    return;
                }
                var fd = new FormData();
                fd.append('id', btn.getAttribute('data-target-delete'));
                post('backup_target_delete', fd).then(function() {
                    window.location.reload();
                });
            });
        });

        function savePassphrase(value) {
            var fd = new FormData();
            fd.append('passphrase', value);
            post('backup_bundle_passphrase', fd).then(function(d) {
                if (d && d.result === true) {
                    window.location.reload();
                    return;
                }
                toast(<?= json_encode($language::get('backup_bundle_passphrase_hint')); ?>, 'error');
            });
        }
        document.getElementById('bundle-form').addEventListener('submit', function(e) {
            e.preventDefault();
            savePassphrase(document.getElementById('bundle-passphrase').value);
        });
        var clear = document.getElementById('bundle-clear');
        if (clear) {
            clear.addEventListener('click', function() {
                savePassphrase('');
            });
        }

        document.getElementById('verify-now').addEventListener('click', function() {
            var btn = this;
            btn.disabled = true;
            post('backup_verify_now', new FormData()).then(function(d) {
                toast(d && d.result === true ? <?= json_encode($language::get('backup_verify_started')); ?> : errors.error, d && d.result === true ? 'success' : 'error');
            });
        });
    })();
</script>
