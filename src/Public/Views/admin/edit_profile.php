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

            <hr class="my-4">
            <h6 class="mb-1"><?= $language::get('appearance_design') ?: 'Appearance (Design)'; ?></h6>
            <p class="text-body-secondary small mb-3"><?= $language::get('appearance_design_hint') ?: 'The same options as the desktop template customizer — handy on mobile, where the customizer panel is hidden.'; ?></p>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="ui-color"><?= $language::get('primary_color') ?: 'Primary Color'; ?></label>
                <div class="col-md-9"><input type="color" class="form-control form-control-color" id="ui-color" data-ui-pref="color" value="#FFAB1D"></div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="ui-theme"><?= $language::get('theme') ?: 'Theme'; ?></label>
                <div class="col-md-9">
                    <select class="form-select" id="ui-theme" data-ui-pref="theme">
                        <option value="light"><?= $language::get('light') ?: 'Light'; ?></option>
                        <option value="dark"><?= $language::get('dark') ?: 'Dark'; ?></option>
                        <option value="system"><?= $language::get('system') ?: 'System'; ?></option>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="ui-skin"><?= $language::get('skin') ?: 'Skin'; ?></label>
                <div class="col-md-9">
                    <select class="form-select" id="ui-skin" data-ui-pref="skin">
                        <option value="default"><?= $language::get('default') ?: 'Default'; ?></option>
                        <option value="bordered"><?= $language::get('bordered') ?: 'Bordered'; ?></option>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="ui-semidark"><?= $language::get('semi_dark') ?: 'Semi Dark'; ?></label>
                <div class="col-md-9"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="ui-semidark" data-ui-pref="semiDark"></div></div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="ui-collapsed"><?= $language::get('menu_collapsed') ?: 'Collapsed Menu'; ?></label>
                <div class="col-md-9"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="ui-collapsed" data-ui-pref="layoutCollapsed"></div></div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="ui-navbar"><?= $language::get('navbar') ?: 'Navbar'; ?></label>
                <div class="col-md-9">
                    <select class="form-select" id="ui-navbar" data-ui-pref="navbar">
                        <option value="sticky"><?= $language::get('sticky') ?: 'Sticky'; ?></option>
                        <option value="static"><?= $language::get('static') ?: 'Static'; ?></option>
                        <option value="hidden"><?= $language::get('hidden') ?: 'Hidden'; ?></option>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="ui-header"><?= $language::get('header_type') ?: 'Header Type'; ?></label>
                <div class="col-md-9">
                    <select class="form-select" id="ui-header" data-ui-pref="headerType">
                        <option value="static"><?= $language::get('static') ?: 'Static'; ?></option>
                        <option value="fixed"><?= $language::get('fixed') ?: 'Fixed'; ?></option>
                    </select>
                </div>
            </div>
            <div class="row mb-3">
                <label class="col-md-3 col-form-label" for="ui-content"><?= $language::get('content_width') ?: 'Content Width'; ?></label>
                <div class="col-md-9">
                    <select class="form-select" id="ui-content" data-ui-pref="contentLayout">
                        <option value="compact"><?= $language::get('compact') ?: 'Compact'; ?></option>
                        <option value="wide"><?= $language::get('wide') ?: 'Wide'; ?></option>
                    </select>
                </div>
            </div>
            <div class="row mb-4">
                <label class="col-md-3 col-form-label" for="ui-rtl"><?= $language::get('rtl') ?: 'RTL'; ?></label>
                <div class="col-md-9"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" id="ui-rtl" data-ui-pref="rtl"></div></div>
            </div>

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

        // Appearance (design) controls — prefill from the effective customizer prefs
        // (server-authoritative, computed in config.js). These map to users.ui_prefs and
        // are saved via save_ui_prefs on submit, so mobile users can change the design the
        // desktop-only template customizer normally owns.
        var uiEff = window.XC_VM_UIEffective || {};
        document.querySelectorAll('#profile-form [data-ui-pref]').forEach(function(el) {
            var key = el.getAttribute('data-ui-pref');
            if (uiEff[key] === undefined || uiEff[key] === null) {
                return;
            }
            if (el.type === 'checkbox') {
                el.checked = (uiEff[key] === true || uiEff[key] === 'true');
            } else {
                el.value = String(uiEff[key]);
            }
        });

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

            // Appearance prefs -> save_ui_prefs (a separate JSON endpoint; these controls
            // carry no name= so they are not part of the edit_profile FormData). The server
            // whitelists/validates the keys, so it is safe to send them all.
            var uiPayload = {};
            this.querySelectorAll('[data-ui-pref]').forEach(function(el) {
                var key = el.getAttribute('data-ui-pref');
                uiPayload[key] = el.type === 'checkbox' ? !!el.checked : el.value;
            });
            var uiUrl = (window.XC_VM && window.XC_VM.uiPrefsUrl) || 'api?action=save_ui_prefs';
            var uiFetch = fetch(uiUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: JSON.stringify(uiPayload)
            }).catch(function() {
                return null;
            });

            var fd = new FormData(this);
            fd.append('submit_profile', '1');
            var profileFetch = fetch('post.php?action=edit_profile', {
                method: 'POST',
                body: fd,
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }).then(function(r) {
                return r.text();
            });

            Promise.all([profileFetch, uiFetch]).then(function(res) {
                var d;
                try {
                    d = JSON.parse(res[0]);
                } catch (err) {
                    d = {
                        result: false
                    };
                }
                if (d && d.result !== false) {
                    // Reload so the shell re-injects the new ui_prefs and the customizer
                    // re-applies the look (works on mobile too, panel hidden or not).
                    window.location.reload();
                    return;
                }
                if (btn) {
                    btn.disabled = false;
                }
                toast(<?= json_encode($language::get('error_occured')); ?>, 'error');
            }).catch(function() {
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