<?php

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\Topbar;

if (count(get_included_files()) != 1) {
	if (!isset($rMobile)) {
		$rMobile = $GLOBALS['rMobile'] ?? false;
	}
	$rPage = AdminHelpers::getPageName();
	$rID = (RequestManager::has('id') ? intval(RequestManager::get('id')) : null);
	$rSID = (RequestManager::has('sid') ? intval(RequestManager::get('sid')) : null);
	$rDropdown = Topbar::config([
		'rID' => $rID,
		'rSID' => $rSID,
		'rMobile' => $rMobile,
		'rImport' => isset($rImport),
	]);


	if ($rPage == 'stream_view') {
		if ($rStream['type'] == 1) {
			$rDropdown['stream_view'] = array('Edit Stream' => array('stream?id=' . $rID, 'edit_stream'), 'Manage Streams' => array('streams', 'streams'));
		} elseif ($rStream['type'] == 2) {
			$rDropdown['stream_view'] = array('Edit Movie' => array('movie?id=' . $rID, 'edit_movie'), 'Manage Movies' => array('movies', 'movies'));
		} elseif ($rStream['type'] == 3) {
			$rDropdown['stream_view'] = array('Edit Channel' => array('created_channel?id=' . $rID, 'edit_cchannel'), 'Manage Channels' => array('created_channels', 'streams'));
		} elseif ($rStream['type'] == 4) {
			$rDropdown['stream_view'] = array('Edit Station' => array('radio?id=' . $rID, 'edit_radio'), 'Manage Stations' => array('radios', 'radio'));
		} elseif ($rStream['type'] == 5) {
			$rDropdown['stream_view'] = array('Edit Episode' => array('episode?id=' . $rID . '&sid=' . intval($rSeriesID), 'edit_episode'), 'View Episodes' => array('episodes?series=' . intval($rSeriesID), 'episodes'), 'Manage Series' => array('series', 'series'));
		}
	}




	if ($rPage == 'server_view') {
		if ($rServer['server_type'] == 0) {
			$rDropdown['server_view'] = array('Process Monitor' => array('process_monitor?server=' . $rID, 'process_monitor'), 'Edit Server' => array('server?id=' . $rID, 'edit_server'), 'Reinstall Server' => array('server_install?id=' . $rID, 'add_server'), 'View FPM Status' => array(null, 'servers', 'onClick="getFPMStatus(' . $rID . ');"'), 'Manage Servers' => array('servers', 'servers'));
		} else {
			$rDropdown['server_view'] = array('Edit Proxy' => array('proxy?id=' . $rID, 'edit_server'), 'Reinstall Proxy' => array('server_install?proxy=1&id=' . $rID, 'add_server'), 'Manage Proxies' => array('proxies', 'servers'));
		}
	}

	switch ($rPage) {
		case 'archive':
			if (!is_null($rRecordings)) {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[1]], $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			} else {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[2]]);
			}

			break;

		case 'movie':
		case 'stream':
			if (!isset($rStream) && !isset($rMovie)) {
				if (!RequestManager::has('import')) {
					unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[2]]);
				} else {
					unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[1]]);
				}

				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			} else {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[4]], $rDropdown[$rPage][array_keys($rDropdown[$rPage])[2]], $rDropdown[$rPage][array_keys($rDropdown[$rPage])[1]]);
			}

			break;

		case 'episode':
			if (!isset($rEpisode)) {
				if (isset($rMulti)) {
					unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
				} else {
					unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[1]]);
				}
			} else {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[1]], $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			}

			break;

		case 'serie':
			if (!isset($rSeriesArr)) {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[3]]);
			}

			if (!RequestManager::has('import')) {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[1]]);
			} else {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			}

			break;

		case 'mag':
		case 'enigma':
		case 'line':
			break;

		case 'server':
		case 'proxy':
			if (!isset($rServer)) {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			}

			break;

		case 'server_install':
			if ($rType == 1) {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			} else {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[1]]);
			}

			break;

		case 'ticket':
			if (!isset($rTicket)) {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			}

			break;

		case 'ticket_view':
			if (isset($rTicketInfo) && $rTicketInfo['status'] == 0) {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			}

			break;

		case 'bouquet':
			if (!isset($rBouquetArr)) {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[1]]);
			}

			break;

		case 'created_channel':
			if (!isset($rChannel)) {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			}

			break;

		default:
			break;
	}
} else {
	exit();
}

$rDropdownPage = array();

if (isset($rDropdown[$rPage])) {
	foreach ($rDropdown[$rPage] as $rName => $rData) {
		if (!in_array($rName, array('Export as CSV', 'Export as JSON'), true) || Authorization::check('adv', 'backups')) {
			if ($rName && (empty($rData[1]) || Authorization::check('adv', $rData[1]))) {
				if (count($rData) == 3) {
					$rDropdownPage[$rName] = 'code:' . $rData[2];
				} else {
					if (count($rData) > 0) {
						$rDropdownPage[$rName] = $rData[0];
					}
				}
			}
		}
	}
}

switch ($rPage) {
	case 'streams':
	case 'created_channels':
	case 'movies':
	case 'series':
	case 'users':
	case 'mags':
	case 'client_logs':
	case 'line_activity':
	case 'live_connections':
	case 'lines':
	case 'radios':
	case 'enigmas':
	case 'ondemand':
	case 'episodes':
		echo '<div class="btn-group">';

		if (!(!$rMobile && (($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][1] ?? '') === '' || Authorization::check('adv', $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][1])) && 0 < strlen(array_keys($rDropdown[$rPage])[0]))) {
		} else {
			if ($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][0]) {
				echo "<button type=\"button\" onClick=\"navigate('" . $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][0] . "');\" class=\"btn btn-sm btn-info waves-effect waves-light\">" . array_keys($rDropdown[$rPage])[0] . '</button>';
			} else {
				if (isset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][2])) {
					echo '<button type="button" ' . $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][2] . ' class="btn btn-sm btn-info waves-effect waves-light">' . array_keys($rDropdown[$rPage])[0] . '</button>';
				} else {
					echo '<button type="button" onClick="showModal();" class="btn btn-sm btn-info waves-effect waves-light">' . array_keys($rDropdown[$rPage])[0] . '</button>';
				}
			}

			echo '<span class="gap"></span>';
		}

		if (!$rMobile) {
		} else {
			echo '<a class="btn btn-success waves-effect waves-light btn-sm btn-fixed-sm" data-toggle="collapse" href="#collapse_filters" role="button" aria-expanded="false">' . "\r\n" . '                    <i class="mdi mdi-filter"></i>' . "\r\n" . '                </a>';
		}

		echo '<button onClick="clearFilters();" type="button" class="btn btn-warning waves-effect waves-light btn-sm btn-fixed-sm" id="clearFilters">' . "\r\n" . '                <i class="mdi mdi-filter-remove"></i>' . "\r\n" . '            </button>' . "\r\n" . '            <button onClick="refreshTable();" type="button" class="btn btn-pink waves-effect waves-light btn-sm btn-fixed-sm">' . "\r\n" . '                <i class="mdi mdi-refresh"></i>' . "\r\n" . '            </button>';

		if (0 >= count(array_slice($rDropdownPage, ($rMobile ? 0 : 1), count($rDropdownPage)))) {
		} else {
			echo '<button type="button" class="btn btn-sm btn-dark waves-effect waves-light dropdown-toggle btn-fixed-sm" data-toggle="dropdown" aria-expanded="false"><i class="fas fa-caret-down"></i></button>' . "\r\n" . '                <div class="dropdown-menu">';

			foreach (array_slice($rDropdownPage, ($rMobile ? 0 : 1), count($rDropdownPage)) as $rName => $rURL) {
				if (!$rName) {
				} else {
					if ($rURL) {
						if (substr($rURL, 0, 5) == 'code:') {
							echo '<a class="dropdown-item" href="javascript: void(0);" ' . substr($rURL, 5, strlen($rURL) - 5) . '>' . $rName . '</a>';
						} else {
							echo "<a class=\"dropdown-item\" href=\"javascript: void(0);\" onClick=\"navigate('" . $rURL . "');\">" . $rName . '</a>';
						}
					} else {
						echo '<a class="dropdown-item" href="javascript: void(0);" onClick="showModal();">' . $rName . '</a>';
					}
				}
			}
			echo '</div>';
		}

		echo '</div>';

		break;

	case 'stream_view':
		echo '<div class="btn-group">';

		if ($rStream['type'] == 1 || $rStream['type'] == 3) {
			echo '<a href="javascript:void(0);" onClick="player(' . intval($rStream['id']) . ');">' . "\r\n" . '                    <button type="button" title="' . $language::get('play') . '" class="tooltip btn btn-info waves-effect waves-light btn-sm">' . "\r\n" . '                        <i class="mdi mdi-play"></i>' . "\r\n" . '                    </button>' . "\r\n" . '                </a>';
		} else {
			if (!($rStream['type'] == 2 || $rStream['type'] == 5)) {
			} else {
				echo '<a href="javascript:void(0);" onClick="player(' . intval($rStream['id']) . ", '" . htmlspecialchars($rStream['target_container']) . "');\">" . "\r\n" . '                    <button type="button" title="' . $language::get('play') . '" class="tooltip btn btn-info waves-effect waves-light btn-sm">' . "\r\n" . '                        <i class="mdi mdi-play"></i>' . "\r\n" . '                    </button>' . "\r\n" . '                </a>';
			}
		}

		if (!(!$rMobile && (($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][1] ?? '') === '' || Authorization::check('adv', $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][1])) && 0 < strlen(array_keys($rDropdown[$rPage])[0]))) {
		} else {
			echo "<button type=\"button\" onClick=\"navigate('" . $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][0] . "');\" class=\"btn btn-sm btn-info waves-effect waves-light\">" . array_keys($rDropdown[$rPage])[0] . '</button>';
		}

		if (0 >= count(array_slice($rDropdownPage, ($rMobile ? 0 : 1), count($rDropdownPage)))) {
		} else {
			echo '<span class="gap"></span><button type="button" class="btn btn-sm btn-dark waves-effect waves-light dropdown-toggle btn-fixed' . (($rMobile ? '-xl' : '-sm')) . '" data-toggle="dropdown" aria-expanded="false">' . (($rMobile ? 'Options &nbsp; ' : '')) . '<i class="fas fa-caret-down"></i></button>' . "\r\n" . '                <div class="dropdown-menu">';

			foreach (array_slice($rDropdownPage, ($rMobile ? 0 : 1), count($rDropdownPage)) as $rName => $rURL) {
				if (!$rName) {
				} else {
					if (substr($rURL, 0, 5) == 'code:') {
						echo '<a class="dropdown-item" href="javascript: void(0);" ' . substr($rURL, 5, strlen($rURL) - 5) . '>' . $rName . '</a>';
					} else {
						echo "<a class=\"dropdown-item\" href=\"javascript: void(0);\" onClick=\"navigate('" . $rURL . "');\">" . $rName . '</a>';
					}
				}
			}
			echo '</div>';
		}

		echo '</div>';

		break;

	case 'stream_categories':
		echo '<div class="btn-group">';

		if (!$rMobile && (($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][1] ?? '') === '' || Authorization::check('adv', $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][1])) && 0 < strlen(array_keys($rDropdown[$rPage])[0])) {
			echo "<button type=\"button\" onClick=\"navigate('" . $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][0] . "');\" class=\"btn btn-sm btn-info waves-effect waves-light\">" . array_keys($rDropdown[$rPage])[0] . '</button>';
		}

		if (!$rMobile && strlen($rSettings["tmdb_api_key"]) > 0) {
			echo "<span class=\"gap\"></span><button type=\"button\" onclick=\"importTmdbCategories();\" class=\"btn btn-sm btn-info waves-effect waves-light\">" . $language::get('import_tmdb_genres') . "</button>";
		}

		if (0 >= count(array_slice($rDropdownPage, ($rMobile ? 0 : 1), count($rDropdownPage)))) {
		} else {
			echo '<span class="gap"></span><button type="button" class="btn btn-sm btn-dark waves-effect waves-light dropdown-toggle btn-fixed' . (($rMobile ? '-xl' : '-sm')) . '" data-toggle="dropdown" aria-expanded="false">' . (($rMobile ? 'Options &nbsp; ' : '')) . '<i class="fas fa-caret-down"></i></button>' . "\r\n" . '                <div class="dropdown-menu">';

			foreach (array_slice($rDropdownPage, ($rMobile ? 0 : 1), count($rDropdownPage)) as $rName => $rURL) {
				if ($rName) {
					if (substr($rURL, 0, 5) == 'code:') {
						echo '<a class="dropdown-item" href="javascript: void(0);" ' . substr($rURL, 5, strlen($rURL) - 5) . '>' . $rName . '</a>';
					} else {
						echo "<a class=\"dropdown-item\" href=\"javascript: void(0);\" onClick=\"navigate('" . $rURL . "');\">" . $rName . '</a>';
					}
				}
			}
			echo "<a class=\"dropdown-item\" href=\"javascript: void(0);\" onClick=\"importTmdbCategories();\">Import TMDB Genres</a>";

			echo '</div>';
		}

		echo '</div>';

		break;

	default:
		echo '<div class="btn-group">';

		// Check if the array exists and has elements
		if (isset($rDropdown[$rPage]) && is_array($rDropdown[$rPage]) && !empty($rDropdown[$rPage])) {
			$firstKey = array_keys($rDropdown[$rPage])[0];
			$firstItem = $rDropdown[$rPage][$firstKey];

			$permAllowed = !isset($firstItem[1]) || $firstItem[1] === '' || $firstItem[1] === null || Authorization::check('adv', $firstItem[1]);

			$shouldShowButton = !$rMobile &&
				$permAllowed &&
				strlen($firstKey) > 0;

			if ($shouldShowButton) {
				if (!empty($firstItem[0])) {
					echo "<button type=\"button\" onClick=\"navigate('" . $firstItem[0] . "');\" class=\"btn btn-sm btn-info waves-effect waves-light\">" . $firstKey . '</button>';
				} else {
					if (isset($firstItem[2])) {
						echo '<button type="button" ' . $firstItem[2] . ' class="btn btn-sm btn-info waves-effect waves-light">' . $firstKey . '</button>';
					} else {
						echo '<button type="button" onClick="showModal();" class="btn btn-sm btn-info waves-effect waves-light">' . $firstKey . '</button>';
					}
				}
			}
		}

		if (0 >= count(array_slice($rDropdownPage, ($rMobile ? 0 : 1), count($rDropdownPage)))) {
		} else {
			echo '<span class="gap"></span><button type="button" class="btn btn-sm btn-dark waves-effect waves-light dropdown-toggle btn-fixed' . (($rMobile ? '-xl' : '-sm')) . '" data-toggle="dropdown" aria-expanded="false">' . (($rMobile ? 'Options &nbsp; ' : '')) . '<i class="fas fa-caret-down"></i></button>' . "\r\n" . '                <div class="dropdown-menu">';

			foreach (array_slice($rDropdownPage, ($rMobile ? 0 : 1), count($rDropdownPage)) as $rName => $rURL) {
				if (!$rName) {
				} else {
					if (substr($rURL, 0, 5) == 'code:') {
						echo '<a class="dropdown-item" href="javascript: void(0);" ' . substr($rURL, 5, strlen($rURL) - 5) . '>' . $rName . '</a>';
					} else {
						echo "<a class=\"dropdown-item\" href=\"javascript: void(0);\" onClick=\"navigate('" . $rURL . "');\">" . $rName . '</a>';
					}
				}
			}
			echo '</div>';
		}

		echo '</div>';
}
