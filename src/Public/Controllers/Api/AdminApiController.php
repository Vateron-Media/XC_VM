<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Audit\AdminAudit;
use XcVm\Core\Auth\ApiTokens;
use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;

/**
 * AdminApiController — admin api controller
 *
 * @package XC_VM_Public_Controllers_Api
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AdminApiController {
	/**
	 * The permissions each action asks of the group of the key: those the panel
	 * asks for the same operation (PageAuthorization, the admin-ajax gates, the
	 * services, TableController). Several mean any one of them; none, that every
	 * key runs the action. A full administrator holds them all
	 * (Authorization::check). An action that is not listed is a module's
	 * (AdminApiRegistry, TableRegistry), which asks for its own permission.
	 *
	 * @var array<string, list<string>>
	 */
	public const ACTION_PERMISSIONS = [
		'mysql_query' => ['database'],
		// The key's own account.
		'user_info' => [],
		// Tables, as TableController asks.
		'get_lines' => ['users', 'mass_edit_lines'],
		'get_mags' => ['manage_mag', 'mass_edit_mags'],
		'get_enigmas' => ['manage_e2', 'mass_edit_enigmas'],
		'get_users' => ['mng_regusers', 'mass_edit_users'],
		'get_streams' => ['streams', 'mass_edit_streams'],
		'get_channels' => ['streams', 'mass_edit_streams'],
		'get_stations' => ['radio', 'mass_edit_radio'],
		'get_movies' => ['movies', 'mass_sedits_vod'],
		'get_series_list' => ['series', 'mass_sedits'],
		'get_episodes' => ['episodes', 'mass_sedits'],
		'activity_logs' => ['connection_logs'],
		'live_connections' => ['live_connections'],
		'credit_logs' => ['credits_log'],
		'client_logs' => ['client_request_log'],
		'user_logs' => ['reg_userlog'],
		'stream_errors' => ['stream_errors'],
		'system_logs' => ['panel_logs'],
		'login_logs' => ['login_logs'],
		'restream_logs' => ['restream_logs'],
		'mag_events' => ['manage_events'],
		// As the table asks: the stream and movie forms and the providers page show it.
		'get_provider_streams' => ['streams', 'add_stream', 'edit_stream', 'add_movie', 'edit_movie'],
		// One record is read with the permission of its form: a table lists less of it.
		'get_line' => ['edit_user'],
		'create_line' => ['add_user'],
		'edit_line' => ['edit_user'],
		'delete_line' => ['edit_user'],
		'disable_line' => ['edit_user'],
		'enable_line' => ['edit_user'],
		'unban_line' => ['edit_user'],
		'ban_line' => ['edit_user'],
		'get_user' => ['edit_reguser'],
		'create_user' => ['add_reguser'],
		'edit_user' => ['edit_reguser'],
		'delete_user' => ['edit_reguser'],
		'disable_user' => ['edit_reguser'],
		'enable_user' => ['edit_reguser'],
		'get_mag' => ['edit_mag'],
		'create_mag' => ['add_mag'],
		'edit_mag' => ['edit_mag'],
		'delete_mag' => ['edit_mag'],
		'disable_mag' => ['edit_mag'],
		'enable_mag' => ['edit_mag'],
		'unban_mag' => ['edit_mag'],
		'ban_mag' => ['edit_mag'],
		'convert_mag' => ['edit_mag'],
		'get_enigma' => ['edit_e2'],
		'create_enigma' => ['add_e2'],
		'edit_enigma' => ['edit_e2'],
		'delete_enigma' => ['edit_e2'],
		'disable_enigma' => ['edit_e2'],
		'enable_enigma' => ['edit_e2'],
		'unban_enigma' => ['edit_e2'],
		'ban_enigma' => ['edit_e2'],
		'convert_enigma' => ['edit_e2'],
		'get_bouquets' => ['bouquets'],
		// Or of its list, where the list answers with the same records.
		'get_bouquet' => ['bouquets', 'edit_bouquet'],
		'create_bouquet' => ['add_bouquet'],
		'edit_bouquet' => ['edit_bouquet'],
		'delete_bouquet' => ['edit_bouquet'],
		'get_access_codes' => ['add_code'],
		'get_access_code' => ['add_code'],
		'create_access_code' => ['add_code'],
		'edit_access_code' => ['add_code'],
		'delete_access_code' => ['add_code'],
		'get_hmacs' => ['add_hmac'],
		'get_hmac' => ['add_hmac'],
		'create_hmac' => ['add_hmac'],
		'edit_hmac' => ['add_hmac'],
		'delete_hmac' => ['add_hmac'],
		'get_epgs' => ['epg'],
		'get_epg' => ['epg', 'epg_edit'],
		'create_epg' => ['add_epg'],
		'edit_epg' => ['epg_edit'],
		'delete_epg' => ['epg_edit'],
		// Every EPG is reloaded with `epg`, one of them with `epg_edit`.
		'reload_epg' => ['epg', 'epg_edit'],
		'get_providers' => ['streams'],
		'get_provider' => ['streams'],
		'create_provider' => ['streams'],
		'edit_provider' => ['streams'],
		'delete_provider' => ['streams'],
		'reload_provider' => ['streams'],
		'get_groups' => ['mng_groups'],
		'get_group' => ['mng_groups', 'edit_group'],
		'create_group' => ['add_group'],
		'edit_group' => ['edit_group'],
		'delete_group' => ['edit_group'],
		'get_packages' => ['mng_packages'],
		'get_package' => ['mng_packages', 'edit_package'],
		'create_package' => ['add_packages'],
		'edit_package' => ['edit_package'],
		'delete_package' => ['edit_package'],
		'get_transcode_profiles' => ['tprofiles'],
		'get_transcode_profile' => ['tprofiles', 'tprofile'],
		'create_transcode_profile' => ['tprofile'],
		'edit_transcode_profile' => ['tprofile'],
		'delete_transcode_profile' => ['tprofiles'],
		'get_rtmp_ips' => ['rtmp'],
		'get_rtmp_ip' => ['rtmp', 'add_rtmp'],
		'create_rtmp_ip' => ['add_rtmp'],
		'edit_rtmp_ip' => ['add_rtmp'],
		'delete_rtmp_ip' => ['add_rtmp'],
		'get_categories' => ['categories'],
		'get_category' => ['categories'],
		'create_category' => ['add_cat'],
		'edit_category' => ['add_cat'],
		'delete_category' => ['edit_cat'],
		'get_blocked_isps' => ['block_isps'],
		'add_blocked_isp' => ['block_isps'],
		'delete_blocked_isp' => ['block_isps'],
		'get_blocked_uas' => ['block_uas'],
		'add_blocked_ua' => ['block_uas'],
		'delete_blocked_ua' => ['block_uas'],
		'get_blocked_ips' => ['block_ips'],
		'add_blocked_ip' => ['block_ips'],
		'delete_blocked_ip' => ['block_ips'],
		'flush_blocked_ips' => ['block_ips'],
		'get_stream' => ['edit_stream'],
		'create_stream' => ['add_stream'],
		'edit_stream' => ['edit_stream'],
		'delete_stream' => ['edit_stream'],
		'start_stream' => ['edit_stream'],
		'stop_stream' => ['edit_stream'],
		'get_channel' => ['edit_cchannel'],
		'create_channel' => ['create_channel'],
		'edit_channel' => ['edit_cchannel'],
		// A created channel or a station is deleted, started and stopped from its
		// row with `edit_stream`, or from the selection with its own permission.
		'delete_channel' => ['edit_cchannel', 'edit_stream'],
		'start_channel' => ['edit_cchannel', 'edit_stream'],
		'stop_channel' => ['edit_cchannel', 'edit_stream'],
		'get_station' => ['edit_radio'],
		'create_station' => ['add_radio'],
		'edit_station' => ['edit_radio'],
		'delete_station' => ['edit_radio', 'edit_stream'],
		'start_station' => ['edit_radio', 'edit_stream'],
		'stop_station' => ['edit_radio', 'edit_stream'],
		'get_movie' => ['edit_movie'],
		'create_movie' => ['add_movie'],
		'edit_movie' => ['edit_movie'],
		'delete_movie' => ['edit_movie'],
		'start_movie' => ['edit_movie'],
		'stop_movie' => ['edit_movie'],
		'get_episode' => ['edit_episode'],
		'create_episode' => ['add_episode'],
		'edit_episode' => ['edit_episode'],
		'delete_episode' => ['edit_episode'],
		'start_episode' => ['edit_episode'],
		'stop_episode' => ['edit_episode'],
		'get_series' => ['edit_series'],
		'create_series' => ['add_series'],
		'edit_series' => ['edit_series'],
		'delete_series' => ['edit_series'],
		'get_servers' => ['servers'],
		'get_server' => ['servers', 'edit_server'],
		'install_server' => ['add_server'],
		'install_proxy' => ['add_server'],
		'edit_server' => ['edit_server'],
		'edit_proxy' => ['edit_server'],
		'delete_server' => ['edit_server'],
		'get_settings' => ['settings'],
		'edit_settings' => ['settings'],
		// The dashboard's figures, or the server view's.
		'get_server_stats' => ['index', 'add_server', 'edit_server'],
		'get_fpm_status' => ['add_server', 'edit_server'],
		'get_rtmp_stats' => ['rtmp'],
		'get_free_space' => ['process_monitor', 'edit_server'],
		'get_pids' => ['process_monitor'],
		'get_certificate_info' => ['servers', 'edit_server'],
		// The panel has no such action: it is one on a server.
		'reload_nginx' => ['edit_server'],
		'clear_temp' => ['process_monitor'],
		'clear_streams' => ['process_monitor'],
		// The file browser of the movie, episode and created channel forms.
		'get_directory' => ['add_episode', 'edit_episode', 'add_movie', 'edit_movie', 'create_channel', 'edit_cchannel'],
		'kill_pid' => ['process_monitor'],
		'kill_connection' => ['connection_logs'],
		'adjust_credits' => ['edit_reguser'],
		'reload_cache' => ['database'],
		'get_active_codes' => ['users', 'mass_edit_lines'],
		'get_active_code' => ['users'],
		'generate_active_codes' => ['add_user'],
		'create_active_code' => ['add_user'],
		// A code is edited with its line, which the panel edits as a line.
		'edit_active_code' => ['edit_user'],
		'delete_active_code' => ['edit_user', 'mass_edit_lines'],
		'disable_active_code' => ['edit_user', 'mass_edit_lines'],
		'enable_active_code' => ['edit_user', 'mass_edit_lines'],
		'reset_active_code_device' => ['edit_user', 'mass_edit_lines'],
		'mass_active_codes' => ['edit_user', 'mass_edit_lines'],
		'get_active_codes_batches' => ['users'],
		'export_active_code_batch' => ['users'],
		'check_active_code' => ['users'],
	];

	/**
	 * Whether the key may run an action: its group lists one of the permissions
	 * the action asks for (ACTION_PERMISSIONS).
	 */
	public static function permitted(string $rAction): bool {
		// A token runs only what its scope allows (ApiTokens::allows()).
		if (!ApiTokens::allows($rAction)) {
			return false;
		}
		$rAsked = self::ACTION_PERMISSIONS[$rAction] ?? [];
		foreach ($rAsked as $rPermission) {
			if (Authorization::check('adv', $rPermission)) {
				return true;
			}
		}
		return count($rAsked) == 0;
	}

	public function index() {
		global $db;
		global $_ERRORS;

		$_ERRORS = [];
		foreach (get_defined_constants(true)['user'] as $rKey => $rValue) {
			if (substr($rKey, 0, 7) == 'STATUS_') {
				$_ERRORS[intval($rValue)] = $rKey;
			}
		}
		$rData = RequestManager::getAll();
		AdminAPIWrapper::$db = &$db;
		AdminAPIWrapper::$rKey = $rData['api_key'] ?? '';
		if (!empty($rData['api_key']) && AdminAPIWrapper::createSession()) {
			$rAction = $rData['action'] ?? '';
			$rStart = intval($rData['start'] ?? 0);
			$rLimit = intval($rData['limit'] ?? 50) ?: 50;
			unset($rData['api_key'], $rData['action'], $rData['start'], $rData['limit']);
			if (RequestManager::has('show_columns')) {
				$rShowColumns = explode(',', RequestManager::get('show_columns'));
			} else {
				$rShowColumns = null;
			}
			if (RequestManager::has('hide_columns')) {
				$rHideColumns = explode(',', RequestManager::get('hide_columns'));
			} else {
				$rHideColumns = null;
			}
			// The admin action trail: every action but a read, refused ones too.
			if (!ApiTokens::isRead($rAction)) {
				AdminAudit::start('api', (string) $rAction, $GLOBALS['rAdminUserInfo'] ?? [], $rData);
			}
			// An action runs with a permission of the key's group (ACTION_PERMISSIONS).
			if (!self::permitted($rAction)) {
				echo json_encode(['status' => 'STATUS_NO_PERMISSIONS']);
				return;
			}
			switch ($rAction) {
				case 'mysql_query':
					echo json_encode(AdminAPIWrapper::runQuery($rData['query']));
					break;
				case 'user_info':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getUserInfo(), $rShowColumns, $rHideColumns));
					break;
				case 'get_lines':
					echo json_encode(AdminAPIWrapper::TableAPI('lines', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_mags':
					echo json_encode(AdminAPIWrapper::TableAPI('mags', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_enigmas':
					echo json_encode(AdminAPIWrapper::TableAPI('enigmas', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_users':
					echo json_encode(AdminAPIWrapper::TableAPI('reg_users', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_streams':
					echo json_encode(AdminAPIWrapper::TableAPI('streams', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_provider_streams':
					echo json_encode(AdminAPIWrapper::TableAPI('provider_streams', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_channels':
					$rData['created'] = true;
					echo json_encode(AdminAPIWrapper::TableAPI('streams', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_stations':
					echo json_encode(AdminAPIWrapper::TableAPI('radios', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_movies':
					echo json_encode(AdminAPIWrapper::TableAPI('movies', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_series_list':
					echo json_encode(AdminAPIWrapper::TableAPI('series', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_episodes':
					echo json_encode(AdminAPIWrapper::TableAPI('episodes', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'activity_logs':
					echo json_encode(AdminAPIWrapper::TableAPI('line_activity', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'live_connections':
					echo json_encode(AdminAPIWrapper::TableAPI('live_connections', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'credit_logs':
					echo json_encode(AdminAPIWrapper::TableAPI('credits_log', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'client_logs':
					echo json_encode(AdminAPIWrapper::TableAPI('client_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'user_logs':
					echo json_encode(AdminAPIWrapper::TableAPI('reg_user_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'stream_errors':
					echo json_encode(AdminAPIWrapper::TableAPI('stream_errors', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'system_logs':
					echo json_encode(AdminAPIWrapper::TableAPI('mysql_syslog', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'login_logs':
					echo json_encode(AdminAPIWrapper::TableAPI('login_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'restream_logs':
					echo json_encode(AdminAPIWrapper::TableAPI('restream_logs', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'mag_events':
					echo json_encode(AdminAPIWrapper::TableAPI('mag_events', $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_line':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getLine($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_line':
					echo json_encode(AdminAPIWrapper::createLine($rData));
					break;
				case 'edit_line':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editLine($rID, $rData));
					break;
				case 'delete_line':
					echo json_encode(AdminAPIWrapper::deleteLine($rData['id']));
					break;
				case 'disable_line':
					echo json_encode(AdminAPIWrapper::disableLine($rData['id']));
					break;
				case 'enable_line':
					echo json_encode(AdminAPIWrapper::enableLine($rData['id']));
					break;
				case 'unban_line':
					echo json_encode(AdminAPIWrapper::unbanLine($rData['id']));
					break;
				case 'ban_line':
					echo json_encode(AdminAPIWrapper::banLine($rData['id']));
					break;
				case 'get_user':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getUser($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_user':
					echo json_encode(AdminAPIWrapper::createUser($rData));
					break;
				case 'edit_user':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editUser($rID, $rData));
					break;
				case 'delete_user':
					echo json_encode(AdminAPIWrapper::deleteUser($rData['id']));
					break;
				case 'disable_user':
					echo json_encode(AdminAPIWrapper::disableUser($rData['id']));
					break;
				case 'enable_user':
					echo json_encode(AdminAPIWrapper::enableUser($rData['id']));
					break;
				case 'get_mag':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getMAG($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_mag':
					echo json_encode(AdminAPIWrapper::createMAG($rData));
					break;
				case 'edit_mag':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editMAG($rID, $rData));
					break;
				case 'delete_mag':
					echo json_encode(AdminAPIWrapper::deleteMAG($rData['id']));
					break;
				case 'disable_mag':
					echo json_encode(AdminAPIWrapper::disableMAG($rData['id']));
					break;
				case 'enable_mag':
					echo json_encode(AdminAPIWrapper::enableMAG($rData['id']));
					break;
				case 'unban_mag':
					echo json_encode(AdminAPIWrapper::unbanMAG($rData['id']));
					break;
				case 'ban_mag':
					echo json_encode(AdminAPIWrapper::banMAG($rData['id']));
					break;
				case 'convert_mag':
					echo json_encode(AdminAPIWrapper::convertMAG($rData['id']));
					break;
				case 'get_enigma':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getEnigma($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_enigma':
					echo json_encode(AdminAPIWrapper::createEnigma($rData));
					break;
				case 'edit_enigma':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editEnigma($rID, $rData));
					break;
				case 'delete_enigma':
					echo json_encode(AdminAPIWrapper::deleteEnigma($rData['id']));
					break;
				case 'disable_enigma':
					echo json_encode(AdminAPIWrapper::disableEnigma($rData['id']));
					break;
				case 'enable_enigma':
					echo json_encode(AdminAPIWrapper::enableEnigma($rData['id']));
					break;
				case 'unban_enigma':
					echo json_encode(AdminAPIWrapper::unbanEnigma($rData['id']));
					break;
				case 'ban_enigma':
					echo json_encode(AdminAPIWrapper::banEnigma($rData['id']));
					break;
				case 'convert_enigma':
					echo json_encode(AdminAPIWrapper::convertEnigma($rData['id']));
					break;
				case 'get_bouquets':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getBouquets(), $rShowColumns, $rHideColumns));
					break;
				case 'get_bouquet':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getBouquet($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_bouquet':
					echo json_encode(AdminAPIWrapper::createBouquet($rData));
					break;
				case 'edit_bouquet':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editBouquet($rID, $rData));
					break;
				case 'delete_bouquet':
					echo json_encode(AdminAPIWrapper::deleteBouquet($rData['id']));
					break;
				case 'get_access_codes':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getAccessCodes(), $rShowColumns, $rHideColumns));
					break;
				case 'get_access_code':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getAccessCode($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_access_code':
					echo json_encode(AdminAPIWrapper::createAccessCode($rData));
					break;
				case 'edit_access_code':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editAccessCode($rID, $rData));
					break;
				case 'delete_access_code':
					echo json_encode(AdminAPIWrapper::deleteAccessCode($rData['id']));
					break;
				case 'get_hmacs':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getHMACs(), $rShowColumns, $rHideColumns));
					break;
				case 'get_hmac':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getHMAC($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_hmac':
					echo json_encode(AdminAPIWrapper::createHMAC($rData));
					break;
				case 'edit_hmac':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editHMAC($rID, $rData));
					break;
				case 'delete_hmac':
					echo json_encode(AdminAPIWrapper::deleteHMAC($rData['id']));
					break;
				case 'get_epgs':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getEPGs(), $rShowColumns, $rHideColumns));
					break;
				case 'get_epg':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getEPG($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_epg':
					echo json_encode(AdminAPIWrapper::createEPG($rData));
					break;
				case 'edit_epg':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editEPG($rID, $rData));
					break;
				case 'delete_epg':
					echo json_encode(AdminAPIWrapper::deleteEPG($rData['id']));
					break;
				case 'reload_epg':
					echo json_encode(AdminAPIWrapper::reloadEPG((isset($rData['id']) ? intval($rData['id']) : null)));
					break;
				case 'get_providers':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getProviders(), $rShowColumns, $rHideColumns));
					break;
				case 'get_provider':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getProvider($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_provider':
					echo json_encode(AdminAPIWrapper::createProvider($rData));
					break;
				case 'edit_provider':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editProvider($rID, $rData));
					break;
				case 'delete_provider':
					echo json_encode(AdminAPIWrapper::deleteProvider($rData['id']));
					break;
				case 'reload_provider':
					echo json_encode(AdminAPIWrapper::reloadProvider((isset($rData['id']) ? intval($rData['id']) : null)));
					break;
				case 'get_groups':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getGroups(), $rShowColumns, $rHideColumns));
					break;
				case 'get_group':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getGroup($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_group':
					echo json_encode(AdminAPIWrapper::createGroup($rData));
					break;
				case 'edit_group':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editGroup($rID, $rData));
					break;
				case 'delete_group':
					echo json_encode(AdminAPIWrapper::deleteGroup($rData['id']));
					break;
				case 'get_packages':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getPackages(), $rShowColumns, $rHideColumns));
					break;
				case 'get_package':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getPackage($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_package':
					echo json_encode(AdminAPIWrapper::createPackage($rData));
					break;
				case 'edit_package':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editPackage($rID, $rData));
					break;
				case 'delete_package':
					echo json_encode(AdminAPIWrapper::deletePackage($rData['id']));
					break;
				case 'get_transcode_profiles':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getTranscodeProfiles(), $rShowColumns, $rHideColumns));
					break;
				case 'get_transcode_profile':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getTranscodeProfile($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_transcode_profile':
					echo json_encode(AdminAPIWrapper::createTranscodeProfile($rData));
					break;
				case 'edit_transcode_profile':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editTranscodeProfile($rID, $rData));
					break;
				case 'delete_transcode_profile':
					echo json_encode(AdminAPIWrapper::deleteTranscodeProfile($rData['id']));
					break;
				case 'get_rtmp_ips':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getRTMPIPs(), $rShowColumns, $rHideColumns));
					break;
				case 'get_rtmp_ip':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getRTMPIP($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_rtmp_ip':
					echo json_encode(AdminAPIWrapper::addRTMPIP($rData));
					break;
				case 'edit_rtmp_ip':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editRTMPIP($rID, $rData));
					break;
				case 'delete_rtmp_ip':
					echo json_encode(AdminAPIWrapper::deleteRTMPIP($rData['id']));
					break;
				case 'get_categories':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getCategories(), $rShowColumns, $rHideColumns));
					break;
				case 'get_category':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getCategory($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_category':
					echo json_encode(AdminAPIWrapper::createCategory($rData));
					break;
				case 'edit_category':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editCategory($rID, $rData));
					break;
				case 'delete_category':
					echo json_encode(AdminAPIWrapper::deleteCategory($rData['id']));
					break;
				case 'get_blocked_isps':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getBlockedISPs(), $rShowColumns, $rHideColumns));
					break;
				case 'add_blocked_isp':
					echo json_encode(AdminAPIWrapper::addBlockedISP($rData['id']));
					break;
				case 'delete_blocked_isp':
					echo json_encode(AdminAPIWrapper::deleteBlockedISP($rData['id']));
					break;
				case 'get_blocked_uas':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getBlockedUAs(), $rShowColumns, $rHideColumns));
					break;
				case 'add_blocked_ua':
					echo json_encode(AdminAPIWrapper::addBlockedUA($rData));
					break;
				case 'delete_blocked_ua':
					echo json_encode(AdminAPIWrapper::deleteBlockedUA($rData['id']));
					break;
				case 'get_blocked_ips':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getBlockedIPs(), $rShowColumns, $rHideColumns));
					break;
				case 'add_blocked_ip':
					echo json_encode(AdminAPIWrapper::addBlockedIP($rData['id']));
					break;
				case 'delete_blocked_ip':
					echo json_encode(AdminAPIWrapper::deleteBlockedIP($rData['id']));
					break;
				case 'flush_blocked_ips':
					echo json_encode(AdminAPIWrapper::flushBlockedIPs());
					break;
				case 'get_stream':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getStream($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_stream':
					echo json_encode(AdminAPIWrapper::createStream($rData));
					break;
				case 'edit_stream':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editStream($rID, $rData));
					break;
				case 'delete_stream':
					echo json_encode(AdminAPIWrapper::deleteStream($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
					break;
				case 'start_station':
				case 'start_channel':
				case 'start_stream':
					echo json_encode(AdminAPIWrapper::startStream($rData['id'], $rData['server_id']));
					break;
				case 'stop_station':
				case 'stop_channel':
				case 'stop_stream':
					echo json_encode(AdminAPIWrapper::stopStream($rData['id'], $rData['server_id']));
					break;
				case 'get_channel':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getChannel($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_channel':
					echo json_encode(AdminAPIWrapper::createChannel($rData));
					break;
				case 'edit_channel':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editChannel($rID, $rData));
					break;
				case 'delete_channel':
					echo json_encode(AdminAPIWrapper::deleteChannel($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
					break;
				case 'get_station':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getStation($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_station':
					echo json_encode(AdminAPIWrapper::createStation($rData));
					break;
				case 'edit_station':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editStation($rID, $rData));
					break;
				case 'delete_station':
					echo json_encode(AdminAPIWrapper::deleteStation($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
					break;
				case 'get_movie':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getMovie($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_movie':
					echo json_encode(AdminAPIWrapper::createMovie($rData));
					break;
				case 'edit_movie':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editMovie($rID, $rData));
					break;
				case 'delete_movie':
					echo json_encode(AdminAPIWrapper::deleteMovie($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
					break;
				case 'start_episode':
				case 'start_movie':
					echo json_encode(AdminAPIWrapper::startMovie($rData['id'], $rData['server_id']));
					break;
				case 'stop_episode':
				case 'stop_movie':
					echo json_encode(AdminAPIWrapper::stopMovie($rData['id'], $rData['server_id']));
					break;
				case 'get_episode':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getEpisode($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_episode':
					echo json_encode(AdminAPIWrapper::createEpisode($rData));
					break;
				case 'edit_episode':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editEpisode($rID, $rData));
					break;
				case 'delete_episode':
					echo json_encode(AdminAPIWrapper::deleteEpisode($rData['id'], (isset($rData['server_id']) ? $rData['server_id'] : -1)));
					break;
				case 'get_series':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getSeries($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'create_series':
					echo json_encode(AdminAPIWrapper::createSeries($rData));
					break;
				case 'edit_series':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editSeries($rID, $rData));
					break;
				case 'delete_series':
					echo json_encode(AdminAPIWrapper::deleteSeries($rData['id']));
					break;
				case 'get_servers':
					echo json_encode(AdminAPIWrapper::filterRows(AdminAPIWrapper::getServers(), $rShowColumns, $rHideColumns));
					break;
				case 'get_server':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getServer($rData['id']), $rShowColumns, $rHideColumns));
					break;
				case 'install_server':
					// The install command's type of a load balancer; 1 is a proxy.
					$rData['type'] = 2;
					echo json_encode(AdminAPIWrapper::installServer($rData));
					break;
				case 'install_proxy':
					$rData['type'] = 1;
					echo json_encode(AdminAPIWrapper::installServer($rData));
					break;
				case 'edit_server':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editServer($rID, $rData));
					break;
				case 'edit_proxy':
					$rID = $rData['id'];
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editProxy($rID, $rData));
					break;
				case 'delete_server':
					echo json_encode(AdminAPIWrapper::deleteServer($rData['id']));
					break;
				case 'get_settings':
					echo json_encode(AdminAPIWrapper::filterRow(AdminAPIWrapper::getSettings(), $rShowColumns, $rHideColumns));
					break;
				case 'edit_settings':
					echo json_encode(AdminAPIWrapper::editSettings($rData));
					break;
				case 'get_server_stats':
					echo json_encode(AdminAPIWrapper::getStats((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
					break;
				case 'get_fpm_status':
					echo json_encode(AdminAPIWrapper::getFPMStatus((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
					break;
				case 'get_rtmp_stats':
					echo json_encode(AdminAPIWrapper::getRTMPStats((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
					break;
				case 'get_free_space':
					echo json_encode(AdminAPIWrapper::getFreeSpace((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
					break;
				case 'get_pids':
					echo json_encode(AdminAPIWrapper::getPIDs((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
					break;
				case 'get_certificate_info':
					echo json_encode(AdminAPIWrapper::getCertificateInfo((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
					break;
				case 'reload_nginx':
					echo json_encode(AdminAPIWrapper::reloadNGINX((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
					break;
				case 'clear_temp':
					echo json_encode(AdminAPIWrapper::clearTemp((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
					break;
				case 'clear_streams':
					echo json_encode(AdminAPIWrapper::clearStreams((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID)));
					break;
				case 'get_directory':
					echo json_encode(AdminAPIWrapper::getDirectory((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['dir']));
					break;
				case 'kill_pid':
					echo json_encode(AdminAPIWrapper::killPID((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['pid']));
					break;
				case 'kill_connection':
					echo json_encode(AdminAPIWrapper::killConnection((isset($rData['server_id']) ? $rData['server_id'] : SERVER_ID), $rData['activity_id']));
					break;
				case 'adjust_credits':
					echo json_encode(AdminAPIWrapper::adjustCredits($rData['id'], $rData['credits'], (isset($rData['reason']) ? $rData['reason'] : '')));
					break;
				case 'reload_cache':
					echo json_encode(AdminAPIWrapper::reloadCache());
					break;
				case 'get_active_codes':
					echo json_encode(AdminAPIWrapper::getActiveCodes($rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
					break;
				case 'get_active_code':
					echo json_encode(AdminAPIWrapper::getActiveCode($rData['id'] ?? $rData['code'] ?? 0));
					break;
				case 'generate_active_codes':
				case 'create_active_code':
					echo json_encode(AdminAPIWrapper::generateActiveCodes($rData));
					break;
				case 'edit_active_code':
					$rID = $rData['id'] ?? 0;
					unset($rData['id']);
					echo json_encode(AdminAPIWrapper::editActiveCode($rID, $rData));
					break;
				case 'delete_active_code':
					echo json_encode(AdminAPIWrapper::deleteActiveCode($rData['id'] ?? 0));
					break;
				case 'disable_active_code':
					echo json_encode(AdminAPIWrapper::disableActiveCode($rData['id'] ?? 0));
					break;
				case 'enable_active_code':
					echo json_encode(AdminAPIWrapper::enableActiveCode($rData['id'] ?? 0));
					break;
				case 'reset_active_code_device':
					echo json_encode(AdminAPIWrapper::resetActiveCodeDevice($rData['id'] ?? $rData['code'] ?? 0));
					break;
				case 'mass_active_codes':
					echo json_encode(AdminAPIWrapper::massActiveCodes($rData['sub_action'] ?? $rData['action_type'] ?? '', $rData['ids'] ?? [], $rData));
					break;
				case 'get_active_codes_batches':
					echo json_encode(AdminAPIWrapper::getActiveCodesBatches($rData['batch_name'] ?? null));
					break;
				case 'export_active_code_batch':
					echo json_encode(AdminAPIWrapper::exportActiveCodeBatch($rData['batch_name'] ?? '', $rData['format'] ?? 'json'));
					break;
				case 'check_active_code':
					echo json_encode(AdminAPIWrapper::checkActiveCode($rData['code'] ?? ''));
					break;
				default:
					// Module actions (AdminApiRegistry) and module-owned serverSide tables
					// (TableRegistry) are exposed generically, so a module needs no case here.
					if ($rModuleAction = \XcVm\Core\Module\AdminApiRegistry::get($rAction)) {
						echo json_encode(AdminAPIWrapper::moduleAction($rModuleAction, $rData, $rShowColumns, $rHideColumns));
						break;
					}
					if (class_exists(\XcVm\Core\Module\TableRegistry::class) && \XcVm\Core\Module\TableRegistry::has($rAction)) {
						echo json_encode(AdminAPIWrapper::TableAPI($rAction, $rStart, $rLimit, $rData, $rShowColumns, $rHideColumns));
						break;
					}
					echo json_encode(['status' => 'STATUS_FAILURE', 'error' => 'Invalid action.']);
					break;
			}
		} else {
			echo json_encode(['status' => 'STATUS_FAILURE', 'error' => 'Invalid API key.']);
		}
	}

	public function shutdown() {
		global $db;
		if (is_object($db)) {
			$db->close_mysql();
		}
	}
}
