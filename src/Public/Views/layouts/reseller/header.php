<?php

/**
 * Bootstrap 5 reseller header — Vertical Menu Template.
 *
 * Mirrors admin/header.php but for the reseller surface: the reseller has
 * NO server / admin-only chrome. The sidebar is built from ResellerNavbarRegistry
 * (see menu.php), gated by the same $rPermissions the legacy reseller header
 * uses. The navbar carries the live header stats, the owner credits pill, a
 * tickets link and the profile dropdown.
 *
 * Rendered by XcVm\Core\Util\LayoutRenderer::renderHeader('reseller') for
 * every reseller page (all migrated to the Bootstrap 5 shell).
 */

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\ResellerNavbarRegistry;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\User\UiPrefs;

if (count(get_included_files()) == 1) {
    exit();
}

/**
 * Shared reseller navbar helpers (same contract as admin/header.php's
 * _xc_nav_visible()/_xc_nav_label(), against ResellerNavbarRegistry and
 * Authorization::hasResellerPermissions() instead of the admin equivalents).
 * Guarded so a single request only ever defines them once.
 */
if (!function_exists('_xc_reseller_nav_visible')) {
    function _xc_reseller_nav_visible(NavbarItem $item, bool $mobile, array $settings): bool {
        if ($item->desktopOnly && $mobile) return false;
        if ($item->settingDisabled !== '' && !empty($settings[$item->settingDisabled])) return false;
        if ($item->divider) return true;
        if (!empty($item->permissions)) {
            foreach ($item->permissions as $_p) {
                if (Authorization::hasResellerPermissions($_p)) return true;
            }
            return false;
        }
        if ($item->url === '#') {
            foreach (ResellerNavbarRegistry::getChildren($item->key) as $_child) {
                if (_xc_reseller_nav_visible($_child, $mobile, $settings)) return true;
            }
            return false;
        }
        return true;
    }
}

if (!function_exists('_xc_reseller_nav_label')) {
    function _xc_reseller_nav_label(NavbarItem $item, string $language): string {
        return $item->translationKey
            ? $language::get($item->translationKey)
            : htmlspecialchars($item->fallbackTitle, ENT_QUOTES);
    }
}
$xmCurrentLang = \XcVm\Core\Localization\Translator::current();
$xmIsRtl       = \XcVm\Core\Localization\Translator::isRtl($xmCurrentLang);
// Customizer prefs; see UiPrefs::pageState() and ui_prefs_head.php.
[$xmUiPrefs, $xmBsTheme] = UiPrefs::pageState($rUserInfo, $xmIsRtl);
?>
<!doctype html>
<html
    lang="<?= htmlspecialchars($xmCurrentLang, ENT_QUOTES); ?>"
    class="layout-navbar-fixed layout-menu-fixed layout-compact"
    dir="<?= $xmIsRtl ? 'rtl' : 'ltr'; ?>"
    data-skin="default"
    data-bs-theme="<?= $xmBsTheme ?>"
    data-assets-path="assets/"
    data-template="vertical-menu-template">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="robots" content="noindex,nofollow">
    <title><?= htmlspecialchars($rSettings['server_name'] ?: 'XC_VM'); ?><?= isset($_TITLE) ? ' | ' . htmlspecialchars($_TITLE) : ''; ?></title>
    <link rel="icon" type="image/x-icon" href="assets/img/favicon/favicon.ico">

    <!-- Fonts -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Public+Sans:ital,wght@0,300;0,400;0,500;0,600;0,700;1,300;1,400;1,500;1,600;1,700&display=swap">

    <!-- Icons: Bootstrap 5 chrome uses Tabler (iconify) -->
    <link rel="stylesheet" href="assets/vendor/fonts/iconify-icons.css">

    <!-- Core theme (single file serves both light & dark via data-bs-theme) -->
    <link rel="stylesheet" href="assets/vendor/libs/node-waves/node-waves.css">
    <link rel="stylesheet" href="assets/vendor/libs/pickr/pickr-themes.css">
    <link rel="stylesheet" href="assets/vendor/libs/perfect-scrollbar/perfect-scrollbar.css">
    <link rel="stylesheet" href="assets/vendor/css/bootstrap.css">
    <link rel="stylesheet" href="assets/vendor/css/custom.css">
    <link rel="stylesheet" href="assets/css/demo.css">

    <!-- Page vendor styles (shared admin registry) + XC_VM overrides -->
    <?php require_once dirname(__DIR__, 2) . '/admin/vendors.php'; ?>
    <?php xc_newui_vendor_css(xc_newui_vendors_wanted()); ?>
    <link rel="stylesheet" href="assets/xcvm/custom.css">
    <?php if ($xmIsRtl): ?>
        <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cairo:wght@300;400;500;600;700;800&display=swap">
        <link rel="stylesheet" href="assets/xcvm/rtl.css">
    <?php endif; ?>

    <?php require __DIR__ . '/../ui_prefs_head.php'; ?>
</head>

<?php if (isset($_GET['modal'])): /* iframe modal shell — no sidebar / navbar / topbar */ ?>

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

                                    <!-- Left: live header stats (polled by footer.php) -->
                                    <div class="navbar-nav align-items-center">
                                        <?php if (!$rMobile && !empty($rSettings['header_stats'])): ?>
                                            <div class="d-none d-xl-flex align-items-center gap-3 px-3 py-1" id="header_stats">
                                                <a href="live_connections" class="d-inline-flex align-items-center text-heading text-decoration-none" title="<?= htmlspecialchars($language::get('connections'), ENT_QUOTES); ?>">
                                                    <i class="icon-base ti tabler-plug-connected icon-22px me-1 text-primary"></i>
                                                    <span class="fw-medium" id="header_connections">0</span>
                                                </a>
                                                <div class="vr opacity-25 my-1"></div>
                                                <a href="live_connections" class="d-inline-flex align-items-center text-heading text-decoration-none" title="<?= htmlspecialchars($language::get('users'), ENT_QUOTES); ?>">
                                                    <i class="icon-base ti tabler-users icon-22px me-1 text-info"></i>
                                                    <span class="fw-medium" id="header_users">0</span>
                                                </a>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <ul class="navbar-nav flex-row align-items-center ms-auto">

                                        <!-- Owner credits pill (refreshed via ?action=stats) -->
                                        <li class="nav-item me-2">
                                            <span class="badge bg-label-primary rounded-pill d-inline-flex align-items-center" title="<?= htmlspecialchars($language::get('credits'), ENT_QUOTES); ?>">
                                                <i class="icon-base ti tabler-coin icon-18px me-1"></i>
                                                <span id="owner_credits"><?= number_format((float) ($rUserInfo['credits'] ?? 0), 0); ?></span>
                                            </span>
                                        </li>

                                        <!-- Tickets -->
                                        <li class="nav-item">
                                            <a class="nav-link btn btn-icon btn-text-secondary rounded-pill" href="tickets" title="<?= htmlspecialchars($language::get('tickets'), ENT_QUOTES); ?>">
                                                <i class="icon-base ti tabler-ticket icon-22px"></i>
                                            </a>
                                        </li>

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

                                        <!-- User dropdown -->
                                        <li class="nav-item navbar-dropdown dropdown-user dropdown">
                                            <a class="nav-link dropdown-toggle hide-arrow p-0" href="javascript:void(0);" data-bs-toggle="dropdown">
                                                <div class="avatar avatar-online">
                                                    <span class="avatar-initial rounded-circle bg-label-primary">
                                                        <?= htmlspecialchars(strtoupper(substr((string) ($rUserInfo['username'] ?? ''), 0, 1))); ?>
                                                    </span>
                                                </div>
                                            </a>
                                            <ul class="dropdown-menu dropdown-menu-end">
                                                <li>
                                                    <div class="dropdown-item mt-0 d-flex align-items-center">
                                                        <div class="flex-shrink-0 me-2">
                                                            <div class="avatar avatar-online">
                                                                <span class="avatar-initial rounded-circle bg-label-primary">
                                                                    <?= htmlspecialchars(strtoupper(substr((string) ($rUserInfo['username'] ?? ''), 0, 1))); ?>
                                                                </span>
                                                            </div>
                                                        </div>
                                                        <div class="flex-grow-1">
                                                            <h6 class="mb-0"><?= htmlspecialchars((string) ($rUserInfo['username'] ?? '')); ?></h6>
                                                            <small class="text-body-secondary"><?= $language::get('shell_role_reseller'); ?></small>
                                                        </div>
                                                    </div>
                                                </li>
                                                <li>
                                                    <div class="dropdown-divider my-1 mx-n2"></div>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="edit_profile">
                                                        <i class="icon-base ti tabler-user me-3 icon-md"></i>
                                                        <span class="align-middle"><?= $language::get('edit_profile'); ?></span>
                                                    </a>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="user_logs?user_id=<?= intval($rUserInfo['id']); ?>">
                                                        <i class="icon-base ti tabler-coin me-3 icon-md"></i>
                                                        <span class="align-middle"><?= $language::get('credit_spend'); ?></span>
                                                    </a>
                                                </li>
                                                <li>
                                                    <div class="dropdown-divider my-1 mx-n2"></div>
                                                </li>
                                                <li>
                                                    <a class="dropdown-item" href="logout">
                                                        <i class="icon-base ti tabler-logout me-3 icon-md"></i>
                                                        <span class="align-middle"><?= $language::get('logout'); ?></span>
                                                    </a>
                                                </li>
                                            </ul>
                                        </li>
                                        <!--/ User dropdown -->
                                    </ul>
                                </div>
                            </nav>
                            <!-- / Navbar -->

                            <!-- Content wrapper (closed in footer.php) -->
                            <div class="content-wrapper">
                                <div class="container-xxl flex-grow-1 container-p-y">
                                <?php endif; /* modal vs full-layout body */ ?>