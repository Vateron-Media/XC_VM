<?php

if (count(get_included_files()) != 1) {
	$rGenTrials = canGenerateTrials($rUserInfo['id']);
	echo '<!DOCTYPE html>' . "\r\n" . '<html lang="en">' . "\r\n" . '    <head>' . "\r\n" . '        <meta http-equiv="Content-Type" content="text/html; charset=utf-8">' . "\r\n" . '        <title>';
	echo ($rSettings['server_name'] ?: 'XC_VM');
	echo ' ';

	if (!isset($_TITLE)) {
	} else {
		echo ' | ' . $_TITLE;
	}

	echo '</title>' . "\r\n" . '        <meta name="viewport" content="width=device-width, initial-scale=1.0">' . "\r\n" . '        <meta http-equiv="X-UA-Compatible" content="IE=edge" />' . "\r\n" . '        <meta name="robots" content="noindex,nofollow">' . "\r\n" . '        <link rel="shortcut icon" href="assets/images/favicon.ico">' . "\r\n" . '        <link href="assets/libs/jquery-nice-select/nice-select.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/switchery/switchery.min.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/select2/select2.min.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/datatables/dataTables.bootstrap4.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/datatables/responsive.bootstrap4.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/datatables/buttons.bootstrap4.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/datatables/select.bootstrap4.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/jquery-toast/jquery.toast.min.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/bootstrap-touchspin/jquery.bootstrap-touchspin.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/treeview/style.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/clockpicker/bootstrap-clockpicker.min.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/daterangepicker/daterangepicker.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/nestable2/jquery.nestable.min.css" rel="stylesheet" />' . "\r\n" . '        <link href="assets/libs/magnific-popup/magnific-popup.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/jbox/jBox.all.min.css" rel="stylesheet" type="text/css" />' . "\r\n\t\t" . '<link href="assets/css/icons.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/jquery-vectormap/jquery-jvectormap-1.2.2.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/libs/bootstrap-colorpicker/bootstrap-colorpicker.min.css" rel="stylesheet" type="text/css" />' . "\r\n\t\t";

	if (!$rThemes[$rUserInfo['theme']]['dark']) {
		echo '        <link href="assets/css/bootstrap.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/css/app.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/css/listings.css" rel="stylesheet" type="text/css" />' . "\r\n\t\t" . '<link href="assets/css/custom.css" rel="stylesheet" type="text/css" />' . "\r\n\t\t";
	} else {
		echo "\t\t" . '<link href="assets/css/bootstrap.dark.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/css/app.dark.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        <link href="assets/css/listings.dark.css" rel="stylesheet" type="text/css" />' . "\r\n\t\t" . '<link href="assets/css/custom.dark.css" rel="stylesheet" type="text/css" />' . "\r\n\t\t";
	}

	echo '        <link href="assets/css/extra.css" rel="stylesheet" type="text/css" />' . "\r\n" . '        ';

	if ($rThemes[$rUserInfo['theme']]['image']) {
		echo '        <style>' . "\r\n" . '        body {' . "\r\n" . "            background: url('./assets/images/theme/";
		echo basename($rThemes[$rUserInfo['theme']]['image']);
		echo "');" . "\r\n" . '        }' . "\r\n" . '        </style>' . "\r\n\t\t";
	} else {
		echo '        <style>' . "\r\n" . '        html, body {' . "\r\n" . '          overflow-x: hidden;' . "\r\n" . '        }' . "\r\n" . '        ';

		if (!$rMobile) {
		} else {
			echo '        .dataTables_wrapper {' . "\r\n" . '            overflow-x: auto !important;' . "\r\n" . '        }' . "\r\n" . '        ';
		}

		echo '        </style>' . "\r\n" . '        ';
	}

	if (isset($customScript)) {
		echo $customScript;
	}

	echo '    </head>' . "\r\n" . '    <body>' . "\r\n" . '        <header id="topnav">' . "\r\n" . '            <div class="navbar-overlay bg-animate';

	if (0 >= strlen($rUserInfo['hue'])) {
	} else {
		echo '-' . $rUserInfo['hue'];
	}

	echo '"></div>' . "\r\n" . '            <div class="navbar-custom" id="topnav-custom">' . "\r\n" . '                <div class="container-fluid">' . "\r\n" . '                    <ul class="list-unstyled topnav-menu float-right mb-0">' . "\r\n" . '                        <li class="dropdown notification-list">' . "\r\n" . '                            <a class="navbar-toggle nav-link">' . "\r\n" . '                                <div class="lines text-white">' . "\r\n" . '                                    <span></span>' . "\r\n" . '                                    <span></span>' . "\r\n" . '                                    <span></span>' . "\r\n" . '                                </div>' . "\r\n" . '                            </a>' . "\r\n" . '                        </li>' . "\r\n" . '                        <li class="dropdown notification-list">' . "\r\n" . '                            <a class="nav-link dropdown-toggle nav-user mr-0 waves-effect text-white" data-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">' . "\r\n" . '                                <i class="mdi mdi-coin noti-icon"></i>&nbsp;' . "\r\n" . '                                <span id="owner_credits">';
	echo number_format($rUserInfo['credits'], 0);
	echo '</span><i class="mdi mdi-chevron-down"></i>' . "\r\n" . '                            </a>' . "\r\n" . '                            <div class="dropdown-menu dropdown-menu-right profile-dropdown ">' . "\r\n" . '                                <div class="dropdown-item noti-title">' . "\r\n" . '                                    <h5 class="m-0">';
	echo $rUserInfo['username'];
	echo '</h5>' . "\r\n" . '                                </div>' . "\r\n" . '                                <a href="edit_profile" class="dropdown-item notify-item">' . "\r\n" . '                                    <span>User Profile</span>' . "\r\n" . '                                </a>' . "\r\n" . '                                <a href="user_logs?user_id=';
	echo intval($rUserInfo['id']);
	echo '" class="dropdown-item notify-item">' . "\r\n" . '                                    <span>Credit Spend</span>' . "\r\n" . '                                </a>' . "\r\n" . '                                <a href="logout" class="dropdown-item notify-item">' . "\r\n" . '                                    <span>Logout</span>' . "\r\n" . '                                </a>' . "\r\n" . '                            </div>' . "\r\n" . '                        </li>' . "\r\n" . '                        ';
	$rTickets = array();
	$rIDs = array();
	$db->query('SELECT `id` FROM `users` WHERE `id` = ? OR `owner_id` = ?;', $rUserInfo['id'], $rUserInfo['id']);

	foreach ($db->get_rows() as $rRow) {
		$rIDs[] = $rRow['id'];
	}

	if (0 >= count($rIDs)) {
	} else {
		$db->query('SELECT `tickets`.`id`, `tickets`.`title`, MAX(`tickets_replies`.`date`) AS `date`, `users`.`username` FROM `tickets` LEFT JOIN `tickets_replies` ON `tickets_replies`.`ticket_id` = `tickets`.`id` LEFT JOIN `users` ON `users`.`id` = `tickets`.`member_id` WHERE `tickets`.`status` <> 0 AND `admin_read` = 0 AND `user_read` = 1 AND `member_id` <> ? AND `member_id` IN (SELECT `id` FROM `users` WHERE `owner_id` = ?) GROUP BY `tickets_replies`.`ticket_id` ORDER BY `tickets_replies`.`date` DESC LIMIT 50;', $rUserInfo['id'], $rUserInfo['id']);
		$unreadTicketCount = $db->num_rows();

		foreach ($db->get_rows() as $rRow) {
			$rTickets[] = $rRow;
		}
		$db->query('SELECT `tickets`.`id`, `tickets`.`title`, MAX(`tickets_replies`.`date`) AS `date`, `users`.`username` FROM `tickets` LEFT JOIN `tickets_replies` ON `tickets_replies`.`ticket_id` = `tickets`.`id` LEFT JOIN `users` ON `users`.`id` = `tickets`.`member_id` WHERE `tickets`.`status` <> 0 AND `user_read` = 0 AND `admin_read` = 1 AND `member_id` = ? GROUP BY `tickets_replies`.`ticket_id` ORDER BY `tickets_replies`.`date` DESC LIMIT 50;', $rUserInfo['id']);
		$unreadTicketCount += $db->num_rows();

		foreach ($db->get_rows() as $rRow) {
			$rTickets[] = $rRow;
		}
	}

	echo '                        <li class="dropdown notification-list">' . "\r\n" . '                            ';

	if (0 < $unreadTicketCount) {
		echo '                            <a class="nav-link dropdown-toggle waves-effect text-white" data-toggle="dropdown" href="#" role="button" aria-haspopup="false" aria-expanded="false">' . "\r\n" . '                            ';
	} else {
		echo '                            <a class="nav-link waves-effect text-white" href="tickets" role="button">' . "\r\n" . '                            ';
	}

	echo '                                <i class="fe-mail noti-icon"></i>' . "\r\n" . '                                ';

	if (0 >= $unreadTicketCount) {
	} else {
		echo '                                <span class="badge badge-info rounded-circle noti-icon-badge" style="min-width:20px;">';
		echo ($unreadTicketCount < 100 ? $unreadTicketCount : '99+');
		echo '</span>' . "\r\n" . '                                ';
	}

	echo '                            </a>' . "\r\n" . '                            <div class="dropdown-menu dropdown-menu-right dropdown-lg">' . "\r\n" . '                                <div class="dropdown-item noti-title">' . "\r\n" . '                                    <h5 class="m-0">' . "\r\n" . '                                        Tickets' . "\r\n" . '                                    </h5>' . "\r\n" . '                                </div>' . "\r\n" . '                                <div class="slimscroll noti-scroll">' . "\r\n" . '                                    ';

	foreach ($rTickets as $rTicket) {
		$timeAgo = time() - intval($rTicket['date']);

		if ($timeAgo < 60) {
			$timeAgo = $timeAgo . ' seconds ago';
		} else {
			if ($timeAgo < 3600) {
				$timeAgo = ceil($timeAgo / 60) . ' minutes ago';
			} else {
				if ($timeAgo < 86400) {
					$timeAgo = ceil($timeAgo / 3600) . ' hours ago';
				} else {
					$timeAgo = ceil($timeAgo / 86400) . ' days ago';
				}
			}
		}

		echo '                                    <a href="ticket_view?id=';
		echo $rTicket['id'];
		echo '" class="dropdown-item notify-item">' . "\r\n" . '                                        <div class="notify-icon bg-info">' . "\r\n" . '                                            <i class="mdi mdi-comment"></i>' . "\r\n" . '                                        </div>' . "\r\n" . '                                        <p class="notify-details">';
		echo htmlspecialchars($rTicket['title']);
		echo '                                            <small class="text-muted">';
		echo $timeAgo;
		echo '</small>' . "\r\n" . '                                        </p>' . "\r\n" . '                                    </a>' . "\r\n" . '                                    ';
	}
	echo '                                </div>' . "\r\n" . '                                <a href="tickets" class="dropdown-item text-center text-primary notify-item notify-all">' . "\r\n" . '                                    View Tickets' . "\r\n" . '                                    <i class="fi-arrow-right"></i>' . "\r\n" . '                                </a>' . "\r\n" . '                            </div>' . "\r\n" . '                        </li>' . "\r\n" . '                    </ul>' . "\r\n" . '                    <div class="logo-box">' . "\r\n" . '                        <a href="dashboard" class="logo text-center">' . "\r\n" . '                            <span class="logo-lg';

	if (0 >= strlen($rUserInfo['hue'])) {
	} else {
		echo ' whiteout';
	}

	echo '">' . "\r\n" . '                                <img src="assets/images/logo-topbar.png" alt="" height="50">' . "\r\n" . '                            </span>' . "\r\n" . '                            <span class="logo-sm';

	if (0 >= strlen($rUserInfo['hue'])) {
	} else {
		echo ' whiteout';
	}

	echo '">' . "\r\n" . '                                <img src="assets/images/logo-topbar.png" alt="" height="28">' . "\r\n" . '                            </span>' . "\r\n" . '                        </a>' . "\r\n" . '                    </div>' . "\r\n" . '                    ';

	if ($rMobile) {
	} else {
		echo '                    <ul class="list-unstyled topnav-menu topnav-menu-left m-0" style="opacity: 80%" id="header_stats">' . "\r\n" . '                        <li class="dropdown notification-list">' . "\r\n" . '                            <a class="nav-link dropdown-toggle nav-user mr-0 waves-effect pd-left pd-right" data-toggle="dropdown" href="./live_connections" role="button" aria-haspopup="false" aria-expanded="false">' . "\r\n" . '                                <span class="pro-user-name text-white ml-1">' . "\r\n" . '                                    <i class="fe-zap text-white"></i> &nbsp; <button type="button" class="btn btn-dark bg-animate';

		if (!(0 < strlen($rUserInfo['hue']) && in_array($rUserInfo['hue'], array_keys($rHues)))) {
		} else {
			echo '-' . $rUserInfo['hue'];
		}

		echo ' btn-xs waves-effect waves-light no-border"><span id="header_connections">0</span></button>' . "\r\n" . '                                </span>' . "\r\n" . '                            </a>' . "\r\n" . '                        </li>' . "\r\n" . '                        <li class="dropdown notification-list">' . "\r\n" . '                            <a class="nav-link dropdown-toggle nav-user mr-0 waves-effect pd-left pd-right" data-toggle="dropdown" href="./live_connections" role="button" aria-haspopup="false" aria-expanded="false">' . "\r\n" . '                                <span class="pro-user-name text-white ml-1">' . "\r\n" . '                                    <i class="fe-users text-white"></i> &nbsp; <button type="button" class="btn btn-dark bg-animate';

		if (!(0 < strlen($rUserInfo['hue']) && in_array($rUserInfo['hue'], array_keys($rHues)))) {
		} else {
			echo '-' . $rUserInfo['hue'];
		}

		echo ' btn-xs waves-effect waves-light no-border"><span id="header_users">0</span></button>' . "\r\n" . '                                </span>' . "\r\n" . '                            </a>' . "\r\n" . '                        </li>' . "\r\n" . '                    </ul>' . "\r\n" . '                    ';
	}

	echo '                    <div class="clearfix"></div>' . "\r\n" . '                </div>' . "\r\n" . '            </div>' . "\r\n" . '            <div class="topbar-menu">' . "\r\n" . '                <div class="container-fluid">' . "\r\n" . '                    <div id="navigation">' . "\r\n" . '                        <ul class="navigation-menu">' . "\r\n" . '                            <li class="has-submenu">' . "\r\n" . '                                <a href="dashboard"><i class="fe-activity"></i>';
	echo $_['dashboard'];
	echo '</a>' . "\r\n" . '                            </li>' . "\r\n" . '                            ';

	if (!$rPermissions['create_sub_resellers']) {
	} else {
		echo '                            <li class="has-submenu">' . "\r\n" . '                                <a href="#"> <i class="fas fa-users"></i>';
		echo $_['users'];
		echo ' <div class="arrow-down"></div></a>' . "\r\n" . '                                <ul class="submenu">' . "\r\n" . '                                    <li><a href="user">';
		echo $_['add_user'];
		echo '</a></li>' . "\r\n" . '                                    <li><a href="users">';
		echo $_['manage_users'];
		echo '</a></li>' . "\r\n" . '                                </ul>' . "\r\n" . '                            </li>' . "\r\n" . '                            ';
	}

	if (!($rPermissions['create_line'] || $rPermissions['create_mag'] || $rPermissions['create_enigma'])) {
	} else {
		echo "\t\t\t\t\t\t\t" . '<li class="has-submenu">' . "\r\n" . '                                <a href="#"> <i class="fas fa-desktop"></i>';
		echo $_['lines'];
		echo ' <div class="arrow-down"></div></a>' . "\r\n" . '                                <ul class="submenu">' . "\r\n" . '                                    ';

		if (!$rPermissions['create_line']) {
		} else {
			echo '                                    <li class="has-submenu">' . "\r\n" . '                                        <a href="#">';
			echo $_['user_lines'];
			echo ' <div class="arrow-down"></div></a>' . "\r\n" . '                                        <ul class="submenu">' . "\r\n" . '                                            <li><a href="line">';
			echo $_['add_line'];
			echo '</a></li>' . "\r\n" . '                                            ';

			if (!$rGenTrials) {
			} else {
				echo '                                            <li><a href="line?trial=1">Generate Trial Line</a></li>' . "\r\n" . '                                            ';
			}

			echo '                                            <li><a href="lines">';
			echo $_['manage_lines'];
			echo '</a></li>' . "\r\n" . '                                        </ul>' . "\r\n" . '                                    </li>' . "\r\n" . '                                    ';
		}

		if (!$rPermissions['create_mag']) {
		} else {
			echo '                                    <li class="has-submenu">' . "\r\n" . '                                        <a href="#">';
			echo $_['mag_devices'];
			echo ' <div class="arrow-down"></div></a>' . "\r\n" . '                                        <ul class="submenu">' . "\r\n" . '                                            <li><a href="mag">';
			echo $_['add_mag'];
			echo '</a></li>' . "\r\n" . '                                            ';

			if (!$rGenTrials) {
			} else {
				echo '                                            <li><a href="mag?trial=1">Generate Trial Device</a></li>' . "\r\n" . '                                            ';
			}

			echo '                                            <li><a href="mags">';
			echo $_['manage_mag_devices'];
			echo '</a></li>' . "\r\n" . '                                        </ul>' . "\r\n" . '                                    </li>' . "\r\n" . '                                    ';
		}

		if (!$rPermissions['create_enigma']) {
		} else {
			echo '                                    <li class="has-submenu">' . "\r\n" . '                                        <a href="#">';
			echo $_['enigma_devices'];
			echo ' <div class="arrow-down"></div></a>' . "\r\n" . '                                        <ul class="submenu">' . "\r\n" . '                                            <li><a href="enigma">';
			echo $_['add_enigma'];
			echo '</a></li>' . "\r\n" . '                                            ';

			if (!$rGenTrials) {
			} else {
				echo '                                            <li><a href="enigma?trial=1">Generate Trial Device</a></li>' . "\r\n" . '                                            ';
			}

			echo '                                            <li><a href="enigmas">';
			echo $_['manage_enigma_devices'];
			echo '</a></li>' . "\r\n" . '                                        </ul>' . "\r\n" . '                                    </li>' . "\r\n" . '                                    ';
		}

		echo '                                </ul>' . "\r\n" . '                            </li>' . "\r\n" . '                            ';
	}

	if (!$rPermissions['can_view_vod']) {
	} else {
		echo '                            <li class="has-submenu">' . "\r\n" . '                                <a href="#"> <i class="fas fa-play"></i>';
		echo $_['content'];
		echo ' <div class="arrow-down"></div></a>' . "\r\n" . '                                <ul class="submenu">' . "\r\n" . '                                    <li><a href="streams">';
		echo $_['streams'];
		echo '</a></li>' . "\r\n" . '                                    <li><a href="created_channels">';
		echo $_['created_channels'];
		echo '</a></li>' . "\r\n" . '                                    <li><a href="movies">';
		echo $_['movies'];
		echo '</a></li>' . "\r\n" . '                                    <li><a href="episodes">';
		echo $_['episodes'];
		echo '</a></li>' . "\r\n" . '                                    <li><a href="radios">';
		echo $_['stations'];
		echo '</a></li>' . "\r\n\t\t\t\t\t\t\t\t\t";

		if ($rMobile) {
		} else {
			echo "\t\t\t\t\t\t\t\t\t" . '<li><a href="epg_view">TV Guide</a></li>' . "\r\n\t\t\t\t\t\t\t\t\t";
		}

		echo '                                </ul>' . "\r\n" . '                            </li>' . "\r\n" . '                            ';
	}

	echo '                            <li class="has-submenu">' . "\r\n" . '                                <a href="#"> <i class="fas fa-wrench"></i>';
	echo $_['logs'];
	echo ' <div class="arrow-down"></div></a>' . "\r\n" . '                                <ul class="submenu">' . "\r\n" . '                                    ';

	if (!$rPermissions['reseller_client_connection_logs']) {
	} else {
		echo '                                    <li><a href="live_connections">';
		echo $_['live_connections'];
		echo '</a></li>' . "\r\n" . '                                    <li><a href="line_activity">';
		echo $_['activity_logs'];
		echo '</a></li>' . "\r\n" . '                                    ';
	}

	echo '                                    <li><a href="user_logs">User Logs</a></li>' . "\r\n" . '                                </ul>' . "\r\n" . '                            </li>' . "\r\n" . '                        </ul>' . "\r\n" . '                        <div class="clearfix"></div>' . "\r\n" . '                    </div>' . "\r\n" . '                </div>' . "\r\n" . '            </div>' . "\r\n" . '        </header>' . "\r\n" . '        ';

	if (!$rSettings['js_navigate']) {
	} else {
		echo '        <div id="status">' . "\r\n" . '            <div class="spinner"></div>' . "\r\n" . '        </div>' . "\r\n" . '        ';
	}
} else {
	exit();
}
