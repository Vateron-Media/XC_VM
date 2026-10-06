<?php

/**
 * The second step of an admin or reseller sign-in (Core\Auth\TwoFactor),
 * inside the sign-in panel of admin/login.php and reseller/login.php: the code
 * form; the first setup of an account whose group requires two factors; or,
 * right after that setup, the recovery codes, shown once.
 *
 * In scope: $language; $rTwoFactor (TwoFactor::pending()) or $rRecovery (the
 * codes to show); $rTwoFactorStatus (the last TwoFactor::confirm() status, or
 * null); $rContinue (where Continue goes after the recovery codes); $rReferrer
 * (the page the sign-in was asked from, carried to the code step).
 */

use XcVm\Core\Auth\Totp;
use XcVm\Core\Config\SettingsManager;

$rTwoFactorMessages = [
    STATUS_2FA_INVALID => 'twofactor_invalid',
    STATUS_2FA_LOCKED  => 'twofactor_locked',
    STATUS_FAILURE     => 'twofactor_expired',
];
?>
<?php if (isset($rTwoFactorStatus) && isset($rTwoFactorMessages[$rTwoFactorStatus])): ?>
    <div class="panel-alert" role="alert">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="12" cy="12" r="9" />
            <path d="M12 8v5M12 16h.01" />
        </svg>
        <span><?= $language::get($rTwoFactorMessages[$rTwoFactorStatus]) ?></span>
    </div>
<?php endif; ?>

<?php if (!empty($rRecovery)): ?>
    <div class="twofactor">
        <div class="twofactor-heading"><?= $language::get('twofactor_recovery_title') ?></div>
        <p class="twofactor-text"><?= $language::get('twofactor_recovery_text') ?></p>
        <ul class="twofactor-codes">
            <?php foreach ($rRecovery as $rRecoveryCode): ?>
                <li><?= htmlspecialchars($rRecoveryCode, ENT_QUOTES) ?></li>
            <?php endforeach; ?>
        </ul>
        <a class="login-button" href="<?= htmlspecialchars($rContinue, ENT_QUOTES) ?>"><?= $language::get('twofactor_continue') ?> <span class="arrow">→</span></a>
    </div>
<?php elseif (!empty($rTwoFactor)): ?>
    <form class="twofactor" method="POST" action="./login" autocomplete="off">
        <input type="hidden" name="referrer" value="<?= htmlspecialchars($rReferrer ?? '', ENT_QUOTES) ?>">
        <div class="twofactor-heading"><?= $language::get('twofactor_title') ?></div>
        <?php if ((string) $rTwoFactor['enrol'] !== ''): ?>
            <?php $rIssuer = (string) (SettingsManager::get('server_name') ?: 'XC_VM'); ?>
            <p class="twofactor-text"><?= $language::get('twofactor_setup_required') ?></p>
            <div class="twofactor-qr" id="twofactor-qr" data-uri="<?= htmlspecialchars(Totp::uri($rTwoFactor['enrol'], $rTwoFactor['username'], $rIssuer), ENT_QUOTES) ?>"></div>
            <div class="twofactor-key"><?= htmlspecialchars(implode(' ', str_split($rTwoFactor['enrol'], 4)), ENT_QUOTES) ?></div>
        <?php else: ?>
            <p class="twofactor-text"><?= $language::get('twofactor_enter_code') ?></p>
        <?php endif; ?>
        <div class="form-group">
            <div class="input-wrapper">
                <span class="input-icon password" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                        <rect x="7" y="3" width="10" height="18" rx="2" />
                        <path d="M11 17h2" />
                    </svg>
                </span>
                <input type="text" name="twofactor_code" id="twofactor_code" autocomplete="one-time-code" required autofocus maxlength="16"
                    placeholder="<?= htmlspecialchars($language::get('twofactor_code'), ENT_QUOTES) ?>">
            </div>
        </div>
        <button class="login-button" type="submit" name="verify_2fa" value="1"><?= $language::get('twofactor_verify') ?> <span class="arrow">→</span></button>
        <a class="twofactor-cancel" href="./login?cancel_2fa=1"><?= $language::get('twofactor_cancel') ?></a>
    </form>
    <?php if ((string) $rTwoFactor['enrol'] !== ''): ?>
        <script src="assets/vendor/libs/qrcode-generator/qrcode.js"></script>
        <script>
            (function() {
                var el = document.getElementById('twofactor-qr');
                if (!el || typeof qrcode !== 'function') {
                    return;
                }
                var qr = qrcode(0, 'M');
                qr.addData(el.getAttribute('data-uri'));
                qr.make();
                el.innerHTML = qr.createSvgTag(4, 8);
            })();
        </script>
    <?php endif; ?>
<?php endif; ?>
