<?php

/**
 * Bootstrap 5 admin header — Vertical Menu Template (v10.11.1).
 *
 * Rebuilt from the stock Bootstrap 5 vertical-menu shell and wired to the current
 * XC_VM backend. Rendered by renderUnifiedLayoutHeader('admin') only for pages
 * opted in through xc_admin_use_newui() (see layouts/admin.php); every other
 * admin page keeps the legacy header.php shell.
 *
 * Theme is server-authoritative: data-bs-theme is emitted from the user's
 * stored theme via the Theme enum. The live per-user customizer
 * (template-customizer.js + DB persistence) is wired in a later phase.
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Enum\Theme;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;

if (count(get_included_files()) == 1) {
    exit();
}

$rUpdate  = (json_decode((string) SettingsManager::getAll()['update_data'], true) ?: []);
$xmIsDark = Theme::fromId($rUserInfo['theme'] ?? 0)->isDark();

// Per-user Bootstrap 5 customizer state (see config.js + StatsAjaxController::saveUiPrefs).
// The stored theme wins for the initial data-bs-theme paint; 'system'/unset falls
// back to the legacy per-user theme column so there is no flash.
$xmUiPrefs   = json_decode($rUserInfo['ui_prefs'] ?? '', true) ?: [];
$xmThemePref = $xmUiPrefs['theme'] ?? null;
$xmBsTheme   = $xmThemePref === 'dark' ? 'dark' : ($xmThemePref === 'light' ? 'light' : ($xmIsDark ? 'dark' : 'light'));

/**
 * Shared navbar helpers (identical contract to legacy header.php). Guarded so a
 * single request only ever defines them once regardless of which header ran.
 */
if (!function_exists('_xc_nav_visible')) {
    function _xc_nav_visible(NavbarItem $item, bool $mobile, array $settings): bool {
        if ($item->desktopOnly && $mobile) return false;
        if ($item->settingDisabled !== '' && !empty($settings[$item->settingDisabled])) return false;
        if ($item->divider) return true;
        if (!empty($item->permissions)) {
            foreach ($item->permissions as $_p) {
                if (Authorization::check('adv', $_p)) return true;
            }
            return false;
        }
        if ($item->url === '#') {
            foreach (NavbarRegistry::getChildren($item->key) as $_child) {
                if (_xc_nav_visible($_child, $mobile, $settings)) return true;
            }
            return false;
        }
        return true;
    }
}

if (!function_exists('_xc_nav_label')) {
    function _xc_nav_label(NavbarItem $item, string $language): string {
        return $item->translationKey
            ? $language::get($item->translationKey)
            : htmlspecialchars($item->fallbackTitle, ENT_QUOTES);
    }
}
?>
<!doctype html>
<html
    lang="en"
    class="layout-navbar-fixed layout-menu-fixed layout-compact"
    dir="ltr"
    data-skin="default"
    data-bs-theme="<?= $xmBsTheme ?>"
    data-assets-path="assets/new/"
    data-template="vertical-menu-template">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars(($rSettings['server_name'] ?? '') ?: 'XC_VM'); ?><?= isset($_TITLE) ? ' | ' . htmlspecialchars($_TITLE) : ''; ?></title>
    <link rel="icon" type="image/x-icon" href="assets/new/img/favicon/favicon.ico">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap">

    <!-- Icons: Bootstrap 5 chrome uses Tabler (iconify) -->
    <link rel="stylesheet" href="assets/new/vendor/fonts/iconify-icons.css">

    <!-- Core theme (single file serves both light & dark via data-bs-theme) -->
    <link rel="stylesheet" href="assets/new/vendor/libs/node-waves/node-waves.css">
    <link rel="stylesheet" href="assets/new/vendor/libs/pickr/pickr-themes.css">
    <link rel="stylesheet" href="assets/new/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
    <link rel="stylesheet" href="assets/new/vendor/css/core.css">
    <link rel="stylesheet" href="assets/new/css/demo.css">

    <!-- Page vendor styles (bundle bridge) + XC_VM overrides -->
    <?php require_once __DIR__ . '/vendors.php'; ?>
    <?php xc_newui_vendor_css(xc_newui_vendors_wanted()); ?>
    <link rel="stylesheet" href="assets/new/xcvm/custom.css">

    <!-- Helpers + template customizer must precede config.js -->
    <script src="assets/new/vendor/js/helpers.js"></script>
    <script src="assets/new/vendor/js/template-customizer.js"></script>
    <!-- Per-user customizer state (server-authoritative) consumed by config.js -->
    <script>
        window.XC_VM = window.XC_VM || {};
        window.XC_VM.uiPrefsUrl = './api?action=save_ui_prefs';
        window.XC_VM_UIPrefs = <?= json_encode($xmUiPrefs, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="assets/new/js/config.js"></script>
</head>

<?php if (!empty($GLOBALS['_SETUP'])): /* setup wizard — branded bare shell: no sidebar / navbar / menu, and never touches $rUserInfo */ ?>

<body>
    <!-- Setup top bar: brand only (no sidebar / navbar / user menu / header stats) -->
    <nav class="layout-navbar navbar navbar-expand-xl align-items-center bg-navbar-theme mb-4">
        <div class="container-xxl">
            <span class="app-brand-link d-flex align-items-center">
                <span class="app-brand-logo demo">
                    <img src="assets/old/images/logo-topbar.png" alt="<?= htmlspecialchars(($rSettings['server_name'] ?? '') ?: 'XC_VM'); ?>" height="24">
                </span>
                <span class="app-brand-text fw-bold ms-3"><?= htmlspecialchars(($rSettings['server_name'] ?? '') ?: 'XC_VM'); ?></span>
            </span>
        </div>
    </nav>
    <div class="container-xxl py-4" style="max-width:900px;margin:auto;">
<?php elseif (isset($_GET['modal'])): /* iframe modal shell — no sidebar / navbar / topbar */ ?>

<body class="xm-modal-body">
    <div class="container-fluid p-4">
<?php else: ?>

<body>
    <div class="layout-wrapper layout-content-navbar">
        <div class="layout-container">

            <?php require __DIR__ . '/menu.php'; ?>

            <!-- Layout page -->
            <div class="layout-page">

                <!-- Navbar -->
                <nav class="layout-navbar container-xxl navbar-detached navbar navbar-expand-xl align-items-center bg-navbar-theme" id="layout-navbar">
                    <div class="layout-menu-toggle navbar-nav align-items-xl-center me-3 me-xl-0 d-xl-none">
                        <a class="nav-item nav-link px-0 me-xl-6" href="javascript:void(0)">
                            <i class="icon-base ti tabler-menu-2 icon-md"></i>
                        </a>
                    </div>

                    <div class="navbar-nav-right d-flex align-items-center justify-content-between w-100" id="navbar-collapse">

                        <!-- Left: live header stats (polled by the inline poller in footer.newui.php) -->
                        <div class="navbar-nav align-items-center">
                            <?php if (!$rMobile && !empty($rSettings['header_stats'])): ?>
                                <div class="d-none d-xl-flex align-items-center" id="header_stats">
                                    <a href="live_connections" class="d-inline-flex align-items-center text-heading text-decoration-none me-4" title="<?= htmlspecialchars($language::get('connections') ?: 'Connections'); ?>">
                                        <i class="icon-base ti tabler-plug-connected icon-22px me-1"></i>
                                        <span class="fw-medium" id="header_connections">0</span>
                                    </a>
                                    <a href="live_connections" class="d-inline-flex align-items-center text-heading text-decoration-none me-4" title="<?= htmlspecialchars($language::get('users') ?: 'Users'); ?>">
                                        <i class="icon-base ti tabler-users icon-22px me-1"></i>
                                        <span class="fw-medium" id="header_users">0</span>
                                    </a>
                                    <a href="streams" class="d-inline-flex align-items-center text-heading text-decoration-none me-4" title="<?= htmlspecialchars($language::get('shell_streams_online_offline'), ENT_QUOTES); ?>">
                                        <i class="icon-base ti tabler-player-play icon-22px me-1"></i>
                                        <span class="fw-medium text-success" id="header_streams_up">0</span>
                                        <span class="mx-1 text-body-secondary">/</span>
                                        <span class="fw-medium text-danger" id="header_streams_down">0</span>
                                    </a>
                                    <span class="d-inline-flex align-items-center text-heading" title="<?= htmlspecialchars($language::get('shell_network_throughput'), ENT_QUOTES); ?>">
                                        <i class="icon-base ti tabler-arrows-up-down icon-22px me-1"></i>
                                        <span class="fw-medium" id="header_network_up">0</span>
                                        <span class="mx-1 text-body-secondary">/</span>
                                        <span class="fw-medium" id="header_network_down">0</span>
                                        <small class="ms-1 text-body-secondary">Mbps</small>
                                    </span>
                                </div>
                            <?php endif; ?>
                        </div>

                        <ul class="navbar-nav flex-row align-items-center ms-auto">

                            <!-- Global quick search -->
                            <?php if (!empty($rSettings['enable_search'])): ?>
                                <li class="nav-item me-3 d-none d-lg-block" style="position:relative; width:300px;">
                                    <i class="icon-base ti tabler-search position-absolute text-body-secondary" style="left:0.85rem; top:50%; transform:translateY(-50%); pointer-events:none; z-index:4;"></i>
                                    <input type="text" id="xc-quick-search" class="form-control form-control-sm rounded-pill" style="padding-left:2.4rem;" autocomplete="off" placeholder="<?= htmlspecialchars($language::get('search_placeholder'), ENT_QUOTES); ?>">
                                    <div id="xc-search-results" class="dropdown-menu w-100 mt-2 p-0 shadow border-0" style="max-height:70vh; overflow-y:auto; min-width:340px;"></div>
                                </li>
                            <?php endif; ?>

                            <!-- Server status indicator -->
                            <?php if (($rServerError ?? false) && Authorization::check('adv', 'servers')): ?>
                                <li class="nav-item">
                                    <a class="nav-link btn btn-icon btn-text-secondary rounded-pill text-danger" href="servers" title="<?= htmlspecialchars($language::get('shell_server_issue'), ENT_QUOTES); ?>">
                                        <i class="icon-base ti tabler-wifi-off icon-22px"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <!-- NOTE: the tickets dropdown (legacy header ran raw $db queries inline here)
                                 is intentionally deferred; it will return fed by a controller/provider,
                                 not inline DB access in the shell. -->

                            <!-- Theme switcher (light / dark / system) -->
                            <li class="nav-item dropdown">
                                <a class="nav-link dropdown-toggle hide-arrow btn btn-icon btn-text-secondary rounded-pill"
                                    id="nav-theme" href="javascript:void(0);" data-bs-toggle="dropdown">
                                    <i class="icon-base ti tabler-sun icon-22px theme-icon-active text-heading"></i>
                                    <span class="d-none ms-2" id="nav-theme-text"><?= $language::get('shell_toggle_theme'); ?></span>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end" aria-labelledby="nav-theme-text">
                                    <li>
                                        <button type="button" class="dropdown-item align-items-center" data-bs-theme-value="light">
                                            <span><i class="icon-base ti tabler-sun icon-22px me-3" data-icon="sun"></i><?= $language::get('shell_theme_light'); ?></span>
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item align-items-center" data-bs-theme-value="dark">
                                            <span><i class="icon-base ti tabler-moon-stars icon-22px me-3" data-icon="moon-stars"></i><?= $language::get('shell_theme_dark'); ?></span>
                                        </button>
                                    </li>
                                    <li>
                                        <button type="button" class="dropdown-item align-items-center" data-bs-theme-value="system">
                                            <span><i class="icon-base ti tabler-device-desktop-analytics icon-22px me-3" data-icon="device-desktop-analytics"></i><?= $language::get('shell_theme_system'); ?></span>
                                        </button>
                                    </li>
                                </ul>
                            </li>

                            <!-- Update badge -->
                            <?php if (!$rMobile && isset($rUpdate['version']) && (version_compare($rUpdate['version'], XC_VM_VERSION) >= 0)): ?>
                                <li class="nav-item">
                                    <a class="nav-link btn btn-icon btn-text-secondary rounded-pill text-warning" href="settings"
                                        title="<?= htmlspecialchars($language::get('shell_update_available', ['{version}' => $rUpdate['version']]), ENT_QUOTES); ?>">
                                        <i class="icon-base ti tabler-arrow-big-up-lines icon-22px"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <!-- User dropdown — registry-driven (CoreNavbarProvider 'profile' + module links) -->
                            <li class="nav-item navbar-dropdown dropdown-user dropdown">
                                <a class="nav-link dropdown-toggle hide-arrow p-0" href="javascript:void(0);" data-bs-toggle="dropdown">
                                    <div class="avatar avatar-online">
                                        <span class="avatar-initial rounded-circle bg-label-primary">
                                            <?= htmlspecialchars(strtoupper(substr((string) $rUserInfo['username'], 0, 1))); ?>
                                        </span>
                                    </div>
                                </a>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li>
                                        <div class="dropdown-item mt-0 d-flex align-items-center">
                                            <div class="flex-shrink-0 me-2">
                                                <div class="avatar avatar-online">
                                                    <span class="avatar-initial rounded-circle bg-label-primary">
                                                        <?= htmlspecialchars(strtoupper(substr((string) $rUserInfo['username'], 0, 1))); ?>
                                                    </span>
                                                </div>
                                            </div>
                                            <div class="flex-grow-1">
                                                <h6 class="mb-0"><?= htmlspecialchars($rUserInfo['username']); ?></h6>
                                                <small class="text-body-secondary"><?= $language::get('shell_role_admin'); ?></small>
                                            </div>
                                        </div>
                                    </li>
                                    <li><div class="dropdown-divider my-1 mx-n2"></div></li>
                                    <?php
                                    $_profileItems = [];
                                    foreach (NavbarRegistry::getChildren('profile') as $_pi) {
                                        if (_xc_nav_visible($_pi, $rMobile, $rSettings)) $_profileItems[] = $_pi;
                                    }
                                    foreach (NavbarRegistry::collapseDividers($_profileItems) as $_pi):
                                        if ($_pi->divider): ?>
                                            <li><div class="dropdown-divider my-1 mx-n2"></div></li>
                                        <?php else: ?>
                                            <li>
                                                <a class="dropdown-item" href="<?= htmlspecialchars($_pi->url, ENT_QUOTES); ?>">
                                                    <i class="icon-base ti tabler-chevron-right me-3 icon-md"></i>
                                                    <span class="align-middle"><?= _xc_nav_label($_pi, $language); ?></span>
                                                </a>
                                            </li>
                                    <?php endif;
                                    endforeach; ?>
                                </ul>
                            </li>
                            <!--/ User dropdown -->
                        </ul>
                    </div>
                </nav>
                <!-- / Navbar -->

                <!-- Content wrapper (closed in footer.newui.php) -->
                <div class="content-wrapper">
                    <div class="container-xxl flex-grow-1 container-p-y">
                        <?php require __DIR__ . '/topbar.php'; ?>
<?php endif; /* modal vs full-layout body */ ?>
