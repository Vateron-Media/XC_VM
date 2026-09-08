<?php

namespace XcVm\Infrastructure;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Server\ServerRepository;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * ResellerTableRenderer — DataTables handler for reseller panel.
 *
 * All $rType branches are private static handler methods.
 *
 * @package XC_VM_Infrastructure
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerTableRenderer {

	/**
	 * Render a reseller DataTables view for the requested type.
	 *
	 * Dispatches to the matching private handler based on the request type.
	 *
	 * @param array      $rReturn      DataTables request payload.
	 * @param bool       $rIsAPI       Whether the request came via the API.
	 * @param array|null $rUserInfo    Authenticated reseller user (or null).
	 * @param array      $rPermissions Effective permissions.
	 * @param array      $rSettings    Panel settings.
	 * @return void
	 */
	public static function render(array $rReturn, bool $rIsAPI, ?array $rUserInfo, array $rPermissions, array $rSettings): void {
		global $db;

		if (!isset($rUserInfo['reports'])) {
			echo json_encode($rReturn);
			exit();
		}

		$rType = RequestManager::get('id');
		$rStart = intval(RequestManager::get('start'));
		$rLimit = intval(RequestManager::get('length'));
		if (1000 < $rLimit || $rLimit <= 0) {
			$rLimit = 1000;
		}

		switch ($rType) {
			case 'lines':
				self::handleLines($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'mags':
				self::handleMags($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'enigmas':
				self::handleEnigmas($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'streams':
				self::handleStreams($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'radios':
				self::handleRadios($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'movies':
				self::handleMovies($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'episodes':
				self::handleEpisodes($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'line_activity':
				self::handleLineActivity($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'live_connections':
				self::handleLiveConnections($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'reg_user_logs':
				self::handleRegUserLogs($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
			case 'reg_users':
				self::handleRegUsers($rReturn, $rIsAPI, $rUserInfo, $rPermissions, $rSettings, $db, $rStart, $rLimit);
				break;
		}
	}

	/**
	 * Render the reseller "lines" table.
	 *
	 * @param array  $rReturn      DataTables request/response payload.
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleLines(array $rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		if (!$rPermissions['create_line']) {
			exit();
		}
		$rOrderBy = '';
		// Order direction is resolved up front because the "last connection" column
		// orders by a composite expression that embeds the direction.
		$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir'] ?? '') === 'desc' ? 'desc' : 'asc');
		// Column index -> SQL order expression. Mirrors the keyed Bootstrap 5 column
		// order emitted by the reseller lines view: leading Responsive-control column,
		// then id, username, password, owner, status, online, trial, restreamer,
		// active connections, max connections, expiration, last connection, actions.
		$rOrder = array(false, '`lines`.`id`', '`lines`.`username`', '`lines`.`password`', '`users`.`username`', '`lines`.`enabled` - `lines`.`admin_enabled`', '`active_connections` > 0', '`lines`.`is_trial`', '`lines`.`is_restreamer`', '`active_connections`', '`lines`.`max_connections`', '`lines`.`exp_date`', '`active_connections` ' . $rOrderDirection . ', `last_activity`', false);
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rWhere = $rWhereV = array();
		$rWhere[] = '`lines`.`is_mag` = 0 AND `lines`.`is_e2` = 0';
		$rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
		if (0 >= strlen(RequestManager::get('search')['value'])) {
		} else {
			foreach (range(1, 6) as $rInt) {
				$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
			}
			$rWhere[] = '(`lines`.`username` LIKE ? OR `lines`.`password` LIKE ? OR `users`.`username` LIKE ? OR FROM_UNIXTIME(`exp_date`) LIKE ? OR `lines`.`max_connections` LIKE ? OR `lines`.`reseller_notes` LIKE ?)';
		}
		if (0 >= strlen(RequestManager::get('filter'))) {
		} else {
			if (RequestManager::get('filter') == 1) {
				$rWhere[] = '(`lines`.`admin_enabled` = 1 AND `lines`.`enabled` = 1 AND (`lines`.`exp_date` IS NULL OR `lines`.`exp_date` > UNIX_TIMESTAMP()))';
			} else {
				if (RequestManager::get('filter') == 2) {
					$rWhere[] = '`lines`.`enabled` = 0';
				} else {
					if (RequestManager::get('filter') == 3) {
						$rWhere[] = '`lines`.`admin_enabled` = 0';
					} else {
						if (RequestManager::get('filter') == 4) {
							$rWhere[] = '(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` <= UNIX_TIMESTAMP())';
						} else {
							if (RequestManager::get('filter') != 5) {
							} else {
								$rWhere[] = '`lines`.`is_trial` = 1';
							}
						}
					}
				}
			}
		}
		if (0 >= strlen(RequestManager::get('reseller'))) {
		} else {
			$rWhere[] = '`lines`.`member_id` = ?';
			$rWhereV[] = RequestManager::get('reseller');
		}
		if (0 < count($rWhere)) {
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
		} else {
			$rWhereString = '';
		}
		$rCountQuery = 'SELECT COUNT(`lines`.`id`) AS `count` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` ' . $rWhereString . ';';
		if (!$rOrder[$rOrderRow]) {
		} else {
			$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
			$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
		}
		$db->query($rCountQuery, ...$rWhereV);
		if ($db->num_rows() == 1) {
			$rReturn['recordsTotal'] = $db->get_row()['count'];
		} else {
			$rReturn['recordsTotal'] = 0;
		}
		$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
		if (0 >= $rReturn['recordsTotal']) {
		} else {
			$rQuery = 'SELECT `lines`.`id`, `lines`.`member_id`, `lines`.`last_activity`, `lines`.`last_activity_array`, `lines`.`username`, `lines`.`password`, `lines`.`exp_date`, `lines`.`admin_enabled`, `lines`.`is_restreamer`, `lines`.`enabled`, `lines`.`admin_notes`, `lines`.`reseller_notes`, `lines`.`max_connections`, `lines`.`is_trial`, `lines`.`contact`, `lines`.`is_isplock`, (SELECT COUNT(*) AS `active_connections` FROM `lines_live` WHERE `user_id` = `lines`.`id` AND `hls_end` = 0) AS `active_connections` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
			$db->query($rQuery, ...$rWhereV);
			if (0 >= $db->num_rows()) {
			} else {
				$rRows = $db->get_rows();
				$rActivityIDs = $rLineInfo = $rLineIDs = array();
				foreach ($rRows as $rRow) {
					$rLineIDs[] = intval($rRow['id']);
					$rLineInfo[intval($rRow['id'])] = array('owner_name' => null, 'stream_display_name' => null, 'stream_id' => null, 'last_active' => null);
					if ($rLastInfo = json_decode($rRow['last_activity_array'], true)) {
						$rLineInfo[intval($rRow['id'])]['stream_id'] = $rLastInfo['stream_id'];
						$rLineInfo[intval($rRow['id'])]['last_active'] = $rLastInfo['date_end'];
					} else {
						if (!$rRow['last_activity']) {
						} else {
							$rActivityIDs[] = intval($rRow['last_activity']);
						}
					}
				}
				if (0 >= count($rLineIDs)) {
				} else {
					$db->query('SELECT `users`.`username`, `lines`.`id` FROM `users` LEFT JOIN `lines` ON `lines`.`member_id` = `users`.`id` WHERE `lines`.`id` IN (' . implode(',', $rLineIDs) . ');');
					foreach ($db->get_rows() as $rRow) {
						$rLineInfo[$rRow['id']]['owner_name'] = $rRow['username'];
					}
					if ($rRedis) {
						$rConnectionCount = array();
						$rConnectionMap = ConnectionTracker::getUserConnections($rLineIDs, false);
						$rStreamIDs = array();
						foreach ($rConnectionMap as $rUserID => $rConnections) {
							foreach ($rConnections as $rConnection) {
								if (in_array($rConnection['stream_id'], $rStreamIDs)) {
								} else {
									$rStreamIDs[] = intval($rConnection['stream_id']);
								}
							}
						}
						$rStreamMap = array();
						if (0 >= count($rStreamIDs)) {
						} else {
							$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rStreamIDs) . ');');
							foreach ($db->get_rows() as $rRow) {
								$rStreamMap[$rRow['id']] = $rRow['stream_display_name'];
							}
						}
						foreach (array_keys($rConnectionMap) as $rUserID) {
							$rDates = array_column($rConnectionMap[$rUserID], 'date_start');
							if (count($rDates) === count($rConnectionMap[$rUserID])) {
								array_multisort($rDates, SORT_DESC, $rConnectionMap[$rUserID]);
							}
							$rLineInfo[$rUserID]['stream_display_name'] = $rStreamMap[$rConnectionMap[$rUserID][0]['stream_id']];
							$rLineInfo[$rUserID]['stream_id'] = $rConnectionMap[$rUserID][0]['stream_id'];
							$rLineInfo[$rUserID]['last_active'] = $rConnectionMap[$rUserID][0]['date_start'];
							$rConnectionCount[$rUserID] = count($rConnectionMap[$rUserID]);
						}
						unset($rConnectionMap);
					} else {
						$db->query('SELECT `lines_live`.`user_id`, `lines_live`.`stream_id`, `lines_live`.`date_start` AS `last_active`, `streams`.`stream_display_name` FROM `lines_live` LEFT JOIN `streams` ON `streams`.`id` = `lines_live`.`stream_id` INNER JOIN (SELECT `user_id`, MAX(`date_start`) AS `ts` FROM `lines_live` GROUP BY `user_id`) `maxt` ON (`lines_live`.`user_id` = `maxt`.`user_id` AND `lines_live`.`date_start` = `maxt`.`ts`) WHERE `lines_live`.`user_id` IN (' . implode(',', $rLineIDs) . ');');
						foreach ($db->get_rows() as $rRow) {
							$rLineInfo[$rRow['user_id']]['stream_display_name'] = $rRow['stream_display_name'];
							$rLineInfo[$rRow['user_id']]['stream_id'] = $rRow['stream_id'];
							$rLineInfo[$rRow['user_id']]['last_active'] = $rRow['last_active'];
						}
					}
				}
				if (0 >= count($rActivityIDs)) {
				} else {
					$db->query('SELECT `user_id`, `stream_id`, `date_end` AS `last_active` FROM `lines_activity` WHERE `activity_id` IN (' . implode(',', $rActivityIDs) . ');');
					foreach ($db->get_rows() as $rRow) {
						if (isset($rLineInfo[$rRow['user_id']]['stream_id'])) {
						} else {
							$rLineInfo[$rRow['user_id']]['stream_id'] = $rRow['stream_id'];
							$rLineInfo[$rRow['user_id']]['last_active'] = $rRow['last_active'];
						}
					}
				}
				foreach ($rRows as $rRow) {
					$rRow = array_merge($rRow, $rLineInfo[$rRow['id']]);
					if (!$rRedis) {
					} else {
						$rRow['active_connections'] = (isset($rConnectionCount[$rRow['id']]) ? $rConnectionCount[$rRow['id']] : 0);
					}
					if (!$rIsAPI) {
						// Clean, keyed row payload (Bootstrap 5 view renders every
						// status dot / badge / action item client-side). Mirrors the
						// admin lines handler; adds reseller-only fields (is_isplock,
						// reseller notes and the direct/indirect owner marker).
						$rStatus = 'active';
						if (!$rRow['admin_enabled']) {
							$rStatus = 'banned';
						} elseif (!$rRow['enabled']) {
							$rStatus = 'disabled';
						} elseif ($rRow['exp_date'] && $rRow['exp_date'] < time()) {
							$rStatus = 'expired';
						}
						$rExpUnix = $rRow['exp_date'] ? (int) $rRow['exp_date'] : 0;
						$rExpStr = $rExpUnix ? date($rSettings['date_format'], $rExpUnix) . ' ' . date('H:i:s', $rExpUnix) : '';
						$rLastUnix = !empty($rRow['last_active']) ? (int) $rRow['last_active'] : 0;
						$rLastStr = $rLastUnix ? date($rSettings['date_format'], $rLastUnix) . ' ' . date('H:i:s', $rLastUnix) : '';
						// Direct reports (and the reseller itself) are "owned" lines;
						// anything deeper in the tree is an indirect report.
						$rIndirect = !in_array($rRow['member_id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])));
						$rReturn['data'][] = array(
							'id' => (int) $rRow['id'],
							'username' => $rRow['username'],
							'password' => $rRow['password'],
							'owner_name' => $rRow['owner_name'],
							'member_id' => (int) $rRow['member_id'],
							'indirect' => $rIndirect,
							'status' => $rStatus,
							'active_connections' => (int) $rRow['active_connections'],
							'trial' => (bool) $rRow['is_trial'],
							'restreamer' => (bool) $rRow['is_restreamer'],
							'max_connections' => (int) $rRow['max_connections'],
							'exp_unix' => $rExpUnix,
							'exp_str' => $rExpStr,
							'exp_expired' => (bool) ($rExpUnix && $rExpUnix < time()),
							'last_active' => $rLastUnix,
							'last_str' => $rLastStr,
							'stream_id' => isset($rRow['stream_id']) ? (int) $rRow['stream_id'] : 0,
							'stream_display_name' => $rRow['stream_display_name'] ?? null,
							'admin_enabled' => (bool) $rRow['admin_enabled'],
							'enabled' => (bool) $rRow['enabled'],
							'is_isplock' => (bool) $rRow['is_isplock'],
							'notes' => (string) $rRow['reseller_notes'],
							'contact' => $rRow['contact'],
						);
					} else {
						$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
					}
				}
			}
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "MAG devices" table.
	 *
	 * @param array  $rReturn      DataTables request/response payload.
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleMags(array $rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		if (!$rPermissions['create_mag']) {
			exit();
		}
		$rOrderBy = '';
		// Column index -> SQL order expression. Mirrors the keyed Bootstrap 5 column
		// order emitted by the reseller mags view: leading Responsive-control column,
		// then id, username, mac, stb type, owner, status, online, trial, expiration, actions.
		$rOrder = array(false, '`lines`.`id`', '`lines`.`username`', '`mag_devices`.`mac`', '`mag_devices`.`stb_type`', '`users`.`username`', '`lines`.`enabled`', '`active_connections`', '`lines`.`is_trial`', '`lines`.`exp_date`', false);
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rWhere = $rWhereV = array();
		$rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
		if (0 >= strlen(RequestManager::get('search')['value'])) {
		} else {
			foreach (range(1, 6) as $rInt) {
				$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
			}
			$rWhere[] = '(`lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `mag_devices`.`stb_type` LIKE ? OR `users`.`username` LIKE ? OR FROM_UNIXTIME(`exp_date`) LIKE ? OR `lines`.`reseller_notes` LIKE ?)';
		}
		if (0 >= strlen(RequestManager::get('filter'))) {
		} else {
			if (RequestManager::get('filter') == 1) {
				$rWhere[] = '(`lines`.`admin_enabled` = 1 AND `lines`.`enabled` = 1 AND (`lines`.`exp_date` IS NULL OR `lines`.`exp_date` > UNIX_TIMESTAMP()))';
			} else {
				if (RequestManager::get('filter') == 2) {
					$rWhere[] = '`lines`.`enabled` = 0';
				} else {
					if (RequestManager::get('filter') == 3) {
						$rWhere[] = '(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` <= UNIX_TIMESTAMP())';
					} else {
						if (RequestManager::get('filter') != 4) {
						} else {
							$rWhere[] = '`lines`.`is_trial` = 1';
						}
					}
				}
			}
		}
		if (0 >= strlen(RequestManager::get('reseller'))) {
		} else {
			$rWhere[] = '`lines`.`member_id` = ?';
			$rWhereV[] = RequestManager::get('reseller');
		}
		if (0 < count($rWhere)) {
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
		} else {
			$rWhereString = '';
		}
		if (!$rOrder[$rOrderRow]) {
		} else {
			$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
			$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
		}
		$rCountQuery = 'SELECT COUNT(`lines`.`id`) AS `count` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` INNER JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` ' . $rWhereString . ';';
		$db->query($rCountQuery, ...$rWhereV);
		if ($db->num_rows() == 1) {
			$rReturn['recordsTotal'] = $db->get_row()['count'];
		} else {
			$rReturn['recordsTotal'] = 0;
		}
		$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
		if (0 >= $rReturn['recordsTotal']) {
		} else {
			$rQuery = 'SELECT `lines`.`id`, `lines`.`username`, `lines`.`member_id`, `lines`.`is_isplock`, `mag_devices`.`mac`, `mag_devices`.`stb_type`, `mag_devices`.`mag_id`, `lines`.`exp_date`, `lines`.`admin_enabled`, `lines`.`enabled`, `lines`.`reseller_notes`, `lines`.`max_connections`,  `lines`.`is_trial`, `users`.`username` AS `owner_name`, (SELECT count(*) FROM `lines_live` WHERE `lines`.`id` = `lines_live`.`user_id` AND `hls_end` = 0) AS `active_connections` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` INNER JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
			$db->query($rQuery, ...$rWhereV);
			if (0 >= $db->num_rows()) {
			} else {
				$rRows = $db->get_rows();
				$rLineIDs = array();
				foreach ($rRows as $rRow) {
					if (!$rRow['id']) {
					} else {
						$rLineIDs[] = intval($rRow['id']);
					}
				}
				if (0 >= count($rLineIDs)) {
				} else {
					if (!$rRedis) {
					} else {
						$rConnectionCount = array();
						$rConnectionMap = ConnectionTracker::getUserConnections($rLineIDs, false);
						foreach (array_keys($rConnectionMap) as $rUserID) {
							$rConnectionCount[$rUserID] = count($rConnectionMap[$rUserID]);
						}
						unset($rConnectionMap);
					}
				}
				foreach ($rRows as $rRow) {
					if (!$rRedis) {
					} else {
						$rRow['active_connections'] = (isset($rConnectionCount[$rRow['id']]) ? $rConnectionCount[$rRow['id']] : 0);
					}
					if (!$rIsAPI) {
						// Clean, keyed row payload; the Bootstrap 5 reseller mags view renders
						// every status icon, badge and action item client-side. Direct reports
						// (and the reseller itself) are "owned"; deeper rows are indirect reports.
						$rIndirect = !in_array($rRow['member_id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])));
						$rReturn['data'][] = array(
							'mag_id' => (int) $rRow['mag_id'],
							'username' => $rRow['username'],
							'mac' => $rRow['mac'],
							'stb_type' => $rRow['stb_type'],
							'member_id' => (int) $rRow['member_id'],
							'owner_name' => $rRow['owner_name'],
							'indirect' => $rIndirect,
							'admin_enabled' => (bool) $rRow['admin_enabled'],
							'enabled' => (bool) $rRow['enabled'],
							'exp_date' => $rRow['exp_date'] ? (int) $rRow['exp_date'] : 0,
							'active_connections' => (int) $rRow['active_connections'],
							'is_trial' => (bool) $rRow['is_trial'],
							'is_isplock' => (bool) $rRow['is_isplock'],
							'notes' => (string) $rRow['reseller_notes'],
						);
					} else {
						$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
					}
				}
			}
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "Enigma2 devices" table.
	 *
	 * @param array  $rReturn      DataTables request/response payload.
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleEnigmas(array $rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		if (!$rPermissions['create_enigma']) {
			exit();
		}
		$rOrderBy = '';
		// Column index -> SQL order expression. Mirrors the keyed Bootstrap 5 column
		// order emitted by the reseller enigmas view: leading Responsive-control column,
		// then id, username, mac, public ip, owner, status, online, trial, expiration, actions.
		$rOrder = array(false, '`lines`.`id`', '`lines`.`username`', '`enigma2_devices`.`mac`', '`enigma2_devices`.`public_ip`', '`users`.`username`', '`lines`.`enabled`', '`active_connections`', '`lines`.`is_trial`', '`lines`.`exp_date`', false);
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rWhere = $rWhereV = array();
		$rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
		if (0 >= strlen(RequestManager::get('search')['value'])) {
		} else {
			foreach (range(1, 6) as $rInt) {
				$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
			}
			$rWhere[] = '(`lines`.`username` LIKE ? OR `enigma2_devices`.`mac` LIKE ? OR `enigma2_devices`.`public_ip` LIKE ? OR `users`.`username` LIKE ? OR FROM_UNIXTIME(`exp_date`) LIKE ? OR `lines`.`reseller_notes` LIKE ?)';
		}
		if (0 >= strlen(RequestManager::get('filter'))) {
		} else {
			if (RequestManager::get('filter') == 1) {
				$rWhere[] = '(`lines`.`admin_enabled` = 1 AND `lines`.`enabled` = 1 AND (`lines`.`exp_date` IS NULL OR `lines`.`exp_date` > UNIX_TIMESTAMP()))';
			} else {
				if (RequestManager::get('filter') == 2) {
					$rWhere[] = '`lines`.`enabled` = 0';
				} else {
					if (RequestManager::get('filter') == 3) {
						$rWhere[] = '(`lines`.`exp_date` IS NOT NULL AND `lines`.`exp_date` <= UNIX_TIMESTAMP())';
					} else {
						if (RequestManager::get('filter') != 4) {
						} else {
							$rWhere[] = '`lines`.`is_trial` = 1';
						}
					}
				}
			}
		}
		if (0 >= strlen(RequestManager::get('reseller'))) {
		} else {
			$rWhere[] = '`lines`.`member_id` = ?';
			$rWhereV[] = RequestManager::get('reseller');
		}
		if (0 < count($rWhere)) {
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
		} else {
			$rWhereString = '';
		}
		if (!$rOrder[$rOrderRow]) {
		} else {
			$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
			$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
		}
		$rCountQuery = 'SELECT COUNT(`lines`.`id`) AS `count` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` INNER JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` ' . $rWhereString . ';';
		$db->query($rCountQuery, ...$rWhereV);
		if ($db->num_rows() == 1) {
			$rReturn['recordsTotal'] = $db->get_row()['count'];
		} else {
			$rReturn['recordsTotal'] = 0;
		}
		$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
		if (0 >= $rReturn['recordsTotal']) {
		} else {
			$rQuery = 'SELECT `lines`.`id`, `lines`.`username`, `lines`.`member_id`, `lines`.`is_isplock`, `enigma2_devices`.`mac`, `enigma2_devices`.`public_ip`, `enigma2_devices`.`device_id`, `lines`.`exp_date`, `lines`.`admin_enabled`, `lines`.`enabled`, `lines`.`reseller_notes`, `lines`.`max_connections`,  `lines`.`is_trial`, `users`.`username` AS `owner_name`, (SELECT count(*) FROM `lines_live` WHERE `lines`.`id` = `lines_live`.`user_id` AND `hls_end` = 0) AS `active_connections` FROM `lines` LEFT JOIN `users` ON `users`.`id` = `lines`.`member_id` INNER JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
			$db->query($rQuery, ...$rWhereV);
			if (0 >= $db->num_rows()) {
			} else {
				$rRows = $db->get_rows();
				$rLineIDs = array();
				foreach ($rRows as $rRow) {
					if (!$rRow['id']) {
					} else {
						$rLineIDs[] = intval($rRow['id']);
					}
				}
				if (0 >= count($rLineIDs)) {
				} else {
					if (!$rRedis) {
					} else {
						$rConnectionCount = array();
						$rConnectionMap = ConnectionTracker::getUserConnections($rLineIDs, false);
						foreach (array_keys($rConnectionMap) as $rUserID) {
							$rConnectionCount[$rUserID] = count($rConnectionMap[$rUserID]);
						}
						unset($rConnectionMap);
					}
				}
				foreach ($rRows as $rRow) {
					if (!$rRedis) {
					} else {
						$rRow['active_connections'] = (isset($rConnectionCount[$rRow['id']]) ? $rConnectionCount[$rRow['id']] : 0);
					}
					if (!$rIsAPI) {
						// Clean, keyed row payload; the Bootstrap 5 reseller enigmas view renders
						// every status icon, badge and action item client-side. Direct reports
						// (and the reseller itself) are "owned"; deeper rows are indirect reports.
						$rIndirect = !in_array($rRow['member_id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])));
						$rReturn['data'][] = array(
							'device_id' => (int) $rRow['device_id'],
							'username' => $rRow['username'],
							'mac' => $rRow['mac'],
							'public_ip' => (string) $rRow['public_ip'],
							'member_id' => (int) $rRow['member_id'],
							'owner_name' => $rRow['owner_name'],
							'indirect' => $rIndirect,
							'admin_enabled' => (bool) $rRow['admin_enabled'],
							'enabled' => (bool) $rRow['enabled'],
							'exp_date' => $rRow['exp_date'] ? (int) $rRow['exp_date'] : 0,
							'active_connections' => (int) $rRow['active_connections'],
							'is_trial' => (bool) $rRow['is_trial'],
							'is_isplock' => (bool) $rRow['is_isplock'],
							'notes' => (string) $rRow['reseller_notes'],
						);
					} else {
						$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
					}
				}
			}
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "streams" table.
	 *
	 * @param array  $rReturn      DataTables request/response payload.
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleStreams(array $rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		if (!$rPermissions['can_view_vod']) {
			exit();
		}
		$rCategories = CategoryService::getAllByType('live');
		$rOrderBy = '';
		// Column index -> SQL order expression. Mirrors the keyed Bootstrap 5 column
		// order emitted by the reseller streams view: leading Responsive-control
		// column, then id, icon, title, category, connections, actions.
		$rOrder = array(false, '`id`', false, '`stream_display_name`', '`category_id`', '`clients`', false);
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rCreated = RequestManager::has('created');
		$rWhere = $rWhereV = array();
		if (0 < count($rPermissions['stream_ids'])) {
			$rWhere[] = '`streams`.`id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ')';
			if ($rCreated) {
				$rWhere[] = '`type` = 3';
			} else {
				$rWhere[] = '`type` = 1';
			}
			if (0 >= strlen(RequestManager::get('search')['value'])) {
			} else {
				foreach (range(1, 2) as $rInt) {
					$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
				}
				$rWhere[] = '(`id` LIKE ? OR `stream_display_name` LIKE ?)';
			}
			if (0 >= strlen(RequestManager::get('category'))) {
			} else {
				$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
				$rWhereV[] = RequestManager::get('category');
			}
			if (!$rOrder[$rOrderRow]) {
			} else {
				$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
				$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
			}
			if (0 < count($rWhere)) {
				$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
			} else {
				$rWhereString = '';
			}
			$rCountQuery = 'SELECT COUNT(`streams`.`id`) AS `count` FROM `streams` ' . $rWhereString . ';';
			$db->query($rCountQuery, ...$rWhereV);
			if ($db->num_rows() == 1) {
				$rReturn['recordsTotal'] = $db->get_row()['count'];
			} else {
				$rReturn['recordsTotal'] = 0;
			}
			$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
			if (0 >= $rReturn['recordsTotal']) {
			} else {
				$rQuery = 'SELECT `id`, `stream_icon`, `stream_display_name`, `tv_archive_duration`, `tv_archive_server_id`, `category_id`, (SELECT COUNT(*) FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')) AS `clients` FROM `streams` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
				$db->query($rQuery, ...$rWhereV);
				if ($db->num_rows() > 0) {
					$rRows = $db->get_rows();
					if ($rRedis) {
						$rConnectionCount = $rReports = array();
						$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
						foreach ($db->get_rows() as $rRow) {
							$rReports[] = $rRow['id'];
						}
						if (0 >= count($rReports)) {
						} else {
							foreach (ConnectionTracker::getUserConnections($rReports, false) as $rUserID => $rConnections) {
								foreach ($rConnections as $rConnection) {
									$rConnectionCount[$rConnection['stream_id']]++;
								}
							}
						}
					}
					foreach ($rRows as $rRow) {
						if (!$rRedis) {
						} else {
							$rRow['clients'] = ($rConnectionCount[$rRow['id']] ?: 0);
						}
						if (!$rIsAPI) {
							// Clean, keyed row payload. The Bootstrap 5 reseller streams
							// view renders the icon, category, connections badge and the
							// (kill-only) action dropdown client-side.
							$rCategoryIDs = json_decode($rRow['category_id'], true);
							if (0 < strlen(RequestManager::get('category'))) {
								$rCategory = ($rCategories[intval(RequestManager::get('category'))]['category_name'] ?: 'No Category');
							} else {
								$rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
							}
							if (1 < count($rCategoryIDs)) {
								$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
							}
							$rReturn['data'][] = array(
								'id' => (int) $rRow['id'],
								'icon' => (string) $rRow['stream_icon'],
								'title' => (string) $rRow['stream_display_name'],
								'archive' => (bool) (0 < $rRow['tv_archive_duration'] && 0 < $rRow['tv_archive_server_id']),
								'category' => $rCategory,
								'clients' => (int) $rRow['clients'],
							);
						} else {
							$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
						}
					}
				}
			}
			echo json_encode($rReturn);
			exit();
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "radios" table.
	 *
	 * @param array  $rReturn      DataTables request/response payload.
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleRadios(array $rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		if (!$rPermissions['can_view_vod']) {
			exit();
		}
		$rCategories = CategoryService::getAllByType('radio');
		$rOrderBy = '';
		// Column index -> SQL order expression. Mirrors the keyed Bootstrap 5 column
		// order emitted by the reseller radios view: leading Responsive-control
		// column, then id, icon, title, category, connections, actions.
		$rOrder = array(false, '`id`', false, '`stream_display_name`', '`category_id`', '`clients`', false);
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rCreated = RequestManager::has('created');
		$rWhere = $rWhereV = array();
		if (0 < count($rPermissions['stream_ids'])) {
			$rWhere[] = '`streams`.`id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ')';
			$rWhere[] = '`type` = 4';
			if (0 >= strlen(RequestManager::get('search')['value'])) {
			} else {
				foreach (range(1, 2) as $rInt) {
					$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
				}
				$rWhere[] = '(`id` LIKE ? OR `stream_display_name` LIKE ?)';
			}
			if (0 >= strlen(RequestManager::get('category'))) {
			} else {
				$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
				$rWhereV[] = RequestManager::get('category');
			}
			if (!$rOrder[$rOrderRow]) {
			} else {
				$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
				$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
			}
			if (0 < count($rWhere)) {
				$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
			} else {
				$rWhereString = '';
			}
			$rCountQuery = 'SELECT COUNT(`streams`.`id`) AS `count` FROM `streams` ' . $rWhereString . ';';
			$db->query($rCountQuery, ...$rWhereV);
			if ($db->num_rows() == 1) {
				$rReturn['recordsTotal'] = $db->get_row()['count'];
			} else {
				$rReturn['recordsTotal'] = 0;
			}
			$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
			if (0 >= $rReturn['recordsTotal']) {
			} else {
				$rQuery = 'SELECT `id`, `stream_icon`, `stream_display_name`, `category_id`, (SELECT COUNT(*) FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')) AS `clients` FROM `streams` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
				$db->query($rQuery, ...$rWhereV);
				if (0 >= $db->num_rows()) {
				} else {
					$rRows = $db->get_rows();
					if (!$rRedis) {
					} else {
						$rConnectionCount = $rReports = array();
						$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
						foreach ($db->get_rows() as $rRow) {
							$rReports[] = $rRow['id'];
						}
						if (0 >= count($rReports)) {
						} else {
							foreach (ConnectionTracker::getUserConnections($rReports, false) as $rUserID => $rConnections) {
								foreach ($rConnections as $rConnection) {
									$rConnectionCount[$rConnection['stream_id']]++;
								}
							}
						}
					}
					foreach ($rRows as $rRow) {
						if (!$rRedis) {
						} else {
							$rRow['clients'] = ($rConnectionCount[$rRow['id']] ?: 0);
						}
						if (!$rIsAPI) {
							// Clean, keyed row payload. The Bootstrap 5 reseller radios
							// view renders the icon, category, connections badge and the
							// (kill-only) action dropdown client-side.
							$rCategoryIDs = json_decode($rRow['category_id'], true);
							if (0 < strlen(RequestManager::get('category'))) {
								$rCategory = ($rCategories[intval(RequestManager::get('category'))]['category_name'] ?: 'No Category');
							} else {
								$rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
							}
							if (1 < count($rCategoryIDs)) {
								$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
							}
							$rReturn['data'][] = array(
								'id' => (int) $rRow['id'],
								'icon' => (string) $rRow['stream_icon'],
								'title' => (string) $rRow['stream_display_name'],
								'category' => $rCategory,
								'clients' => (int) $rRow['clients'],
							);
						} else {
							$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
						}
					}
				}
			}
			echo json_encode($rReturn);
			exit();
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "movies" table.
	 *
	 * @param array  $rReturn      DataTables request/response payload.
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleMovies(array $rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		if (!$rPermissions['can_view_vod']) {
			exit();
		}
		$rCategories = CategoryService::getAllByType('movie');
		$rOrderBy = '';
		// Column index -> SQL order expression. Mirrors the keyed Bootstrap 5 column
		// order emitted by the reseller movies view: leading Responsive-control
		// column, then id, cover, title, category, connections, actions.
		$rOrder = array(false, '`id`', false, '`stream_display_name`', '`category_id`', '`clients`', false);
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rCreated = RequestManager::has('created');
		$rWhere = $rWhereV = array();
		if (0 < count($rPermissions['stream_ids'])) {
			$rWhere[] = '`streams`.`id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ')';
			$rWhere[] = '`type` = 2';
			if (0 >= strlen(RequestManager::get('search')['value'])) {
			} else {
				foreach (range(1, 2) as $rInt) {
					$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
				}
				$rWhere[] = '(`id` LIKE ? OR `stream_display_name` LIKE ?)';
			}
			if (0 >= strlen(RequestManager::get('category'))) {
			} else {
				$rWhere[] = "JSON_CONTAINS(`category_id`, ?, '\$')";
				$rWhereV[] = RequestManager::get('category');
			}
			if (!$rOrder[$rOrderRow]) {
			} else {
				$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
				$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
			}
			if (0 < count($rWhere)) {
				$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
			} else {
				$rWhereString = '';
			}
			$rCountQuery = 'SELECT COUNT(`streams`.`id`) AS `count` FROM `streams` ' . $rWhereString . ';';
			$db->query($rCountQuery, ...$rWhereV);
			if ($db->num_rows() == 1) {
				$rReturn['recordsTotal'] = $db->get_row()['count'];
			} else {
				$rReturn['recordsTotal'] = 0;
			}
			$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
			if (0 >= $rReturn['recordsTotal']) {
			} else {
				$rQuery = 'SELECT `id`, `stream_icon`, `stream_display_name`, `movie_properties`, `category_id`, (SELECT COUNT(*) FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')) AS `clients` FROM `streams` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
				$db->query($rQuery, ...$rWhereV);
				if (0 >= $db->num_rows()) {
				} else {
					$rRows = $db->get_rows();
					if (!$rRedis) {
					} else {
						$rConnectionCount = $rReports = array();
						$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
						foreach ($db->get_rows() as $rRow) {
							$rReports[] = $rRow['id'];
						}
						if (0 >= count($rReports)) {
						} else {
							foreach (ConnectionTracker::getUserConnections($rReports, false) as $rUserID => $rConnections) {
								foreach ($rConnections as $rConnection) {
									$rConnectionCount[$rConnection['stream_id']]++;
								}
							}
						}
					}
					foreach ($rRows as $rRow) {
						if (!$rRedis) {
						} else {
							$rRow['clients'] = ($rConnectionCount[$rRow['id']] ?: 0);
						}
						if (!$rIsAPI) {
							// Clean, keyed row payload. The Bootstrap 5 reseller movies
							// view renders the cover, category, connections badge and the
							// (kill-only) action dropdown client-side.
							$rCategoryIDs = json_decode($rRow['category_id'], true);
							if (0 < strlen(RequestManager::get('category'))) {
								$rCategory = ($rCategories[intval(RequestManager::get('category'))]['category_name'] ?: 'No Category');
							} else {
								$rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
							}
							if (1 < count($rCategoryIDs)) {
								$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
							}
							$rProperties = json_decode($rRow['movie_properties'], true);
							$rReturn['data'][] = array(
								'id' => (int) $rRow['id'],
								'image' => (string) ($rProperties['movie_image'] ?? ''),
								'title' => (string) $rRow['stream_display_name'],
								'category' => $rCategory,
								'clients' => (int) $rRow['clients'],
							);
						} else {
							$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
						}
					}
				}
			}
			echo json_encode($rReturn);
			exit();
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "episodes" table.
	 *
	 * @param array  $rReturn      DataTables request/response payload.
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleEpisodes(array $rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		if (!$rPermissions['can_view_vod']) {
			exit();
		}
		$rCategories = CategoryService::getAllByType('series');
		$rOrderBy = '';
		// Column index -> SQL order expression. Mirrors the keyed Bootstrap 5 column
		// order emitted by the reseller episodes view: leading Responsive-control
		// column, then id, image, title, category, connections, actions. The category
		// column is qualified (`streams_series`) because `streams` also has a
		// `category_id`, which would make an unqualified ORDER BY ambiguous.
		$rOrder = array(false, '`streams`.`id`', false, '`stream_display_name`', '`streams_series`.`category_id`', '`clients`', false);
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rCreated = RequestManager::has('created');
		$rWhere = $rWhereV = array();
		if (0 < count($rPermissions['stream_ids'])) {
			$rWhere[] = '`streams`.`id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ')';
			$rWhere[] = '`type` = 5';
			if (0 >= strlen(RequestManager::get('search')['value'])) {
			} else {
				foreach (range(1, 3) as $rInt) {
					$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
				}
				$rWhere[] = '(`streams`.`id` LIKE ? OR `stream_display_name` LIKE ? OR `streams_series`.`title` LIKE ?)';
			}
			if (0 >= strlen(RequestManager::get('category'))) {
			} else {
				$rWhere[] = "JSON_CONTAINS(`streams_series`.`category_id`, ?, '\$')";
				$rWhereV[] = RequestManager::get('category');
			}
			if (0 >= strlen(RequestManager::get('series'))) {
			} else {
				$rWhere[] = '`streams_series`.`id` = ?';
				$rWhereV[] = RequestManager::get('series');
			}
			// Guard with !empty (not isset): index 0 is `false` (the Responsive-control
			// column), and `isset` would emit "ORDER BY false" -> SQL error.
			if (!empty($rOrder[$rOrderRow])) {
				$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
				$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
			}
			if (0 < count($rWhere)) {
				$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
			} else {
				$rWhereString = '';
			}
			$rCountQuery = 'SELECT COUNT(`streams`.`id`) AS `count` FROM `streams` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` ' . $rWhereString . ';';
			$db->query($rCountQuery, ...$rWhereV);
			if ($db->num_rows() == 1) {
				$rReturn['recordsTotal'] = $db->get_row()['count'];
			} else {
				$rReturn['recordsTotal'] = 0;
			}
			$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
			if (0 >= $rReturn['recordsTotal']) {
			} else {
				$rQuery = 'SELECT `streams`.`id`, `stream_icon`, `stream_display_name`, `movie_properties`, `streams_series`.`category_id`, `streams_series`.`title`, `streams_episodes`.`season_num`, (SELECT COUNT(*) FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = `streams`.`id` AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')) AS `clients` FROM `streams` LEFT JOIN `streams_episodes` ON `streams_episodes`.`stream_id` = `streams`.`id` LEFT JOIN `streams_series` ON `streams_series`.`id` = `streams_episodes`.`series_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
				$db->query($rQuery, ...$rWhereV);
				if (0 >= $db->num_rows()) {
				} else {
					$rRows = $db->get_rows();
					if (!$rRedis) {
					} else {
						$rConnectionCount = $rReports = array();
						$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
						foreach ($db->get_rows() as $rRow) {
							$rReports[] = $rRow['id'];
						}
						if (0 >= count($rReports)) {
						} else {
							foreach (ConnectionTracker::getUserConnections($rReports, false) as $rUserID => $rConnections) {
								foreach ($rConnections as $rConnection) {
									$rConnectionCount[$rConnection['stream_id']]++;
								}
							}
						}
					}
					foreach ($rRows as $rRow) {
						if (!$rRedis) {
						} else {
							$rRow['clients'] = ($rConnectionCount[$rRow['id']] ?: 0);
						}
						if (!$rIsAPI) {
							// Clean, keyed row payload. The Bootstrap 5 reseller episodes
							// view renders the image, name (+ series/season subtitle),
							// category, connections badge and the (kill-only) action
							// dropdown client-side.
							$rCategoryIDs = json_decode($rRow['category_id'], true);
							if (0 < strlen(RequestManager::get('category'))) {
								$rCategory = ($rCategories[intval(RequestManager::get('category'))]['category_name'] ?: 'No Category');
							} else {
								$rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
							}
							if (1 < count($rCategoryIDs)) {
								$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
							}
							$rProperties = json_decode($rRow['movie_properties'], true);
							$rReturn['data'][] = array(
								'id' => (int) $rRow['id'],
								'image' => (string) ($rProperties['movie_image'] ?? ''),
								'title' => (string) $rRow['stream_display_name'],
								'series' => (string) $rRow['title'],
								'season' => ($rRow['season_num'] !== null ? (int) $rRow['season_num'] : null),
								'category' => $rCategory,
								'clients' => (int) $rRow['clients'],
							);
						} else {
							$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
						}
					}
				}
			}
			echo json_encode($rReturn);
			exit();
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "line activity" table.
	 *
	 * @param array  $rReturn      DataTables request/response payload.
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleLineActivity(array $rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		if (!$rPermissions['reseller_client_connection_logs']) {
			exit();
		}
		$rOrderBy = '';
		// Column index -> SQL order expression. Leading false = the Bootstrap 5
		// Responsive control column (client index 0); the remaining entries mirror the
		// keyed reseller line_activity columns: username, stream, player, isp, ip,
		// start, stop, duration, container, restreamer.
		$rOrder = array(false, '`username`', '`streams`.`stream_display_name`', '`lines_activity`.`user_agent`', '`lines_activity`.`isp`', '`lines_activity`.`user_ip`', '`lines_activity`.`date_start`', '`lines_activity`.`date_end`', '`lines_activity`.`date_end` - `lines_activity`.`date_start`', '`lines_activity`.`container`', '`lines`.`is_restreamer`');
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rWhere = $rWhereV = array();
		$rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
		if (0 >= strlen(RequestManager::get('search')['value'])) {
		} else {
			foreach (range(1, 10) as $rInt) {
				$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
			}
			$rWhere[] = '(`lines_activity`.`user_agent` LIKE ? OR `lines_activity`.`user_ip` LIKE ? OR `lines_activity`.`container` LIKE ? OR FROM_UNIXTIME(`lines_activity`.`date_start`) LIKE ? OR FROM_UNIXTIME(`lines_activity`.`date_end`) LIKE ? OR `lines_activity`.`geoip_country_code` LIKE ? OR `lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ? OR `streams`.`stream_display_name` LIKE ?)';
		}
		if (0 >= strlen(RequestManager::get('range'))) {
		} else {
			$rStartTime = substr(RequestManager::get('range'), 0, 10);
			$rEndTime = substr(RequestManager::get('range'), strlen(RequestManager::get('range')) - 10, 10);
			if ($rStartTime = strtotime($rStartTime . ' 00:00:00')) {
			} else {
				$rStartTime = null;
			}
			if ($rEndTime = strtotime($rEndTime . ' 23:59:59')) {
			} else {
				$rEndTime = null;
			}
			if (!($rStartTime && $rEndTime)) {
			} else {
				$rWhere[] = '(`lines_activity`.`date_start` >= ? AND `lines_activity`.`date_end` <= ?)';
				$rWhereV[] = $rStartTime;
				$rWhereV[] = $rEndTime;
			}
		}
		if (0 >= strlen(RequestManager::get('stream'))) {
		} else {
			$rWhere[] = '`lines_activity`.`stream_id` = ?';
			$rWhereV[] = RequestManager::get('stream');
		}
		if (0 >= strlen(RequestManager::get('user'))) {
		} else {
			$rWhere[] = '`lines`.`member_id` = ?';
			$rWhereV[] = RequestManager::get('user');
		}
		if (0 >= strlen(RequestManager::get('line'))) {
		} else {
			$rWhere[] = '`lines_activity`.`user_id` = ?';
			$rWhereV[] = RequestManager::get('line');
		}
		if (0 < count($rWhere)) {
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
		} else {
			$rWhereString = '';
		}
		if (!empty($rOrder[$rOrderRow])) {
			$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
			$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
		}
		$rCountQuery = 'SELECT COUNT(*) AS `count` FROM `lines_activity` LEFT JOIN `lines` ON `lines_activity`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_activity`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_activity`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_activity`.`user_id` ' . $rWhereString . ';';
		$db->query($rCountQuery, ...$rWhereV);
		if ($db->num_rows() == 1) {
			$rReturn['recordsTotal'] = $db->get_row()['count'];
		} else {
			$rReturn['recordsTotal'] = 0;
		}
		$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
		if (0 >= $rReturn['recordsTotal']) {
		} else {
			$rQuery = 'SELECT `mag_devices`.`mag_id`, `enigma2_devices`.`device_id`, `lines`.`is_e2`, `lines`.`is_mag`, `lines_activity`.`activity_id`, `lines_activity`.`container`, `lines_activity`.`isp`, `lines_activity`.`user_id`, `lines_activity`.`stream_id`, `streams`.`series_no`, `lines_activity`.`server_id`, `lines_activity`.`user_agent`, `lines_activity`.`user_ip`, `lines_activity`.`container`, `lines_activity`.`date_start`, `lines_activity`.`date_end`, `lines_activity`.`geoip_country_code`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username`, `streams`.`stream_display_name`, `streams`.`type`, `lines`.`is_restreamer` FROM `lines_activity` LEFT JOIN `lines` ON `lines_activity`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_activity`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_activity`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_activity`.`user_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
			$db->query($rQuery, ...$rWhereV);
			if (0 >= $db->num_rows()) {
			} else {
				foreach ($db->get_rows() as $rRow) {
					if (!$rIsAPI) {
						// Clean, keyed row payload; the Bootstrap 5 reseller line_activity
						// view renders the user/stream links, country flag, duration badge
						// and IP whois client-side. Device rows link to the MAG/Enigma editor,
						// everything else to the line editor (mirrors the legacy reseller view).
						if (!empty($rRow['is_mag'])) {
							$rUserUrl = 'mag?id=' . (int) $rRow['mag_id'];
						} elseif (!empty($rRow['is_e2'])) {
							$rUserUrl = 'enigma?id=' . (int) $rRow['device_id'];
						} else {
							$rUserUrl = 'line?id=' . (int) $rRow['user_id'];
						}
						// Reseller stream links only when the reseller may view VOD/streams.
						$rStreamUrl = ($rPermissions['can_view_vod'] && 0 < (int) $rRow['stream_id']) ? 'stream_view?id=' . (int) $rRow['stream_id'] : null;
						$rReturn['data'][] = array(
							'user_label'    => $rRow['username'],
							'user_sub'      => null,
							'user_url'      => $rUserUrl,
							'stream_name'   => $rRow['stream_display_name'],
							'stream_url'    => $rStreamUrl,
							'player'        => trim(explode('(', (string) $rRow['user_agent'])[0]),
							'isp'           => $rRow['isp'],
							'user_ip'       => $rRow['user_ip'],
							'country'       => (0 < strlen((string) $rRow['geoip_country_code'])) ? strtolower($rRow['geoip_country_code']) : null,
							'date_start'    => (int) $rRow['date_start'],
							'date_end'      => (int) $rRow['date_end'],
							'duration'      => (int) $rRow['date_end'] - (int) $rRow['date_start'],
							'container'     => strtoupper((string) $rRow['container']),
							'is_restreamer' => (1 == (int) $rRow['is_restreamer']),
						);
					} else {
						$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
					}
				}
			}
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "live connections" table.
	 *
	 * @param array  $rReturn      DataTables payload (by reference).
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleLiveConnections(array &$rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		$rRedis = SettingsManager::getBool('redis_handler');
		if (!$rPermissions['reseller_client_connection_logs']) {
			exit();
		}
		$rOrderBy = '';
		$rRows = array();
		if ($rRedis) {
			$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? false : true);
			$rReports = array();
			$rUserID = (0 < intval(RequestManager::get('user')) ? intval(RequestManager::get('user')) : null);
			$rStreamID = (0 < intval(RequestManager::get('stream_id')) ? intval(RequestManager::get('stream_id')) : null);
			if ($rUserID && in_array($rUserID, $rUserInfo['reports'])) {
				$db->query('SELECT `id` FROM `lines` WHERE `member_id` = ?;', $rUserID);
			} else {
				$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			}
			foreach ($db->get_rows() as $rRow) {
				$rReports[] = $rRow['id'];
			}
			$rKeys = ConnectionTracker::getUserConnections($rReports, false, true);
			if (!$rOrderDirection) {
			} else {
				$rKeys = array_reverse($rKeys);
			}
			$rKeyCount = count($rKeys);
			foreach (RedisManager::instance()->mGet($rKeys) as $rRow) {
				$rRow = igbinary_unserialize($rRow);
				if (is_array($rRow)) {
					if (!($rStreamID && $rStreamID != $rRow['stream_id'])) {
						if (in_array($rRow['user_id'], $rReports)) {
						} else {
							$rKeyCount--;
						}
					} else {
						$rKeyCount--;
					}
					$rRow['activity_id'] = $rRow['uuid'];
					$rRow['identifier'] = ($rRow['user_id'] ?: $rRow['hmac_id'] . '_' . $rRow['hmac_identifier']);
					$rRow['active_time'] = time() - $rRow['date_start'];
					$rRow['server_name'] = (ServerRepository::getAll()[$rRow['server_id']]['server_name'] ?: '');
					$rRows[] = $rRow;
				} else {
					$rKeyCount--;
				}
			}
			// array_column keys for the Redis path. Leading false = the Bootstrap 5
			// Responsive control column (client index 0); mirrors the keyed reseller
			// live_connections columns: uuid, divergence, line, stream, player, isp,
			// ip, duration, container, restreamer, actions.
			$rOrder = array(false, 'uuid', 'divergence', 'identifier', 'stream_display_name', 'user_agent', 'isp', 'user_ip', 'active_time', 'container', null, null);
			if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
				$rOrderRow = intval(RequestManager::get('order')[0]['column']);
			} else {
				$rOrderRow = 0;
			}
			if (!$rOrder[$rOrderRow]) {
			} else {
				array_multisort(array_column($rRows, $rOrder[$rOrderRow]), ($rOrderDirection ? SORT_ASC : SORT_DESC), $rRows);
			}
			$rRows = array_slice($rRows, $rStart, $rLimit);
			$rUUIDs = $rStreamIDs = $rUserIDs = array();
			foreach ($rRows as $rRow) {
				if (!$rRow['stream_id']) {
				} else {
					$rStreamIDs[] = intval($rRow['stream_id']);
				}
				if (!$rRow['user_id']) {
				} else {
					$rUserIDs[] = intval($rRow['user_id']);
				}
				if (!$rRow['uuid']) {
				} else {
					$rUUIDs[] = $rRow['uuid'];
				}
			}
			$rStreamNames = $rDivergenceMap = $rSeriesMap = $rUserMap = array();
			if (0 >= count($rUserIDs)) {
			} else {
				$db->query('SELECT `lines`.`id`, `lines`.`is_mag`, `lines`.`is_e2`, `lines`.`is_restreamer`, `lines`.`username`, `mag_devices`.`mag_id`, `enigma2_devices`.`device_id` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `lines`.`id` IN (' . implode(',', $rUserIDs) . ');');
				foreach ($db->get_rows() as $rRow) {
					$rUserID = $rRow['id'];
					unset($rRow['id']);
					$rUserMap[$rUserID] = $rRow;
				}
			}
			if (0 >= count($rStreamIDs)) {
			} else {
				$db->query('SELECT `stream_id`, `series_id` FROM `streams_episodes` WHERE `stream_id` IN (' . implode(',', $rStreamIDs) . ');');
				foreach ($db->get_rows() as $rRow) {
					$rSeriesMap[$rRow['stream_id']] = $rRow['series_id'];
				}
				$db->query('SELECT `id`, `type`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', $rStreamIDs) . ');');
				foreach ($db->get_rows() as $rRow) {
					$rStreamNames[$rRow['id']] = array($rRow['stream_display_name'], $rRow['type']);
				}
			}
			if (0 >= count($rUUIDs)) {
			} else {
				$db->query("SELECT `uuid`, `divergence` FROM `lines_divergence` WHERE `uuid` IN ('" . implode("','", $rUUIDs) . "');");
				foreach ($db->get_rows() as $rRow) {
					$rDivergenceMap[$rRow['uuid']] = $rRow['divergence'];
				}
			}
			$i = 0;
			while ($i < count($rRows)) {
				// ?? (not ?:) so a row whose uuid/stream_id/user_id is absent from
				// the lookup maps falls back to the default without an undefined-key warning.
				$rRows[$i]['divergence'] = ($rDivergenceMap[$rRows[$i]['uuid']] ?? 0);
				$rRows[$i]['series_no'] = ($rSeriesMap[$rRows[$i]['stream_id']] ?? null);
				$rRows[$i]['stream_display_name'] = ($rStreamNames[$rRows[$i]['stream_id']][0] ?? '');
				$rRows[$i]['type'] = ($rStreamNames[$rRows[$i]['stream_id']][1] ?? 1);
				$rRows[$i] = array_merge($rRows[$i], ($rUserMap[$rRows[$i]['user_id']] ?? array()));
				$i++;
			}
			$rReturn['recordsTotal'] = $rKeyCount;
			$rReturn['recordsFiltered'] = ($rIsAPI ? ($rReturn['recordsTotal'] < $rLimit ? $rReturn['recordsTotal'] : $rLimit) : $rReturn['recordsTotal']);
		} else {
			$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
			// Column index -> SQL order expression. Leading false = the Bootstrap 5
			// Responsive control column (client index 0); mirrors the keyed reseller
			// live_connections columns (see the Redis path above).
			$rOrder = array(false, '`lines_live`.`activity_id`', '`lines_live`.`divergence`', '`username`', '`streams`.`stream_display_name`', '`lines_live`.`user_agent`', '`lines_live`.`isp`', '`lines_live`.`user_ip`', 'UNIX_TIMESTAMP() - `lines_live`.`date_start`', '`lines_live`.`container`', '`lines`.`is_restreamer`', false);
			if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
				$rOrderRow = intval(RequestManager::get('order')[0]['column']);
			} else {
				$rOrderRow = 0;
			}
			$rWhere = $rWhereV = array();
			$rWhere[] = '`hls_end` = 0';
			$rWhere[] = '`lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
			if (0 >= strlen(RequestManager::get('search')['value'])) {
			} else {
				foreach (range(1, 9) as $rInt) {
					$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
				}
				$rWhere[] = '(`lines_live`.`user_agent` LIKE ? OR `lines_live`.`user_ip` LIKE ? OR `lines_live`.`container` LIKE ? OR FROM_UNIXTIME(`lines_live`.`date_start`) LIKE ? OR `lines_live`.`geoip_country_code` LIKE ? OR `lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ? OR `streams`.`stream_display_name` LIKE ?)';
			}
			if (0 >= intval(RequestManager::get('stream'))) {
			} else {
				$rWhere[] = '`lines_live`.`stream_id` = ?';
				$rWhereV[] = RequestManager::get('stream');
			}
			if (0 >= intval(RequestManager::get('user'))) {
			} else {
				$rWhere[] = '`lines`.`member_id` = ?';
				$rWhereV[] = RequestManager::get('user');
			}
			if (0 >= intval(RequestManager::get('line'))) {
			} else {
				$rWhere[] = '`lines_live`.`user_id` = ?';
				$rWhereV[] = RequestManager::get('line');
			}
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
			if (!$rOrder[$rOrderRow]) {
			} else {
				$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
			}
			$rCountQuery = 'SELECT COUNT(*) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines_live`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_live`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_live`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_live`.`user_id` ' . $rWhereString . ';';
			$db->query($rCountQuery, ...$rWhereV);
			if ($db->num_rows() == 1) {
				$rReturn['recordsTotal'] = $db->get_row()['count'];
			} else {
				$rReturn['recordsTotal'] = 0;
			}
			$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
			if (0 >= $rReturn['recordsTotal']) {
			} else {
				$rQuery = 'SELECT `mag_devices`.`mag_id`, `enigma2_devices`.`device_id`, `lines`.`is_e2`, `lines`.`is_mag`, `lines_live`.`activity_id`, `lines_live`.`divergence`, `lines_live`.`user_id`, `lines_live`.`stream_id`, `streams`.`series_no`, `lines`.`is_restreamer`, `lines_live`.`isp`, `lines_live`.`server_id`, `lines_live`.`user_agent`, `lines_live`.`user_ip`, `lines_live`.`container`, `lines_live`.`uuid`, `lines_live`.`date_start`, `lines_live`.`geoip_country_code`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username`, `streams`.`stream_display_name`, `streams`.`type` FROM `lines_live` LEFT JOIN `lines` ON `lines_live`.`user_id` = `lines`.`id` LEFT JOIN `streams` ON `lines_live`.`stream_id` = `streams`.`id` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines_live`.`user_id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines_live`.`user_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
				$db->query($rQuery, ...$rWhereV);
				if (0 >= $db->num_rows()) {
				} else {
					$rRows = $db->get_rows();
				}
			}
		}
		if (0 >= count($rRows)) {
		} else {
			foreach ($rRows as $rRow) {
				if (!$rIsAPI) {
					// Clean, keyed row payload; the Bootstrap 5 reseller live_connections
					// view renders the divergence square, user/stream links, country flag,
					// live-duration badge, IP whois and the kill button client-side.
					if (!empty($rRow['is_mag'])) {
						$rUserUrl = 'mag?id=' . (int) $rRow['mag_id'];
					} elseif (!empty($rRow['is_e2'])) {
						$rUserUrl = 'enigma?id=' . (int) $rRow['device_id'];
					} else {
						$rUserUrl = 'line?id=' . (int) $rRow['user_id'];
					}
					// Reseller stream links only when the reseller may view VOD/streams.
					$rStreamUrl = ($rPermissions['can_view_vod'] && 0 < (int) $rRow['stream_id']) ? 'stream_view?id=' . (int) $rRow['stream_id'] : null;
					$rReturn['data'][] = array(
						'activity_id'   => $rRow['activity_id'],
						'uuid'          => $rRow['uuid'] ?? null,
						'user_id'       => (int) $rRow['user_id'],
						'divergence'    => (int) $rRow['divergence'],
						'user_label'    => $rRow['username'] ?? '',
						'user_url'      => $rUserUrl,
						'stream_name'   => $rRow['stream_display_name'],
						'stream_url'    => $rStreamUrl,
						'player'        => trim(explode('(', (string) $rRow['user_agent'])[0]),
						'isp'           => $rRow['isp'],
						'user_ip'       => $rRow['user_ip'],
						'country'       => (0 < strlen((string) $rRow['geoip_country_code'])) ? strtolower($rRow['geoip_country_code']) : null,
						'date_start'    => (int) $rRow['date_start'],
						'container'     => strtoupper((string) $rRow['container']),
						'is_restreamer' => (1 == (int) $rRow['is_restreamer']),
					);
				} else {
					$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
				}
			}
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "registered user logs" table.
	 *
	 * @param array  $rReturn      DataTables payload (by reference).
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleRegUserLogs(array &$rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		$rOrderBy = '';
		// Column index -> SQL order expression. Leading false = the Bootstrap 5
		// Responsive control column (client index 0); mirrors the keyed reseller
		// user_logs columns: owner, target, action text, cost, credits after, date.
		$rOrder = array(false, '`users`.`username`', '`users_logs`.`log_id`', '`users_logs`.`type`, `users_logs`.`action`', '`users_logs`.`cost`', '`users_logs`.`credits_after`', '`users_logs`.`date`');
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rWhere = $rWhereV = array();
		$rWhere[] = '`users_logs`.`owner` IN (' . implode(',', $rUserInfo['reports']) . ')';
		if (0 >= strlen(RequestManager::get('search')['value'])) {
		} else {
			foreach (range(1, 3) as $rInt) {
				$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
			}
			$rWhere[] = '(`users`.`username` LIKE ? OR `users_logs`.`type` LIKE ? OR `users_logs`.`action` LIKE ?)';
		}
		if (0 >= strlen(RequestManager::get('range'))) {
		} else {
			$rStartTime = substr(RequestManager::get('range'), 0, 10);
			$rEndTime = substr(RequestManager::get('range'), strlen(RequestManager::get('range')) - 10, 10);
			if ($rStartTime = strtotime($rStartTime . ' 00:00:00')) {
			} else {
				$rStartTime = null;
			}
			if ($rEndTime = strtotime($rEndTime . ' 23:59:59')) {
			} else {
				$rEndTime = null;
			}
			if (!($rStartTime && $rEndTime)) {
			} else {
				$rWhere[] = '(`users_logs`.`date` >= ? AND `users_logs`.`date` <= ?)';
				$rWhereV[] = $rStartTime;
				$rWhereV[] = $rEndTime;
			}
		}
		if (0 >= strlen(RequestManager::get('reseller'))) {
		} else {
			$rWhere[] = '`users_logs`.`owner` = ?';
			$rWhereV[] = RequestManager::get('reseller');
		}
		if (0 < count($rWhere)) {
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
		} else {
			$rWhereString = '';
		}
		if (!empty($rOrder[$rOrderRow])) {
			$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
			$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
		}
		$rCountQuery = 'SELECT COUNT(*) AS `count` FROM `users_logs` LEFT JOIN `users` ON `users`.`id` = `users_logs`.`owner` ' . $rWhereString . ';';
		$db->query($rCountQuery, ...$rWhereV);
		if ($db->num_rows() == 1) {
			$rReturn['recordsTotal'] = $db->get_row()['count'];
		} else {
			$rReturn['recordsTotal'] = 0;
		}
		$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
		if (0 >= $rReturn['recordsTotal']) {
		} else {
			$rPackages = PackageService::getAll();
			$rQuery = 'SELECT `users`.`username`, `users_logs`.`id`, `users_logs`.`owner`, `users_logs`.`type`, `users_logs`.`action`, `users_logs`.`log_id`, `users_logs`.`package_id`, `users_logs`.`cost`, `users_logs`.`credits_after`, `users_logs`.`date`, `users_logs`.`deleted_info` FROM `users_logs` LEFT JOIN `users` ON `users`.`id` = `users_logs`.`owner` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
			$db->query($rQuery, ...$rWhereV);
			if (0 >= $db->num_rows()) {
			} else {
				foreach ($db->get_rows() as $rRow) {
					if (!$rIsAPI) {
						// Clean, keyed row payload; the Bootstrap 5 reseller user_logs view
						// renders the owner link (with an indirect marker), the resolved
						// target line/user/device link and the numeric badges client-side.
						// Direct reports (and the reseller itself) are "owned" rows; anything
						// deeper in the tree is an indirect report.
						$rOwnerIndirect = !in_array($rRow['owner'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])));
						$rDevice = array('line' => 'User Line', 'mag' => 'MAG Device', 'enigma' => 'Enigma2 Device', 'user' => 'Reseller')[$rRow['type']] ?? (string) $rRow['type'];
						$rText = '';
						switch ($rRow['action']) {
							case 'new':
								$rText = $rRow['package_id'] ? 'Created New ' . $rDevice . ' with Package: ' . ($rPackages[$rRow['package_id']]['package_name'] ?? '') : 'Created New ' . $rDevice;
								break;
							case 'extend':
								$rText = $rRow['package_id'] ? 'Extended ' . $rDevice . ' with Package: ' . ($rPackages[$rRow['package_id']]['package_name'] ?? '') : 'Extended ' . $rDevice;
								break;
							case 'edit':
								$rText = 'Edited ' . $rDevice;
								break;
							case 'enable':
								$rText = 'Enabled ' . $rDevice;
								break;
							case 'disable':
								$rText = 'Disabled ' . $rDevice;
								break;
							case 'delete':
								$rText = 'Deleted ' . $rDevice;
								break;
							case 'send_event':
								$rText = 'Sent Event to ' . $rDevice;
								break;
							case 'adjust_credits':
								$rText = 'Adjusted Credits by ' . $rRow['cost'];
								break;
							default:
								$rText = (string) $rRow['action'];
						}
						$rLineLabel = null;
						$rLineUrl = null;
						switch ($rRow['type']) {
							case 'line':
								$rEntity = UserRepository::getLineById($rRow['log_id']);
								if ($rEntity) {
									$rLineLabel = $rEntity['username'];
									$rLineUrl = 'line?id=' . (int) $rRow['log_id'];
								}
								break;
							case 'user':
								$rEntity = UserRepository::getRegisteredUserById($rRow['log_id']);
								if ($rEntity) {
									$rLineLabel = $rEntity['username'];
									$rLineUrl = 'user?id=' . (int) $rRow['log_id'];
								}
								break;
							case 'mag':
								$rEntity = MagService::getById($rRow['log_id']);
								if ($rEntity) {
									$rLineLabel = $rEntity['mac'];
									$rLineUrl = 'mag?id=' . (int) $rRow['log_id'];
								}
								break;
							case 'enigma':
								$rEntity = EnigmaService::getById($rRow['log_id']);
								if ($rEntity) {
									$rLineLabel = $rEntity['mac'];
									$rLineUrl = 'enigma?id=' . (int) $rRow['log_id'];
								}
								break;
						}
						if ($rLineLabel === null) {
							$rDeletedInfo = json_decode($rRow['deleted_info'], true);
							$rLineLabel = is_array($rDeletedInfo) ? ($rDeletedInfo['mac'] ?? $rDeletedInfo['username'] ?? 'DELETED') : 'DELETED';
						}
						$rReturn['data'][] = array(
							'id'             => (int) $rRow['id'],
							'owner'          => $rRow['username'],
							'owner_url'      => 'user?id=' . (int) $rRow['owner'],
							'owner_indirect' => $rOwnerIndirect,
							'line_label'     => $rLineLabel,
							'line_url'       => $rLineUrl,
							'text'           => $rText,
							'cost'           => (int) $rRow['cost'],
							'credits_after'  => (int) $rRow['credits_after'],
							'date'           => (int) $rRow['date'],
						);
					} else {
						unset($rRow['deleted_info']);
						$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
					}
				}
			}
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Render the reseller "registered users" table.
	 *
	 * @param array  $rReturn      DataTables payload (by reference).
	 * @param bool   $rIsAPI       Whether the request came via the API.
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param array  $rSettings    Panel settings.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @param int    $rStart       Pagination offset.
	 * @param int    $rLimit       Page size.
	 * @return void
	 */
	private static function handleRegUsers(array &$rReturn, bool $rIsAPI, array $rUserInfo, array $rPermissions, array $rSettings, $db, int $rStart, int $rLimit): void {
		if (!$rPermissions['create_sub_resellers']) {
			exit();
		}
		$rOrderBy = '';
		// Column index -> SQL order expression. Mirrors the keyed Bootstrap 5 column
		// order emitted by the reseller users view: leading Responsive-control column,
		// then id, username, owner, ip, status, credits, lines, last login, actions.
		$rOrder = array(false, '`users`.`id`', '`users`.`username`', '`r`.`username`', '`users`.`ip`', '`users`.`status`', '`users`.`credits`', '`user_count`', '`users`.`last_login`', false);
		if (RequestManager::has('order') && 0 < strlen(RequestManager::get('order')[0]['column'])) {
			$rOrderRow = intval(RequestManager::get('order')[0]['column']);
		} else {
			$rOrderRow = 0;
		}
		$rWhere = $rWhereV = array();
		$rWhere[] = '`users`.`owner_id` IN (' . implode(',', $rUserInfo['reports']) . ')';
		if (0 >= strlen(RequestManager::get('search')['value'])) {
		} else {
			foreach (range(1, 9) as $rInt) {
				$rWhereV[] = '%' . RequestManager::get('search')['value'] . '%';
			}
			$rWhere[] = '(`users`.`id` LIKE ? OR `users`.`username` LIKE ? OR `users`.`notes` LIKE ? OR `r`.`username` LIKE ? OR FROM_UNIXTIME(`users`.`date_registered`) LIKE ? OR FROM_UNIXTIME(`users`.`last_login`) LIKE ? OR `users`.`email` LIKE ? OR `users`.`ip` LIKE ? OR `users_groups`.`group_name` LIKE ?)';
		}
		if (0 >= strlen(RequestManager::get('filter'))) {
		} else {
			if (RequestManager::get('filter') == 1) {
				$rWhere[] = '`users`.`status` = 1';
			} else {
				if (RequestManager::get('filter') != 2) {
				} else {
					$rWhere[] = '`users`.`status` = 0';
				}
			}
		}
		if (0 >= strlen(RequestManager::get('reseller'))) {
		} else {
			$rWhere[] = '`users`.`owner_id` = ?';
			$rWhereV[] = RequestManager::get('reseller');
		}
		if (0 < count($rWhere)) {
			$rWhereString = 'WHERE ' . implode(' AND ', $rWhere);
		} else {
			$rWhereString = '';
		}
		if (!$rOrder[$rOrderRow]) {
		} else {
			$rOrderDirection = (strtolower(RequestManager::get('order')[0]['dir']) === 'desc' ? 'desc' : 'asc');
			$rOrderBy = 'ORDER BY ' . $rOrder[$rOrderRow] . ' ' . $rOrderDirection;
		}
		$rCountQuery = 'SELECT COUNT(*) AS `count` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` LEFT JOIN `users` AS `r` on `r`.`id` = `users`.`owner_id` ' . $rWhereString . ';';
		$db->query($rCountQuery, ...$rWhereV);
		if ($db->num_rows() == 1) {
			$rReturn['recordsTotal'] = $db->get_row()['count'];
		} else {
			$rReturn['recordsTotal'] = 0;
		}
		$rReturn['recordsFiltered'] = $rReturn['recordsTotal'];
		if (0 >= $rReturn['recordsTotal']) {
		} else {
			$rQuery = 'SELECT `users`.`id`, `users`.`status`, `users_groups`.`is_reseller`, `users`.`notes`, `users`.`owner_id`, `users`.`credits`, `users`.`username`, `users`.`email`, `users`.`ip`, FROM_UNIXTIME(`users`.`date_registered`) AS `date_registered`, FROM_UNIXTIME(`users`.`last_login`) AS `last_login`, `r`.`username` as `owner_username`, `users_groups`.`group_name`, `users`.`status`, (SELECT COUNT(`id`) FROM `lines` WHERE `member_id` = `users`.`id`) AS `user_count` FROM `users` LEFT JOIN `users_groups` ON `users_groups`.`group_id` = `users`.`member_group_id` LEFT JOIN `users` AS `r` on `r`.`id` = `users`.`owner_id` ' . $rWhereString . ' ' . $rOrderBy . ' LIMIT ' . $rStart . ', ' . $rLimit . ';';
			$db->query($rQuery, ...$rWhereV);
			if (0 >= $db->num_rows()) {
			} else {
				foreach ($db->get_rows() as $rRow) {
					if (!$rIsAPI) {
						// Clean, keyed row payload (the Bootstrap 5 reseller users view renders
						// the status icon, credit / line-count badges, IP whois link and action
						// dropdown client-side). Direct reports (and the reseller itself) are
						// "owned" rows; anything deeper in the tree is an indirect report.
						$rIndirect = !in_array($rRow['id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])));
						$rOwnerIndirect = !in_array($rRow['owner_id'], array_merge($rPermissions['direct_reports'], array($rUserInfo['id'])));
						$rReturn['data'][] = array(
							'id' => (int) $rRow['id'],
							'username' => $rRow['username'],
							'owner_id' => (int) $rRow['owner_id'],
							'owner_username' => $rRow['owner_username'],
							'owner_indirect' => $rOwnerIndirect,
							'indirect' => $rIndirect,
							'ip' => (string) $rRow['ip'],
							'status' => (int) $rRow['status'],
							'is_reseller' => (bool) $rRow['is_reseller'],
							'credits' => (int) $rRow['credits'],
							'user_count' => (int) $rRow['user_count'],
							'last_login' => $rRow['last_login'] ?: '',
							'notes' => (string) $rRow['notes'],
						);
					} else {
						$rReturn['data'][] = self::filterRow($rRow, RequestManager::get('show_columns'), RequestManager::get('hide_columns'));
					}
				}
			}
		}
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Filter a row's columns by show/hide lists.
	 *
	 * @param array $rRow  Row data.
	 * @param array $rShow Columns to keep (whitelist), or empty for all.
	 * @param array $rHide Columns to remove (blacklist).
	 * @return array The filtered row.
	 */
	private static function filterRow($rRow, $rShow, $rHide) {
		if ($rShow || $rHide) {
			$rReturn = array();
			foreach (array_keys($rRow) as $rKey) {
				if ($rShow) {
					if (!in_array($rKey, $rShow)) {
					} else {
						$rReturn[$rKey] = $rRow[$rKey];
					}
				} else {
					if (!$rHide) {
					} else {
						if (in_array($rKey, $rHide)) {
						} else {
							$rReturn[$rKey] = $rRow[$rKey];
						}
					}
				}
			}
			return $rReturn;
		} else {
			return $rRow;
		}
	}
}
