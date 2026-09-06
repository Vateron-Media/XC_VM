<?php

/**
 * Bootstrap 5 reseller header — Vertical Menu Template.
 *
 * Mirrors admin/header.php but for the reseller surface: the reseller has
 * NO server / admin-only chrome. The sidebar is built from a hardcoded reseller
 * menu array (the legacy reseller navigation is hardcoded too, so there is no
 * NavbarRegistry tree to walk here), gated by the same $rPermissions the legacy
 * reseller header uses. The navbar carries the live header stats, the owner
 * credits pill, a tickets link and the profile dropdown.
 *
 * Rendered by renderUnifiedLayoutHeader('reseller') for every reseller page
 * (all migrated to the Bootstrap 5 shell).
 */

use XcVm\Core\Enum\Theme;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Domain\Line\LineService;

if (count(get_included_files()) == 1) {
    exit();
}

// Trial generation gate — legacy reseller header computes and publishes this so
// the "Generate Trial" menu entries and downstream views can read it.
$rGenTrials = LineService::canGenerateTrials($rUserInfo['id']);
$GLOBALS['rGenTrials'] = $rGenTrials;

$xmIsDark = Theme::fromId($rUserInfo['theme'] ?? 0)->isDark();

// Per-user Bootstrap 5 customizer state (shared config.js contract). The stored
// theme wins for the initial data-bs-theme paint; 'system'/unset falls back to
// the legacy per-user theme column so there is no flash.
$xmUiPrefs   = json_decode($rUserInfo['ui_prefs'] ?? '', true) ?: [];
$xmThemePref = $xmUiPrefs['theme'] ?? null;
$xmBsTheme   = $xmThemePref === 'dark' ? 'dark' : ($xmThemePref === 'light' ? 'light' : ($xmIsDark ? 'dark' : 'light'));

$xmPage        = AdminHelpers::getPageName();
$xmPermissions = $rPermissions ?? [];

/**
 * Reseller sidebar menu (hardcoded, permission-gated). Each node is:
 *   ['label' => i18n key, 'icon' => tabler class, 'url' => string,
 *    'show' => bool, 'children' => node[]].
 * Only top-level nodes carry an icon (matches the admin vertical-menu markup).
 */
$xmMenu = [
    [
        'label' => 'dashboard',
        'icon'  => 'ti tabler-smart-home',
        'url'   => 'dashboard',
        'show'  => true,
    ],
    [
        'label' => 'sub_resellers',
        'icon'  => 'ti tabler-users',
        'url'   => '#',
        'show'  => !empty($xmPermissions['create_sub_resellers']),
        'children' => [
            ['label' => 'add_user',     'url' => 'user',  'show' => true],
            ['label' => 'manage_users', 'url' => 'users', 'show' => true],
        ],
    ],
    [
        'label' => 'devices',
        'icon'  => 'ti tabler-device-desktop',
        'url'   => '#',
        'show'  => !empty($xmPermissions['create_line']) || !empty($xmPermissions['create_mag']) || !empty($xmPermissions['create_enigma']),
        'children' => [
            [
                'label' => 'user_lines',
                'url'   => '#',
                'show'  => !empty($xmPermissions['create_line']),
                'children' => [
                    ['label' => 'add_line',      'url' => 'line',         'show' => true],
                    ['label' => 'generate_trial', 'url' => 'line?trial=1', 'show' => $rGenTrials],
                    ['label' => 'manage_lines',  'url' => 'lines',        'show' => true],
                ],
            ],
            [
                'label' => 'mag_devices',
                'url'   => '#',
                'show'  => !empty($xmPermissions['create_mag']),
                'children' => [
                    ['label' => 'add_mag',            'url' => 'mag',         'show' => true],
                    ['label' => 'generate_trial',     'url' => 'mag?trial=1', 'show' => $rGenTrials],
                    ['label' => 'manage_mag_devices', 'url' => 'mags',        'show' => true],
                ],
            ],
            [
                'label' => 'enigma_devices',
                'url'   => '#',
                'show'  => !empty($xmPermissions['create_enigma']),
                'children' => [
                    ['label' => 'add_enigma',            'url' => 'enigma',         'show' => true],
                    ['label' => 'generate_trial',        'url' => 'enigma?trial=1', 'show' => $rGenTrials],
                    ['label' => 'manage_enigma_devices', 'url' => 'enigmas',        'show' => true],
                ],
            ],
        ],
    ],
    [
        'label' => 'content',
        'icon'  => 'ti tabler-player-play',
        'url'   => '#',
        'show'  => !empty($xmPermissions['can_view_vod']),
        'children' => [
            ['label' => 'streams',          'url' => 'streams',          'show' => true],
            ['label' => 'created_channels',  'url' => 'created_channels', 'show' => true],
            ['label' => 'movies',           'url' => 'movies',           'show' => true],
            ['label' => 'episodes',         'url' => 'episodes',         'show' => true],
            ['label' => 'radios',           'url' => 'radios',           'show' => true],
            ['label' => 'tv_guide',         'url' => 'epg_view',         'show' => !$rMobile],
        ],
    ],
    [
        'label' => 'tickets',
        'icon'  => 'ti tabler-ticket',
        'url'   => 'tickets',
        'show'  => true,
    ],
    [
        'label' => 'logs',
        'icon'  => 'ti tabler-clipboard-list',
        'url'   => '#',
        'show'  => true,
        'children' => [
            ['label' => 'live_connections', 'url' => 'live_connections', 'show' => !empty($xmPermissions['reseller_client_connection_logs'])],
            ['label' => 'activity_logs',    'url' => 'line_activity',    'show' => !empty($xmPermissions['reseller_client_connection_logs'])],
            ['label' => 'user_logs',        'url' => 'user_logs',        'show' => true],
        ],
    ],
];

/**
 * Render the reseller sidebar tree.
 *
 * Returns [html, isActive] so the active leaf and its ancestors are resolved in
 * one traversal (ancestors gain `open active`). Only depth-0 nodes carry an icon.
 */
if (!function_exists('_xc_reseller_menu_node')) {
    function _xc_reseller_menu_node(array $item, int $depth, string $page, string $language): array {
        if (empty($item['show'])) {
            return ['', false];
        }
        $children = array_filter($item['children'] ?? [], static fn($c) => !empty($c['show']));

        $childHtml = '';
        $childActive = false;
        if ($children) {
            $sub = '';
            foreach ($children as $child) {
                [$node, $active] = _xc_reseller_menu_node($child, $depth + 1, $page, $language);
                $sub .= $node;
                $childActive = $childActive || $active;
            }
            if ($sub !== '') {
                $childHtml = '<ul class="menu-sub">' . $sub . '</ul>';
            }
        }
        $hasKids = $childHtml !== '';

        $url  = (string) ($item['url'] ?? '#');
        // Match the current page by the item's URL basename (strip any query).
        $urlBase    = $url !== '#' && $url !== '' ? basename(explode('?', $url)[0]) : '';
        $selfActive = $urlBase !== '' && $urlBase === $page;
        $active     = $selfActive || ($hasKids && $childActive);

        $liClass = 'menu-item' . ($active ? ' active' : '') . ($hasKids && $active ? ' open' : '');
        $href    = htmlspecialchars($hasKids ? 'javascript:void(0);' : $url, ENT_QUOTES);
        $icon    = $depth === 0
            ? '<i class="menu-icon icon-base ' . htmlspecialchars((string) ($item['icon'] ?? 'ti tabler-circle'), ENT_QUOTES) . '"></i>'
            : '';
        $label   = htmlspecialchars($language::get($item['label']), ENT_QUOTES);

        $html  = '<li class="' . $liClass . '">';
        $html .= '<a href="' . $href . '" class="menu-link' . ($hasKids ? ' menu-toggle' : '') . '">';
        $html .= $icon . '<div>' . $label . '</div>';
        $html .= '</a>' . $childHtml . '</li>';

        return [$html, $active];
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
    <title><?= htmlspecialchars($rSettings['server_name'] ?: 'XC_VM'); ?><?= isset($_TITLE) ? ' | ' . htmlspecialchars($_TITLE) : ''; ?></title>
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

    <!-- Page vendor styles (shared admin registry) + XC_VM overrides -->
    <?php require_once dirname(__DIR__, 2) . '/admin/vendors.php'; ?>
    <?php xc_newui_vendor_css(xc_newui_vendors_wanted()); ?>
    <link rel="stylesheet" href="assets/new/xcvm/custom.css">

    <!-- Helpers + template customizer must precede config.js -->
    <script src="assets/new/vendor/js/helpers.js"></script>
    <script src="assets/new/vendor/js/template-customizer.js"></script>
    <!-- Per-user customizer state (theme persists in localStorage via the
         customizer; the reseller api exposes no save_ui_prefs endpoint yet). -->
    <script>
        window.XC_VM = window.XC_VM || {};
        window.XC_VM_UIPrefs = <?= json_encode($xmUiPrefs, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <script src="assets/new/js/config.js"></script>
</head>

<?php if (isset($_GET['modal'])): /* iframe modal shell — no sidebar / navbar / topbar */ ?>

<body class="xm-modal-body">
    <div class="container-fluid p-4">
<?php else: ?>

<body>
    <div class="layout-wrapper layout-content-navbar">
        <div class="layout-container">

            <!-- Vertical menu -->
            <aside id="layout-menu" class="layout-menu menu-vertical menu">
                <div class="app-brand demo">
                    <a href="dashboard" class="app-brand-link">
                        <span class="app-brand-logo demo">
                            <img src="assets/old/images/logo-topbar.png" alt="<?= htmlspecialchars($rSettings['server_name'] ?: 'XC_VM'); ?>" height="24">
                        </span>
                        <span class="app-brand-text demo menu-text fw-bold ms-3"><?= htmlspecialchars($rSettings['server_name'] ?: 'XC_VM'); ?></span>
                    </a>
                    <a href="javascript:void(0);" class="layout-menu-toggle menu-link text-large ms-auto">
                        <i class="icon-base ti menu-toggle-icon d-none d-xl-block"></i>
                        <i class="icon-base ti tabler-x d-block d-xl-none"></i>
                    </a>
                </div>

                <div class="menu-inner-shadow"></div>

                <ul class="menu-inner py-1">
                    <?php foreach ($xmMenu as $xmItem): ?>
                        <?php [$xmNodeHtml] = _xc_reseller_menu_node($xmItem, 0, $xmPage, $language); ?>
                        <?= $xmNodeHtml; ?>
                    <?php endforeach; ?>
                </ul>
            </aside>
            <!-- / Vertical menu -->

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
                                <div class="d-none d-xl-flex align-items-center" id="header_stats">
                                    <a href="live_connections" class="d-inline-flex align-items-center text-heading text-decoration-none me-4" title="<?= htmlspecialchars($language::get('connections'), ENT_QUOTES); ?>">
                                        <i class="icon-base ti tabler-plug-connected icon-22px me-1"></i>
                                        <span class="fw-medium" id="header_connections">0</span>
                                    </a>
                                    <a href="live_connections" class="d-inline-flex align-items-center text-heading text-decoration-none me-4" title="<?= htmlspecialchars($language::get('users'), ENT_QUOTES); ?>">
                                        <i class="icon-base ti tabler-users icon-22px me-1"></i>
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
                                                <small class="text-body-secondary"><?= $language::get('shell_role_reseller'); ?></small>
                                            </div>
                                        </div>
                                    </li>
                                    <li><div class="dropdown-divider my-1 mx-n2"></div></li>
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
                                    <li><div class="dropdown-divider my-1 mx-n2"></div></li>
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
