<?php 
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\CoreNavbarProvider;
use XcVm\Core\Module\NavbarItem;
use XcVm\Core\Module\NavbarRegistry;

if (count(get_included_files()) != 1 || TRUE):
    $rModal = isset(RequestManager::getAll()['modal']);
    $rUpdate = (json_decode((string) SettingsManager::get('update_data'), true) ?: array());

?>

    <!DOCTYPE html>
    <html lang="en">

    <head>
        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">
        <title><?= $rSettings['server_name'] ?: 'XC_VM'; ?> <?= isset($_TITLE) ? ' | ' . $_TITLE : ''; ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta http-equiv="X-UA-Compatible" content="IE=edge" />
        <meta name="robots" content="noindex,nofollow">
        <link rel="shortcut icon" href="assets/images/favicon.ico">
        <link href="assets/libs/jquery-nice-select/nice-select.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/switchery/switchery.min.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/select2/select2.min.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/datatables/dataTables.bootstrap4.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/datatables/responsive.bootstrap4.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/datatables/buttons.bootstrap4.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/datatables/select.bootstrap4.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/jquery-toast/jquery.toast.min.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/bootstrap-touchspin/jquery.bootstrap-touchspin.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/treeview/style.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/clockpicker/bootstrap-clockpicker.min.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/daterangepicker/daterangepicker.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/nestable2/jquery.nestable.min.css" rel="stylesheet" />
        <link href="assets/libs/magnific-popup/magnific-popup.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/quill/quill.min.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/jbox/jBox.all.min.css" rel="stylesheet" type="text/css" />
        <link href="assets/css/icons.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/jquery-vectormap/jquery-jvectormap-1.2.2.css" rel="stylesheet" type="text/css" />
        <link href="assets/libs/bootstrap-colorpicker/bootstrap-colorpicker.min.css" rel="stylesheet" type="text/css" />
        <?php if (!empty($_SETUP) || !($rThemes[$rUserInfo['theme'] ?? ''] ?? ['dark' => false])['dark']): ?>
            <link href="assets/css/bootstrap.css" rel="stylesheet" type="text/css" />
            <link href="assets/css/app.css" rel="stylesheet" type="text/css" />
            <link href="assets/css/listings.css" rel="stylesheet" type="text/css" />
            <link href="assets/css/custom.css" rel="stylesheet" type="text/css" />
        <?php else: ?>
            <link href="assets/css/bootstrap.dark.css" rel="stylesheet" type="text/css" />
            <link href="assets/css/app.dark.css" rel="stylesheet" type="text/css" />
            <link href="assets/css/listings.dark.css" rel="stylesheet" type="text/css" />
            <link href="assets/css/custom.dark.css" rel="stylesheet" type="text/css" />
        <?php endif; ?>
        <link href="assets/css/extra.css" rel="stylesheet" type="text/css" />
        <?php if (!isset($rModal) || !$rModal): ?>
            <!-- No modal specific CSS needed -->
        <?php else: ?>
            <link href="assets/css/modal.css" rel="stylesheet" type="text/css" />
        <?php endif; ?>
    </head>


    <body>
        <?php if (!isset($rModal) || !$rModal): ?>
            <!-- Header and other content -->
            <header id="topnav">
                <div
                    class="navbar-overlay bg-animate<?= (!empty($rUserInfo['hue']) && isset($rHues[$rUserInfo['hue']])) ? '-' . $rUserInfo['hue'] : '' ?>">
                </div>
                <div class="navbar-custom">
                    <div class="container-fluid">
                        <div class="logo-box">
                            <a href="index" class="logo text-center">
                                <span class="logo-lg<?= (isset($rUserInfo['hue']) && strlen($rUserInfo['hue']) > 0) ? ' whiteout' : ''; ?>">
                                    <img src="assets/images/logo-topbar.png" alt="" height="60">
                                </span>
                                <span class="logo-sm<?= (isset($rUserInfo['hue']) && strlen($rUserInfo['hue']) > 0) ? ' whiteout' : ''; ?>">
                                    <img src="assets/images/logo-topbar.png" alt="" height="50">
                                </span>
                            </a>
                        </div>

                        <?php if (!isset($_SETUP)): ?>
                            <?php
                            /**
                             * Shared navbar helpers, defined once before both the profile
                             * dropdown (below) and the main navigation (further down) consume
                             * them. All nav items — core and module — are registered via
                             * NavbarRegistry::add(NavbarItem) in CoreNavbarProvider and modules'
                             * registerNavbar().
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
                            <?php if (!$rMobile && $rSettings['header_stats']): ?>
                                <ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%" id="header_stats">
                                    <li class="dropdown notification-list">
                                        <a class="nav-link dropdown-toggle nav-user mr-0 waves-effect pd-left pd-right" data-toggle="dropdown" href="./live_connections" role="button" aria-haspopup="false" aria-expanded="false">
                                            <span class="pro-user-name text-white ml-1">
                                                <i class="fe-zap text-white"></i> &nbsp; <button type="button" class="btn btn-dark bg-animate<?= $rUserInfo['hue'] ? '-' . $rUserInfo['hue'] : ''; ?> btn-xs waves-effect waves-light no-border"><span id="header_connections">0</span></button>
                                            </span>
                                        </a>
                                    </li>
                                    <li class="dropdown notification-list">
                                        <a class="nav-link dropdown-toggle nav-user mr-0 waves-effect pd-left pd-right" data-toggle="dropdown" href="./live_connections" role="button" aria-haspopup="false" aria-expanded="false">
                                            <span class="pro-user-name text-white ml-1">
                                                <i class="fe-users text-white"></i> &nbsp; <button type="button" class="btn btn-dark bg-animate<?= $rUserInfo['hue'] ? '-' . $rUserInfo['hue'] : ''; ?> btn-xs waves-effect waves-light no-border"><span id="header_users">0</span></button>
                                            </span>
                                        </a>
                                    </li>
                                    <li class="dropdown notification-list">
                                        <a class="nav-link dropdown-toggle nav-user mr-0 waves-effect pd-left pd-right" data-toggle="dropdown" href="./streams" role="button" aria-haspopup="false" aria-expanded="false">
                                            <span class="pro-user-name text-white ml-1">
                                                <i class="fe-play text-white"></i> &nbsp; <button type="button" class="btn btn-dark bg-animate<?= $rUserInfo['hue'] ? '-' . $rUserInfo['hue'] : ''; ?> btn-xs waves-effect waves-light no-border"><span id="header_streams_up">0</span> <i class="mdi mdi-arrow-up-thick"></i> &nbsp; <span id="header_streams_down">0</span> <i class="mdi mdi-arrow-down-thick"></i></button>
                                            </span>
                                        </a>
                                    </li>
                                    <li class="dropdown notification-list">
                                        <a class="nav-link dropdown-toggle nav-user mr-0 waves-effect pd-left pd-right" data-toggle="dropdown" href="./dashboard" role="button" aria-haspopup="false" aria-expanded="false">
                                            <span class="pro-user-name text-white ml-1">
                                                <i class="fe-trending-up text-white"></i> &nbsp; <button type="button" class="btn btn-dark bg-animate<?= $rUserInfo['hue'] ? '-' . $rUserInfo['hue'] : ''; ?> btn-xs waves-effect waves-light no-border"><span id="header_network_up">0</span> <small>Mbps</small> <i class="mdi mdi-arrow-up-thick"></i> &nbsp; <span id="header_network_down">0</span> <small>Mbps</small> <i class="mdi mdi-arrow-down-thick"></i></button>
                                            </span>
                                        </a>
                                    </li>
                                </ul>

                            <?php endif; ?>
                            <!-- Streams, Channels, Movies, Episodes & Radio Stations -->
                            <!-- Include similar structure for multiselect_streams, multiselect_series, etc. -->
                            <ul class="list-unstyled topnav-menu float-right mb-0 topnav-custom">
                                <li class="dropdown notification-list">
                                    <a class="navbar-toggle nav-link">
                                        <div class="lines text-white">
                                            <span></span>
                                            <span></span>
                                            <span></span>
                                        </div>
                                    </a>
                                </li>
                                <?php if (SettingsManager::get('enable_search')): ?>
                                    <li class="dropdown notification-list" id="search-mobile">
                                        <a href="javascript:void(0);"
                                            class="search-toggle pad-15 nav-link right-bar-toggle waves-effect text-white">
                                            <i class="mdi mdi-magnify noti-icon"></i>
                                        </a>
                                    </li>
                                    <li class="d-none d-sm-block" id="topnav-search">
                                        <div class="app-search"
                                            data-theme="bg-animate<?= (0 < strlen($rUserInfo['hue']) && in_array($rUserInfo['hue'], array_keys($rHues))) ? '-' . $rUserInfo['hue'] : ''; ?>">
                                            <div class="app-search-box">
                                                <select placeholder="<?= $language::get('search_placeholder') ?>"
                                                    class="quick_search form-control bg-animate<?= (0 < strlen($rUserInfo['hue']) && in_array($rUserInfo['hue'], array_keys($rHues))) ? '-' . $rUserInfo['hue'] : ''; ?>"
                                                    data-toggle="select2"></select>
                                            </div>
                                        </div>
                                    </li>
                                <?php endif; ?>
                                <li class="dropdown notification-list">
                                    <a class="nav-link dropdown-toggle nav-user mr-0 waves-effect" data-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                                        <span class="pro-user-name text-white ml-1">
                                            <?= htmlspecialchars($rUserInfo['username']) ?> <i class="mdi mdi-chevron-down"></i>
                                        </span>
                                        <span class="pro-user-name-mob nav-link text-white waves-effect">
                                            <i class="fe-user noti-icon"></i>
                                        </span>
                                    </a>
                                    <div class="dropdown-menu dropdown-menu-right profile-dropdown">
                                        <?php
                                        /**
                                         * Profile dropdown is registry-driven. Core items live in
                                         * CoreNavbarProvider::_profile() under the 'profile' parent;
                                         * modules add their own links (plex/watch settings, etc.) via
                                         * NavbarRegistry::add((new NavbarItem('profile.x'))->parent('profile')...).
                                         * Permission/setting gating reuses the same _xc_nav_visible() as
                                         * the main navigation.
                                         */
                                        $_profileItems = [];
                                        foreach (NavbarRegistry::getChildren('profile') as $_pi) {
                                            if (_xc_nav_visible($_pi, $rMobile, $rSettings)) $_profileItems[] = $_pi;
                                        }
                                        // Drop orphan dividers left behind by permission-hidden items.
                                        $_profileClean = NavbarRegistry::collapseDividers($_profileItems);
                                        foreach ($_profileClean as $_pi):
                                            if ($_pi->divider): ?>
                                                <div class="dropdown-divider"></div>
                                            <?php else: ?>
                                                <a href="<?= htmlspecialchars($_pi->url, ENT_QUOTES); ?>" class="dropdown-item notify-item">
                                                    <span><?= _xc_nav_label($_pi, $language); ?></span>
                                                </a>
                                            <?php endif;
                                        endforeach; ?>
                                    </div>
                                </li>

                                <!-- User Profile, General Settings, etc. -->
                                <?php if ($rServerError && Authorization::check('adv', 'servers')): ?>
                                    <li class="notification-list">
                                        <a href="servers" class="nav-link right-bar-toggle waves-effect <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-warning'; ?>">
                                            <i class="mdi mdi-wifi-strength-off noti-icon"></i>
                                        </a>
                                    </li>
                                <?php elseif ($allServersHealthy && Authorization::check('adv', 'servers')): ?>
                                    <li class="notification-list">
                                        <a href="proxies" class="nav-link right-bar-toggle waves-effect <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-warning'; ?>">
                                            <i class="mdi mdi-wifi-strength-off noti-icon"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php if (!$rMobile && isset($rUpdate) && is_array($rUpdate) && isset($rUpdate['version']) && (version_compare($rUpdate['version'], XC_VM_VERSION) >= 0)): ?>
                                    <li class="notification-list">
                                        <a href="settings" class="nav-link right-bar-toggle waves-effect <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-warning'; ?>" title="Official Release v<?php echo $rUpdate['version']; ?> is available to download.">
                                            <i class="mdi mdi-update noti-icon"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php if ($rSettings['show_tickets']): ?>
                                    <?php
                                    $rTickets = array();
                                    $rIDs = array();
                                    $unreadTicketCount = 0;
                                    // Assuming $db is your database connection variable
                                    $db->query('SELECT `id` FROM `users` WHERE `owner_id` = ?;', $rUserInfo['id']);

                                    foreach ($db->get_rows() as $rRow) {
                                        $rIDs[] = $rRow['id'];
                                    }

                                    if (count($rIDs) > 0) {
                                        $db->query('SELECT `tickets`.`id`, `tickets`.`title`, MAX(`tickets_replies`.`date`) AS `date`, `users`.`username` FROM `tickets` LEFT JOIN `tickets_replies` ON `tickets_replies`.`ticket_id` = `tickets`.`id` LEFT JOIN `users` ON `users`.`id` = `tickets`.`member_id` WHERE `tickets`.`status` <> 0 AND `admin_read` = 0 AND `user_read` = 1 AND `member_id` <> ? AND `member_id` IN (?) GROUP BY `tickets_replies`.`ticket_id` ORDER BY `tickets_replies`.`date` DESC LIMIT 50;', $rUserInfo['id'], implode(',', $rIDs));
                                        $unreadTicketCount = $db->num_rows();

                                        foreach ($db->get_rows() as $rRow) {
                                            $rTickets[] = $rRow;
                                        }
                                    }
                                    ?>
                                    <li class="dropdown notification-list">
                                        <a class="nav-link dropdown-toggle waves-effect text-white" data-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">
                                            <i class="fe-mail noti-icon"></i>
                                            <?php if ($unreadTicketCount > 0): ?>
                                                <span class="badge badge-info rounded-circle noti-icon-badge"><?php echo $unreadTicketCount < 100 ? $unreadTicketCount : '99+'; ?></span>
                                            <?php endif; ?>
                                        </a>
                                        <div class="dropdown-menu dropdown-menu-right dropdown-lg">
                                            <div class="dropdown-item noti-title">
                                                <h5 class="m-0"><?= $language::get('tickets') ?></h5>
                                            </div>
                                            <div class="slimscroll noti-scroll">
                                                <?php foreach ($rTickets as $rTicket): ?>
                                                    <?php $timeAgo = time() - intval($rTicket['date']);
                                                    if ($timeAgo < 60) {
                                                        $timeAgo = $timeAgo . ' seconds ago';
                                                    } elseif ($timeAgo < 3600) {
                                                        $timeAgo = ceil($timeAgo / 60) . ' minutes ago';
                                                    } else if ($timeAgo < 86400) {
                                                        $timeAgo = ceil($timeAgo / 3600) . ' hours ago';
                                                    } else {
                                                        $timeAgo = ceil($timeAgo / 86400) . ' days ago';
                                                    }
                                                    ?>
                                                    <a href="ticket_view?id=<?php echo $rTicket['id']; ?>" class="dropdown-item notify-item">
                                                        <div class="notify-icon bg-info"><i class="mdi mdi-comment"></i></div>
                                                        <p class="notify-details"><?php echo htmlspecialchars($rTicket['title']); ?><small class="text-muted"><?php echo $timeAgo; ?></small></p>
                                                    </a>
                                                <?php endforeach; ?>
                                            </div>
                                            <a href="tickets" class="dropdown-item text-center text-primary notify-item notify-all">View Tickets<i class="fi-arrow-right"></i></a>
                                        </div>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        <?php endif; ?>

                        <div class="clearfix"></div>
                    </div>
                </div>

                <?php if (!isset($_SETUP)): ?>
                    <div class="topbar-menu">
                        <div class="container-fluid">
                            <div id="navigation">
                                <?php
                                /**
                                 * Main navigation rendering.
                                 * All nav items are registered in CoreNavbarProvider (and modules'
                                 * registerNavbar()) via NavbarRegistry::add(NavbarItem). The
                                 * _xc_nav_visible()/_xc_nav_label() helpers are defined once near
                                 * the top of the header (shared with the profile dropdown).
                                 *
                                 * To inject a button anywhere, add a NavbarItem with the desired
                                 * parent key and order, e.g.:
                                 *   NavbarRegistry::add((new NavbarItem('management.service_setup.watch'))
                                 *       ->parent('management.service_setup')
                                 *       ->url('watch')->label('folder_watch')
                                 *       ->permissions(['folder_watch'])->order(60));
                                 */
                                if (!function_exists('_xc_nav_children')) {
                                    function _xc_nav_children(NavbarItem $parent, bool $mobile, array $settings, string $language): void {
                                        $visible = [];
                                        foreach (NavbarRegistry::getChildren($parent->key) as $_c) {
                                            if (_xc_nav_visible($_c, $mobile, $settings)) $visible[] = $_c;
                                        }
                                        if (empty($visible)) return;

                                        $cls = 'submenu' . ($parent->submenuClass !== '' ? ' ' . $parent->submenuClass : '');
                                        echo '<ul class="' . $cls . '">';

                                        if ($parent->submenuClass === 'megamenu') {
                                            $total = count($visible);
                                            $split = $total > 8 ? (int)ceil($total / 2) : null;
                                            echo '<li><ul>';
                                            foreach ($visible as $_i => $_c) {
                                                if ($_c->divider) continue;
                                                if ($split !== null && $_i === $split) echo '</ul></li><li><ul>';
                                                echo '<li><a href="' . htmlspecialchars($_c->url, ENT_QUOTES) . '">'
                                                    . _xc_nav_label($_c, $language) . '</a></li>';
                                            }
                                            echo '</ul></li>';
                                        } else {
                                            foreach ($visible as $_c) {
                                                if ($_c->divider) {
                                                    echo '<div class="dropdown-divider"></div>';
                                                    continue;
                                                }
                                                $_hasKids = NavbarRegistry::hasChildren($_c->key) && !($_c->noMobileSubmenu && $mobile);
                                                echo '<li' . ($_hasKids ? ' class="has-submenu"' : '') . '>';
                                                echo '<a href="' . htmlspecialchars($_c->url, ENT_QUOTES) . '">'
                                                    . _xc_nav_label($_c, $language);
                                                if ($_hasKids) echo ' <div class="arrow-down"></div>';
                                                echo '</a>';
                                                if ($_hasKids) _xc_nav_children($_c, $mobile, $settings, $language);
                                                echo '</li>';
                                            }
                                        }
                                        echo '</ul>';
                                    }
                                }
                                ?>
                                <ul class="navigation-menu">
                                    <?php foreach (NavbarRegistry::getTopLevel() as $_navTop): ?>
                                        <?php
                                        if (!_xc_nav_visible($_navTop, $rMobile, $rSettings)) continue;
                                        $_topKids = NavbarRegistry::hasChildren($_navTop->key) && !($_navTop->noMobileSubmenu && $rMobile);
                                        ?>
                                        <li<?= $_topKids ? ' class="has-submenu"' : ''; ?>>
                                            <a href="<?= htmlspecialchars($_navTop->url, ENT_QUOTES); ?>">
                                                <?php if ($_navTop->icon): ?><i class="<?= htmlspecialchars($_navTop->icon, ENT_QUOTES); ?>"></i><?php endif; ?>
                                                <?= _xc_nav_label($_navTop, $language); ?>
                                                <?php if ($_topKids && !$rMobile): ?><div class="arrow-down"></div><?php endif; ?>
                                            </a>
                                            <?php if ($_topKids): _xc_nav_children($_navTop, $rMobile, $rSettings, $language);
                                            endif; ?>
                                            </li>
                                        <?php endforeach; ?>
                                </ul>
                                <div class="clearfix"></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </header>
        <?php endif; ?>

        <div id="status">
            <div class="spinner"></div>
        </div>

    <?php else: exit();
endif; ?>