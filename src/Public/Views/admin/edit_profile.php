<?php

/**
 * Edit profile (Bootstrap 5). The current admin's own account: password, email, timezone,
 * system theme, topbar hue, language and (for API-enabled groups) the API key. Saves via
 * post.php?action=edit_profile. Reached full-page in the new-UI shell.
 */

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Enum\Theme;
use XcVm\Core\Reference\UiReference;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Server\ServerRepository;

$rApiCode = null;
foreach (AuthRepository::getAllCodes() as $rCode) {
    if ($rCode['type'] == 3 && in_array($rUserInfo['member_group_id'], json_decode((string) $rCode['groups'], true) ?: [])) {
        $rApiCode = $rCode;
        break;
    }
}
?>

<div class="d-flex align-items-center mb-4">
    <h4 class="mb-0"><?= htmlspecialchars(ucfirst((string) $rUserInfo['username']), ENT_QUOTES); ?></h4>
</div>

<div class="card">
    <div class="card-body">
        <form id="profile-form">
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="password"><?= $language::get('change_password'); ?></label>
                <div class="col-md-9"><input type="text" class="form-control" id="password" name="password" value="" autocomplete="new-password"></div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="email"><?= $language::get('email_address'); ?></label>
                <div class="col-md-9"><input type="email" id="email" class="form-control" name="email" value="<?= htmlspecialchars((string) $rUserInfo['email'], ENT_QUOTES); ?>"></div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="timezone"><?= $language::get('timezone'); ?></label>
                <div class="col-md-9">
                    <select name="timezone" id="timezone" class="form-select">
                        <option value="" <?= empty($rUserInfo['timezone']) ? 'selected' : ''; ?>><?= $language::get('server_default'); ?></option>
                        <?php foreach (AdminHelpers::TimeZoneList() as $rValue): ?>
                            <option value="<?= htmlspecialchars((string) $rValue['zone'], ENT_QUOTES); ?>" <?= $rUserInfo['timezone'] == $rValue['zone'] ? 'selected' : ''; ?>><?= htmlspecialchars($rValue['zone'] . ' ' . $rValue['diff_from_GMT'], ENT_QUOTES); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="theme"><?= $language::get('system_theme'); ?></label>
                <div class="col-md-9">
                    <select name="theme" id="theme" class="form-select">
                        <?php foreach (Theme::options() as $rValue => $rName): ?><option value="<?= $rValue; ?>" <?= $rUserInfo['theme'] == $rValue ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rName, ENT_QUOTES); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="hue"><?= $language::get('topbar_theme'); ?></label>
                <div class="col-md-9">
                    <select name="hue" id="hue" class="form-select">
                        <?php foreach (UiReference::hues() as $rValue => $rText): ?><option value="<?= $rValue; ?>" <?= $rUserInfo['hue'] == $rValue ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rText, ENT_QUOTES); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="lang"><?= $language::get('language'); ?></label>
                <div class="col-md-9">
                    <select name="lang" id="lang" class="form-select">
                        <?php foreach ((is_array($allowedLangs ?? null) ? $allowedLangs : []) as $rText): ?><option value="<?= htmlspecialchars((string) $rText, ENT_QUOTES); ?>" <?= $rUserInfo['lang'] == $rText ? 'selected' : ''; ?>><?= htmlspecialchars((string) $rText, ENT_QUOTES); ?></option><?php endforeach; ?>
                    </select>
                </div>
            </div>
            <?php if ($rApiCode): ?>
                <div class="row mb-4">
                    <label class="col-md-3 col-form-label" for="api_key">API Key <i class="icon-base ti tabler-info-circle text-body-secondary" data-bs-toggle="tooltip" title="API URL: <?= htmlspecialchars((ServerRepository::getAll()[SERVER_ID]['site_url'] ?? '') . $rApiCode['code'] . '/', ENT_QUOTES); ?>"></i></label>
                    <div class="col-md-9">
                        <div class="input-group">
                            <input readonly type="text" maxlength="32" class="form-control" id="api_key" name="api_key" value="<?= htmlspecialchars((string) $rUserInfo['api_key'], ENT_QUOTES); ?>">
                            <button class="btn btn-outline-danger" type="button" id="clear-code"><i class="icon-base ti tabler-x"></i></button>
                            <button class="btn btn-outline-info" type="button" id="generate-code"><i class="icon-base ti tabler-refresh"></i></button>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            <div class="text-end"><button type="submit" class="btn btn-primary" name="submit_profile" value="1"><?= $language::get('save_profile'); ?></button></div>
        </form>
    </div>
</div>

<?php
require_once __DIR__ . '/../layouts/footer.php';
renderUnifiedLayoutFooter('admin');
?>
<script>
    (function() {
        var $ = window.jQuery;
        if (!$) {
            return;
        }
        var toast = window.xcToast || function() {};
        if ($.fn.select2) {
            $('#timezone, #theme, #hue, #lang').select2({
                width: '100%'
            });
        }

        var gen = document.getElementById('generate-code');
        if (gen) {
            gen.addEventListener('click', function() {
                var chars = 'ABCDEF0123456789',
                    out = '';
                for (var i = 0; i < 32; i++) {
                    out += chars.charAt(Math.floor(Math.random() * chars.length));
                }
                document.getElementById('api_key').value = out;
            });
            document.getElementById('clear-code').addEventListener('click', function() {
                document.getElementById('api_key').value = '';
            });
        }

        document.getElementById('profile-form').addEventListener('submit', function(e) {
            e.preventDefault();
            var btn = this.querySelector('button[type="submit"]');
            if (btn) {
                btn.disabled = true;
            }
            var fd = new FormData(this);
            fd.append('submit_profile', '1');
            fetch('post.php?action=edit_profile', {
                    method: 'POST',
                    body: fd,
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest'
                    }
                })
                .then(function(r) {
                    return r.text();
                })
                .then(function(txt) {
                    var d;
                    try {
                        d = JSON.parse(txt);
                    } catch (err) {
                        d = {
                            result: false
                        };
                    }
                    if (d && d.result !== false) {
                        window.location.reload();
                        return;
                    }
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                })
                .catch(function() {
                    if (btn) {
                        btn.disabled = false;
                    }
                    toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
                });
        });
    })();
</script>
</body>

</html>