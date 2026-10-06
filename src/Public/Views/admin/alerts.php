<?php

/**
 * Alerts (Bootstrap 5): where alerts go (channels), when they fire (rules),
 * and the latest messages (Domain\Alert). Saves through the admin Ajax
 * actions alert_channel_save / _delete / _test and alert_rules_save.
 *
 * In scope: $rChannels (AlertChannels::forPage(), secrets masked), $rRules
 * (Alerts::rules()), $rHistory (Alerts::history()).
 */

use XcVm\Core\Util\LayoutRenderer;
use XcVm\Domain\Alert\AlertChannels;
use XcVm\Domain\Alert\Alerts;

$rTypeNames = ['telegram' => 'Telegram', 'webhook' => 'Webhook', 'email' => $language::get('alert_type_email')];
$rFieldNames = [
    'bot_token' => $language::get('alert_bot_token'), 'chat_id' => $language::get('alert_chat_id'),
    'url' => $language::get('alert_webhook_url'), 'secret' => $language::get('alert_webhook_secret'),
    'host' => $language::get('alert_smtp_host'), 'port' => $language::get('alert_smtp_port'), 'security' => $language::get('alert_smtp_security'),
    'username' => $language::get('alert_smtp_username'), 'password' => $language::get('alert_smtp_password'), 'from' => $language::get('alert_smtp_from'), 'to' => $language::get('alert_smtp_to'),
];
?>

<div class="card mb-6">
    <div class="card-header">
        <h5 class="card-title mb-1"><?= $language::get('alert_channels'); ?></h5>
        <p class="mb-0 text-body-secondary"><?= $language::get('alerts_intro'); ?></p>
    </div>
    <div class="card-body">
        <?php if ($rChannels !== []): ?>
            <div class="table-responsive mb-6">
                <table class="table table-sm">
                    <thead>
                        <tr>
                            <th><?= $language::get('name'); ?></th>
                            <th><?= $language::get('type'); ?></th>
                            <th><?= $language::get('status'); ?></th>
                            <th class="text-end"><?= $language::get('actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($rChannels as $rChannel): ?>
                            <tr>
                                <td><?= htmlspecialchars($rChannel['name'], ENT_QUOTES); ?></td>
                                <td><?= htmlspecialchars($rTypeNames[$rChannel['type']] ?? $rChannel['type'], ENT_QUOTES); ?></td>
                                <td><span class="badge <?= $rChannel['enabled'] ? 'bg-label-success' : 'bg-label-secondary'; ?>"><?= $language::get($rChannel['enabled'] ? 'enabled' : 'disabled'); ?></span></td>
                                <td class="text-end text-nowrap">
                                    <button type="button" class="btn btn-sm btn-label-info" data-alert-test="<?= (int) $rChannel['id']; ?>"><?= $language::get('alert_test'); ?></button>
                                    <button type="button" class="btn btn-sm btn-label-primary" data-alert-edit="<?= htmlspecialchars((string) json_encode($rChannel), ENT_QUOTES); ?>"><?= $language::get('edit'); ?></button>
                                    <button type="button" class="btn btn-sm btn-label-danger" data-alert-delete="<?= (int) $rChannel['id']; ?>"><?= $language::get('delete'); ?></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <form id="alert-channel-form" class="row g-3" autocomplete="off">
            <input type="hidden" name="id" value="0">
            <div class="col-md-4">
                <label class="form-label" for="alert-type"><?= $language::get('type'); ?></label>
                <select class="form-select" id="alert-type" name="type">
                    <?php foreach (AlertChannels::TYPES as $rType): ?>
                        <option value="<?= $rType; ?>"><?= htmlspecialchars($rTypeNames[$rType], ENT_QUOTES); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-5">
                <label class="form-label" for="alert-name"><?= $language::get('name'); ?></label>
                <input type="text" class="form-control" id="alert-name" name="name" maxlength="64" required>
            </div>
            <div class="col-md-3 d-flex align-items-end">
                <div class="form-check form-switch mb-2">
                    <input class="form-check-input" type="checkbox" id="alert-enabled" name="enabled" value="1" checked>
                    <label class="form-check-label" for="alert-enabled"><?= $language::get('enabled'); ?></label>
                </div>
            </div>
            <?php foreach (AlertChannels::FIELDS as $rType => $rFields): ?>
                <?php foreach ($rFields as $rField => $rSecret): ?>
                    <div class="col-md-4" data-alert-field="<?= $rType; ?>">
                        <label class="form-label" for="alert-<?= $rType . '-' . $rField; ?>"><?= htmlspecialchars($rFieldNames[$rField], ENT_QUOTES); ?></label>
                        <?php if ($rField === 'security'): ?>
                            <select class="form-select" id="alert-<?= $rType . '-' . $rField; ?>" data-name="<?= $rField; ?>">
                                <option value="starttls">STARTTLS (587)</option>
                                <option value="ssl">SSL/TLS (465)</option>
                                <option value="none"><?= $language::get('alert_smtp_none'); ?></option>
                            </select>
                        <?php else: ?>
                            <input type="<?= $rSecret ? 'password' : 'text'; ?>" class="form-control" id="alert-<?= $rType . '-' . $rField; ?>" data-name="<?= $rField; ?>"
                                <?= $rSecret ? 'placeholder="' . htmlspecialchars($language::get('alert_secret_kept'), ENT_QUOTES) . '"' : ''; ?>
                                <?= $rField === 'port' ? 'inputmode="numeric" value="587"' : ''; ?>>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            <?php endforeach; ?>
            <div class="col-12">
                <button type="submit" class="btn btn-primary"><?= $language::get('save'); ?></button>
                <button type="button" class="btn btn-label-secondary d-none" id="alert-cancel"><?= $language::get('cancel'); ?></button>
            </div>
        </form>
    </div>
</div>

<div class="card mb-6">
    <div class="card-header">
        <h5 class="card-title mb-1"><?= $language::get('alert_rules'); ?></h5>
        <p class="mb-0 text-body-secondary"><?= $language::get('alert_rules_intro'); ?></p>
    </div>
    <div class="card-body">
        <form id="alert-rules-form">
            <div class="table-responsive">
                <table class="table table-sm align-middle">
                    <thead>
                        <tr>
                            <th><?= $language::get('alert_rule'); ?></th>
                            <th><?= $language::get('enabled'); ?></th>
                            <th><?= $language::get('alert_threshold'); ?></th>
                            <th><?= $language::get('alert_minutes'); ?></th>
                            <th><?= $language::get('alert_channels'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (Alerts::RULES as $rRule => [$rTitle, $rThreshold]): ?>
                            <?php $rRow = $rRules[$rRule]; ?>
                            <tr>
                                <td><?= $language::get('alert_rule_' . $rRule); ?></td>
                                <td><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="rules[<?= $rRule; ?>][enabled]" value="1" <?= $rRow['enabled'] ? 'checked' : ''; ?>></div></td>
                                <td style="width: 7rem;">
                                    <?php if ($rThreshold !== null): ?>
                                        <div class="input-group input-group-sm"><input type="number" class="form-control" name="rules[<?= $rRule; ?>][threshold]" min="1" max="100" value="<?= (int) $rRow['threshold']; ?>"><span class="input-group-text">%</span></div>
                                    <?php endif; ?>
                                </td>
                                <td style="width: 7rem;"><input type="number" class="form-control form-control-sm" name="rules[<?= $rRule; ?>][minutes]" min="0" max="1440" value="<?= (int) $rRow['minutes']; ?>"></td>
                                <td>
                                    <?php foreach ($rChannels as $rChannel): ?>
                                        <div class="form-check form-check-inline">
                                            <input class="form-check-input" type="checkbox" id="rule-<?= $rRule . '-' . (int) $rChannel['id']; ?>" name="rules[<?= $rRule; ?>][channels][]" value="<?= (int) $rChannel['id']; ?>" <?= in_array($rChannel['id'], $rRow['channels'], true) ? 'checked' : ''; ?>>
                                            <label class="form-check-label" for="rule-<?= $rRule . '-' . (int) $rChannel['id']; ?>"><?= htmlspecialchars($rChannel['name'], ENT_QUOTES); ?></label>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if ($rChannels === []): ?><span class="text-body-secondary"><?= $language::get('alert_no_channels'); ?></span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <button type="submit" class="btn btn-primary mt-4"><?= $language::get('save'); ?></button>
        </form>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('alert_history'); ?></h5>
    </div>
    <div class="table-responsive">
        <table class="table table-sm">
            <thead>
                <tr>
                    <th><?= $language::get('date'); ?></th>
                    <th><?= $language::get('alert_message'); ?></th>
                    <th><?= $language::get('alert_results'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($rHistory as $rEntry): ?>
                    <tr>
                        <td class="text-nowrap"><?= date('Y-m-d H:i', (int) $rEntry['date']); ?></td>
                        <td>
                            <span class="badge <?= ['fired' => 'bg-label-danger', 'resolved' => 'bg-label-success'][$rEntry['kind']] ?? 'bg-label-secondary'; ?> me-1"><?= htmlspecialchars((string) $rEntry['kind'], ENT_QUOTES); ?></span>
                            <strong><?= htmlspecialchars((string) $rEntry['title'], ENT_QUOTES); ?></strong>
                            <div class="text-body-secondary" style="white-space: pre-line;"><?= htmlspecialchars((string) $rEntry['text'], ENT_QUOTES); ?></div>
                        </td>
                        <td>
                            <?php foreach ((array) json_decode((string) $rEntry['results'], true) as $rChannelName => $rResult): ?>
                                <div><?= htmlspecialchars((string) $rChannelName, ENT_QUOTES); ?>: <span class="<?= $rResult === 'ok' ? 'text-success' : 'text-danger'; ?>"><?= htmlspecialchars((string) $rResult, ENT_QUOTES); ?></span></div>
                            <?php endforeach; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                <?php if ($rHistory === []): ?>
                    <tr><td colspan="3" class="text-center text-body-secondary"><?= $language::get('alert_history_empty'); ?></td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php
LayoutRenderer::renderFooter('admin');
?>
<script>
    (function() {
        var toast = window.xcToast || function(text) {
            window.alert(text);
        };
        var errors = <?= json_encode([
            'name' => $language::get('alert_error_name'),
            'bot_token' => $language::get('alert_error_bot_token'),
            'chat_id' => $language::get('alert_error_chat_id'),
            'url' => $language::get('alert_error_url'),
            'host' => $language::get('alert_error_host'),
            'from' => $language::get('alert_error_from'),
            'to' => $language::get('alert_error_to'),
            'error' => $language::get('error_occured'),
        ]); ?>;
        var sent = <?= json_encode($language::get('alert_test_sent')); ?>;
        var form = document.getElementById('alert-channel-form');

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
            form.querySelectorAll('[data-alert-field]').forEach(function(el) {
                el.classList.toggle('d-none', el.getAttribute('data-alert-field') !== type);
            });
        }
        form.elements.type.addEventListener('change', showType);
        showType();

        form.addEventListener('submit', function(e) {
            e.preventDefault();
            var fd = new FormData(form);
            var type = form.elements.type.value;
            form.querySelectorAll('[data-alert-field="' + type + '"] [data-name]').forEach(function(el) {
                fd.append(el.getAttribute('data-name'), el.value);
            });
            post('alert_channel_save', fd).then(function(d) {
                if (d && d.result === true) {
                    window.location.reload();
                    return;
                }
                toast(errors[d && d.error] || errors.error, 'error');
            }).catch(function() {
                toast(errors.error, 'error');
            });
        });

        // Edit: the form takes the channel; its secrets come back masked and are kept unless typed again.
        document.querySelectorAll('[data-alert-edit]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var channel = JSON.parse(btn.getAttribute('data-alert-edit'));
                form.elements.id.value = channel.id;
                form.elements.type.value = channel.type;
                form.elements.type.disabled = true;
                form.elements.name.value = channel.name;
                form.elements.enabled.checked = channel.enabled === 1;
                form.querySelectorAll('[data-alert-field="' + channel.type + '"] [data-name]').forEach(function(el) {
                    el.value = channel.config[el.getAttribute('data-name')] || '';
                });
                showType();
                document.getElementById('alert-cancel').classList.remove('d-none');
                form.scrollIntoView({
                    behavior: 'smooth'
                });
            });
        });
        document.getElementById('alert-cancel').addEventListener('click', function() {
            window.location.reload();
        });
        // A disabled select is not posted: send the type of a channel being edited anyway.
        form.addEventListener('formdata', function(e) {
            if (form.elements.type.disabled) {
                e.formData.set('type', form.elements.type.value);
            }
        });

        document.querySelectorAll('[data-alert-test]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var fd = new FormData();
                fd.append('id', btn.getAttribute('data-alert-test'));
                btn.disabled = true;
                post('alert_channel_test', fd).then(function(d) {
                    btn.disabled = false;
                    toast(d && d.result === true ? sent : (d && d.error) || errors.error, d && d.result === true ? 'success' : 'error');
                }).catch(function() {
                    btn.disabled = false;
                    toast(errors.error, 'error');
                });
            });
        });

        document.querySelectorAll('[data-alert-delete]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                if (!window.confirm(<?= json_encode($language::get('alert_delete_confirm')); ?>)) {
                    return;
                }
                var fd = new FormData();
                fd.append('id', btn.getAttribute('data-alert-delete'));
                post('alert_channel_delete', fd).then(function() {
                    window.location.reload();
                });
            });
        });

        document.getElementById('alert-rules-form').addEventListener('submit', function(e) {
            e.preventDefault();
            post('alert_rules_save', new FormData(this)).then(function(d) {
                if (d && d.result === true) {
                    window.location.reload();
                    return;
                }
                toast(errors.error, 'error');
            }).catch(function() {
                toast(errors.error, 'error');
            });
        });
    })();
</script>
</body>

</html>
