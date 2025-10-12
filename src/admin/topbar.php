<?php

if (count(get_included_files()) != 1) {
	$rPage = getPageName();
	$rID = (isset(CoreUtilities::$rRequest['id']) ? intval(CoreUtilities::$rRequest['id']) : null);
	$rSID = (isset(CoreUtilities::$rRequest['sid']) ? intval(CoreUtilities::$rRequest['sid']) : null);
	$rDropdown = array('ondemand' => array('Manage Streams' => array('streams', 'streams'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('stream_mass', 'mass_edit_streams'), 'Stream Tools' => array('stream_tools', 'stream_tools'), 'Stream Error Logs' => array('stream_errors', 'stream_errors'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'streams' => array('Add Stream' => array('stream', 'add_stream'), 'Import & Review' => ($rMobile ? array() : array('review?type=1', 'import_streams')), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), "EPG's" => array('epgs', 'epg'), 'Fingerprint' => array('fingerprint', 'fingerprint'), 'On-Demand Scanner' => array('ondemand', 'streams'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('stream_mass', 'mass_edit_streams'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools'), 'Stream Error Logs' => array('stream_errors', 'stream_errors'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'created_channels' => array('Create Channel' => array('created_channel', 'create_channel'), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('created_channel_mass', null), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'stream_review' => array((isset($rImport) ? 'Save Changes' : 'Review Streams') => array(null, null, 'id="btn-submit"')), 'panel_logs' => array('Download log' => array(null, null, 'id="btn-download-log"')), 'movies' => array('Add Movie' => array('movie', 'add_movie'), 'Import & Review' => ($rMobile ? array() : array('review?type=2', 'import_movies')), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('movie_mass', 'mass_sedits_vod'), 'Watch Folder' => array('watch', 'folder_watch'), 'Watch Output Logs' => array('watch_output', 'folder_watch_output'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'series' => array('Add Series' => array('serie', 'add_series'), 'Episodes' => array('episodes', 'episodes'), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('series_mass', 'mass_sedits'), 'Watch Folder' => array('watch', 'folder_watch'), 'Watch Output Logs' => array('watch_output', 'folder_watch_output'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'episodes' => array('Add Episode' => array(null, 'add_episode'), 'TV Series' => array('series', 'series'), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('episodes_mass', 'mass_sedits'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'radios' => array('Add Station' => array('radio', 'add_radio'), 'Categories' => array('stream_categories', 'categories'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('radio_mass', 'mass_edit_radio'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'lines' => array('Add Line' => array('line', 'add_user'), "Blocked ASN's" => array('asns', 'block_isps'), "Blocked IP's" => array('ips', 'block_ips'), "Blocked ISP's" => array('isps', 'block_isps'), 'Blocked User-Agents' => array('useragents', 'block_uas'), 'Live Connections' => array('live_connections', 'live_connections'), 'Activity Logs' => array('line_activity', 'connection_logs'), "IP's per Line" => array('line_ips', 'connection_logs'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('line_mass', 'mass_edit_users'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'live_connections' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Activity Logs' => array('line_activity', 'connection_logs'), "IP's per Line" => array('line_ips', 'connection_logs')), 'mags' => array('Add Device' => array('mag', 'add_mag'), "Blocked IP's" => array('ips', 'block_ips'), "Blocked ISP's" => array('isps', 'block_isps'), 'Live Connections' => array('live_connections', 'connection_logs'), 'Activity Logs' => array('line_activity', 'connection_logs'), 'MAG Event Logs' => array('mag_events', 'manage_events'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('mag_mass', 'mass_edit_mags'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'enigmas' => array('Add Device' => array('enigma', 'add_e2'), "Blocked IP's" => array('ips', 'block_ips'), "Blocked ISP's" => array('isps', 'block_isps'), 'Live Connections' => array('live_connections', 'connection_logs'), 'Activity Logs' => array('line_activity', 'connection_logs'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('enigma_mass', 'mass_edit_enigmas'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'users' => array('Add User' => array('user', 'add_reguser'), 'Groups' => array('groups', 'mng_groups'), 'Packages' => array('packages', 'mng_packages'), 'Subresellers' => array('subresellers', 'subreseller'), 'Client Logs' => array('client_logs', 'client_request_log'), 'Credit Logs' => array('credit_logs', 'credits_log'), 'Reseller Logs' => array('user_logs', 'reg_userlog'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Mass Edit' => array('user_mass', 'mass_edit_reguser'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'bouquet' => array('Manage Bouquets' => array('bouquets', 'bouquets'), 'Sort Bouquet' => array('bouquet_sort?id=' . $rID, 'edit_bouquet')), 'bouquet_sort' => array('Manage Bouquets' => array('bouquets', 'bouquets'), 'Edit Bouquet' => array('bouquet?id=' . $rID, 'edit_bouquet')), 'bouquet_order' => array('Manage Bouquets' => array('bouquets', 'bouquets'), 'Add Bouquet' => array('bouquet', 'add_bouquet')), 'archive' => array('View Stream' => array('stream_view?id=' . $rID, 'streams'), 'Edit Stream' => array('stream?id=' . $rID, 'edit_stream'), 'Create Recording' => array('record', 'add_movie'), 'Manage Streams' => array('streams', 'streams')), 'asns' => array('Quick Tools' => array('quick_tools', 'quick_tools')), 'backups' => array('General Settings' => array('settings', 'settings'), 'Watch Settings' => array('settings_watch', 'folder_watch_settings'), 'Plex Settings' => array('settings_plex', 'folder_watch_settings'), 'Cache Settings' => array('cache', 'backups')), 'cache' => array('General Settings' => array('settings', 'settings'), 'Watch Settings' => array('settings_watch', 'folder_watch_settings'), 'Plex Settings' => array('settings_plex', 'folder_watch_settings'), 'Backup Settings' => array('backups', 'database')), 'settings' => array('Backup Settings' => array('backups', 'database'), 'Watch Settings' => array('settings_watch', 'folder_watch_settings'), 'Plex Settings' => array('settings_plex', 'folder_watch_settings'), 'Cache Settings' => array('cache', 'backups')), 'settings_watch' => array('Folders' => array('watch', 'folder_watch'), 'General Settings' => array('settings', 'settings'), 'Backup Settings' => array('backups', 'database'), 'Plex Settings' => array('settings_plex', 'folder_watch_settings'), 'Watch Folder Logs' => array('watch_output', 'folder_watch_output')), 'settings_plex' => array('Libraries' => array('plex', 'folder_watch'), 'General Settings' => array('settings', 'settings'), 'Backup Settings' => array('backups', 'database'), 'Watch Settings' => array('settings_watch', 'folder_watch_settings'), 'Watch Folder Logs' => array('watch_output', 'folder_watch_output')), 'channel_order' => array('Categories' => array('stream_categories', 'categories'), 'Bouquets' => array('bouquets', 'bouquets')), 'bouquets' => array('Add Bouquet' => array('bouquet', 'add_bouquet'), 'Order Bouquets' => ($rMobile ? array() : array('bouquet_order', 'edit_bouquet')), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Categories' => array('stream_categories', 'categories')), 'stream_categories' => array('Add Category' => array('stream_category', 'add_cat'), 'Channel Order' => ($rMobile ? array() : array('channel_order', 'channel_order')), 'Bouquets' => array('bouquets', 'bouquets')), 'client_logs' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')), 'credit_logs' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')), 'user_logs' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')), 'stream_errors' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')), 'line_activity' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"')), 'watch_output' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'Clear Logs' => array(null, null, 'id="btn-clear-logs"'), 'Watch Folder' => array('watch', 'folder_watch')), 'code' => array('Access Codes' => array('codes', 'add_code')), 'codes' => array('Add Code' => array('code', 'add_code')), 'hmacs' => array('Add HMAC' => array('hmac', 'add_hmac')), 'hmac' => array('HMAC Keys' => array('hmacs', 'add_hmac')), 'stream' => array('View Stream' => array('stream_view?id=' . $rID, 'streams'), 'Import' => array('stream?import', 'import_streams'), 'Add Single' => array('stream', 'add_stream'), 'Manage Streams' => array('streams', 'streams'), 'Import & Review' => ($rMobile ? array() : array('review?type=1', 'import_streams'))), 'movie' => array('View Movie' => array('stream_view?id=' . $rID, 'movies'), 'Import' => array('movie?import', 'import_movies'), 'Add Single' => array('movie', 'add_movie'), 'Manage Movies' => array('movies', 'movies'), 'Import & Review' => ($rMobile ? array() : array('review?type=2', 'import_movies'))), 'episode' => array('Add Multiple' => array('episode?sid=' . $rSID . '&multi', 'add_episode'), 'Add Single' => array('episode?sid=' . $rSID, 'add_episode'), 'View Episodes' => array('episodes?series=' . $rSID, 'episodes'), 'Manage Series' => array('series', 'series')), 'serie' => array('Import' => array('serie?import', 'import_streams'), 'Add Single' => array('serie', 'add_series'), 'Manage Series' => array('series', 'series'), 'View Episodes' => array('episodes?series=' . $rID, 'episodes')), 'created_channel' => array('View Channel' => array('stream_view?id=' . $rID, 'streams'), 'Manage Channels' => array('created_channels', 'streams')), 'epg' => array("Manage EPG's" => array('epgs', 'epg')), 'epgs' => array('Add EPG' => array('epg', 'add_epg'), 'Force Reload' => array(null, 'add_epg', 'onClick="forceUpdate();" id="force_update"')), 'fingerprint' => array('Manage Streams' => array('streams', 'streams')), 'group' => array('Manage Groups' => array('groups', 'mng_groups')), 'groups' => array('Add Group' => array('group', 'add_group')), 'package' => array('Manage Packages' => array('packages', 'mng_packages')), 'packages' => array('Add Package' => array('package', 'add_packages')), 'provider' => array('Providers' => array('providers', 'streams')), 'providers' => array('Add Provider' => array('provider', 'streams')), 'ip' => array('Blocked IPs' => array('ips', 'block_ips')), 'ips' => array('Block IP' => array('ip', 'block_ips'), 'Flush Blocks' => array('ips?flush=1', 'block_ips')), 'isp' => array('Blocked ISPs' => array('isps', 'block_isps')), 'isps' => array('Block ISP' => array('isp', 'block_isps')), 'line' => array('Manage Lines' => array('lines', 'users')), 'user' => array('Manage Users' => array('users', 'mng_regusers')), 'mag' => array('MAG Devices' => array('mags', 'manage_mag')), 'enigma' => array('Enigma Devices' => array('enigmas', 'manage_e2')), 'line_ips' => array('Manage Lines' => array('lines', 'users')), 'line_mass' => array('Manage Lines' => array('lines', 'users'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')), 'user_mass' => array('Manage Users' => array('users', 'mng_regusers'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')), 'mag_mass' => array('Manage Devices' => array('mags', 'manage_mag'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')), 'enigma_mass' => array('Manage Devices' => array('enigmas', 'manage_e2'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')), 'stream_mass' => array('Manage Streams' => array('streams', 'streams'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')), 'created_channel_mass' => array('Manage Channels' => array('created_channels', 'streams'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')), 'movie_mass' => array('Manage Movies' => array('movies', 'movies'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')), 'radio_mass' => array('Manage Stations' => array('radios', 'radio'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')), 'series_mass' => array('Manage Series' => array('series', 'series'), 'Manage Episodes' => array('episodes', 'episodes'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools')), 'episodes_mass' => array('Manage Episodes' => array('episodes', 'episodes'), 'Manage Series' => array('series', 'series'), 'Mass Delete' => array('mass_delete', 'mass_delete'), 'Quick Tools' => array('quick_tools', 'quick_tools'), 'Stream Tools' => array('stream_tools', 'stream_tools')), 'mag_events' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"'), 'MAG Devices' => array('mags', 'manage_mag')), 'login_logs' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'mysql_syslog' => array('Export as CSV' => array(null, null, 'id="btn-export-csv"')), 'mass_delete' => array('Manage Streams' => array('streams', 'streams'), 'Manage Channels' => array('created_channels', 'streams'), 'Manage Series' => array('series', 'series'), 'Manage Episodes' => array('episodes', 'episodes'), 'Manage Stations' => array('radios', 'radio'), 'Manage Lines' => array('lines', 'users'), 'Manage Users' => array('users', 'mng_regusers'), 'Manage MAGs' => array('mags', 'manage_mag'), 'Manage Enigmas' => array('enigmas', 'manage_e2')), 'quick_tools' => array('Stream Tools' => array('stream_tools', 'stream_tools')), 'stream_tools' => array('Quick Tools' => array('quick_tools', 'quick_tools')), 'profile' => array('Manage Profiles' => array('profiles', 'tprofiles')), 'profiles' => array('Create Profile' => array('profile', 'tprofile')), 'rtmp_ips' => array('Add IP' => array('rtmp_ip', 'add_rtmp')), 'rtmp_ip' => array('RTMP IPs' => array('rtmp_ips', 'rtmp')), 'server' => array('View Server' => array('server_view?id=' . $rID, 'servers'), 'Manage Servers' => array('servers', 'servers')), 'proxy' => array('View Proxy' => array('server_view?id=' . $rID, 'servers'), 'Manage Proxies' => array('proxies', 'servers')), 'server_install' => array('Manage Servers' => array('servers', 'servers'), 'Manage Proxies' => array('proxies', 'servers')), 'servers' => array('Install Server' => array('server_install', 'add_server'), 'Server Order' => array('server_order', 'servers'), 'Proxies' => array('proxies', 'servers'), 'Process Monitor' => array('process_monitor', 'process_monitor'), 'Update All Servers' => array(null, 'servers', 'onClick="updateAll();"'), 'Update All Binaries' => array(null, 'servers', 'onClick="updateBinaries();"'), 'Restart All Services' => array(null, 'servers', 'onClick="restartServices();"')), 'server_order' => array('Servers' => array('servers', 'servers'), 'Proxies' => array('proxies', 'servers'), 'Process Monitor' => array('process_monitor', 'process_monitor')), 'proxies' => array('Install Proxy' => array('server_install?proxy=1', 'add_server'), 'Servers' => array('servers', 'servers'), 'Process Monitor' => array('process_monitor', 'process_monitor')), 'stream_category' => array('Manage Categories' => array('stream_categories', 'categories')), 'ticket' => array('View Ticket' => array('ticket_view?id=' . $rID, 'ticket'), 'View Tickets' => array('tickets', 'manage_tickets')), 'ticket_view' => array('Add Response' => array('ticket?id=' . $rID, 'ticket'), 'View Tickets' => array('tickets', 'manage_tickets')), 'useragent' => array('Blocked User-Agents' => array('useragents', 'block_uas')), 'useragents' => array('Block User-Agent' => array('useragent', 'block_uas')), 'watch' => array('Add Folder' => array('watch_add', 'folder_watch_add'), 'Settings' => array('settings_watch', 'folder_watch_settings'), 'Watch Output Logs' => array('watch_output', 'folder_watch_output'), 'Kill Running' => array(null, 'folder_watch_settings', 'onClick="killWatchFolder();"'), 'Enable All' => array(null, 'folder_watch_settings', 'onClick="enableAll();"'), 'Disable All' => array(null, 'folder_watch_settings', 'onClick="disableAll();"')), 'watch_add' => array('Manage Folders' => array('watch', 'folder_watch')), 'plex' => array('Add Library' => array('plex_add', 'folder_watch_add'), 'Settings' => array('settings_plex', 'folder_watch_settings'), 'Watch Folder Logs' => array('watch_output', 'folder_watch_output'), 'Kill Running' => array(null, 'folder_watch_settings', 'onClick="killPlexSync();"'), 'Enable All' => array(null, 'folder_watch_settings', 'onClick="enableAll();"'), 'Disable All' => array(null, 'folder_watch_settings', 'onClick="disableAll();"')), 'plex_add' => array('Manage Libraries' => array('plex', 'folder_watch')));

	$rDropdown['servers'] = array('Proxies' => array('proxies', 'servers'), 'Process Monitor' => array('process_monitor', 'process_monitor'));


	if ($rPage == 'stream_view') {
		if ($rStream['type'] == 1) {
			$rDropdown['stream_view'] = array('Edit Stream' => array('stream?id=' . $rID, 'edit_stream'), 'Manage Streams' => array('streams', 'streams'));
		} else {
			if ($rStream['type'] == 2) {
				$rDropdown['stream_view'] = array('Edit Movie' => array('movie?id=' . $rID, 'edit_movie'), 'Manage Movies' => array('movies', 'movies'));
			} else {
				if ($rStream['type'] == 3) {
					$rDropdown['stream_view'] = array('Edit Channel' => array('created_channel?id=' . $rID, 'edit_cchannel'), 'Manage Channels' => array('created_channels', 'streams'));
				} else {
					if ($rStream['type'] == 4) {
						$rDropdown['stream_view'] = array('Edit Station' => array('radio?id=' . $rID, 'edit_radio'), 'Manage Stations' => array('radios', 'radio'));
					} else {
						if ($rStream['type'] != 5) {
						} else {
							$rDropdown['stream_view'] = array('Edit Episode' => array('episode?id=' . $rID . '&sid=' . intval($rSeriesID), 'edit_episode'), 'View Episodes' => array('episodes?series=' . intval($rSeriesID), 'episodes'), 'Manage Series' => array('series', 'series'));
						}
					}
				}
			}
		}
	}

	if ($rPage != 'server_view') {
	} else {
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
				if (!isset(CoreUtilities::$rRequest['import'])) {
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
			if (isset($rSeriesArr)) {
			} else {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[3]]);
			}

			if (!isset(CoreUtilities::$rRequest['import'])) {
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
			if (isset($rServer)) {
			} else {
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
			if (isset($rTicket)) {
			} else {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			}

			break;

		case 'ticket_view':
			if (!(isset($rTicketInfo) && $rTicketInfo['status'] == 0)) {
			} else {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]]);
			}

			break;

		case 'bouquet':
			if (isset($rBouquetArr)) {
			} else {
				unset($rDropdown[$rPage][array_keys($rDropdown[$rPage])[1]]);
			}

			break;

		case 'created_channel':
			if (isset($rChannel)) {
			} else {
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
		if ($rName != 'Export as CSV' || hasPermissions('adv', 'backups')) {
			if (!($rName && (!$rData[1] || hasPermissions('adv', $rData[1])))) {
			} else {
				if (count($rData) == 3) {
					$rDropdownPage[$rName] = 'code:' . $rData[2];
				} else {
					if (0 >= count($rData)) {
					} else {
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

		if (!(!$rMobile && hasPermissions('adv', $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][1]) && 0 < strlen(array_keys($rDropdown[$rPage])[0]))) {
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
			echo '<a href="javascript:void(0);" onClick="player(' . intval($rStream['id']) . ');">' . "\r\n" . '                    <button type="button" title="Play" class="tooltip btn btn-info waves-effect waves-light btn-sm">' . "\r\n" . '                        <i class="mdi mdi-play"></i>' . "\r\n" . '                    </button>' . "\r\n" . '                </a>';
		} else {
			if (!($rStream['type'] == 2 || $rStream['type'] == 5)) {
			} else {
				echo '<a href="javascript:void(0);" onClick="player(' . intval($rStream['id']) . ", '" . htmlspecialchars($rStream['target_container']) . "');\">" . "\r\n" . '                    <button type="button" title="Play" class="tooltip btn btn-info waves-effect waves-light btn-sm">' . "\r\n" . '                        <i class="mdi mdi-play"></i>' . "\r\n" . '                    </button>' . "\r\n" . '                </a>';
			}
		}

		if (!(!$rMobile && hasPermissions('adv', $rDropdown[$rPage][array_keys($rDropdown[$rPage])[0]][1]) && 0 < strlen(array_keys($rDropdown[$rPage])[0]))) {
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

	default:
		echo '<div class="btn-group">';

		// Check if the array exists and has elements
		if (isset($rDropdown[$rPage]) && is_array($rDropdown[$rPage]) && !empty($rDropdown[$rPage])) {
			$firstKey = array_keys($rDropdown[$rPage])[0];
			$firstItem = $rDropdown[$rPage][$firstKey];

			$shouldShowButton = !$rMobile &&
				hasPermissions('adv', $firstItem[1]) &&
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
