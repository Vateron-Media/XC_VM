<?php

namespace XcVm\Public\Controllers\Reseller\Ajax;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Reseller-ajax controller for MAG and Enigma2 devices.
 *
 * Actions: mag, enigma, send_event.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DeviceAjaxController extends BaseResellerAjaxController {
	use DatabaseAware;

	/**
	 * Create/edit a MAG device (JSON) and exit.
	 */
	public function mag(): void {
		[$rUserInfo, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['create_mag']) {
			$rSub = RequestManager::get('sub');
			$rMagDetails = MagService::getById(intval(RequestManager::get('mag_id')));

			if ($rMagDetails) {
				if (Authorization::check('line', $rMagDetails['user_id'])) {
					if ($rSub == 'delete') {
						MagService::deleteDevice(RequestManager::get('mag_id'));
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', RequestManager::get('mag_id'), 0, $rUserInfo['credits'], time(), json_encode($rMagDetails));
						echo json_encode(['result' => true]);
						exit();
					}

					if ($rSub == 'enable') {
						// A line that waits for an activation code is switched on by redeeming the code.
						if (ActiveCodeService::lineAwaitsRedemption($rMagDetails['user'] ?: [])) {
							echo json_encode(['result' => false]);
							exit();
						}
						$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rMagDetails['user_id']);
						LineService::updateLineSignal($rMagDetails['user_id']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', RequestManager::get('mag_id'), 0, $rUserInfo['credits'], time(), json_encode($rMagDetails));
						echo json_encode(['result' => true]);
						exit();
					}

					if ($rSub == 'disable') {
						$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rMagDetails['user_id']);
						LineService::updateLineSignal($rMagDetails['user_id']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', RequestManager::get('mag_id'), 0, $rUserInfo['credits'], time(), json_encode($rMagDetails));
						echo json_encode(['result' => true]);
						exit();
					}

					if ($rSub == 'convert') {
						MagService::deleteDevice(RequestManager::get('mag_id'), false, false, true);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'convert', $rMagDetails['user']['id'], 0, $rUserInfo['credits'], time(), json_encode($rMagDetails['user']));
						echo json_encode(['result' => true, 'line_id' => $rMagDetails['user']['id']]);
						exit();
					}

					if ($rSub == 'reset_isp') {
						$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `id` = ?;", $rMagDetails['user']['id']);
						echo json_encode(['result' => true]);
						exit();
					}

					if ($rSub == 'kill_line') {
						if ($rPermissions['reseller_client_connection_logs']) {
							if (SettingsManager::getBool('redis_handler')) {
								foreach (ConnectionTracker::getUserConnections([$rMagDetails['user_id']], false)[$rMagDetails['user_id']] as $rConnection) {
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
							echo json_encode(['result' => true]);
							exit();
						}
						exit();
					}
				} else {
					echo json_encode(['result' => false, 'error' => 'No permissions.']);
					exit();
				}
			}

			echo json_encode(['result' => false]);
			exit();
		}
		exit();
	}

	/**
	 * Create/edit an Enigma2 device (JSON) and exit.
	 */
	public function enigma(): void {
		[$rUserInfo, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['create_enigma']) {
			$rSub = RequestManager::get('sub');
			$rE2Details = EnigmaService::getById(intval(RequestManager::get('e2_id')));

			if ($rE2Details) {
				if (Authorization::check('line', $rE2Details['user_id'])) {
					if ($rSub == 'delete') {
						EnigmaService::deleteDevice(RequestManager::get('e2_id'));
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'enigma', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', RequestManager::get('e2_id'), 0, $rUserInfo['credits'], time(), json_encode($rE2Details));
						echo json_encode(['result' => true]);
						exit();
					}

					if ($rSub == 'enable') {
						// A line that waits for an activation code is switched on by redeeming the code.
						if (ActiveCodeService::lineAwaitsRedemption($rE2Details['user'] ?: [])) {
							echo json_encode(['result' => false]);
							exit();
						}
						$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rE2Details['user_id']);
						LineService::updateLineSignal($rE2Details['user_id']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'enigma', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', RequestManager::get('e2_id'), 0, $rUserInfo['credits'], time(), json_encode($rE2Details));
						echo json_encode(['result' => true]);
						exit();
					}

					if ($rSub == 'disable') {
						$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rE2Details['user_id']);
						LineService::updateLineSignal($rE2Details['user_id']);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'enigma', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', RequestManager::get('e2_id'), 0, $rUserInfo['credits'], time(), json_encode($rE2Details));
						echo json_encode(['result' => true]);
						exit();
					}

					if ($rSub == 'convert') {
						EnigmaService::deleteDevice(RequestManager::get('e2_id'), false, false, true);
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'convert', $rE2Details['user']['id'], 0, $rUserInfo['credits'], time(), json_encode($rE2Details['user']));
						echo json_encode(['result' => true, 'line_id' => $rE2Details['user']['id']]);
						exit();
					}

					if ($rSub == 'reset_isp') {
						$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `id` = ?;", $rE2Details['user']['id']);
						echo json_encode(['result' => true]);
						exit();
					}

					if ($rSub == 'kill_line') {
						if ($rPermissions['reseller_client_connection_logs']) {
							if (SettingsManager::getBool('redis_handler')) {
								foreach (ConnectionTracker::getUserConnections([$rE2Details['user_id']], false)[$rE2Details['user_id']] as $rConnection) {
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
							echo json_encode(['result' => true]);
							exit();
						}
						exit();
					}
				} else {
					echo json_encode(['result' => false, 'error' => 'No permissions.']);
					exit();
				}
			}

			echo json_encode(['result' => false]);
			exit();
		}
		exit();
	}

	/**
	 * Send an event/signal (JSON) and exit.
	 */
	public function sendEvent(): void {
		[$rUserInfo, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['create_mag']) {
			$rData = json_decode(RequestManager::get('data'), true);
			$rMag = MagService::getById($rData['id']);

			if ($rMag) {
				if (Authorization::check('line', $rMag['user_id'])) {
					if ($rData['type'] == 'send_msg') {
						$rData['need_confirm'] = 1;
					} elseif ($rData['type'] == 'play_channel') {
						$rData['need_confirm'] = 0;
						$rData['reboot_portal'] = 0;
						$rData['message'] = intval($rData['channel']);
					} elseif ($rData['type'] == 'reset_stb_lock') {
						$rReset = MagService::resetSTBForReseller((int) $rData['id']);
						echo json_encode(['result' => $rReset]);
						exit();
					} else {
						$rData['need_confirm'] = 0;
						$rData['reboot_portal'] = 0;
						$rData['message'] = '';
					}

					if ($db->query('INSERT INTO `mag_events`(`status`, `mag_device_id`, `event`, `need_confirm`, `msg`, `reboot_after_ok`, `send_time`) VALUES (0, ?, ?, ?, ?, ?, ?);', $rData['id'], $rData['type'], $rData['need_confirm'], $rData['message'], $rData['reboot_portal'], time())) {
						$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'mag', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'send_event', $rMag['mag_id'], 0, $rUserInfo['credits'], time(), json_encode($rMag));
						echo json_encode(['result' => true]);
						exit();
					}
				} else {
					echo json_encode(['result' => false, 'error' => 'No permissions.']);
					exit();
				}
			}

			echo json_encode(['result' => false]);
			exit();
		}
		exit();
	}
}
