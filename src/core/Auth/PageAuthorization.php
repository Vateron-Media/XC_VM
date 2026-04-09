<?php

/**
 * PageAuthorization — page-level permission checks for admin/reseller panels.
 *
 * @package XC_VM_Core_Auth
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class PageAuthorization {
	public static function checkResellerPermissions($rPage = null) {
		global $rPermissions;

		if ($rPage) {
		} else {
			$rPage = strtolower(basename($_SERVER['SCRIPT_FILENAME'], '.php'));
		}

		switch ($rPage) {
			case 'user':
			case 'users':
				return $rPermissions['create_sub_resellers'];

			case 'line':
			case 'lines':
				return $rPermissions['create_line'];

			case 'mag':
			case 'mags':
				return $rPermissions['create_mag'];

			case 'enigma':
			case 'enigmas':
				return $rPermissions['create_enigma'];

			case 'epg_view':
			case 'streams':
			case 'created_channels':
			case 'movies':
			case 'episodes':
			case 'radios':
				return $rPermissions['can_view_vod'];

			case 'live_connections':
			case 'line_activity':
				return $rPermissions['reseller_client_connection_logs'];
		}

		return true;
	}

	public static function checkPermissions($rPage = null) {
		if ($rPage) {
		} else {
			$rPage = strtolower(basename($_SERVER['SCRIPT_FILENAME'], '.php'));
		}

		switch ($rPage) {
			case 'isps':
			case 'isp':
			case 'asns':
				return Authorization::check('adv', 'block_isps');

			case 'bouquet':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_bouquet')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_bouquet')) {
				} else {
					return true;
				}

				// no break
			case 'bouquet_order':
			case 'bouquet_sort':
				return Authorization::check('adv', 'edit_bouquet');

			case 'bouquets':
				return Authorization::check('adv', 'bouquets');

			case 'channel_order':
				return Authorization::check('adv', 'channel_order');

			case 'client_logs':
				return Authorization::check('adv', 'client_request_log');

			case 'created_channel':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_cchannel')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'create_channel')) {
				} else {
					return true;
				}

				// no break
			case 'code':
			case 'codes':
				return Authorization::check('adv', 'add_code');

			case 'hmac':
			case 'hmacs':
				return Authorization::check('adv', 'add_hmac');

			case 'credit_logs':
				return Authorization::check('adv', 'credits_log');

			case 'enigmas':
				return Authorization::check('adv', 'manage_e2');

			case 'epg':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'epg_edit')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_epg')) {
				} else {
					return true;
				}

				// no break
			case 'epgs':
				return Authorization::check('adv', 'epg');

			case 'episode':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_episode')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_episode')) {
				} else {
					return true;
				}

				// no break
			case 'episodes':
				return Authorization::check('adv', 'episodes');

			case 'series_mass':
			case 'episodes_mass':
				return Authorization::check('adv', 'mass_sedits');

			case 'fingerprint':
				return Authorization::check('adv', 'fingerprint');

			case 'group':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_group')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_group')) {
				} else {
					return true;
				}

				// no break
			case 'groups':
				return Authorization::check('adv', 'mng_groups');

			case 'ip':
			case 'ips':
				return Authorization::check('adv', 'block_ips');

			case 'live_connections':
				return Authorization::check('adv', 'live_connections');

			case 'mag':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_mag')) {
					return true;
				}
				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_mag')) {
					break;
				}
				return true;
			case 'mag_events':
				return Authorization::check('adv', 'manage_events');
			case 'mags':
				return Authorization::check('adv', 'manage_mag');

			case 'mass_delete':
				return Authorization::check('adv', 'mass_delete');

			case 'record':
				return Authorization::check('adv', 'add_movie');
			case 'recordings':
				return Authorization::check('adv', 'movies');
			case 'queue':
				return Authorization::check('adv', 'streams') || Authorization::check('adv', 'episodes') || Authorization::check('adv', 'series');
			case 'movie':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_movie')) {
					return true;
				}
				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_movie')) {
				} else {
					if (isset(RequestManager::getAll()['import']) && !Authorization::check('adv', 'import_movies')) {
					} else {
						return true;
					}
				}
				break;
			case 'movie_mass':
				return Authorization::check('adv', 'mass_sedits_vod');
			case 'movies':
				return Authorization::check('adv', 'movies');
			case 'package':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_package')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_packages')) {
					break;
				}
				return true;
			case 'packages':
			case 'addons':
				return Authorization::check('adv', 'mng_packages');

			case 'player':
				return Authorization::check('adv', 'player');

			case 'process_monitor':
				return Authorization::check('adv', 'process_monitor');

			case 'profile':
				return Authorization::check('adv', 'tprofile');

			case 'profiles':
				return Authorization::check('adv', 'tprofiles');

			case 'radio':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_radio')) {
					return true;
				}
				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_radio')) {
					break;
				}
				return true;
			case 'radio_mass':
				return Authorization::check('adv', 'mass_edit_radio');
			case 'radios':
				return Authorization::check('adv', 'radio');
			case 'user':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_reguser')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_reguser')) {
					break;
				}
				return true;
			case 'user_logs':
				return Authorization::check('adv', 'reg_userlog');
			case 'users':
				return Authorization::check('adv', 'mng_regusers');
			case 'rtmp_ip':
				return Authorization::check('adv', 'add_rtmp');
			case 'rtmp_ips':
			case 'rtmp_monitor':
				return Authorization::check('adv', 'rtmp');
			case 'serie':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_series')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_series')) {
					break;
				}
				return true;
			case 'series':
				return Authorization::check('adv', 'series');
			case 'series_order':
				return Authorization::check('adv', 'edit_series');
			case 'server':
			case 'proxy':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_server')) {
					return true;
				}
				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_server')) {
					break;
				}
				return true;
			case 'server_install':
				return Authorization::check('adv', 'add_server');
			case 'servers':
			case 'server_view':
			case 'server_order':
			case 'proxies':
				return Authorization::check('adv', 'servers');

			case 'settings':
				return Authorization::check('adv', 'settings');

			case 'backups':
			case 'cache':
			case 'setup':
				return Authorization::check('adv', 'database');

			case 'settings_watch':
			case 'settings_plex':
				return Authorization::check('adv', 'folder_watch_settings');

			case 'stream':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_stream')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_stream')) {
				} else {
					if (isset(RequestManager::getAll()['import']) && !Authorization::check('adv', 'import_streams')) {
					} else {
						return true;
					}
				}

				break;

			case 'review':
				return Authorization::check('adv', 'import_streams');

			case 'mass_edit_streams':
				return Authorization::check('adv', 'edit_stream');

			case 'stream_categories':
				return Authorization::check('adv', 'categories');

			case 'stream_category':
				return Authorization::check('adv', 'add_cat');

			case 'stream_errors':
				return Authorization::check('adv', 'stream_errors');

			case 'created_channel_mass':
			case 'stream_mass':
				return Authorization::check('adv', 'mass_edit_streams');

			case 'user_mass':
				return Authorization::check('adv', 'mass_edit_users');

			case 'mag_mass':
				return Authorization::check('adv', 'mass_edit_mags');

			case 'enigma_mass':
				return Authorization::check('adv', 'mass_edit_enigmas');

			case 'quick_tools':
				return Authorization::check('adv', 'quick_tools');

			case 'stream_tools':
				return Authorization::check('adv', 'stream_tools');

			case 'stream_view':
			case 'provider':
			case 'providers':
			case 'streams':
			case 'epg_view':
			case 'created_channels':
			case 'stream_rank':
			case 'archive':
				return Authorization::check('adv', 'streams');

			case 'ticket':
				return Authorization::check('adv', 'ticket');

			case 'ticket_view':
			case 'tickets':
				return Authorization::check('adv', 'manage_tickets');

			case 'line':
				if (isset(RequestManager::getAll()['id']) && Authorization::check('adv', 'edit_user')) {
					return true;
				}

				if (isset(RequestManager::getAll()['id']) || !Authorization::check('adv', 'add_user')) {
					break;
				}

				return true;

			case 'line_activity':
			case 'theft_detection':
			case 'line_ips':
				return Authorization::check('adv', 'connection_logs');

			case 'line_mass':
				return Authorization::check('adv', 'mass_edit_lines');

			case 'useragents':
			case 'useragent':
				return Authorization::check('adv', 'block_uas');

			case 'lines':
				return Authorization::check('adv', 'users');

			case 'plex':
			case 'watch':
				return Authorization::check('adv', 'folder_watch');

			case 'plex_add':
			case 'watch_add':
				return Authorization::check('adv', 'folder_watch_add');

			case 'watch_output':
				return Authorization::check('adv', 'folder_watch_output');

			case 'mysql_syslog':
			case 'panel_logs':
				return Authorization::check('adv', 'panel_logs');

			case 'login_logs':
				return Authorization::check('adv', 'login_logs');

			case 'restream_logs':
				return Authorization::check('adv', 'restream_logs');

			default:
				return true;
		}
	}
}
