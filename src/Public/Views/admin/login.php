<?php

use XcVm\Core\Auth\Authenticator;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\NetworkUtils;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Core\Reference\UiReference;

$rHues = UiReference::hues();

include 'functions.php';

if (file_exists(TMP_PATH . '.migration.first')) {
    header('Location: setup');
}

if (!isset($_SESSION['hash'])) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // Bypass reCAPTCHA on rescue/setup access codes so admins can always get in.
    if (($rBypassRecaptcha = in_array(AuthRepository::getCurrentCode(), array('setup', 'rescue')))) {
        $rSettings['recaptcha_enable'] = false;
    }

    $rIP = NetworkUtils::getUserIP();

    if (0 >= intval($rSettings['login_flood'])) {
    } else {
        $db->query("SELECT COUNT(`id`) AS `count` FROM `login_logs` WHERE `status` = 'INVALID_LOGIN' AND `login_ip` = ? AND TIME_TO_SEC(TIMEDIFF(NOW(), `date`)) <= 86400;", $rIP);

        if ($db->num_rows() != 1) {
        } else {
            if (intval($rSettings['login_flood']) > intval($db->get_row()['count'])) {
            } else {
                BlocklistService::blockIP(array('ip' => $rIP, 'notes' => 'LOGIN FLOOD ATTACK'));

                exit();
            }
        }
    }

    if (!RequestManager::has('login')) {
    } else {
        $rReturn = Authenticator::login(RequestManager::getAll(), $rBypassRecaptcha);
        $_STATUS = $rReturn['status'];

        if ($_STATUS != STATUS_SUCCESS) {
        } else {
            if (AuthRepository::getCurrentCode() == 'setup') {
                header('Location: codes');

                exit();
            }

            if (0 < strlen(RequestManager::get('referrer'))) {
                $rReferer = basename(RequestManager::get('referrer'));

                if (substr($rReferer, 0, 6) != 'logout') {
                } else {
                    $rReferer = 'dashboard';
                }

                header('Location: ' . $rReferer);

                exit();
            }

            header('Location: dashboard');

            exit();
        }
    }

    // Bootstrap 5 (new-UI) login — XC_VM "Core Access" HUD: a full-bleed sci-fi
    // backdrop with a single angular, red-accented sign-in console in the centre.
    // Deliberately single-look (dark + red), so it does not follow the theme/hue
    // cookies the rest of the panel uses.
    $rBrand  = $rSettings['server_name'] ?: 'XC_VM';
    $rStatusMessages = [
        STATUS_FAILURE         => 'login_message_1',
        STATUS_INVALID_CODE    => 'login_message_2',
        STATUS_NOT_ADMIN       => 'login_message_3',
        STATUS_DISABLED        => 'login_message_4',
        STATUS_INVALID_CAPTCHA => 'login_message_5',
    ];
    $rYears = (date('Y') === '2025') ? '2025' : '2025–' . date('Y');
    $rRecaptcha = (bool)($rSettings['recaptcha_enable'] ?? false);
?>
    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta charset="UTF-8">
        <title data-id="login"><?= htmlspecialchars($rBrand, ENT_QUOTES) ?> | <?= $language::get('login') ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="robots" content="noindex,nofollow">
        <link rel="icon" type="image/x-icon" href="assets/img/favicon.ico">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Chakra+Petch:wght@400;500;600;700&family=Share+Tech+Mono&display=swap">

        <link rel="stylesheet" href="assets/xcvm/login.css">
    </head>

    <body>
        <div class="background"></div>
        <div class="grid"></div>

        <div class="page">
            <div class="stage">
                <section class="login-panel">
                    <span class="panel-frame" aria-hidden="true"></span>

                    <div class="panel-logo">
                        <div class="panel-logo-main"><?= htmlspecialchars($rBrand, ENT_QUOTES) ?></div>
                        <div class="panel-title"><?= $language::get('admin_access') ?></div>
                    </div>

                    <?php if (isset($_STATUS) && isset($rStatusMessages[$_STATUS])): ?>
                        <div class="panel-alert" role="alert">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="9" />
                                <path d="M12 8v5M12 16h.01" />
                            </svg>
                            <span><?= $language::get($rStatusMessages[$_STATUS]) ?></span>
                        </div>
                    <?php endif; ?>

                    <form id="loginForm" method="POST" action="./login">
                        <input type="hidden" name="referrer" value="<?= htmlspecialchars(RequestManager::get('referrer') ?? '', ENT_QUOTES) ?>">

                        <div class="form-group">
                            <div class="input-wrapper">
                                <span class="input-icon user" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <circle cx="12" cy="8" r="4" />
                                        <path d="M4 20c0-4 4-6 8-6s8 2 8 6" />
                                    </svg>
                                </span>
                                <input type="text" name="username" id="username" autocomplete="username" required autofocus
                                    placeholder="<?= htmlspecialchars($language::get('username'), ENT_QUOTES) ?>">
                            </div>

                            <div class="input-wrapper">
                                <span class="input-icon password" aria-hidden="true">
                                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="5" y="11" width="14" height="9" rx="2" />
                                        <path d="M8 11V8a4 4 0 0 1 8 0v3" />
                                    </svg>
                                </span>
                                <input type="password" name="password" id="password" autocomplete="current-password" required
                                    placeholder="<?= htmlspecialchars($language::get('password'), ENT_QUOTES) ?>">

                                <button type="button" class="show-password" id="showPassword" aria-label="Show password">
                                    <svg class="eye-on" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6S2 12 2 12z" />
                                        <circle cx="12" cy="12" r="3" />
                                    </svg>
                                    <svg class="eye-off" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                                        <path d="M3 3l18 18" />
                                        <path d="M10.6 10.6a3 3 0 0 0 4.2 4.2" />
                                        <path d="M9.4 5.2A10 10 0 0 1 12 5c6.5 0 10 6 10 6a17 17 0 0 1-3.3 3.9M6.1 6.1A17 17 0 0 0 2 12s3.5 6 10 6a10 10 0 0 0 3.3-.5" />
                                    </svg>
                                </button>
                            </div>
                        </div>

                        <?php if ($rRecaptcha): ?>
                            <div class="recaptcha-wrap">
                                <div class="g-recaptcha" id="verification" data-callback="recaptchaCallback" data-expired-callback="recaptchaExpired" data-sitekey="<?= htmlspecialchars($rSettings['recaptcha_v2_site_key'], ENT_QUOTES) ?>"></div>
                            </div>
                        <?php endif; ?>

                        <button class="login-button" type="submit" id="login_button" name="login" <?= $rRecaptcha ? 'disabled' : '' ?>>
                            <?= $language::get('login') ?>
                            <span class="arrow">→</span>
                        </button>
                    </form>

                    <span class="panel-divider" aria-hidden="true"></span>

                    <div class="panel-foot">
                        &copy; <?= $rYears ?>
                        <a href="https://github.com/Vateron-Media/XC_VM" target="_blank" rel="noopener noreferrer">Vateron Media</a>
                        &middot;
                        <a href="https://www.gnu.org/licenses/agpl-3.0.html" target="_blank" rel="noopener noreferrer">AGPL-3.0</a>
                    </div>
                </section>

                <div class="panel-glow" aria-hidden="true">
                    <span class="g-left"></span>
                    <span class="g-top"></span>
                    <span class="g-bl"></span>
                    <span class="g-blh"></span>
                    <span class="g-br"></span>
                    <span class="g-brh"></span>
                    <span class="g-ticks"><i></i><i></i><i></i><i></i></span>
                    <span class="g-plus p1"></span>
                    <span class="g-plus p2"></span>

                    <span class="g-tl"></span>
                    <span class="g-tr"></span>
                    <span class="e e-topl"></span>
                    <span class="e e-topr"></span>
                    <span class="e e-lv1"></span>
                    <span class="e e-rv1"></span>
                    <span class="e e-rv2"></span>
                    <span class="e e-botl"></span>
                </div>
            </div>
        </div>

        <script>
            (function() {
                "use strict";

                var password = document.getElementById("password");
                var showPassword = document.getElementById("showPassword");

                if (password && showPassword) {
                    showPassword.addEventListener("click", function() {
                        var reveal = password.type === "password";
                        password.type = reveal ? "text" : "password";
                        showPassword.classList.toggle("revealed", reveal);
                        showPassword.setAttribute("aria-label", reveal ? "Hide password" : "Show password");
                    });
                }
            })();
        </script>

        <?php if ($rRecaptcha): ?>
            <script src="https://www.google.com/recaptcha/api.js" async defer></script>
            <script>
                function recaptchaCallback() {
                    var b = document.getElementById('login_button');
                    if (b) {
                        b.disabled = false;
                    }
                }

                function recaptchaExpired() {
                    var b = document.getElementById('login_button');
                    if (b) {
                        b.disabled = true;
                    }
                }
            </script>
        <?php endif; ?>
    </body>

    </html>
<?php
} else {
    header('Location: dashboard');

    exit();
}
