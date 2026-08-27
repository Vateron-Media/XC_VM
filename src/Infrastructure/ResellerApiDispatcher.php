<?php

namespace XcVm\Infrastructure;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\TicketRepository;
use XcVm\Domain\User\UserRepository;
use XcVm\Domain\User\UserService;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * ResellerApiDispatcher — dispatcher for reseller API actions.
 *
 * Routes action requests to private static handler methods.
 * Each handler outputs JSON and calls exit().
 *
 * @package XC_VM_Infrastructure
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerApiDispatcher {

	/**
	 * Dispatch an API action for the reseller panel.
	 *
	 * Routes to the corresponding private handler method.
	 * Each handler outputs JSON and calls exit(). This method never returns normally.
	 *
	 * @param string     $action       Action name (from $_GET['action'])
	 * @param array|null $rUserInfo    Authenticated reseller user info
	 * @param array      $rPermissions Reseller permissions
	 */
	public static function dispatch(string $action, ?array $rUserInfo, array $rPermissions): void {
		global $db;
		switch ($action) {
			case 'dashboard':         self::handleDashboard($rUserInfo, $rPermissions, $db);         break;
			case 'connections':       self::handleConnections($rUserInfo, $rPermissions, $db);       break;
			case 'line':              self::handleLine($rUserInfo, $rPermissions, $db);              break;
			case 'line_activity':     self::handleLineActivity($rUserInfo, $rPermissions, $db);      break;
			case 'adjust_credits':    self::handleAdjustCredits($rUserInfo, $rPermissions, $db);     break;
			case 'reg_user':          self::handleRegUser($rUserInfo, $rPermissions, $db);           break;
			case 'ticket':            self::handleTicket($rUserInfo, $rPermissions, $db);            break;
			case 'mag':               self::handleMag($rUserInfo, $rPermissions, $db);               break;
			case 'enigma':            self::handleEnigma($rUserInfo, $rPermissions, $db);            break;
			case 'get_package':       self::handleGetPackage($rUserInfo, $rPermissions, $db);        break;
			case 'get_package_trial': self::handleGetPackageTrial($rUserInfo, $rPermissions, $db);   break;
			case 'header_stats':      self::handleHeaderStats($rUserInfo, $rPermissions, $db);       break;
			case 'stats':             self::handleStats($rUserInfo, $rPermissions, $db);             break;
			case 'userlist':          self::handleUserList($rUserInfo, $rPermissions, $db);          break;
			case 'send_event':        self::handleSendEvent($rUserInfo, $rPermissions, $db);         break;
			case 'streamlist':        self::handleStreamList($rUserInfo, $rPermissions, $db);        break;
			case 'ip_whois':          self::handleIpWhois($rUserInfo, $rPermissions, $db);           break;
			case 'get_epg':           self::handleGetEpg($rUserInfo, $rPermissions, $db);            break;
			case 'get_programme':     self::handleGetProgramme($rUserInfo, $rPermissions, $db);      break;
		}
	}

	/**
	 * Output reseller dashboard data (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleDashboard(array $rUserInfo, array $rPermissions, $db): void {
		$rReturn = array('open_connections' => 0, 'online_users' => 0, 'active_accounts' => 0, 'credits' => 0, 'credits_assigned' => 0);

		if (SettingsManager::getBool('redis_handler')) {
			$rReports = array();
			$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			foreach ($db->get_rows() as $rRow) {
				$rReports[] = $rRow['id'];
			}
			if (0 < count($rReports)) {
				foreach (ConnectionTracker::getUserConnections($rReports, true) as $rUserID => $rConnections) {
					$rReturn['open_connections'] += $rConnections;
					if (0 < $rConnections) {
						$rReturn['online_users']++;
					}
				}
			}
		} else {
			$db->query('SELECT COUNT(`activity_id`) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			$rReturn['open_connections'] = ($db->get_row()['count'] ?: 0);
			$db->query('SELECT `activity_id` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') GROUP BY `lines_live`.`user_id`;');
			$rReturn['online_users'] = $db->num_rows();
		}

		$db->query('SELECT COUNT(`id`) AS `count` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
		$rReturn['active_accounts'] = ($db->get_row()['count'] ?: 0);
		$db->query('SELECT SUM(`credits`) AS `credits` FROM `users` WHERE `id` IN (' . implode(',', $rUserInfo['reports']) . ');');
		$rReturn['credits'] = ($db->get_row()['credits'] ?: 0);
		$rReturn['credits_assigned'] = ($rReturn['credits'] - intval($rUserInfo['credits']) ?: 0);
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Output the reseller's active connections (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleConnections(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['reseller_client_connection_logs']) {
			$rStreamID = RequestManager::get('stream_id');
			$rSub = RequestManager::get('sub');

			if ($rSub == 'purge') {
				if (SettingsManager::getBool('redis_handler')) {
					$rReports = array();
					$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
					foreach ($db->get_rows() as $rRow) {
						$rReports[] = $rRow['id'];
					}
					$rConnections = ConnectionTracker::getRedisConnections(null, null, $rStreamID, true, false, false, false);
					foreach ($rConnections as $rConnection) {
						if (in_array($rConnection['user_id'], $rReports)) {
							ConnectionTracker::closeConnection($rConnection);
						}
					}
				} else {
					$db->query('SELECT `lines_live`.* FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `lines_live`.`stream_id` = ? AND `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');', $rStreamID);
					foreach ($db->get_rows() as $rRow) {
						ConnectionTracker::closeConnection($rRow);
					}
				}
				echo json_encode(array('result' => true));
				exit();
			}

			echo json_encode(array('result' => false));
			exit();
		}
		exit();
	}

	/**
	 * Create/edit a line via the reseller API (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleLine(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['create_line']) {
			$rSub = RequestManager::get('sub');
			$rUserID = intval(RequestManager::get('user_id'));
			$rLine = UserRepository::getLineById($rUserID);

			if (Authorization::check('line', $rUserID) && $rLine) {
				if ($rSub == 'delete') {
					LineService::deleteLineById($rUserID);
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rLine));
					echo json_encode(array('result' => true));
					exit();
				}

				if ($rSub == 'enable') {
					$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rUserID);
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rLine));
					echo json_encode(array('result' => true));
					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rUserID);
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rLine));
					echo json_encode(array('result' => true));
					exit();
				}

				if ($rSub == 'reset_isp') {
					$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `id` = ?;", $rUserID);
					echo json_encode(array('result' => true));
					exit();
				}

				if ($rSub == 'kill_line') {
					if ($rPermissions['reseller_client_connection_logs']) {
						if (SettingsManager::getBool('redis_handler')) {
							foreach (ConnectionTracker::getUserConnections(array($rUserID), false)[$rUserID] as $rConnection) {
								ConnectionTracker::closeConnection($rConnection);
							}
						} else {
							$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rUserID);
							if ($db->num_rows() > 0) {
								foreach ($db->get_rows() as $rRow) {
									ConnectionTracker::closeConnection($rRow);
								}
							}
						}
						echo json_encode(array('result' => true));
						exit();
					}
					exit();
				}

				echo json_encode(array('result' => false));
				exit();
			}

			echo json_encode(array('result' => false, 'error' => 'No permissions.'));
			exit();
		}
		exit();
	}

	/**
	 * Output a line's activity log (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleLineActivity(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['reseller_client_connection_logs']) {
			$rSub = RequestManager::get('sub');

			if ($rSub == 'kill') {
				if (SettingsManager::getBool('redis_handler')) {
					$raw = RedisManager::instance()->get(RequestManager::get('uuid'));
					$rActivityInfo = ($raw !== false) ? igbinary_unserialize($raw) : null;
					if ($rActivityInfo) {
						if (Authorization::check('line', $rActivityInfo['user_id'])) {
							ConnectionTracker::closeConnection($rActivityInfo);
							echo json_encode(array('result' => true));
							exit();
						}
						echo json_encode(array('result' => false, 'error' => 'No permissions.'));
						exit();
					}
				} else {
					$db->query('SELECT * FROM `lines_live` WHERE `uuid` = ? LIMIT 1;', RequestManager::get('uuid'));
					if ($db->num_rows() == 1) {
						$rRow = $db->get_row();
						if (Authorization::check('line', $rRow['user_id'])) {
							ConnectionTracker::closeConnection($rRow);
							echo json_encode(array('result' => true));
							exit();
						}
						echo json_encode(array('result' => false, 'error' => 'No permissions.'));
						exit();
					}
				}
			}

			echo json_encode(array('result' => false));
			exit();
		}
		exit();
	}

	/**
	 * Adjust a sub-reseller's credit balance (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleAdjustCredits(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['create_sub_resellers']) {
			if (Authorization::check('user', RequestManager::get('id'))) {
				$rUser = UserRepository::getRegisteredUserById(RequestManager::get('id'));

				if ($rUser && is_numeric(RequestManager::get('credits'))) {
					$rOwnerCredits = intval($rUserInfo['credits']) - intval(RequestManager::get('credits'));
					$rCredits = intval($rUser['credits']) + intval(RequestManager::get('credits'));

					if (0 <= $rCredits && 0 <= $rOwnerCredits) {
						$db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rOwnerCredits, $rUserInfo['id']);
						$db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rCredits, $rUser['id']);
						$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUser['id'], $rUserInfo['id'], RequestManager::get('credits'), time(), RequestManager::get('reason'));
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'adjust_credits', RequestManager::get('id'), intval(RequestManager::get('credits')), $rOwnerCredits, time(), json_encode($rUser));
						echo json_encode(array('result' => true));
						exit();
					}
				}

				echo json_encode(array('result' => false));
				exit();
			}

			echo json_encode(array('result' => false, 'error' => 'No permissions.'));
			exit();
		}
		exit();
	}

	/**
	 * Create/edit a registered user (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleRegUser(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['create_sub_resellers']) {
			if (Authorization::check('user', RequestManager::get('user_id'))) {
				$rSub = RequestManager::get('sub');
				$rUser = UserRepository::getRegisteredUserById(RequestManager::get('user_id'));

				if ($rSub == 'delete') {
					if ($rPermissions['delete_users']) {
						$rOwnerCredits = intval($rUserInfo['credits']) + intval($rUser['credits']);
						$db->query('UPDATE `users` SET `credits` = ? WHERE `id` = ?;', $rOwnerCredits, $rUserInfo['id']);
						UserService::deleteRegisteredUser(RequestManager::get('user_id'), false, false, $rUserInfo['id']);
						$db->query('INSERT INTO `users_credits_logs`(`target_id`, `admin_id`, `amount`, `date`, `reason`) VALUES(?, ?, ?, ?, ?);', $rUserInfo['id'], $rUserInfo['id'], intval($rUser['credits']), time(), 'Deleted user: ' . $rUser['username']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', RequestManager::get('user_id'), intval($rUser['credits']), $rOwnerCredits, time(), json_encode($rUser));
						echo json_encode(array('result' => true));
						exit();
					}
					exit();
				}

				if ($rSub == 'enable') {
					$db->query('UPDATE `users` SET `status` = 1 WHERE `id` = ?;', RequestManager::get('user_id'));
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rUser));
					echo json_encode(array('result' => true));
					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `users` SET `status` = 0 WHERE `id` = ?;', RequestManager::get('user_id'));
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'user', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rUser));
					echo json_encode(array('result' => true));
					exit();
				}

				echo json_encode(array('result' => false));
				exit();
			}

			echo json_encode(array('result' => false, 'error' => 'No permissions.'));
			exit();
		}
		exit();
	}

	/**
	 * Submit/handle a support ticket (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleTicket(array $rUserInfo, array $rPermissions, $db): void {
		$rTicket = TicketRepository::getById(RequestManager::get('ticket_id'));

		if ($rTicket) {
			if (Authorization::check('user', $rTicket['member_id'])) {
				$rSub = RequestManager::get('sub');

				if ($rSub == 'close') {
					$db->query('UPDATE `tickets` SET `status` = 0 WHERE `id` = ?;', RequestManager::get('ticket_id'));
					echo json_encode(array('result' => true));
					exit();
				}

				if ($rSub == 'reopen') {
					if ($rTicket['member_id'] != $rUserInfo['id']) {
						$db->query('UPDATE `tickets` SET `status` = 1 WHERE `id` = ?;', RequestManager::get('ticket_id'));
						echo json_encode(array('result' => true));
						exit();
					}
					exit();
				}
			} else {
				echo json_encode(array('result' => false, 'error' => 'No permissions.'));
				exit();
			}
		}

		echo json_encode(array('result' => false));
		exit();
	}

	/**
	 * Create/edit a MAG device (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleMag(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['create_mag']) {
			$rSub = RequestManager::get('sub');
			$rMagDetails = MagService::getById(intval(RequestManager::get('mag_id')));

			if ($rMagDetails) {
				if (Authorization::check('line', $rMagDetails['user_id'])) {
					if ($rSub == 'delete') {
						MagService::deleteDevice(RequestManager::get('mag_id'));
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', RequestManager::get('mag_id'), 0, $rUserInfo['credits'], time(), json_encode($rMagDetails));
						echo json_encode(array('result' => true));
						exit();
					}

					if ($rSub == 'enable') {
						$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rMagDetails['user_id']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', RequestManager::get('mag_id'), 0, $rUserInfo['credits'], time(), json_encode($rMagDetails));
						echo json_encode(array('result' => true));
						exit();
					}

					if ($rSub == 'disable') {
						$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rMagDetails['user_id']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', RequestManager::get('mag_id'), 0, $rUserInfo['credits'], time(), json_encode($rMagDetails));
						echo json_encode(array('result' => true));
						exit();
					}

					if ($rSub == 'convert') {
						MagService::deleteDevice(RequestManager::get('mag_id'), false, false, true);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'convert', $rMagDetails['user']['id'], 0, $rUserInfo['credits'], time(), json_encode($rMagDetails['user']));
						echo json_encode(array('result' => true, 'line_id' => $rMagDetails['user']['id']));
						exit();
					}

					if ($rSub == 'reset_isp') {
						$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `id` = ?;", $rMagDetails['user']['id']);
						echo json_encode(array('result' => true));
						exit();
					}

					if ($rSub == 'kill_line') {
						if ($rPermissions['reseller_client_connection_logs']) {
							if (SettingsManager::getBool('redis_handler')) {
								foreach (ConnectionTracker::getUserConnections(array($rMagDetails['user_id']), false)[$rMagDetails['user_id']] as $rConnection) {
									ConnectionTracker::closeConnection($rConnection);
								}
							} else {
								$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rMagDetails['user_id']);
								if ($db->num_rows() > 0) {
									foreach ($db->get_rows() as $rRow) {
										ConnectionTracker::closeConnection($rRow);
									}
								}
							}
							echo json_encode(array('result' => true));
							exit();
						}
						exit();
					}
				} else {
					echo json_encode(array('result' => false, 'error' => 'No permissions.'));
					exit();
				}
			}

			echo json_encode(array('result' => false));
			exit();
		}
		exit();
	}

	/**
	 * Create/edit an Enigma2 device (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleEnigma(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['create_enigma']) {
			$rSub = RequestManager::get('sub');
			$rE2Details = EnigmaService::getById(intval(RequestManager::get('e2_id')));

			if ($rE2Details) {
				if (Authorization::check('line', $rE2Details['user_id'])) {
					if ($rSub == 'delete') {
						EnigmaService::deleteDevice(RequestManager::get('e2_id'));
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'enigma', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', RequestManager::get('e2_id'), 0, $rUserInfo['credits'], time(), json_encode($rE2Details));
						echo json_encode(array('result' => true));
						exit();
					}

					if ($rSub == 'enable') {
						$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rE2Details['user_id']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'enigma', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', RequestManager::get('e2_id'), 0, $rUserInfo['credits'], time(), json_encode($rE2Details));
						echo json_encode(array('result' => true));
						exit();
					}

					if ($rSub == 'disable') {
						$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rE2Details['user_id']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'enigma', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', RequestManager::get('e2_id'), 0, $rUserInfo['credits'], time(), json_encode($rE2Details));
						echo json_encode(array('result' => true));
						exit();
					}

					if ($rSub == 'convert') {
						EnigmaService::deleteDevice(RequestManager::get('e2_id'), false, false, true);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'convert', $rE2Details['user']['id'], 0, $rUserInfo['credits'], time(), json_encode($rE2Details['user']));
						echo json_encode(array('result' => true, 'line_id' => $rE2Details['user']['id']));
						exit();
					}

					if ($rSub == 'reset_isp') {
						$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `id` = ?;", $rE2Details['user']['id']);
						echo json_encode(array('result' => true));
						exit();
					}

					if ($rSub == 'kill_line') {
						if ($rPermissions['reseller_client_connection_logs']) {
							if (SettingsManager::getBool('redis_handler')) {
								foreach (ConnectionTracker::getUserConnections(array($rE2Details['user_id']), false)[$rE2Details['user_id']] as $rConnection) {
									ConnectionTracker::closeConnection($rConnection);
								}
							} else {
								$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rE2Details['user_id']);
								if ($db->num_rows() > 0) {
									foreach ($db->get_rows() as $rRow) {
										ConnectionTracker::closeConnection($rRow);
									}
								}
							}
							echo json_encode(array('result' => true));
							exit();
						}
						exit();
					}
				} else {
					echo json_encode(array('result' => false, 'error' => 'No permissions.'));
					exit();
				}
			}

			echo json_encode(array('result' => false));
			exit();
		}
		exit();
	}

	/**
	 * Output package details/options (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleGetPackage(array $rUserInfo, array $rPermissions, $db): void {
		$rReturn = array();
		$rOverride = json_decode($rUserInfo['override_packages'], true);
		$db->query('SELECT `id`, `bouquets`, `official_credits` AS `cost_credits`, `official_duration`, `official_duration_in`, `max_connections`, `check_compatible`, `is_isplock` FROM `users_packages` WHERE `id` = ?;', RequestManager::get('package_id'));

		if ($db->num_rows() == 1) {
			$rData = $db->get_row();

			if (isset($rOverride[$rData['id']]['official_credits']) && 0 < strlen($rOverride[$rData['id']]['official_credits'])) {
				$rData['cost_credits'] = $rOverride[$rData['id']]['official_credits'];
			}

			if (RequestManager::has('orig_id') && $rData['check_compatible']) {
				$rData['compatible'] = PackageService::checkCompatible(RequestManager::get('package_id'), RequestManager::get('orig_id'));
			} else {
				$rData['compatible'] = true;
			}

			$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in']));

			if (RequestManager::has('user_id') && $rData['compatible']) {
				$rUser = UserRepository::getLineById(RequestManager::get('user_id'));
				if ($rUser) {
					if (time() < $rUser['exp_date']) {
						$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in'], $rUser['exp_date']));
					} else {
						$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in']));
					}
				}
			}

			foreach (json_decode($rData['bouquets'], true) as $rBouquet) {
				$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rBouquet);
				if ($db->num_rows() == 1) {
					$rRow = $db->get_row();
					$rReturn[] = array('id' => $rRow['id'], 'bouquet_name' => str_replace("'", "\\'", $rRow['bouquet_name']), 'bouquet_channels' => json_decode($rRow['bouquet_channels'], true), 'bouquet_radios' => json_decode($rRow['bouquet_radios'], true), 'bouquet_movies' => json_decode($rRow['bouquet_movies'], true), 'bouquet_series' => json_decode($rRow['bouquet_series'], true));
				}
			}
			$rData['duration'] = $rData['official_duration'] . ' ' . $rData['official_duration_in'];
			echo json_encode(array('result' => true, 'bouquets' => $rReturn, 'data' => $rData));
		} else {
			echo json_encode(array('result' => false));
		}
		exit();
	}

	/**
	 * Output trial package details (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleGetPackageTrial(array $rUserInfo, array $rPermissions, $db): void {
		$rReturn = array();
		$db->query('SELECT `bouquets`, `trial_credits` AS `cost_credits`, `trial_duration`, `trial_duration_in`, `max_connections`, `is_isplock` FROM `users_packages` WHERE `id` = ?;', RequestManager::get('package_id'));

		if ($db->num_rows() == 1) {
			$rData = $db->get_row();
			$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['trial_duration']) . ' ' . $rData['trial_duration_in']));

			foreach (json_decode($rData['bouquets'], true) as $rBouquet) {
				$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rBouquet);
				if ($db->num_rows() == 1) {
					$rRow = $db->get_row();
					$rReturn[] = array('id' => $rRow['id'], 'bouquet_name' => str_replace("'", "\\'", $rRow['bouquet_name']), 'bouquet_channels' => json_decode($rRow['bouquet_channels'], true), 'bouquet_radios' => json_decode($rRow['bouquet_radios'], true), 'bouquet_movies' => json_decode($rRow['bouquet_movies'], true), 'bouquet_series' => json_decode($rRow['bouquet_series'], true));
				}
			}
			$rData['duration'] = $rData['trial_duration'] . ' ' . $rData['trial_duration_in'];
			$rData['compatible'] = true;
			echo json_encode(array('result' => true, 'bouquets' => $rReturn, 'data' => $rData));
		} else {
			echo json_encode(array('result' => false));
		}
		exit();
	}

	/**
	 * Output header summary statistics (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleHeaderStats(array $rUserInfo, array $rPermissions, $db): void {
		$rReturn = array('total_connections' => 0, 'total_users' => 0);

		if (SettingsManager::getBool('redis_handler')) {
			$rReports = array();
			$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			foreach ($db->get_rows() as $rRow) {
				$rReports[] = $rRow['id'];
			}
			if (0 < count($rReports)) {
				foreach (ConnectionTracker::getUserConnections($rReports, true) as $rUserID => $rConnections) {
					$rReturn['total_connections'] += $rConnections;
					if (0 < $rConnections) {
						$rReturn['total_users']++;
					}
				}
			}
		} else {
			$db->query('SELECT COUNT(`activity_id`) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			$rReturn['total_connections'] = ($db->get_row()['count'] ?: 0);
			$db->query('SELECT `activity_id` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') GROUP BY `lines_live`.`user_id`;');
			$rReturn['total_users'] = $db->num_rows();
		}

		echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
		exit();
	}

	/**
	 * Output reseller statistics (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleStats(array $rUserInfo, array $rPermissions, $db): void {
		$rReturn = array('open_connections' => 0, 'online_users' => 0, 'total_lines' => 0, 'total_users' => 0, 'owner_credits' => 0, 'user_credits' => 0, 'total_credits' => 0);

		if (SettingsManager::getBool('redis_handler')) {
			$rReports = array();
			$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			foreach ($db->get_rows() as $rRow) {
				$rReports[] = $rRow['id'];
			}
			if (0 < count($rReports)) {
				foreach (ConnectionTracker::getUserConnections($rReports, true) as $rUserID => $rConnections) {
					$rReturn['open_connections'] += $rConnections;
					if (0 < $rConnections) {
						$rReturn['online_users']++;
					}
				}
			}
		} else {
			$db->query('SELECT COUNT(`activity_id`) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			$rReturn['open_connections'] = ($db->get_row()['count'] ?: 0);
			$db->query('SELECT `activity_id` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') GROUP BY `lines_live`.`user_id`;');
			$rReturn['online_users'] = $db->num_rows();
		}

		$db->query('SELECT COUNT(*) AS `count` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
		$rReturn['total_lines'] = $db->get_row()['count'];
		$db->query('SELECT COUNT(*) AS `count`, SUM(`credits`) AS `credits` FROM `users` WHERE `owner_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
		$rRow = $db->get_row();
		$rReturn['total_users'] = $rRow['count'];
		$rReturn['user_credits'] = $rRow['credits'];
		$rReturn['owner_credits'] = $rUserInfo['credits'];
		$rReturn['total_credits'] = $rReturn['owner_credits'] + $rReturn['user_credits'];
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Output the reseller's user list (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleUserList(array $rUserInfo, array $rPermissions, $db): void {
		$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

		if (RequestManager::has('search')) {
			$rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;

			$db->query('SELECT COUNT(`id`) AS `id` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') AND (`lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ?);', '%' . RequestManager::get('search') . '%', '%' . RequestManager::get('search') . '%', '%' . RequestManager::get('search') . '%');
			$rReturn['total_count'] = $db->get_row()['id'];
			$db->query('SELECT `id`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ') AND (`lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ?) ORDER BY `username` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%', '%' . RequestManager::get('search') . '%', '%' . RequestManager::get('search') . '%');

			if ($db->num_rows() > 0) {
				foreach ($db->get_rows() as $rRow) {
					$rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['username']);
				}
			}
		}

		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Send an event/signal (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleSendEvent(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['create_mag']) {
			$rData = json_decode(RequestManager::get('data'), true);
			$rMag = MagService::getById($rData['id']);

			if ($rMag) {
				if (Authorization::check('line', $rMag['user_id'])) {
					if ($rData['type'] == 'send_msg') {
						$rData['need_confirm'] = 1;
					} else if ($rData['type'] == 'play_channel') {
						$rData['need_confirm'] = 0;
						$rData['reboot_portal'] = 0;
						$rData['message'] = intval($rData['channel']);
					} else if ($rData['type'] == 'reset_stb_lock') {
						MagService::resetSTB($rData['id']);
						echo json_encode(array('result' => true));
						exit();
					} else {
						$rData['need_confirm'] = 0;
						$rData['reboot_portal'] = 0;
						$rData['message'] = '';
					}

					if ($db->query('INSERT INTO `mag_events`(`status`, `mag_device_id`, `event`, `need_confirm`, `msg`, `reboot_after_ok`, `send_time`) VALUES (0, ?, ?, ?, ?, ?, ?);', $rData['id'], $rData['type'], $rData['need_confirm'], $rData['message'], $rData['reboot_portal'], time())) {
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'send_event', $rMag['mag_id'], 0, $rUserInfo['credits'], time(), json_encode($rMag));
						echo json_encode(array('result' => true));
						exit();
					}
				} else {
					echo json_encode(array('result' => false, 'error' => 'No permissions.'));
					exit();
				}
			}

			echo json_encode(array('result' => false));
			exit();
		}
		exit();
	}

	/**
	 * Output the available stream list (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleStreamList(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['create_mag'] || $rPermissions['can_view_vod'] || $rPermissions['reseller_client_connection_logs']) {
			$rReturn = array('total_count' => 0, 'items' => array(), 'result' => true);

			if (RequestManager::has('search')) {
				$rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;

				$db->query('SELECT COUNT(`id`) AS `id` FROM `streams` WHERE `stream_display_name` LIKE ? AND `id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ');', '%' . RequestManager::get('search') . '%');
				$rReturn['total_count'] = $db->get_row()['id'];
				$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ') AND `stream_display_name` LIKE ? ORDER BY `stream_display_name` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%');

				if ($db->num_rows() > 0) {
					foreach ($db->get_rows() as $rRow) {
						$rReturn['items'][] = array('id' => $rRow['id'], 'text' => $rRow['stream_display_name']);
					}
				}
			}

			echo json_encode($rReturn);
			exit();
		}
		exit();
	}

	/**
	 * Output WHOIS information for an IP (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleIpWhois(array $rUserInfo, array $rPermissions, $db): void {
		$rIP = RequestManager::get('ip');
		$rReader = new \MaxMind\Db\Reader(GEOLITE2C_BIN);
		$rResponse = $rReader->get($rIP);

		if (isset($rResponse['location']['time_zone'])) {
			$rDate = new \DateTime('now', new \DateTimeZone($rResponse['location']['time_zone']));
			$rResponse['location']['time'] = $rDate->format('Y-m-d H:i:s');
		}

		$rReader->close();

		if (RequestManager::has('isp')) {
			$rReader = new \MaxMind\Db\Reader(GEOISP_BIN);
			$rResponse['isp'] = $rReader->get($rIP);
			$rReader->close();
		}

		$rResponse['type'] = null;

		if (!empty($rResponse['isp']['autonomous_system_number'])) {
			$db->query('SELECT `type` FROM `blocked_asns` WHERE `asn` = ?;', $rResponse['isp']['autonomous_system_number']);
			if ($db->num_rows() > 0) {
				$rResponse['type'] = $db->get_row()['type'];
			}
		}

		echo json_encode(array('result' => true, 'data' => $rResponse));
		exit();
	}

	/**
	 * Output EPG data for a stream (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleGetEpg(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['can_view_vod']) {
			if (count($rPermissions['stream_ids']) != 0) {
				$rTimezone = (RequestManager::get('timezone') ?: 'Europe/London');
				date_default_timezone_set($rTimezone);
				$rReturn = array('Channels' => array());
				$rChannels = array_map('intval', explode(',', RequestManager::get('channels')));

				if (count($rChannels) != 0) {
					$rHours = (intval(RequestManager::get('hours')) ?: 3);
					$rStartDate = (intval(strtotime(RequestManager::get('startdate'))) ?: time());
					$rFinishDate = $rStartDate + $rHours * 3600;
					$rPerUnit = floatval(100 / ($rHours * 60));
					$rChannelsSort = $rChannels;
					sort($rChannelsSort);
					$rListings = array();

					$rArchiveInfo = array();
					$db->query('SELECT `id`, `tv_archive_server_id`, `tv_archive_duration` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ');');
					if ($db->num_rows() > 0) {
						foreach ($db->get_rows() as $rRow) {
							$rArchiveInfo[$rRow['id']] = $rRow;
						}
					}

					$rEPG = EpgService::getStreamsEpg($rChannels, $rStartDate, $rFinishDate);

					foreach ($rEPG as $rChannelID => $rEPGData) {
						$rFullSize = 0;

						foreach ($rEPGData as $rEPGItem) {
							$rCapStart = ($rEPGItem['start'] < $rStartDate ? $rStartDate : $rEPGItem['start']);
							$rCapEnd = ($rFinishDate < $rEPGItem['end'] ? $rFinishDate : $rEPGItem['end']);
							$rDuration = ($rCapEnd - $rCapStart) / 60;
							$rArchive = null;

							if (isset($rArchiveInfo[$rChannelID])) {
								if (0 < $rArchiveInfo[$rChannelID]['tv_archive_server_id'] && 0 < $rArchiveInfo[$rChannelID]['tv_archive_duration']) {
									if (!(time() - $rArchiveInfo[$rChannelID]['tv_archive_duration'] * 86400 > $rEPGItem['start'])) {
										$rArchive = array($rEPGItem['start'], intval(($rEPGItem['end'] - $rEPGItem['start']) / 60));
									}
								}
							}

							$rRelativeSize = round($rDuration * $rPerUnit, 2);
							$rFullSize += $rRelativeSize;

							if (100 < $rFullSize) {
								$rRelativeSize -= $rFullSize - 100;
							}

							$rListings[$rChannelID][] = array('ListingId' => $rEPGItem['id'], 'ChannelId' => $rChannelID, 'Title' => $rEPGItem['title'], 'RelativeSize' => $rRelativeSize, 'StartTime' => date('h:iA', $rCapStart), 'EndTime' => date('h:iA', $rCapEnd), 'Start' => $rEPGItem['start'], 'End' => $rEPGItem['end'], 'Specialisation' => 'tv', 'Archive' => $rArchive);
						}
					}

					$rDefaultEPG = array('ChannelId' => null, 'Title' => 'No Programme Information...', 'RelativeSize' => 100, 'StartTime' => 'Not Available', 'EndTime' => '', 'Specialisation' => 'tv', 'Archive' => null);
					$db->query('SELECT `id`, `stream_icon`, `stream_display_name`, `tv_archive_duration`, `tv_archive_server_id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ') ORDER BY FIELD(`id`, ' . implode(',', $rChannels) . ') ASC;');

					foreach ($db->get_rows() as $rStream) {
						if (0 < $rStream['tv_archive_duration'] && 0 < $rStream['tv_archive_server_id']) {
							$rArchive = $rStream['tv_archive_duration'];
						} else {
							$rArchive = 0;
						}

						$rDefaultArray = $rDefaultEPG;
						$rDefaultArray['ChannelId'] = $rStream['id'];
						$rCategoryIDs = json_decode($rStream['category_id'], true);
						$rCategories = CategoryService::getAllByType('live');

						if (0 < strlen(RequestManager::get('category'))) {
							$rCategory = ($rCategories[intval(RequestManager::get('category'))]['category_name'] ?: 'No Category');
						} else {
							$rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
						}

						if (1 < count($rCategoryIDs)) {
							$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
						}

						$rReturn['Channels'][] = array('Id' => $rStream['id'], 'DisplayName' => $rStream['stream_display_name'], 'CategoryName' => $rCategory, 'Archive' => $rArchive, 'Image' => (ImageUtils::validateURL($rStream['stream_icon']) ?: ''), 'TvListings' => ($rListings[$rStream['id']] ?? array($rDefaultArray)));
					}
					echo json_encode($rReturn);
					exit();
				}

				echo json_encode($rReturn);
				exit();
			}
			exit();
		}
		exit();
	}

	/**
	 * Output a single EPG programme (JSON) and exit.
	 *
	 * @param array  $rUserInfo    Authenticated reseller user.
	 * @param array  $rPermissions Effective permissions.
	 * @param \XcVm\Core\Database\DatabaseHandler $db           Database handler.
	 * @return void
	 */
	private static function handleGetProgramme(array $rUserInfo, array $rPermissions, $db): void {
		if ($rPermissions['can_view_vod']) {
			$rTimezone = (RequestManager::get('timezone') ?: 'Europe/London');
			date_default_timezone_set($rTimezone);

			if (RequestManager::has('id')) {
				$rRow = EpgService::getProgramme(RequestManager::get('stream_id'), RequestManager::get('id'));

				if ($rRow) {
					$rArchive = $rAvailable = false;

					if (time() < $rRow['end']) {
						$db->query('SELECT `server_id`, `direct_source`, `monitor_pid`, `pid`, `stream_status`, `on_demand` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`id` = ? AND `server_id` IS NOT NULL;', RequestManager::get('stream_id'));
						if ($db->num_rows() > 0) {
							foreach ($db->get_rows() as $rStreamRow) {
								if ($rStreamRow['server_id'] && !$rStreamRow['direct_source']) {
									$rAvailable = true;
									break;
								}
							}
						}
					}

					$rRow['date'] = date('H:i', $rRow['start']) . ' - ' . date('H:i', $rRow['end']);
					echo json_encode(array('result' => true, 'data' => $rRow, 'available' => $rAvailable, 'archive' => $rArchive));
					exit();
				}
			}

			echo json_encode(array('result' => false));
			exit();
		}
		exit();
	}
}
