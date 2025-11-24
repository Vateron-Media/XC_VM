<?php if (count(get_included_files()) != 1 || TRUE):
    $rModal = isset(CoreUtilities::$rRequest['modal']);
    $rUpdate = (json_decode(CoreUtilities::$rSettings['update_data'], true) ?: array());
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
        <?php if (isset($_SETUP) || !$rThemes[$rUserInfo['theme']]['dark']): ?>
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
                    class="navbar-overlay bg-animate<?= (0 < strlen($rUserInfo['hue']) && in_array($rUserInfo['hue'], array_keys($rHues))) ? '-' . $rUserInfo['hue'] : ''; ?>">
                </div>
                <div class="navbar-custom">
                    <div class="container-fluid">
                        <div class="logo-box">
                            <a href="index" class="logo text-center">
                                <span class="logo-lg<?= (0 >= strlen($rUserInfo['hue'])) ? '' : ' whiteout'; ?>">
                                    <img src="assets/images/logo-topbar.png" alt="" height="60">
                                </span>
                                <span class="logo-sm<?= (0 >= strlen($rUserInfo['hue'])) ? '' : ' whiteout'; ?>">
                                    <img src="assets/images/logo-topbar.png" alt="" height="50">
                                </span>
                            </a>
                        </div>

                        <?php if (!isset($_SETUP)): ?>
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
                                <?php if (CoreUtilities::$rSettings['enable_search']): ?>
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
                                                <select placeholder="Search..."
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
                                        <a href="edit_profile" class="dropdown-item notify-item">
                                            <span>User Profile</span>
                                        </a>
                                        <?php if (hasPermissions('adv', 'settings')): ?>
                                            <a href="settings" class="dropdown-item notify-item">
                                                <span>General Settings</span>
                                            </a>
                                        <?php endif; ?>
                                        <?php if (hasPermissions('adv', 'database')): ?>
                                            <a href="backups" class="dropdown-item notify-item">
                                                <span>Backup Settings</span>
                                            </a>
                                            <a href="cache" class="dropdown-item notify-item">
                                                <span>Cache & Redis</span>
                                            </a>
                                        <?php endif; ?>

                                        <?php if (hasPermissions('adv', 'folder_watch_settings')): ?>
                                            <div class="dropdown-divider"></div>
                                            <a href="settings_plex" class="dropdown-item notify-item">
                                                <span>Plex Settings</span>
                                            </a>
                                            <a href="settings_watch" class="dropdown-item notify-item">
                                                <span>Watch Settings</span>
                                            </a>
                                        <?php endif; ?>

                                        <div class="dropdown-divider"></div>
                                        <a href="logout" class="dropdown-item notify-item">
                                            <span>Logout</span>
                                        </a>
                                    </div>
                                </li>

                                <!-- User Profile, General Settings, etc. -->
                                <?php if ($rServerError && hasPermissions('adv', 'servers')): ?>
                                    <li class="notification-list">
                                        <a href="servers" class="nav-link right-bar-toggle waves-effect <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-warning'; ?>">
                                            <i class="mdi mdi-wifi-strength-off noti-icon"></i>
                                        </a>
                                    </li>
                                <?php elseif ($allServersHealthy && hasPermissions('adv', 'servers')): ?>
                                    <li class="notification-list">
                                        <a href="proxies" class="nav-link right-bar-toggle waves-effect <?php echo $rUserInfo['theme'] == 1 ? 'text-white' : 'text-warning'; ?>">
                                            <i class="mdi mdi-wifi-strength-off noti-icon"></i>
                                        </a>
                                    </li>
                                <?php endif; ?>

                                <?php if (!$rMobile && isset($rUpdate) && is_array($rUpdate) && $rUpdate['version'] && (0 < version_compare($rUpdate['version'], XC_VM_VERSION) || version_compare($rUpdate['version'], XC_VM_VERSION) == 0)): ?>
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
                                                <h5 class="m-0">Tickets</h5>
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
                                <ul class="navigation-menu">
                                    <li class="has-submenu">
                                        <a href="index"><i class="fe-activity"></i><?= $_['dashboard']; ?>
                                            <?php if (!$rMobile): ?>
                                                <div class="arrow-down"></div>
                                        </a>
                                        <ul class="submenu">
                                            <?php if (hasPermissions('adv', 'live_connections')): ?>
                                                <li><a href="live_connections"><?= $_['live_connections']; ?></a></li>
                                            <?php endif; ?>
                                        </ul>
                                    <?php else: ?>
                                        </a>
                                    <?php endif; ?>
                                    </li>
                                    <?php if (hasPermissions('adv', 'servers') || hasPermissions('adv', 'process_monitor')): ?>
                                        <li class="has-submenu">
                                            <a href="#"><i class="fas fa-server"></i><?= $_['servers']; ?> <div class="arrow-down">
                                                </div></a>
                                            <ul class="submenu">
                                                <?php if (hasPermissions('adv', 'servers')): ?>
                                                    <li><a href="server_install">Install Load Balancer</a></li>
                                                    <li><a href="servers"><?= $_['manage_servers']; ?></a></li>
                                                    <li><a href="proxies"><?= $_['manage_proxies']; ?></a></li>
                                                    <li><a href="server_order">Server Order</a></li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'process_monitor')): ?>
                                                    <li><a href="process_monitor"><?= $_['process_monitor']; ?></a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermissions('adv', 'add_user') || hasPermissions('adv', 'users') || hasPermissions('adv', 'add_mag') || hasPermissions('adv', 'manage_mag') || hasPermissions('adv', 'add_e2') || hasPermissions('adv', 'manage_e2')): ?>
                                        <li class="has-submenu">
                                            <a href="#"> <i class="fas fa-desktop"></i><?= $_['users']; ?> <div class="arrow-down">
                                                </div></a>
                                            <ul class="submenu">
                                                <?php if (hasPermissions('adv', 'add_user') || hasPermissions('adv', 'users')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['user_lines']; ?> <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'add_user')): ?>
                                                                <li><a href="line">Add User</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'users')): ?>
                                                                <li><a href="lines">Manage Users</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mass_edit_lines')): ?>
                                                                <li><a href="line_mass">Mass Edit Users</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'add_mag') || hasPermissions('adv', 'manage_mag')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['mag_devices']; ?> <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'add_mag')): ?>
                                                                <li><a href="mag"><?= $_['add_mag']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'manage_mag')): ?>
                                                                <li><a href="mags"><?= $_['manage_mag_devices']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mass_edit_mags')): ?>
                                                                <li><a href="mag_mass">Mass Edit Mags</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'add_e2') || hasPermissions('adv', 'manage_e2')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['enigma_devices']; ?> <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'add_e2')): ?>
                                                                <li><a href="enigma"><?= $_['add_enigma']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'manage_e2')): ?>
                                                                <li><a href="enigmas"><?= $_['manage_enigma_devices']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mass_edit_enigmas')): ?>
                                                                <li><a href="enigma_mass">Mass Edit Enigmas</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'add_reguser') || hasPermissions('adv', 'mng_regusers')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#">Reseller <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'add_reguser')): ?>
                                                                <li><a href="user">Add Registered User</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mng_regusers')): ?>
                                                                <li><a href="users">Manage Registered User</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mass_edit_users')): ?>
                                                                <li><a href="user_mass">Mass Edit Resellers</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermissions('adv', 'add_stream') || hasPermissions('adv', 'create_channel') || hasPermissions('adv', 'import_streams') || hasPermissions('adv', 'streams') || hasPermissions('adv', 'add_movie') || hasPermissions('adv', 'import_movies') || hasPermissions('adv', 'movies') || hasPermissions('adv', 'series') || hasPermissions('adv', 'episodes') || hasPermissions('adv', 'add_series') || hasPermissions('adv', 'radio') || hasPermissions('adv', 'add_radio')): ?>
                                        <li class="has-submenu">
                                            <a href="#"> <i class="fas fa-play"></i><?= $_['content']; ?> <div class="arrow-down"></div>
                                            </a>
                                            <ul class="submenu">
                                                <?php if (hasPermissions('adv', 'add_stream') || hasPermissions('adv', 'import_streams') || hasPermissions('adv', 'streams')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['streams']; ?> <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'add_stream')): ?><li><a
                                                                        href="stream"><?= $_['add_stream']; ?></a></li><?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'import_streams') && !$rMobile): ?>
                                                                <li><a href="stream?import=1">Import Multiple</a></li>
                                                                <li><a href="review?type=1">Import & Review</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'streams')): ?><li><a
                                                                        href="streams"><?= $_['manage_streams']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'streams')): ?><li><a
                                                                        href="stream_mass">Mass Edit Streams</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>

                                                <?php if (hasPermissions('adv', 'create_channel') || hasPermissions('adv', 'streams')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['created_channels']; ?> <div class="arrow-down"></div>
                                                        </a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'create_channel')): ?><li><a
                                                                        href="created_channel"><?= $_['create_channel']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'streams')): ?><li><a
                                                                        href="created_channels"><?= $_['manage_created_channels']; ?></a>
                                                                </li><?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'streams')): ?><li><a
                                                                        href="created_channel_mass">Mass Edit Created Channels</a>
                                                                </li><?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>

                                                <?php if (hasPermissions('adv', 'add_movie') || hasPermissions('adv', 'import_movies') || hasPermissions('adv', 'movies')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['movies']; ?> <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'add_movie')): ?><li><a
                                                                        href="movie"><?= $_['add_movie']; ?></a></li><?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'import_movies') && !$rMobile): ?>
                                                                <li><a href="movie?import=1">Import Multiple</a></li>
                                                                <li><a href="review?type=2">Import & Review</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'movies')): ?><li><a
                                                                        href="movies"><?= $_['manage_movies']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mass_sedits_vod')): ?>
                                                                <li><a href="movie_mass">Mass Edit Movies</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>

                                                <?php if (hasPermissions('adv', 'add_series') || hasPermissions('adv', 'series') || hasPermissions('adv', 'episodes')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['series']; ?> <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'add_series')): ?><li><a
                                                                        href="serie"><?= $_['add_series']; ?></a></li><?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'series')): ?><li><a
                                                                        href="series"><?= $_['manage_series']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'episodes')): ?><li><a
                                                                        href="episodes"><?= $_['manage_episodes']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mass_sedits')): ?>
                                                                <li><a href="series_mass">Mass Edit Series</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mass_sedits')): ?>
                                                                <li><a href="episodes_mass">Mass Edit Episodes</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>

                                                <?php if (hasPermissions('adv', 'add_radio') || hasPermissions('adv', 'radio')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['stations']; ?> <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'add_radio')): ?><li><a
                                                                        href="radio"><?= $_['add_station']; ?></a></li><?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'radio')): ?><li><a
                                                                        href="radios"><?= $_['manage_stations']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mass_edit_radio')): ?>
                                                                <li><a href="radio_mass">Mass Edit Stations</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>

                                                <?php if (hasPermissions('adv', 'movies')): ?><li><a href="archive">Recordings</a></li>
                                                <?php endif; ?>
                                                <?php if (!$rMobile && hasPermissions('adv', 'streams')): ?><li><a href="epg_view">TV
                                                            Guide</a></li><?php endif; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermissions('adv', 'add_bouquet') || hasPermissions('adv', 'bouquets') || hasPermissions('adv', 'edit_bouquet')): ?>
                                        <li class="has-submenu">
                                            <a href="#"> <i class="fas fa-spa"></i><?= $_['bouquets']; ?> <div class="arrow-down"></div>
                                            </a>
                                            <ul class="submenu">
                                                <?php if (hasPermissions('adv', 'add_bouquet')): ?>
                                                    <li><a href="bouquet"><?= $_['add_bouquet']; ?></a></li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'bouquets')): ?>
                                                    <li><a href="bouquets"><?= $_['manage_bouquets']; ?></a></li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'edit_bouquet') && !$rMobile): ?>
                                                    <li><a href="bouquet_order"><?= $_['order_bouquets']; ?></a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermissions('adv', 'streams') || hasPermissions('adv', 'episodes') || hasPermissions('adv', 'series') || hasPermissions('adv', 'categories') || hasPermissions('adv', 'epg') || hasPermissions('adv', 'mng_groups') || hasPermissions('adv', 'mng_packages') || hasPermissions('adv', 'tprofiles') || hasPermissions('adv', 'folder_watch') || hasPermissions('adv', 'add_code') || hasPermissions('adv', 'block_asns') || hasPermissions('adv', 'block_ips') || hasPermissions('adv', 'block_isps') || hasPermissions('adv', 'block_uas') || hasPermissions('adv', 'rtmp') || hasPermissions('adv', 'channel_order') || hasPermissions('adv', 'fingerprint') || hasPermissions('adv', 'mass_delete') || hasPermissions('adv', 'stream_tools') || hasPermissions('adv', 'mass_edit_enigmas') || hasPermissions('adv', 'mass_edit_lines') || hasPermissions('adv', 'mass_edit_mags') || hasPermissions('adv', 'mass_sedits_vod') || hasPermissions('adv', 'mass_sedits') || hasPermissions('adv', 'mass_edit_radio') || hasPermissions('adv', 'mass_edit_streams') || hasPermissions('adv', 'mass_edit_users') || hasPermissions('adv', 'connection_logs') || hasPermissions('adv', 'client_request_log') || hasPermissions('adv', 'login_logs') || hasPermissions('adv', 'panel_logs') || hasPermissions('adv', 'credits_log') || hasPermissions('adv', 'live_connections') || hasPermissions('adv', 'manage_events') || hasPermissions('adv', 'reg_userlog') || hasPermissions('adv', 'stream_errors') || hasPermissions('adv', 'folder_watch') || hasPermissions('adv', 'add_hmac') || hasPermissions('adv', 'quick_tools') || hasPermissions('adv', 'manage_tickets')): ?>
                                        <li class="has-submenu">
                                            <a href="#"> <i class="fas fa-wrench"></i><?= $_['management']; ?> <div class="arrow-down">
                                                </div></a>
                                            <ul class="submenu">
                                                <?php if (hasPermissions('adv', 'categories') || hasPermissions('adv', 'epg') || hasPermissions('adv', 'mng_groups') || hasPermissions('adv', 'mng_packages') || hasPermissions('adv', 'tprofiles') || hasPermissions('adv', 'folder_watch')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#">Service Setup <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'mng_packages')): ?>
                                                                <li><a href="packages"><?= $_['packages']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'categories')): ?>
                                                                <li><a href="stream_categories"><?= $_['categories']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mng_groups')): ?>
                                                                <li><a href="groups"><?= $_['groups']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'epg')): ?>
                                                                <li><a href="epgs"><?= $_['epgs']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'tprofiles')): ?>
                                                                <li><a href="profiles"><?= $_['transcode_profiles']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'folder_watch')): ?>
                                                                <li><a href="plex">Plex Sync</a></li>
                                                                <li><a href="watch"><?= $_['folder_watch']; ?></a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'add_code') || hasPermissions('adv', 'block_asns') || hasPermissions('adv', 'block_ips') || hasPermissions('adv', 'block_isps') || hasPermissions('adv', 'block_uas') || hasPermissions('adv', 'rtmp') || hasPermissions('adv', 'add_hmac')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#">Access Codes <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'add_code')): ?>
                                                                <li><a href="code">Add Access Codes</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'add_code')): ?>
                                                                <li><a href="codes">Menage Access Codes</a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'add_code') || hasPermissions('adv', 'block_asns') || hasPermissions('adv', 'block_ips') || hasPermissions('adv', 'block_isps') || hasPermissions('adv', 'block_uas') || hasPermissions('adv', 'rtmp') || hasPermissions('adv', 'add_hmac')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#">Security <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'block_asns')): ?>
                                                                <li><a href="asns">Blocked ASN's</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'block_ips')): ?>
                                                                <li><a href="ips"><?= $_['blocked_ips']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'block_isps')): ?>
                                                                <li><a href="isps"><?= $_['blocked_isps']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'block_uas')): ?>
                                                                <li><a href="useragents"><?= $_['blocked_uas']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'add_hmac')): ?>
                                                                <li><a href="hmacs">HMAC Keys</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'rtmp')): ?>
                                                                <li><a href="rtmp_ips"><?= $_['rtmp_ips']; ?></a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'channel_order') || hasPermissions('adv', 'rtmp') || hasPermissions('adv', 'fingerprint') || hasPermissions('adv', 'mass_delete') || hasPermissions('adv', 'stream_tools') || hasPermissions('adv', 'quick_tools')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['tools']; ?> <div class="arrow-down"></div></a>
                                                        <ul class="submenu">
                                                            <?php if (hasPermissions('adv', 'channel_order') && !$rMobile): ?>
                                                                <li><a href="channel_order"><?= $_['channel_order']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'fingerprint')): ?>
                                                                <li><a href="fingerprint"><?= $_['fingerprint']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'mass_delete')): ?>
                                                                <li><a href="mass_delete"><?= $_['mass_delete']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'quick_tools')): ?>
                                                                <li><a href="quick_tools"><?= $_['quick_tools']; ?></a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'rtmp')): ?>
                                                                <li><a href="rtmp_monitor">RTMP Monitor</a></li>
                                                            <?php endif; ?>
                                                            <?php if (hasPermissions('adv', 'stream_tools')): ?>
                                                                <li><a href="stream_tools"><?= $_['stream_tools']; ?></a></li>
                                                            <?php endif; ?>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'movies') || hasPermissions('adv', 'streams') || hasPermissions('adv', 'connection_logs') || hasPermissions('adv', 'client_request_log') || hasPermissions('adv', 'login_logs') || hasPermissions('adv', 'panel_logs') || hasPermissions('adv', 'credits_log') || hasPermissions('adv', 'live_connections') || hasPermissions('adv', 'manage_events') || hasPermissions('adv', 'reg_userlog') || hasPermissions('adv', 'streams') || hasPermissions('adv', 'episodes') || hasPermissions('adv', 'series') || hasPermissions('adv', 'stream_errors') || hasPermissions('adv', 'folder_watch')): ?>
                                                    <li class="has-submenu">
                                                        <a href="#"><?= $_['logs']; ?> <div class="arrow-down"></div></a>
                                                        <ul class="submenu megamenu">
                                                            <li>
                                                                <ul>
                                                                    <?php
                                                                    $logs = [
                                                                        ['url' => 'line_activity', 'title' => $_['activity_logs'], 'permissions' => ['connection_logs']],
                                                                        ['url' => 'client_logs', 'title' => $_['client_logs'], 'permissions' => ['client_request_log']],
                                                                        ['url' => 'credit_logs', 'title' => $_['credit_logs'], 'permissions' => ['credits_log']],
                                                                        ['url' => 'queue', 'title' => 'Encoding Queue', 'permissions' => ['streams', 'episodes', 'series']],
                                                                        ['url' => 'line_ips', 'title' => $_['ips_per_line'], 'permissions' => ['connection_logs']],
                                                                        ['url' => 'live_connections', 'title' => $_['live_connections'], 'permissions' => ['live_connections']],
                                                                        ['url' => 'login_logs', 'title' => 'Login Logs', 'permissions' => ['login_logs']],
                                                                        ['url' => 'mag_events', 'title' => $_['mag_event_logs'], 'permissions' => ['manage_events']],
                                                                        ['url' => 'ondemand', 'title' => 'On-Demand Scanner', 'permissions' => ['streams']],
                                                                        ['url' => 'panel_logs', 'title' => 'Panel Errors', 'permissions' => ['panel_logs']],
                                                                        ['url' => 'user_logs', 'title' => $_['reseller_logs'], 'permissions' => ['reg_userlog']],
                                                                        ['url' => 'restream_logs', 'title' => 'Restream Detection', 'permissions' => ['restream_logs']],
                                                                        ['url' => 'stream_errors', 'title' => $_['stream_errors'], 'permissions' => ['stream_errors']],
                                                                        ['url' => 'stream_rank', 'title' => 'Stream Rank', 'permissions' => ['streams']],
                                                                        ['url' => 'mysql_syslog', 'title' => 'System Logs', 'permissions' => ['panel_logs']],
                                                                        ['url' => 'theft_detection', 'title' => 'VOD Theft Detection', 'permissions' => ['movies']],
                                                                        ['url' => 'watch_output', 'title' => $_['watch_folder_logs'], 'permissions' => ['folder_watch']]
                                                                    ];
                                                                    $filteredLogs = array_filter($logs, function ($log) {
                                                                        return array_reduce($log['permissions'], function ($carry, $permission) {
                                                                            return $carry || hasPermissions('adv', $permission);
                                                                        }, false);
                                                                    });
                                                                    $splitIndex = count($filteredLogs) > 8 ? ceil(count($filteredLogs) / 2) : null;
                                                                    $i = 0;
                                                                    foreach ($filteredLogs as $log) {
                                                                        if ($splitIndex && $i == $splitIndex) {
                                                                            echo '</ul></li><li><ul>';
                                                                        }
                                                                        echo '<li><a href="' . $log['url'] . '">' . $log['title'] . '</a></li>';
                                                                        $i++;
                                                                    }
                                                                    ?>
                                                                </ul>
                                                            </li>
                                                        </ul>
                                                    </li>
                                                <?php endif; ?>
                                                <?php if (!$rSettings['show_tickets'] && hasPermissions('adv', 'manage_tickets')): ?>
                                                    <li><a href="tickets"><?= $_['tickets']; ?></a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
                                    <?php if (hasPermissions('adv', 'add_bouquet') || hasPermissions('adv', 'streams') || hasPermissions('adv', 'categories')): ?>
                                        <li class="has-submenu">
                                            <a href="#"> <i class="fas fa-users"></i>Supplirs <div class="arrow-down"></div>
                                            </a>
                                            <ul class="submenu">
                                                <?php if (hasPermissions('adv', 'streams')): ?>
                                                    <li><a href="provider">Add Providers</a></li>
                                                <?php endif; ?>
                                                <?php if (hasPermissions('adv', 'streams')): ?>
                                                    <li><a href="providers">Stream Providers</a></li>
                                                <?php endif; ?>
                                            </ul>
                                        </li>
                                    <?php endif; ?>
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
