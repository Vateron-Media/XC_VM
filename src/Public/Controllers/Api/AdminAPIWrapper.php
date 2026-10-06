<?php

namespace XcVm\Public\Controllers\Api;

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Auth\AuthService;
use XcVm\Core\Cluster\NodeRpc;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Security\BlocklistService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Server\ServerService;
use XcVm\Domain\Server\SettingsService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ChannelService;
use XcVm\Domain\Stream\ProfileService;
use XcVm\Domain\Stream\ProviderService;
use XcVm\Domain\Stream\RadioService;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Domain\Stream\StreamRepository;
use XcVm\Domain\Stream\StreamService;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;
use XcVm\Domain\Vod\EpisodeService;
use XcVm\Domain\Vod\MovieService;
use XcVm\Domain\Vod\SeriesService;
use XcVm\Public\Controllers\Admin\TableController;

class AdminAPIWrapper {
	public static $db;

	public static $rKey;

	public static function filterRow($rData, $rShow, $rHide, $rSkipResult = false) {
		if ($rShow || $rHide) {
			if ($rSkipResult) {
				$rRow = $rData;
			} else {
				$rRow = $rData['data'];
			}
			$rReturn = [];
			if ($rRow) {
				foreach (array_keys($rRow) as $rKey) {
					if ($rShow) {
						if (in_array($rKey, $rShow)) {
							$rReturn[$rKey] = $rRow[$rKey];
						}
					} else {
						if ($rHide) {
							if (!in_array($rKey, $rHide)) {
								$rReturn[$rKey] = $rRow[$rKey];
							}
						}
					}
				}
			}
			if ($rSkipResult) {
				return $rReturn;
			}
			$rData['data'] = $rReturn;
			return $rData;
		}
		return $rData;
	}

	public static function filterRows($rRows, $rShow, $rHide) {
		$rReturn = [];
		if ($rRows['data']) {
			foreach ($rRows['data'] as $rRow) {
				$rReturn[] = self::filterRow($rRow, $rShow, $rHide, true);
			}
		}
		return $rReturn;
	}

	public static function TableAPI($rID, $rStart = 0, $rLimit = 10, $rData = [], $rShowColumns = [], $rHideColumns = []) {
		// Historically this proxied over HTTP to `<code>/table.php` on the broadcast
		// port. That path is dead under the Front Controller: `dirname(PHP_SELF)` now
		// resolves to `/public`, nginx 404s every `*.php`, and — crucially — the
		// api-scope access code routes *every* path back to AdminApiController (never
		// TableController), so the round-trip could only ever return `null`.
		//
		// Dispatch the DataTables handler in-process instead. TableController::index()
		// authenticates via `api_key`, renders the JSON for `$rID`, echoes it and
		// exit()s — so this method emits the response directly and never returns.
		$rData['api_key'] = self::$rKey;
		$rData['id'] = $rID;
		$rData['start'] = $rStart;
		$rData['length'] = $rLimit;
		$rData['show_columns'] = $rShowColumns;
		$rData['hide_columns'] = $rHideColumns;
		$rData['draw'] = 0;

		RequestManager::set(array_merge(RequestManager::getAll(), $rData));
		$_SERVER['HTTP_X_REQUESTED_WITH'] = 'xmlhttprequest';

		(new TableController())->index();
		return null; // unreachable: TableController::index() exit()s after echoing
	}

	public static function createSession() {
		global $rUserInfo;
		global $rPermissions;
		self::$db->query('SELECT * FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` WHERE `api_key` = ? AND LENGTH(`api_key`) > 0 AND `is_admin` = 1 AND `status` = 1;', self::$rKey);
		if (0 >= self::$db->num_rows()) {
			return false;
		}
		$rUserID = self::$db->get_row()['id'];
		$GLOBALS['rAdminUserInfo'] = UserRepository::getRegisteredUserById($rUserID);
		unset($GLOBALS['rAdminUserInfo']['password']);
		$rUserInfo = $GLOBALS['rAdminUserInfo'];
		$rPermissions = AuthRepository::getPermissions($rUserInfo['member_group_id']);
		// A key acts with the permissions its group lists, as its holder does in
		// the panel (and as TableController applies them to the API's tables).
		$rPermissions['advanced'] = json_decode((string) $rPermissions['allowed_pages'], true) ?: [];
		if ((string) $rUserInfo['timezone'] !== '') {
			date_default_timezone_set($rUserInfo['timezone']);
		}
		return true;
	}

	public static function getUserInfo() {
		global $rUserInfo;
		global $rPermissions;
		return ['status' => 'STATUS_SUCCESS', 'data' => $rUserInfo, 'permissions' => $rPermissions];
	}

	public static function getLine($rID) {
		if (!($rLine = UserRepository::getLineById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rLine];
	}

	public static function createLine($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(LineService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getLine($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editLine($rID, $rData) {
		if (!($rLine = self::getLine($rID)) || !isset($rLine['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		if (isset($rData['isp_clear'])) {
			$rData['isp_clear'] = '';
		}
		$rStored = self::storedRow('SELECT * FROM `lines` WHERE `id` = ?;', $rID);
		// A request that leaves out the username or the password keeps the line's own.
		$rData += ['username' => $rStored['username'], 'password' => $rStored['password']];
		$rReturn = parseerror(LineService::process(self::keepLineFields($rData, $rStored)));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getLine($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	/**
	 * LineService::process() takes the whole line form, where a field that is
	 * not posted is switched off or emptied. An edit through the API keeps
	 * every field the request leaves out: each is added here from the stored
	 * line, in the shape the form posts it. A request clears a field by sending
	 * it empty; a switch is off at 0 as well.
	 *
	 * @param array $rData The request.
	 * @param array $rLine The line as stored.
	 * @return array The request with the fields it left out.
	 */
	private static function keepLineFields(array $rData, array $rLine) {
		$rList = static fn($rJSON) => is_array($rDecoded = json_decode((string) $rJSON, true)) ? $rDecoded : [];

		// `isp_clear` empties the stored ISP when it is posted empty.
		$rData += [
			'max_connections' => $rLine['max_connections'],
			'enabled' => $rLine['enabled'],
			'admin_enabled' => $rLine['admin_enabled'],
			'isp_clear' => '1',
			'bouquets_selected' => json_encode($rList($rLine['bouquet'])),
			'allowed_ips' => $rList($rLine['allowed_ips']),
			'allowed_ua' => $rList($rLine['allowed_ua']),
			'access_output' => $rList($rLine['allowed_outputs']),
		];

		// A list sent empty is the empty list.
		foreach (['bouquets_selected' => '[]', 'allowed_ips' => [], 'allowed_ua' => [], 'access_output' => []] as $rKey => $rEmpty) {
			if ($rData[$rKey] === '') {
				$rData[$rKey] = $rEmpty;
			}
		}

		// The form posts a switch only when it is on.
		foreach (['is_stalker', 'is_restreamer', 'is_trial', 'is_isplock', 'bypass_ua'] as $rKey) {
			$rOn = !empty($rData[$rKey] ?? $rLine[$rKey]);
			unset($rData[$rKey]);
			if ($rOn) {
				$rData[$rKey] = 1;
			}
		}

		// `no_expire` counts when it is on, as the form's checkbox does.
		if (empty($rData['no_expire'])) {
			unset($rData['no_expire']);
		}

		// A request without a date is read as no expiry: left out, the date is
		// the stored one; sent empty, there is none.
		if (!isset($rData['exp_date']) && !is_null($rLine['exp_date'])) {
			$rData['exp_date'] = '@' . intval($rLine['exp_date']);
		} elseif (($rData['exp_date'] ?? null) === '') {
			unset($rData['exp_date']);
		}

		return $rData;
	}

	/**
	 * The first row of a query as stored: get_raw_row(), not get_row(), which
	 * rewrites text holding < or >.
	 *
	 * @return array The row, empty when there is none.
	 */
	private static function storedRow(string $rQuery, ...$rArgs) {
		self::$db->query($rQuery, ...$rArgs);
		return self::$db->get_raw_row() ?? [];
	}

	/**
	 * The rows of a query as stored (storedRow()).
	 *
	 * @return array[]
	 */
	private static function storedRows(string $rQuery, ...$rArgs) {
		self::$db->query($rQuery, ...$rArgs);
		return self::$db->get_raw_rows();
	}

	/**
	 * A stored JSON list (or map), decoded; empty when it holds none.
	 */
	private static function storedList($rJSON) {
		return is_array($rDecoded = json_decode((string) $rJSON, true)) ? $rDecoded : [];
	}

	/**
	 * An edit through the API keeps every field the request leaves out, as
	 * keepLineFields() does for a line: the services take the whole form of
	 * the panel, where a field that is not posted is switched off, emptied or
	 * given its default.
	 *
	 * @param array $rData     The request.
	 * @param array $rKept     Each field the request may leave out => its stored value, as the form posts it.
	 * @param array $rSwitches Each switch => its stored value. The form posts a switch only when it is
	 *                         on; a request switches one off by sending 0 or nothing.
	 * @param array $rEmpty    Each list => what it is when the request sends it empty.
	 * @return array The request with the fields it left out.
	 */
	private static function keepFields(array $rData, array $rKept, array $rSwitches = [], array $rEmpty = []) {
		$rData += $rKept;
		foreach ($rEmpty as $rKey => $rValue) {
			if (($rData[$rKey] ?? null) === '') {
				$rData[$rKey] = $rValue;
			}
		}
		foreach ($rSwitches as $rKey => $rStored) {
			$rOn = !empty($rData[$rKey] ?? $rStored);
			unset($rData[$rKey]);
			if ($rOn) {
				$rData[$rKey] = 1;
			}
		}
		return $rData;
	}

	/**
	 * keepFields() for a MAG or an Enigma2 device: its own row and its line's.
	 *
	 * @param array $rData   The request.
	 * @param array $rDevice The device as stored.
	 * @param array $rLine   Its line as stored.
	 * @return array
	 */
	private static function keepDeviceFields(array $rData, array $rDevice, array $rLine) {
		// The line's credentials as stored: the service starts from the cleaned row.
		$rKept = ['mac' => $rDevice['mac'], 'username' => $rLine['username'] ?? '', 'password' => $rLine['password'] ?? '', 'isp_clear' => '1', 'bouquets_selected' => json_encode(self::storedList($rLine['bouquet'] ?? '')), 'allowed_ips' => self::storedList($rLine['allowed_ips'] ?? '')];
		if (!is_null($rLine['pair_id'] ?? null)) {
			$rKept['pair_id'] = $rLine['pair_id'];
		}
		// `no_expire` counts when it is on; a date left out is the stored one,
		// one sent empty is none (as keepLineFields()).
		if (empty($rData['no_expire'])) {
			unset($rData['no_expire']);
		}
		if (!isset($rData['exp_date']) && !is_null($rLine['exp_date'] ?? null)) {
			$rData['exp_date'] = '@' . intval($rLine['exp_date']);
		} elseif (($rData['exp_date'] ?? null) === '') {
			unset($rData['exp_date']);
		}
		return self::keepFields($rData, $rKept, ['is_trial' => $rLine['is_trial'] ?? 0, 'is_isplock' => $rLine['is_isplock'] ?? 0, 'lock_device' => $rDevice['lock_device']], ['bouquets_selected' => '[]', 'allowed_ips' => []]);
	}

	/**
	 * The ids of the bouquets whose list of this kind holds an item.
	 *
	 * @param string $rColumn bouquet_channels, bouquet_movies, bouquet_radios or bouquet_series.
	 * @param int    $rID     The stream or series.
	 * @return int[]
	 */
	private static function storedBouquets(string $rColumn, $rID) {
		$rIDs = [];
		foreach (self::storedRows('SELECT `id`, `' . $rColumn . '` AS `items` FROM `bouquets`;') as $rRow) {
			if (in_array(intval($rID), array_map('intval', self::storedList($rRow['items'])), true)) {
				$rIDs[] = intval($rRow['id']);
			}
		}
		return $rIDs;
	}

	/**
	 * keepFields() for the fields every stream form shares: its categories and
	 * bouquets, its servers (the tree the form posts, and on demand), and, for a
	 * live kind, its restart schedule and the source options.
	 *
	 * @param array  $rData   The request.
	 * @param array  $rStream The stream as stored.
	 * @param ?string $rColumn The bouquets' list of its kind (none for an episode).
	 * @param bool   $rLive   Whether the form has the restart schedule and the source options.
	 * @return array
	 */
	private static function keepStreamFields(array $rData, array $rStream, ?string $rColumn, bool $rLive) {
		$rTree = $rOnDemand = [];
		foreach (self::storedRows('SELECT `server_id`, `parent_id`, `on_demand` FROM `streams_servers` WHERE `stream_id` = ?;', $rStream['id']) as $rRow) {
			$rTree[] = ['id' => intval($rRow['server_id']), 'parent' => $rRow['parent_id'] ? intval($rRow['parent_id']) : 'source'];
			if ($rRow['on_demand']) {
				$rOnDemand[] = intval($rRow['server_id']);
			}
		}
		$rKept = ['category_id' => self::storedList($rStream['category_id']), 'bouquets' => $rColumn ? self::storedBouquets($rColumn, $rStream['id']) : [], 'server_tree_data' => json_encode($rTree), 'on_demand' => $rOnDemand];
		$rEmpty = ['category_id' => [], 'bouquets' => [], 'on_demand' => []];
		$rSwitches = [];
		if ($rLive) {
			$rRestart = self::storedList($rStream['auto_restart']);
			if (!empty($rRestart['days'])) {
				$rKept += ['days_to_restart' => $rRestart['days'], 'time_to_restart' => $rRestart['at'] ?? ''];
			}
			$rEmpty['days_to_restart'] = [];
			$rOptions = array_column(self::storedRows('SELECT `argument_id`, `value` FROM `streams_options` WHERE `stream_id` = ?;', $rStream['id']), 'value', 'argument_id');
			foreach ([1 => 'user_agent', 2 => 'http_proxy', 17 => 'cookie', 19 => 'headers', 20 => 'force_input_acodec'] as $rArgument => $rKey) {
				if (isset($rOptions[$rArgument])) {
					$rKept[$rKey] = $rOptions[$rArgument];
				}
			}
			$rSwitches['skip_ffprobe'] = $rOptions[21] ?? 0;
		}
		return self::keepFields($rData, $rKept, $rSwitches, $rEmpty);
	}

	/**
	 * A movie's or an episode's subtitles as the form posts them: s:<server>:<path>.
	 */
	private static function subtitlesField($rJSON) {
		$rSubtitles = self::storedList($rJSON);
		return isset($rSubtitles['location']) ? 's:' . $rSubtitles['location'] . ':' . ($rSubtitles['files'][0] ?? '') : '';
	}

	/**
	 * keepFields() for an access code.
	 *
	 * @param array $rData The request.
	 * @param array $rCode The code as stored.
	 * @return array
	 */
	private static function keepCodeFields(array $rData, array $rCode) {
		return self::keepFields($rData, ['code' => $rCode['code'], 'type' => $rCode['type'], 'whitelist' => self::storedList($rCode['whitelist'])], ['enabled' => $rCode['enabled']], ['whitelist' => []]);
	}

	/**
	 * keepFields() for a transcode profile: ProfileService::process() builds
	 * the profile's options from the form alone, so each field the request
	 * leaves out is read back from them, as the profile form shows them.
	 *
	 * @param array $rData    The request.
	 * @param array $rProfile The profile as stored.
	 * @return array
	 */
	private static function keepProfileFields(array $rData, array $rProfile) {
		$rOptions = self::storedList($rProfile['profile_options'] ?? '');
		$rGPU = $rOptions['gpu'] ?? [];
		$rValue = static fn($rIndex) => $rOptions[$rIndex]['val'] ?? '';
		$rKept = [
			'profile_name' => $rProfile['profile_name'] ?? '',
			'gpu_device' => $rGPU['val'] ?? 0,
			'software_decoding' => $rOptions['software_decoding'] ?? 0,
			'resize' => $rGPU['resize'] ?? '',
			'deint' => $rGPU['deint'] ?? 0,
			'video_codec_gpu' => $rGPU ? ($rOptions['-vcodec'] ?? '') : '',
			'video_codec_cpu' => $rGPU ? '' : ($rOptions['-vcodec'] ?? ''),
			'audio_codec' => $rOptions['-acodec'] ?? '',
			'scaling' => $rGPU ? '' : $rValue(9),
			'logo_path' => $rValue(16),
			'logo_pos' => $rOptions[16]['pos'] ?? '',
		];
		foreach (['video_bitrate' => 3, 'audio_bitrate' => 4, 'min_tolerance' => 5, 'max_tolerance' => 6, 'buffer_size' => 7, 'crf_value' => 8, 'aspect_ratio' => 10, 'framerate' => 11, 'samplerate' => 12, 'audio_channels' => 13, 'threads' => 15] as $rKey => $rIndex) {
			$rKept[$rKey] = $rValue($rIndex);
		}
		// The preset and the video profile are read from the field of the codec in use.
		foreach (['cpu', 'h264', 'hevc', ''] as $rCodec) {
			$rKept['preset_' . $rCodec] = $rOptions['-preset'] ?? '';
			$rKept['video_profile_' . $rCodec] = $rOptions['-profile:v'] ?? '';
		}
		return self::keepFields($rData, $rKept, ['yadif_filter' => !$rGPU && $rValue(17) == 1]);
	}

	/**
	 * keepFields() for a server or a proxy.
	 *
	 * @param array $rData   The request.
	 * @param array $rServer The server as stored.
	 * @param bool  $rProxy  Whether it is edited with the proxy form.
	 * @return array
	 */
	private static function keepServerFields(array $rData, array $rServer, bool $rProxy) {
		$rSplit = static fn($rList) => array_values(array_filter(explode(',', (string) $rList), 'strlen'));
		$rKept = ['server_ip' => $rServer['server_ip'], 'domain_name' => $rSplit($rServer['domain_name']), 'geoip_countries' => self::storedList($rServer['geoip_countries'])];
		$rSwitches = array_intersect_key($rServer, array_flip(['enable_https', 'random_ip', 'enable_geoip', 'enabled']));
		if (!$rProxy) {
			// The first port of each kind and the ones added to it, as the form lists them.
			$rKept += [
				'http_broadcast_ports' => array_merge(array_filter([$rServer['http_broadcast_port']]), $rSplit($rServer['http_ports_add'])),
				'https_broadcast_ports' => array_merge(array_filter([$rServer['https_broadcast_port']]), $rSplit($rServer['https_ports_add'])),
				'isp_names' => self::storedList($rServer['isp_names']),
				'total_services' => $rServer['total_services'],
			];
			$rSwitches += array_intersect_key($rServer, array_flip(['enable_gzip', 'timeshift_only', 'enable_isp', 'enable_proxy'])) + ['disable_ramdisk' => $rServer['use_disk']];
		}
		return self::keepFields($rData, $rKept, $rSwitches, ['domain_name' => [], 'geoip_countries' => [], 'http_broadcast_ports' => [], 'https_broadcast_ports' => [], 'isp_names' => []]);
	}

	/**
	 * An edit of the settings through the API changes only the settings the
	 * request names. SettingsService::edit() takes the settings form, which
	 * empties the stream arguments' defaults and the lists it does not post;
	 * each is added here as stored. The switches are all posted, as the form
	 * posts them, so one is switched off by sending 0 or nothing.
	 *
	 * @param array $rData      The request.
	 * @param array $rStored    The settings as stored.
	 * @param array $rArguments argument_key => argument_default_value of streams_arguments.
	 * @param array $rGenres    The genre mapping's rows (watch_categories).
	 * @return array
	 */
	private static function keepSettings(array $rData, array $rStored, array $rArguments, array $rGenres) {
		$rKept = ['search_items' => $rStored['search_items'] ?? null];
		foreach (['user_agent' => 'user_agent', 'http_proxy' => 'proxy', 'cookie' => 'cookie', 'headers' => 'headers'] as $rField => $rKey) {
			$rKept[$rField] = $rArguments[$rKey] ?? null;
		}
		// A list sent empty is none selected, which the service stores as the form does.
		foreach (['allowed_stb_types_for_local_recording', 'allowed_stb_types', 'maxmind_editions', 'shared_mount_prefixes', 'allow_countries'] as $rKey) {
			if (!array_key_exists($rKey, $rData)) {
				$rKept[$rKey] = self::storedList($rStored[$rKey] ?? '');
			} elseif ($rData[$rKey] === '') {
				unset($rData[$rKey]);
			}
		}
		// A genre posted without its bouquets keeps them.
		foreach ($rGenres as $rGenre) {
			$rKey = (intval($rGenre['type']) == 2 ? 'genretv_' : 'genre_') . intval($rGenre['genre_id']);
			$rBouquets = (intval($rGenre['type']) == 2 ? 'bouquettv_' : 'bouquet_') . intval($rGenre['genre_id']);
			if (isset($rData[$rKey]) && !array_key_exists($rBouquets, $rData)) {
				$rKept[$rBouquets] = self::storedList($rGenre['bouquets']);
			} elseif (($rData[$rBouquets] ?? null) === '') {
				$rData[$rBouquets] = [];
			}
		}
		$rSwitches = ['responsive_tables' => empty($rStored['disable_table_responsive'])];
		foreach (SettingsService::checkboxes() as $rKey) {
			$rSwitches[$rKey] = $rStored[$rKey] ?? 0;
		}
		return self::keepFields($rData, $rKept, $rSwitches) + ['submit_settings' => 1];
	}

	public static function deleteLine($rID) {
		if (($rLine = self::getLine($rID)) && isset($rLine['data'])) {
			if (LineService::deleteLineById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function disableLine($rID) {
		if (!($rLine = self::getLine($rID)) || !isset($rLine['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rID);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function enableLine($rID) {
		if (!($rLine = self::getLine($rID)) || !isset($rLine['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rID);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function banLine($rID) {
		if (!($rLine = self::getLine($rID)) || !isset($rLine['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rID);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function unbanLine($rID) {
		if (!($rLine = self::getLine($rID)) || !isset($rLine['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rID);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getUser($rID) {
		global $rUserInfo;
		if (!($rUser = UserRepository::getRegisteredUserById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		// As the panel shows an account: its password hash nowhere, its API key
		// on its holder's own profile only.
		unset($rUser['password']);
		if (intval($rUser['id']) != intval($rUserInfo['id'] ?? 0)) {
			unset($rUser['api_key']);
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rUser];
	}

	public static function createUser($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(UserService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getUser($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editUser($rID, $rData) {
		if (!($rUser = self::getUser($rID)) || !isset($rUser['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `users` WHERE `id` = ?;', $rID);
		// The form posts the password empty when it stays.
		$rKept = ['username' => $rStored['username'], 'password' => '', 'member_group_id' => $rStored['member_group_id']];
		// A package's credits override posts as override_<package>; one sent empty is none.
		foreach (self::storedList($rStored['override_packages']) as $rPackage => $rOverride) {
			if (!empty($rOverride['official_credits'])) {
				$rKept['override_' . $rPackage] = $rOverride['official_credits'];
			}
		}
		$rReturn = parseerror(UserService::process(self::keepFields($rData, $rKept)));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getUser($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteUser($rID) {
		if (($rUser = self::getUser($rID)) && isset($rUser['data'])) {
			if (UserService::deleteRegisteredUser($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function disableUser($rID) {
		// An administrator's account is switched off or on by a full administrator (GroupService::reservedGroups).
		if (!($rUser = self::getUser($rID)) || !isset($rUser['data']) || in_array(intval($rUser['data']['member_group_id']), GroupService::reservedGroups())) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `users` SET `status` = 0 WHERE `id` = ?;', $rID);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function enableUser($rID) {
		// An administrator's account is switched off or on by a full administrator (GroupService::reservedGroups).
		if (!($rUser = self::getUser($rID)) || !isset($rUser['data']) || in_array(intval($rUser['data']['member_group_id']), GroupService::reservedGroups())) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `users` SET `status` = 1 WHERE `id` = ?;', $rID);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getMAG($rID) {
		if (!($rDevice = MagService::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rDevice];
	}

	public static function createMAG($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(MagService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editMAG($rID, $rData) {
		if (!($rDevice = self::getMAG($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		if (isset($rData['isp_clear'])) {
			$rData['isp_clear'] = '';
		}
		$rStored = self::storedRow('SELECT * FROM `mag_devices` WHERE `mag_id` = ?;', $rID);
		$rData = self::keepDeviceFields($rData, $rStored, self::storedRow('SELECT * FROM `lines` WHERE `id` = ?;', $rStored['user_id']));
		$rReturn = parseerror(MagService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getMAG($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteMAG($rID) {
		if (($rDevice = self::getMAG($rID)) && isset($rDevice['data'])) {
			if (MagService::deleteDevice($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	// getMAG()/getEnigma() return the device under `data`. These actions read
	// $rDevice['user_id'] — always null — so they ran `WHERE id = NULL`, changed
	// nothing and answered success; convert returned a failure object as its data.
	public static function disableMAG($rID) {
		if (!($rDevice = self::getMAG($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rDevice['data']['user_id']);
		LineService::updateLineSignal($rDevice['data']['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function enableMAG($rID) {
		if (!($rDevice = self::getMAG($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rDevice['data']['user_id']);
		LineService::updateLineSignal($rDevice['data']['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function banMAG($rID) {
		if (!($rDevice = self::getMAG($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rDevice['data']['user_id']);
		LineService::updateLineSignal($rDevice['data']['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function unbanMAG($rID) {
		if (!($rDevice = self::getMAG($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rDevice['data']['user_id']);
		LineService::updateLineSignal($rDevice['data']['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function convertMAG($rID) {
		if (!($rDevice = self::getMAG($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		MagService::deleteDevice($rID, false, false, true);
		return ['status' => 'STATUS_SUCCESS', 'data' => self::getLine($rDevice['data']['user_id'])];
	}

	public static function getEnigma($rID) {
		if (!($rDevice = EnigmaService::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rDevice];
	}

	public static function createEnigma($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(EnigmaService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getEnigma($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editEnigma($rID, $rData) {
		if (!($rDevice = self::getEnigma($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		if (isset($rData['isp_clear'])) {
			$rData['isp_clear'] = '';
		}
		$rStored = self::storedRow('SELECT * FROM `enigma2_devices` WHERE `device_id` = ?;', $rID);
		$rData = self::keepDeviceFields($rData, $rStored, self::storedRow('SELECT * FROM `lines` WHERE `id` = ?;', $rStored['user_id']));
		$rReturn = parseerror(EnigmaService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getEnigma($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteEnigma($rID) {
		if (($rDevice = self::getEnigma($rID)) && isset($rDevice['data'])) {
			if (EnigmaService::deleteDevice($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function disableEnigma($rID) {
		if (!($rDevice = self::getEnigma($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rDevice['data']['user_id']);
		LineService::updateLineSignal($rDevice['data']['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function enableEnigma($rID) {
		if (!($rDevice = self::getEnigma($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rDevice['data']['user_id']);
		LineService::updateLineSignal($rDevice['data']['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function banEnigma($rID) {
		if (!($rDevice = self::getEnigma($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `admin_enabled` = 0 WHERE `id` = ?;', $rDevice['data']['user_id']);
		LineService::updateLineSignal($rDevice['data']['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function unbanEnigma($rID) {
		if (!($rDevice = self::getEnigma($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		self::$db->query('UPDATE `lines` SET `admin_enabled` = 1 WHERE `id` = ?;', $rDevice['data']['user_id']);
		LineService::updateLineSignal($rDevice['data']['user_id']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function convertEnigma($rID) {
		if (!($rDevice = self::getEnigma($rID)) || !isset($rDevice['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		EnigmaService::deleteDevice($rID, false, false, true);
		return ['status' => 'STATUS_SUCCESS', 'data' => self::getLine($rDevice['data']['user_id'])];
	}

	public static function getBouquets() {
		return ['status' => 'STATUS_SUCCESS', 'data' => BouquetService::getAllSimple()];
	}

	public static function getBouquet($rID) {
		if (!($rBouquet = BouquetService::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rBouquet];
	}

	public static function createBouquet($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(BouquetService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getBouquet($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editBouquet($rID, $rData) {
		if (!($rBouquet = self::getBouquet($rID)) || !isset($rBouquet['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `bouquets` WHERE `id` = ?;', $rID);
		// The form posts the bouquet's four lists together.
		$rData += ['bouquet_data' => json_encode(['stream' => self::storedList($rStored['bouquet_channels']), 'movies' => self::storedList($rStored['bouquet_movies']), 'radios' => self::storedList($rStored['bouquet_radios']), 'series' => self::storedList($rStored['bouquet_series'])])];
		$rReturn = parseerror(BouquetService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getBouquet($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteBouquet($rID) {
		if (($rBouquet = self::getBouquet($rID)) && isset($rBouquet['data'])) {
			if (BouquetService::deleteById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getAccessCodes() {
		return ['status' => 'STATUS_SUCCESS', 'data' => AuthRepository::getAllCodes()];
	}

	public static function getAccessCode($rID) {
		if (!($rCode = AuthRepository::getCodeById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rCode];
	}

	public static function createAccessCode($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(AuthService::processCode($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getAccessCode($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editAccessCode($rID, $rData) {
		if (!($rCode = self::getAccessCode($rID)) || !isset($rCode['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rData = self::keepCodeFields($rData, self::storedRow('SELECT * FROM `access_codes` WHERE `id` = ?;', $rID));
		$rReturn = parseerror(AuthService::processCode($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getAccessCode($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteAccessCode($rID) {
		if (($rCode = self::getAccessCode($rID)) && isset($rCode['data'])) {
			if (AuthRepository::deleteCode($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getHMACs() {
		return ['status' => 'STATUS_SUCCESS', 'data' => AuthRepository::getAllHMAC()];
	}

	public static function getHMAC($rID) {
		if (!($rToken = AuthRepository::getHMACById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rToken];
	}

	public static function createHMAC($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(AuthService::processHMAC($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getHMAC($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editHMAC($rID, $rData) {
		if (!($rToken = self::getHMAC($rID)) || !isset($rToken['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `hmac_keys` WHERE `id` = ?;', $rID);
		// The form posts the key as hidden when it stays.
		$rData = self::keepFields($rData, ['keygen' => 'HMAC KEY HIDDEN', 'notes' => $rStored['notes']], ['enabled' => $rStored['enabled']]);
		$rReturn = parseerror(AuthService::processHMAC($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getHMAC($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteHMAC($rID) {
		if (($rToken = self::getHMAC($rID)) && isset($rToken['data'])) {
			if (AuthRepository::deleteHMAC($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getEPGs() {
		return ['status' => 'STATUS_SUCCESS', 'data' => EpgService::getAll()];
	}

	public static function getEPG($rID) {
		if (!($rEPG = EpgService::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rEPG];
	}

	public static function createEPG($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(EpgService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getEPG($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editEPG($rID, $rData) {
		if (!($rEPG = self::getEPG($rID)) || !isset($rEPG['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rReturn = parseerror(EpgService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getEPG($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteEPG($rID) {
		if (($rEPG = self::getEPG($rID)) && isset($rEPG['data'])) {
			if (EpgService::deleteEpgById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function reloadEPG($rID = null) {
		if ($rID) {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:epg "' . intval($rID) . '" > /dev/null 2>/dev/null &');
		} else {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:epg > /dev/null 2>/dev/null &');
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getProviders() {
		return ['status' => 'STATUS_SUCCESS', 'data' => ProviderService::getAll()];
	}

	public static function getProvider($rID) {
		if (!($rProvider = ProviderService::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rProvider];
	}

	public static function createProvider($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(ProviderService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getProvider($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editProvider($rID, $rData) {
		if (!($rProvider = self::getProvider($rID)) || !isset($rProvider['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `providers` WHERE `id` = ?;', $rID);
		$rData = self::keepFields($rData, array_intersect_key($rStored, array_flip(['name', 'ip', 'port', 'username', 'password'])), array_intersect_key($rStored, array_flip(['enabled', 'ssl', 'hls', 'legacy'])));
		$rReturn = parseerror(ProviderService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getProvider($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteProvider($rID) {
		if (($rProvider = self::getProvider($rID)) && isset($rProvider['data'])) {
			if (ProviderService::deleteById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function reloadProvider($rID = null) {
		if ($rID) {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:providers "' . intval($rID) . '" > /dev/null 2>/dev/null &');
		} else {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:providers > /dev/null 2>/dev/null &');
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getGroups() {
		return ['status' => 'STATUS_SUCCESS', 'data' => GroupService::getAll()];
	}

	public static function getGroup($rID) {
		if (!($rGroup = GroupService::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rGroup];
	}

	public static function createGroup($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(GroupService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getGroup($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editGroup($rID, $rData) {
		if (!($rGroup = self::getGroup($rID)) || !isset($rGroup['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `users_groups` WHERE `group_id` = ?;', $rID);
		$rPackages = [];
		foreach (self::storedRows('SELECT `id`, `groups` FROM `users_packages`;') as $rRow) {
			if (in_array(intval($rID), array_map('intval', self::storedList($rRow['groups'])), true)) {
				$rPackages[] = intval($rRow['id']);
			}
		}
		// The notice is stored with its entities, as GroupService::process() writes it.
		$rKept = ['group_name' => $rStored['group_name'], 'permissions_selected' => json_encode(self::storedList($rStored['allowed_pages'])), 'groups_selected' => json_encode(self::storedList($rStored['subresellers'])), 'notice_html' => html_entity_decode((string) $rStored['notice_html']), 'packages_selected' => json_encode($rPackages)];
		$rSwitches = array_intersect_key($rStored, array_flip(['is_admin', 'is_reseller', 'allow_restrictions', 'create_sub_resellers', 'delete_users', 'allow_download', 'can_view_vod', 'reseller_client_connection_logs', 'allow_change_bouquets', 'allow_change_username', 'allow_change_password']));
		$rData = self::keepFields($rData, $rKept, $rSwitches, ['permissions_selected' => '[]', 'groups_selected' => '[]', 'packages_selected' => '[]']);
		$rReturn = parseerror(GroupService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getGroup($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteGroup($rID) {
		if (($rGroup = self::getGroup($rID)) && isset($rGroup['data'])) {
			if (GroupService::deleteById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getPackages() {
		return ['status' => 'STATUS_SUCCESS', 'data' => PackageService::getAll()];
	}

	public static function getPackage($rID) {
		if (!($rPackage = PackageService::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rPackage];
	}

	public static function createPackage($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(PackageService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getPackage($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editPackage($rID, $rData) {
		if (!($rPackage = self::getPackage($rID)) || !isset($rPackage['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `users_packages` WHERE `id` = ?;', $rID);
		$rKept = ['package_name' => $rStored['package_name'], 'groups_selected' => json_encode(self::storedList($rStored['groups'])), 'bouquets_selected' => json_encode(self::storedList($rStored['bouquets']))];
		$rSwitches = array_intersect_key($rStored, array_flip(['is_trial', 'is_official', 'is_mag', 'is_e2', 'is_line', 'lock_device', 'is_restreamer', 'is_isplock', 'check_compatible']));
		$rData = self::keepFields($rData, $rKept, $rSwitches, ['groups_selected' => '[]', 'bouquets_selected' => '[]']);
		$rReturn = parseerror(PackageService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getPackage($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deletePackage($rID) {
		if (($rPackage = self::getPackage($rID)) && isset($rPackage['data'])) {
			if (PackageService::deleteById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getTranscodeProfiles() {
		return ['status' => 'STATUS_SUCCESS', 'data' => StreamConfigRepository::getTranscodeProfiles()];
	}

	public static function getTranscodeProfile($rID) {
		if (!($rProfile = StreamConfigRepository::getTranscodeProfile($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rProfile];
	}

	public static function createTranscodeProfile($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(ProfileService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getTranscodeProfile($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editTranscodeProfile($rID, $rData) {
		if (!($rProfile = self::getTranscodeProfile($rID)) || !isset($rProfile['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rData = self::keepProfileFields($rData, self::storedRow('SELECT * FROM `profiles` WHERE `profile_id` = ?;', $rID));
		$rReturn = parseerror(ProfileService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getTranscodeProfile($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteTranscodeProfile($rID) {
		if (($rProfile = self::getTranscodeProfile($rID)) && isset($rProfile['data'])) {
			if (StreamConfigRepository::deleteProfile($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getRTMPIPs() {
		return ['status' => 'STATUS_SUCCESS', 'data' => BlocklistService::getRTMPIPsSimple()];
	}

	public static function getRTMPIP($rID) {
		if (!($rIP = BlocklistService::getRTMPIPById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rIP];
	}

	public static function addRTMPIP($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(BlocklistService::processRTMPIP($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getRTMPIP($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editRTMPIP($rID, $rData) {
		if (!($rIP = self::getRTMPIP($rID)) || !isset($rIP['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `rtmp_ips` WHERE `id` = ?;', $rID);
		// A password left out is the stored one, not a new one.
		$rData = self::keepFields($rData, ['ip' => $rStored['ip'], 'password' => $rStored['password']], ['push' => $rStored['push'], 'pull' => $rStored['pull']]);
		$rReturn = parseerror(BlocklistService::processRTMPIP($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getRTMPIP($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteRTMPIP($rID) {
		if (($rIP = self::getRTMPIP($rID)) && isset($rIP['data'])) {
			if (BlocklistService::deleteRTMPIP($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getCategories() {
		return ['status' => 'STATUS_SUCCESS', 'data' => CategoryService::getAllByType()];
	}

	public static function getCategory($rID) {
		if (!($rCategory = CategoryService::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rCategory];
	}

	public static function createCategory($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(CategoryService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getCategory($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editCategory($rID, $rData) {
		if (!($rCategory = self::getCategory($rID)) || !isset($rCategory['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `streams_categories` WHERE `id` = ?;', $rID);
		$rData = self::keepFields($rData, [], ['is_adult' => $rStored['is_adult']]);
		$rReturn = parseerror(CategoryService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getCategory($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteCategory($rID) {
		if (($rCategory = self::getCategory($rID)) && isset($rCategory['data'])) {
			if (CategoryService::deleteById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	/**
	 * Run a module's Admin API action (AdminApiRegistry): its reply's numeric
	 * status becomes the STATUS_* name, and show/hide_columns apply as the
	 * action declared.
	 *
	 * @param array      $rAction      The AdminApiRegistry entry.
	 * @param array      $rData
	 * @param array|null $rShowColumns
	 * @param array|null $rHideColumns
	 * @return array
	 */
	public static function moduleAction(array $rAction, array $rData, $rShowColumns, $rHideColumns) {
		$rReply = parseerror(($rAction['handler'])($rData));
		if ($rAction['columns'] === 'rows') {
			return self::filterRows($rReply, $rShowColumns, $rHideColumns);
		}
		if ($rAction['columns'] === 'row') {
			return self::filterRow($rReply, $rShowColumns, $rHideColumns);
		}
		return $rReply;
	}

	public static function getBlockedISPs() {
		return ['status' => 'STATUS_SUCCESS', 'data' => BlocklistService::getAllISPs()];
	}

	public static function addBlockedISP($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(BlocklistService::processISP($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = $rReturn['data']['insert_id'];
		}
		return $rReturn;
	}

	public static function deleteBlockedISP($rID) {
		if (!BlocklistService::deleteBlockedISP($rID)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getBlockedUAs() {
		return ['status' => 'STATUS_SUCCESS', 'data' => BlocklistService::getAllUserAgents()];
	}

	public static function addBlockedUA($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(BlocklistService::processUA($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = $rReturn['data']['insert_id'];
		}
		return $rReturn;
	}

	public static function deleteBlockedUA($rID) {
		if (!BlocklistService::deleteBlockedUA($rID)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getBlockedIPs() {
		return ['status' => 'STATUS_SUCCESS', 'data' => BlocklistService::getBlockedIPsSimple()];
	}

	public static function addBlockedIP($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(BlocklistService::blockIP($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = $rReturn['data']['insert_id'];
		}
		return $rReturn;
	}

	public static function deleteBlockedIP($rID) {
		if (!BlocklistService::deleteBlockedIP($rID)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function flushBlockedIPs() {
		BlocklistService::flushIPs();
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getStream($rID) {
		if (!($rStream = StreamRepository::getById($rID)) || $rStream['type'] != 1) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rStream];
	}

	public static function createStream($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(StreamService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getStream($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editStream($rID, $rData) {
		if (!($rStream = self::getStream($rID)) || !isset($rStream['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
		$rKept = ['stream_source' => self::storedList($rStored['stream_source'])];
		if (!is_null($rStored['adaptive_link'])) {
			$rKept['adaptive_link'] = self::storedList($rStored['adaptive_link']);
		}
		if (!is_null($rStored['title_sync'])) {
			$rKept['title_sync'] = $rStored['title_sync'];
		}
		$rSwitches = array_intersect_key($rStored, array_flip(['fps_restart', 'gen_timestamps', 'allow_record', 'rtmp_output', 'stream_all', 'direct_source', 'direct_proxy', 'read_native']));
		$rData = self::keepFields(self::keepStreamFields($rData, $rStored, 'bouquet_channels', true), $rKept, $rSwitches, ['stream_source' => [], 'adaptive_link' => []]);
		$rReturn = parseerror(StreamService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getStream($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteStream($rID, $rServerID = -1) {
		if (($rStream = self::getStream($rID)) && isset($rStream['data'])) {
			if (StreamRepository::deleteStream($rID, $rServerID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function startStream($rID, $rServerID = -1) {
		if ($rServerID == -1) {
			$rData = json_decode(ApiClient::request(['action' => 'stream', 'sub' => 'start', 'stream_ids' => [$rID], 'servers' => array_keys(ServerRepository::getAll())]), true);
		} else {
			$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'stream', 'stream_ids' => [$rID], 'function' => 'start']), true);
		}
		if (!$rData['result']) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function stopStream($rID, $rServerID = -1) {
		if ($rServerID == -1) {
			$rData = json_decode(ApiClient::request(['action' => 'stream', 'sub' => 'stop', 'stream_ids' => [$rID], 'servers' => array_keys(ServerRepository::getAll())]), true);
		} else {
			$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'stream', 'stream_ids' => [$rID], 'function' => 'stop']), true);
		}
		if (!$rData['result']) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getChannel($rID) {
		if (!($rStream = StreamRepository::getById($rID)) || $rStream['type'] != 3) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rStream];
	}

	public static function createChannel($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(ChannelService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getChannel($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editChannel($rID, $rData) {
		if (!($rStream = self::getChannel($rID)) || !isset($rStream['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
		// A created channel plays a series (type 0) or its video files (type 1).
		$rKept = ['channel_type' => self::storedList($rStored['movie_properties'])['type'] ?? 0, 'series_no' => $rStored['series_no'], 'video_files' => json_encode(self::storedList($rStored['stream_source'])), 'transcode_profile_id' => $rStored['transcode_profile_id'], 'bouquet_create_list' => '[]', 'category_create_list' => '[]'];
		$rData = self::keepFields(self::keepStreamFields($rData, $rStored, 'bouquet_channels', false), $rKept, array_intersect_key($rStored, array_flip(['allow_record', 'rtmp_output'])));
		$rReturn = parseerror(ChannelService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getChannel($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteChannel($rID, $rServerID = -1) {
		if (($rStream = self::getChannel($rID)) && isset($rStream['data'])) {
			if (StreamRepository::deleteStream($rID, $rServerID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getStation($rID) {
		if (!($rStream = StreamRepository::getById($rID)) || $rStream['type'] != 4) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rStream];
	}

	public static function createStation($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(RadioService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getStation($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editStation($rID, $rData) {
		if (!($rStream = self::getStation($rID)) || !isset($rStream['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
		$rKept = ['stream_display_name' => $rStored['stream_display_name'], 'stream_source' => self::storedList($rStored['stream_source']), 'probesize_ondemand' => $rStored['probesize_ondemand']];
		$rData = self::keepFields(self::keepStreamFields($rData, $rStored, 'bouquet_radios', true), $rKept, ['direct_source' => $rStored['direct_source']], ['stream_source' => ['']]);
		$rReturn = parseerror(RadioService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getStation($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteStation($rID, $rServerID = -1) {
		if (($rStream = self::getStation($rID)) && isset($rStream['data'])) {
			if (StreamRepository::deleteStream($rID, $rServerID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getMovie($rID) {
		if (!($rStream = StreamRepository::getById($rID)) || $rStream['type'] != 2) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rStream];
	}

	public static function createMovie($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(MovieService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getMovie($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editMovie($rID, $rData) {
		if (!($rStream = self::getMovie($rID)) || !isset($rStream['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
		$rProperties = self::storedList($rStored['movie_properties']);
		$rKept = ['stream_display_name' => $rStored['stream_display_name'], 'stream_source' => self::storedList($rStored['stream_source'])[0] ?? '', 'movie_subtitles' => self::subtitlesField($rStored['movie_subtitles']), 'tmdb_id' => (string) ($rStored['tmdb_id'] ?: ($rProperties['tmdb_id'] ?? '')), 'backdrop_path' => ((array) ($rProperties['backdrop_path'] ?? []))[0] ?? ''];
		foreach (['movie_image', 'release_date', 'episode_run_time', 'youtube_trailer', 'director', 'cast', 'plot', 'country', 'genre', 'rating'] as $rKey) {
			$rKept[$rKey] = $rProperties[$rKey] ?? '';
		}
		$rSwitches = array_intersect_key($rStored, array_flip(['read_native', 'movie_symlink', 'direct_source', 'direct_proxy', 'remove_subtitles']));
		$rData = self::keepFields(self::keepStreamFields($rData, $rStored, 'bouquet_movies', false), $rKept, $rSwitches);
		$rReturn = parseerror(MovieService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getMovie($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteMovie($rID, $rServerID = -1) {
		if (($rStream = self::getMovie($rID)) && isset($rStream['data'])) {
			if (StreamRepository::deleteStream($rID, $rServerID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function startMovie($rID, $rServerID = -1) {
		if ($rServerID == -1) {
			$rData = json_decode(ApiClient::request(['action' => 'vod', 'sub' => 'start', 'stream_ids' => [$rID], 'servers' => array_keys(ServerRepository::getAll())]), true);
		} else {
			$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'vod', 'stream_ids' => [$rID], 'function' => 'start']), true);
		}
		if (!$rData['result']) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function stopMovie($rID, $rServerID = -1) {
		if ($rServerID == -1) {
			$rData = json_decode(ApiClient::request(['action' => 'vod', 'sub' => 'stop', 'stream_ids' => [$rID], 'servers' => array_keys(ServerRepository::getAll())]), true);
		} else {
			$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'vod', 'stream_ids' => [$rID], 'function' => 'stop']), true);
		}
		if (!$rData['result']) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getEpisode($rID) {
		if (!($rStream = StreamRepository::getById($rID)) || $rStream['type'] != 5) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rStream];
	}

	public static function createEpisode($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(EpisodeService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getEpisode($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editEpisode($rID, $rData) {
		if (!($rStream = self::getEpisode($rID)) || !isset($rStream['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `streams` WHERE `id` = ?;', $rID);
		$rEpisode = self::storedRow('SELECT * FROM `streams_episodes` WHERE `stream_id` = ?;', $rID);
		$rProperties = self::storedList($rStored['movie_properties']);
		// The runtime is posted in minutes, as the form shows it.
		$rKept = ['stream_source' => self::storedList($rStored['stream_source'])[0] ?? '', 'movie_subtitles' => self::subtitlesField($rStored['movie_subtitles']), 'series' => (string) ($rEpisode['series_id'] ?? ''), 'season_num' => (string) ($rEpisode['season_num'] ?? ''), 'episode' => (string) ($rEpisode['episode_num'] ?? ''), 'target_container' => $rStored['target_container'], 'episode_run_time' => intval(($rProperties['duration_secs'] ?? 0) / 60)];
		foreach (['release_date', 'plot', 'movie_image', 'rating', 'tmdb_id'] as $rKey) {
			$rKept[$rKey] = $rProperties[$rKey] ?? '';
		}
		$rSwitches = array_intersect_key($rStored, array_flip(['read_native', 'movie_symlink', 'direct_source', 'direct_proxy', 'remove_subtitles']));
		$rData = self::keepFields(self::keepStreamFields($rData, $rStored, null, false), $rKept, $rSwitches);
		$rReturn = parseerror(EpisodeService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getEpisode($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteEpisode($rID, $rServerID = -1) {
		if (($rStream = self::getEpisode($rID)) && isset($rStream['data'])) {
			if (StreamRepository::deleteStream($rID, $rServerID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getSeries($rID) {
		if (!($rSeries = SeriesService::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rSeries];
	}

	public static function createSeries($rData) {
		if (isset($rData['edit'])) {
			unset($rData['edit']);
		}
		$rReturn = parseerror(SeriesService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getSeries($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editSeries($rID, $rData) {
		if (!($rStream = self::getSeries($rID)) || !isset($rStream['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rStored = self::storedRow('SELECT * FROM `streams_series` WHERE `id` = ?;', $rID);
		$rKept = ['title' => $rStored['title'], 'cover' => $rStored['cover'], 'backdrop_path' => self::storedList($rStored['backdrop_path'])[0] ?? '', 'category_id' => self::storedList($rStored['category_id']), 'bouquets' => self::storedBouquets('bouquet_series', $rID)];
		$rData = self::keepFields($rData, $rKept, [], ['category_id' => [], 'bouquets' => []]);
		$rReturn = parseerror(SeriesService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getSeries($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteSeries($rID) {
		if (($rStream = self::getSeries($rID)) && isset($rStream['data'])) {
			if (SeriesService::deleteSeriesById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getServers() {
		global $rPermissions;
		return ['status' => 'STATUS_SUCCESS', 'data' => ServerRepository::getStreamingSimple($rPermissions)];
	}

	public static function getServer($rID) {
		if (!($rServer = ServerRepository::getById($rID))) {
			return ['status' => 'STATUS_FAILURE'];
		}
		// Worker pid list, left out as in get_servers.
		unset($rServer['php_pids']);
		return ['status' => 'STATUS_SUCCESS', 'data' => $rServer];
	}

	public static function installServer($rData) {
		global $rPermissions;
		if (!(empty($rData['type']) || empty($rData['ssh_port']) || empty($rData['root_username']) || empty($rData['root_password']))) {
			if ($rData['type'] != 1 || !empty($rData['type']) && !empty($rData['ssh_port'])) {
				$rReturn = parseerror(ServerService::install($rData, ServerRepository::getStreamingSimple($rPermissions, 'all'), ServerRepository::getProxySimple($rPermissions)));
				if (isset($rReturn['data']['insert_id'])) {
					$rReturn['data'] = self::getServer($rReturn['data']['insert_id'])['data'] ?? null;
				}
				return $rReturn;
			}
			return ['status' => 'STATUS_INVALID_INPUT'];
		}
		return ['status' => 'STATUS_INVALID_INPUT'];
	}

	public static function editServer($rID, $rData) {
		if (!($rServer = self::getServer($rID)) || !isset($rServer['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rData = self::keepServerFields($rData, self::storedRow('SELECT * FROM `servers` WHERE `id` = ?;', $rID), false);
		$rReturn = parseerror(ServerService::process($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getServer($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function editProxy($rID, $rData) {
		if (!($rServer = self::getServer($rID)) || !isset($rServer['data'])) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['edit'] = $rID;
		$rData = self::keepServerFields($rData, self::storedRow('SELECT * FROM `servers` WHERE `id` = ?;', $rID), true);
		$rReturn = parseerror(ServerService::processProxy($rData));
		if (isset($rReturn['data']['insert_id'])) {
			$rReturn['data'] = self::getServer($rReturn['data']['insert_id'])['data'];
		}
		return $rReturn;
	}

	public static function deleteServer($rID) {
		if (($rServer = self::getServer($rID)) && isset($rServer['data'])) {
			if (ServerRepository::deleteById($rID)) {
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function getSettings() {
		return ['status' => 'STATUS_SUCCESS', 'data' => SettingsManager::getAll()];
	}

	public static function editSettings($rData) {
		$rStored = self::storedRow('SELECT * FROM `settings` LIMIT 1;');
		$rArguments = array_column(self::storedRows('SELECT `argument_key`, `argument_default_value` FROM `streams_arguments`;'), 'argument_default_value', 'argument_key');
		$rGenres = preg_grep('/^(genre|genretv)_\d+$/', array_keys($rData)) ? self::storedRows('SELECT `genre_id`, `type`, `bouquets` FROM `watch_categories`;') : [];
		$rReturn = parseerror(SettingsService::edit(self::keepSettings($rData, $rStored, $rArguments, $rGenres)));
		$rReturn['data'] = self::getSettings()['data'];
		return $rReturn;
	}

	public static function getStats($rServerID) {
		global $db;
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'stats']), true);
		if (!$rData) {
			return ['status' => 'STATUS_FAILURE'];
		}
		$rData['requests_per_second'] = ServerRepository::getAll()[$rServerID]['requests_per_second'];
		$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0;', $rServerID);
		if (0 < $db->num_rows()) {
			$rData['open_connections'] = $db->get_row()['count'];
		}
		$db->query('SELECT COUNT(*) AS `count` FROM `lines_live` WHERE `hls_end` = 0;');
		if (0 < $db->num_rows()) {
			$rData['total_connections'] = $db->get_row()['count'];
		}
		$db->query('SELECT `activity_id` FROM `lines_live` WHERE `server_id` = ? AND `hls_end` = 0 GROUP BY `user_id`;', $rServerID);
		if (0 < $db->num_rows()) {
			$rData['online_users'] = $db->num_rows();
		}
		$db->query('SELECT `activity_id` FROM `lines_live` WHERE `hls_end` = 0 GROUP BY `user_id`;');
		if (0 < $db->num_rows()) {
			$rData['total_users'] = $db->num_rows();
		}
		$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `stream_status` <> 2 AND `type` = 1;', $rServerID);
		if (0 < $db->num_rows()) {
			$rData['total_streams'] = $db->get_row()['count'];
		}
		$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `pid` > 0 AND `type` = 1;', $rServerID);
		if (0 < $db->num_rows()) {
			$rData['total_running_streams'] = $db->get_row()['count'];
		}
		$db->query('SELECT COUNT(*) AS `count` FROM `streams_servers` LEFT JOIN `streams` ON `streams`.`id` = `streams_servers`.`stream_id` WHERE `server_id` = ? AND `type` = 1 AND (`streams`.`direct_source` = 0 AND (`streams_servers`.`monitor_pid` IS NOT NULL AND `streams_servers`.`monitor_pid` > 0) AND (`streams_servers`.`pid` IS NULL OR `streams_servers`.`pid` <= 0) AND `streams_servers`.`stream_status` <> 0);', $rServerID);
		if (0 < $db->num_rows()) {
			$rData['offline_streams'] = $db->get_row()['count'];
		}
		$rData['network_guaranteed_speed'] = ServerRepository::getAll()[$rServerID]['network_guaranteed_speed'];
		return ['status' => 'STATUS_SUCCESS', 'data' => $rData];
	}

	public static function getFPMStatus($rServerID) {
		$rData = NodeRpc::request($rServerID, ['action' => 'fpm_status']);
		if (!$rData) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rData];
	}

	public static function getRTMPStats($rServerID) {
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'rtmp_stats']), true);
		if (!$rData) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rData];
	}

	public static function getFreeSpace($rServerID) {
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'get_free_space']), true);
		if (!$rData) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rData];
	}

	public static function getPIDs($rServerID) {
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'get_pids']), true);
		if (!$rData) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rData];
	}

	public static function getCertificateInfo($rServerID) {
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'get_certificate_info']), true);
		if (!$rData) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $rData];
	}

	public static function reloadNGINX($rServerID) {
		NodeRpc::request($rServerID, ['action' => 'reload_nginx']);
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function clearTemp($rServerID) {
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'free_temp']), true);
		if (!$rData['result']) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function clearStreams($rServerID) {
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'free_streams']), true);
		if (!$rData['result']) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function getDirectory($rServerID, $rDirectory) {
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'scandir', 'dir' => $rDirectory]), true);
		if (!$rData) {
			return ['status' => 'STATUS_FAILURE'];
		}
		unset($rData['result']);
		if (!isset($rData['result']) || $rData['result']) {
			return ['status' => 'STATUS_SUCCESS', 'data' => $rData];
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function killPID($rServerID, $rPID) {
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'kill_pid', 'pid' => intval($rPID)]), true);
		if (!$rData['result']) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function killConnection($rServerID, $rActivityID) {
		$rData = json_decode(NodeRpc::request($rServerID, ['action' => 'closeConnection', 'activity_id' => intval($rActivityID)]), true);
		if (!$rData['result']) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function adjustCredits($rID, $rCredits, $rReason = '') {
		global $db;
		global $rUserInfo;
		// The credits of an administrator's account are adjusted by a full administrator (GroupService::reservedGroups).
		if (is_numeric($rCredits) && ($rUser = self::getUser($rID)) && isset($rUser['data']) && !in_array(intval($rUser['data']['member_group_id']), GroupService::reservedGroups())) {
			$rAmount = intval($rCredits);
			// The balance changes by the amount on the row as it is stored now: an
			// amount is taken only from a balance that covers it. The log carries the amount.
			if ($rAmount < 0 ? UserCredits::debit($rUser['data']['id'], -$rAmount) : UserCredits::credit($rUser['data']['id'], $rAmount)) {
				$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rID, $rUserInfo['id'], $rAmount, time(), $rReason);
				return ['status' => 'STATUS_SUCCESS'];
			}
		}
		return ['status' => 'STATUS_FAILURE'];
	}

	public static function reloadCache() {
		shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:cache_engine > /dev/null 2>/dev/null &');
		return ['status' => 'STATUS_SUCCESS'];
	}

	public static function runQuery($rQuery) {
		global $db;
		if (!$db->query($rQuery)) {
			return ['status' => 'STATUS_FAILURE'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $db->get_rows(), 'insert_id' => $db->last_insert_id()];
	}

	// ─── Active Codes API Handlers ──────────────────────────────────────────
	// The active-code API calls these as well, so each asks for the permission
	// of its action itself (AdminApiController::ACTION_PERMISSIONS).

	public static function getActiveCodes($rStart = 0, $rLimit = 50, $rData = [], $rShowColumns = null, $rHideColumns = null) {
		if (!AdminApiController::permitted('get_active_codes')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		$res = ActiveCodeService::listCodes($rData, $user, true, (int) $rStart, (int) $rLimit);
		return [
			'status' => 'STATUS_SUCCESS',
			'total' => $res['total'],
			'count' => $res['count'],
			'start' => $res['start'],
			'limit' => $res['limit'],
			'data' => self::filterRows(['data' => $res['data']], $rShowColumns, $rHideColumns),
		];
	}

	public static function getActiveCode($rID) {
		if (!AdminApiController::permitted('get_active_code')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		$code = ActiveCodeService::getCodeDetails($rID, $user, true);
		if (!$code) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Active code not found.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $code];
	}

	public static function generateActiveCodes($rData) {
		if (!AdminApiController::permitted('generate_active_codes')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		$res = ActiveCodeService::generateCodes($rData, $user, true);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to generate active codes.'];
		}
		return [
			'status' => 'STATUS_SUCCESS',
			'message' => $res['message'],
			'batch_name' => $res['batch_name'],
			'qty' => $res['qty'],
			'data' => $res['codes'],
		];
	}

	public static function editActiveCode($rID, $rData) {
		if (!AdminApiController::permitted('edit_active_code')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		$res = ActiveCodeService::updateCode((int) $rID, $rData, $user, true);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to update active code.'];
		}
		return [
			'status' => 'STATUS_SUCCESS',
			'message' => $res['message'],
			'data' => ActiveCodeService::getCodeDetails((int) $rID, $user, true),
		];
	}

	public static function deleteActiveCode($rID) {
		if (!AdminApiController::permitted('delete_active_code')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		$res = ActiveCodeService::deleteCode((int) $rID, $user, true, false);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to delete active code.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'message' => $res['message']];
	}

	public static function enableActiveCode($rID) {
		if (!AdminApiController::permitted('enable_active_code')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		$res = ActiveCodeService::massAction('enable', [(int) $rID], $user, true);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to enable active code.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'message' => $res['message']];
	}

	public static function disableActiveCode($rID) {
		if (!AdminApiController::permitted('disable_active_code')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		$res = ActiveCodeService::massAction('disable', [(int) $rID], $user, true);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to disable active code.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'message' => $res['message']];
	}

	public static function resetActiveCodeDevice($rID) {
		if (!AdminApiController::permitted('reset_active_code_device')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		$res = ActiveCodeService::resetDevice($rID, $user, true);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Failed to reset device lock.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'message' => $res['message']];
	}

	public static function massActiveCodes($rAction, $rIDs, $rExtra = []) {
		if (!AdminApiController::permitted('mass_active_codes')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		if (is_string($rIDs)) {
			$rIDs = explode(',', $rIDs);
		}
		$rIDs = array_filter(array_map('intval', (array) $rIDs));
		if ($rIDs === []) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'No active code IDs provided.'];
		}
		$res = ActiveCodeService::massAction((string) $rAction, $rIDs, $user, true, (array) $rExtra);
		if ($res['status'] !== 'SUCCESS') {
			return ['status' => 'STATUS_FAILURE', 'error' => $res['message'] ?? 'Mass action failed.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'message' => $res['message']];
	}

	public static function getActiveCodesBatches($rBatchName = null) {
		if (!AdminApiController::permitted('get_active_codes_batches')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		$batches = ActiveCodeService::getBatchSummary($user, true, $rBatchName);
		return ['status' => 'STATUS_SUCCESS', 'data' => $batches];
	}

	public static function exportActiveCodeBatch($rBatchName, $rFormat = 'json') {
		if (!AdminApiController::permitted('export_active_code_batch')) {
			return ['status' => 'STATUS_NO_PERMISSIONS'];
		}
		$user = $GLOBALS['rAdminUserInfo'] ?? ['id' => 1, 'username' => 'Admin'];
		if (empty($rBatchName)) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Batch name is required.'];
		}
		if (strtolower((string) $rFormat) === 'txt' || strtolower((string) $rFormat) === 'text') {
			$txt = ActiveCodeService::exportBatchTxt((string) $rBatchName, $user, true);
			return ['status' => 'STATUS_SUCCESS', 'format' => 'txt', 'content' => $txt];
		}
		$json = ActiveCodeService::exportBatchJson((string) $rBatchName, $user, true);
		return ['status' => 'STATUS_SUCCESS', 'format' => 'json', 'data' => $json];
	}

	public static function checkActiveCode($rCode) {
		$details = ActiveCodeService::checkCode((string) $rCode);
		if ($details === []) {
			return ['status' => 'STATUS_FAILURE', 'error' => 'Invalid or inactive code.'];
		}
		return ['status' => 'STATUS_SUCCESS', 'data' => $details];
	}
}

if (!function_exists(__NAMESPACE__ . '\\parseError') && !function_exists('parseError')) {
	function parseError($rArray) {
		global $_ERRORS;
		if (isset($rArray['status']) && is_numeric($rArray['status'])) {
			$rArray['status'] = $_ERRORS[$rArray['status']];
		}
		if (!$rArray) {
			$rArray['status'] = 'STATUS_NO_PERMISSIONS';
		}
		return $rArray;
	}
}
