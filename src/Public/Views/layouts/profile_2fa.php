<?php

/**
 * The "Two-factor sign-in" card of the admin and reseller profile pages
 * (Core\Auth\TwoFactor): turn it on with an authenticator app, get new
 * recovery codes, or turn it off. Posts to post.php?action=twofactor
 * (TwoFactor::manage()). In scope: $language, $rUserInfo (the signed-in account).
 *
 * The secret offered here is drawn as a QR code in the browser and goes back
 * with the first code: it is stored only once that code proves the app has it.
 */

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Auth\Totp;
use XcVm\Core\Auth\TwoFactor;
use XcVm\Core\Config\SettingsManager;

$rFactor = TwoFactor::of((int) $rUserInfo['id']);
$rRequired = TwoFactor::required(AuthRepository::getPermissions((int) $rUserInfo['member_group_id']) ?: []);
$rSetupSecret = $rFactor === null ? Totp::newSecret() : null;
?>
<div class="card mt-6" id="twofactor-card">
    <div class="card-header">
        <h5 class="card-title mb-0"><?= $language::get('twofactor_title'); ?></h5>
    </div>
    <div class="card-body">
        <?php if ($rFactor !== null): ?>
            <p class="mb-4"><span class="badge bg-label-success me-2"><?= $language::get('twofactor_on'); ?></span><?= $language::get('twofactor_recovery_left', ['{count}' => (string) count($rFactor['recovery'])]); ?></p>
            <div class="row g-3 align-items-end">
                <div class="col-md-4">
                    <label class="form-label" for="twofactor-code"><?= $language::get('twofactor_current_code'); ?></label>
                    <input type="text" class="form-control" id="twofactor-code" autocomplete="one-time-code" maxlength="16">
                </div>
                <div class="col-md-8">
                    <button type="button" class="btn btn-label-primary me-2" data-twofactor="recovery"><?= $language::get('twofactor_new_recovery'); ?></button>
                    <button type="button" class="btn btn-label-danger" data-twofactor="disable"><?= $language::get('twofactor_turn_off'); ?></button>
                </div>
            </div>
        <?php else: ?>
            <p class="mb-4"><span class="badge bg-label-secondary me-2"><?= $language::get('twofactor_off'); ?></span><?= $rRequired ? $language::get('twofactor_required_note') : ''; ?></p>
            <button type="button" class="btn btn-primary" id="twofactor-start"><?= $language::get('twofactor_turn_on'); ?></button>
            <div class="d-none" id="twofactor-setup">
                <p><?= $language::get('twofactor_setup_intro'); ?></p>
                <div class="d-flex flex-wrap gap-6 align-items-center mb-4">
                    <div id="twofactor-qr" class="bg-white" style="line-height: 0;" data-uri="<?= htmlspecialchars(Totp::uri($rSetupSecret, (string) $rUserInfo['username'], (string) (SettingsManager::get('server_name') ?: 'XC_VM')), ENT_QUOTES); ?>"></div>
                    <code class="fs-5" style="user-select: all;"><?= htmlspecialchars(implode(' ', str_split($rSetupSecret, 4)), ENT_QUOTES); ?></code>
                </div>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label" for="twofactor-code"><?= $language::get('twofactor_code'); ?></label>
                        <input type="text" class="form-control" id="twofactor-code" inputmode="numeric" autocomplete="one-time-code" maxlength="6">
                    </div>
                    <div class="col-md-8">
                        <button type="button" class="btn btn-primary" data-twofactor="enable" data-secret="<?= htmlspecialchars($rSetupSecret, ENT_QUOTES); ?>"><?= $language::get('twofactor_verify'); ?></button>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        <div class="d-none mt-4" id="twofactor-recovery">
            <h6><?= $language::get('twofactor_recovery_title'); ?></h6>
            <p><?= $language::get('twofactor_recovery_text'); ?></p>
            <ul class="list-unstyled row row-cols-2 font-monospace fs-5 mb-4" style="user-select: all;"></ul>
            <button type="button" class="btn btn-primary" id="twofactor-done"><?= $language::get('twofactor_continue'); ?></button>
        </div>
    </div>
</div>
<script src="assets/vendor/libs/qrcode-generator/qrcode.js"></script>
<script>
    (function() {
        var card = document.getElementById('twofactor-card');
        if (!card) {
            return;
        }
        var toast = window.xcToast || function(text) {
            window.alert(text);
        };
        var messages = <?= json_encode([
            STATUS_2FA_INVALID => $language::get('twofactor_invalid'),
            STATUS_2FA_LOCKED => $language::get('twofactor_locked_profile'),
            'error' => $language::get('error_occured'),
        ]); ?>;
        var start = document.getElementById('twofactor-start');
        if (start) {
            start.addEventListener('click', function() {
                start.classList.add('d-none');
                document.getElementById('twofactor-setup').classList.remove('d-none');
                var qrEl = document.getElementById('twofactor-qr');
                if (typeof qrcode === 'function' && qrEl.childNodes.length === 0) {
                    var qr = qrcode(0, 'M');
                    qr.addData(qrEl.getAttribute('data-uri'));
                    qr.make();
                    qrEl.innerHTML = qr.createSvgTag(4, 8);
                }
                document.getElementById('twofactor-code').focus();
            });
        }
        document.getElementById('twofactor-done').addEventListener('click', function() {
            window.location.reload();
        });
        card.querySelectorAll('[data-twofactor]').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var sub = btn.getAttribute('data-twofactor');
                var fd = new FormData();
                fd.append('sub', sub);
                fd.append('code', document.getElementById('twofactor-code').value);
                if (btn.hasAttribute('data-secret')) {
                    fd.append('secret', btn.getAttribute('data-secret'));
                }
                btn.disabled = true;
                fetch('post.php?action=twofactor', {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                }).then(function(r) {
                    return r.json();
                }).then(function(d) {
                    btn.disabled = false;
                    if (!d || d.result !== true) {
                        toast(messages[d && d.status] || messages.error, 'error');
                        return;
                    }
                    if (!d.recovery) {
                        window.location.reload();
                        return;
                    }
                    var list = card.querySelector('#twofactor-recovery ul');
                    list.innerHTML = '';
                    d.recovery.forEach(function(code) {
                        var li = document.createElement('li');
                        li.className = 'col';
                        li.textContent = code;
                        list.appendChild(li);
                    });
                    ['twofactor-setup'].forEach(function(id) {
                        var el = document.getElementById(id);
                        if (el) {
                            el.classList.add('d-none');
                        }
                    });
                    card.querySelectorAll('[data-twofactor]').forEach(function(b) {
                        b.disabled = true;
                    });
                    document.getElementById('twofactor-recovery').classList.remove('d-none');
                }).catch(function() {
                    btn.disabled = false;
                    toast(messages.error, 'error');
                });
            });
        });
    })();
</script>
