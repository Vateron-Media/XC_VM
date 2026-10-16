<?php

namespace XcVm\Public\Controllers\Reseller\Ajax;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Line\ActiveCodeService;
use XcVm\Domain\Line\LineService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserCredits;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseAware;
use XcVm\Infrastructure\Redis\RedisManager;

/**
 * Reseller-ajax controller for lines, their connections and the packages they are sold.
 *
 * Actions: line, line_activity, connections, userlist, ip_whois, get_package, get_package_trial.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class LineAjaxController extends BaseResellerAjaxController {
	use DatabaseAware;

	/**
	 * Create/edit a line via the reseller API (JSON) and exit.
	 */
	public function line(): void {
		[$rUserInfo, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['create_line']) {
			$rSub = RequestManager::get('sub');
			$rUserID = intval(RequestManager::get('user_id'));
			$rLine = UserRepository::getLineById($rUserID);

			if (Authorization::check('line', $rUserID) && $rLine) {
				if ($rSub == 'delete') {
					LineService::deleteLineById($rUserID);
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'delete', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rLine));
					echo json_encode(['result' => true]);
					exit();
				}

				if ($rSub == 'enable') {
					// A line that waits for its activation code is switched on by redeeming the code.
					if (ActiveCodeService::lineAwaitsRedemption($rLine)) {
						echo json_encode(['result' => false]);
						exit();
					}
					$db->query('UPDATE `lines` SET `enabled` = 1 WHERE `id` = ?;', $rUserID);
					LineService::updateLineSignal($rUserID);
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'enable', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rLine));
					echo json_encode(['result' => true]);
					exit();
				}

				if ($rSub == 'disable') {
					$db->query('UPDATE `lines` SET `enabled` = 0 WHERE `id` = ?;', $rUserID);
					LineService::updateLineSignal($rUserID);
					$db->query("INSERT INTO `users_logs`(`owner`, `type`, `action`, `log_id`, `package_id`, `cost`, `credits_after`, `date`, `deleted_info`) VALUES(?, 'line', ?, ?, null, ?, ?, ?, ?);", $rUserInfo['id'], 'disable', RequestManager::get('user_id'), 0, $rUserInfo['credits'], time(), json_encode($rLine));
					echo json_encode(['result' => true]);
					exit();
				}

				if ($rSub == 'reset_isp') {
					$db->query("UPDATE `lines` SET `isp_desc` = '', `as_number` = NULL WHERE `id` = ?;", $rUserID);
					echo json_encode(['result' => true]);
					exit();
				}

				if ($rSub == 'kill_line') {
					if ($rPermissions['reseller_client_connection_logs']) {
						if (SettingsManager::getBool('redis_handler')) {
							foreach (ConnectionTracker::getUserConnections([$rUserID], false)[$rUserID] as $rConnection) {
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
						echo json_encode(['result' => true]);
						exit();
					}
					exit();
				}

				echo json_encode(['result' => false]);
				exit();
			}

			echo json_encode(['result' => false, 'error' => 'No permissions.']);
			exit();
		}
		exit();
	}

	/**
	 * Output a line's activity log (JSON) and exit.
	 */
	public function lineActivity(): void {
		[, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['reseller_client_connection_logs']) {
			$rSub = RequestManager::get('sub');

			if ($rSub == 'kill') {
				if (SettingsManager::getBool('redis_handler')) {
					// Redis may be momentarily unreachable (down, or in the connect backoff):
					// fail the kill gracefully instead of calling a method on null.
					$raw = ($rRedis = RedisManager::instance()) !== null ? $rRedis->get(RequestManager::get('uuid')) : false;
					$rActivityInfo = ($raw !== false) ? igbinary_unserialize($raw) : null;
					if ($rActivityInfo) {
						if (Authorization::check('line', $rActivityInfo['user_id'])) {
							ConnectionTracker::closeConnection($rActivityInfo);
							echo json_encode(['result' => true]);
							exit();
						}
						echo json_encode(['result' => false, 'error' => 'No permissions.']);
						exit();
					}
				} else {
					$db->query('SELECT * FROM `lines_live` WHERE `uuid` = ? LIMIT 1;', RequestManager::get('uuid'));
					if ($db->num_rows() == 1) {
						$rRow = $db->get_row();
						if (Authorization::check('line', $rRow['user_id'])) {
							ConnectionTracker::closeConnection($rRow);
							echo json_encode(['result' => true]);
							exit();
						}
						echo json_encode(['result' => false, 'error' => 'No permissions.']);
						exit();
					}
				}
			}

			echo json_encode(['result' => false]);
			exit();
		}
		exit();
	}

	/**
	 * Output the reseller's active connections (JSON) and exit.
	 */
	public function connections(): void {
		[$rUserInfo, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['reseller_client_connection_logs']) {
			$rStreamID = RequestManager::get('stream_id');
			$rSub = RequestManager::get('sub');

			if ($rSub == 'purge') {
				if (SettingsManager::getBool('redis_handler')) {
					$rReports = [];
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
				echo json_encode(['result' => true]);
				exit();
			}

			echo json_encode(['result' => false]);
			exit();
		}
		exit();
	}

	/**
	 * Output the reseller's user list (JSON) and exit.
	 */
	public function userlist(): void {
		[$rUserInfo] = $this->begin();
		$db = self::db();
		$rReturn = ['total_count' => 0, 'items' => [], 'result' => true];

		if (RequestManager::has('search')) {
			$rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;

			$db->query('SELECT COUNT(`id`) AS `id` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') AND (`lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ?);', '%' . RequestManager::get('search') . '%', '%' . RequestManager::get('search') . '%', '%' . RequestManager::get('search') . '%');
			$rReturn['total_count'] = $db->get_row()['id'];
			$db->query('SELECT `id`, IF(`lines`.`is_mag`, `mag_devices`.`mac`, IF(`lines`.`is_e2`, `enigma2_devices`.`mac`, `lines`.`username`)) AS `username` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ') AND (`lines`.`username` LIKE ? OR `mag_devices`.`mac` LIKE ? OR `enigma2_devices`.`mac` LIKE ?) ORDER BY `username` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%', '%' . RequestManager::get('search') . '%', '%' . RequestManager::get('search') . '%');

			if ($db->num_rows() > 0) {
				foreach ($db->get_rows() as $rRow) {
					$rReturn['items'][] = ['id' => $rRow['id'], 'text' => $rRow['username']];
				}
			}
		}

		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Output WHOIS information for an IP (JSON) and exit.
	 */
	public function ipWhois(): void {
		$this->begin();
		$db = self::db();
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
		echo json_encode(['result' => true, 'data' => $rResponse]);
		exit();
	}

	/**
	 * Output package details/options (JSON) and exit.
	 */
	public function getPackage(): void {
		[$rUserInfo] = $this->begin();
		$db = self::db();
		$rReturn = [];
		$rOverride = json_decode($rUserInfo['override_packages'], true);
		$db->query('SELECT `id`, `bouquets`, `official_credits` AS `cost_credits`, `official_duration`, `official_duration_in`, `max_connections`, `check_compatible`, `is_isplock` FROM `users_packages` WHERE `id` = ?;', RequestManager::get('package_id'));

		if ($db->num_rows() == 1) {
			$rData = UserCredits::amounts($db->get_row());

			if (isset($rOverride[$rData['id']]['official_credits']) && (string) $rOverride[$rData['id']]['official_credits'] !== '') {
				$rData['cost_credits'] = $rOverride[$rData['id']]['official_credits'];
			}

			if (RequestManager::has('orig_id') && $rData['check_compatible']) {
				$rData['compatible'] = PackageService::checkCompatible(RequestManager::get('package_id'), RequestManager::get('orig_id'));
			} else {
				$rData['compatible'] = true;
			}

			$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in']));

			$rUser = null;
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

			$rAllowed = \XcVm\Domain\Bouquet\ResellerBouquetPolicy::allowed($rUserInfo);
			$rOwner = (int) (RequestManager::get('member_id') ?: ($rUser['member_id'] ?? ($rUserInfo['id'] ?? 0)));
			if (Authorization::check('user', $rOwner)) {
				$rOwnerAllowed = \XcVm\Domain\Bouquet\ResellerBouquetPolicy::forOwner($db, $rOwner);
				if ($rOwnerAllowed !== null) {
					$rAllowed = $rAllowed === null ? $rOwnerAllowed : array_values(array_intersect($rAllowed, $rOwnerAllowed));
				}
			}
			$rData['bouquets'] = json_encode(\XcVm\Domain\Bouquet\ResellerBouquetPolicy::filter(
				\XcVm\Domain\Bouquet\ResellerBouquetPolicy::ids($rData['bouquets']),
				$rAllowed
			));
			foreach (json_decode($rData['bouquets'], true) as $rBouquet) {
				$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rBouquet);
				if ($db->num_rows() == 1) {
					$rRow = $db->get_row();
					$rReturn[] = ['id' => $rRow['id'], 'bouquet_name' => str_replace("'", "\\'", $rRow['bouquet_name']), 'bouquet_channels' => json_decode($rRow['bouquet_channels'], true), 'bouquet_radios' => json_decode($rRow['bouquet_radios'], true), 'bouquet_movies' => json_decode($rRow['bouquet_movies'], true), 'bouquet_series' => json_decode($rRow['bouquet_series'], true)];
				}
			}
			$rData['duration'] = $rData['official_duration'] . ' ' . $rData['official_duration_in'];
			echo json_encode(['result' => true, 'bouquets' => $rReturn, 'data' => $rData]);
		} else {
			echo json_encode(['result' => false]);
		}
		exit();
	}

	/**
	 * Output trial package details (JSON) and exit.
	 */
	public function getPackageTrial(): void {
		[$rUserInfo] = $this->begin();
		$db = self::db();
		$rReturn = [];
		$db->query('SELECT `bouquets`, `trial_credits` AS `cost_credits`, `trial_duration`, `trial_duration_in`, `max_connections`, `is_isplock` FROM `users_packages` WHERE `id` = ?;', RequestManager::get('package_id'));
		if ($db->num_rows() == 1) {
			$rData = UserCredits::amounts($db->get_row());
			$rData['exp_date'] = date('Y-m-d H:i', strtotime('+' . intval($rData['trial_duration']) . ' ' . $rData['trial_duration_in']));

			$rAllowed = \XcVm\Domain\Bouquet\ResellerBouquetPolicy::allowed($rUserInfo);
			$rOwner = (int) (RequestManager::get('member_id') ?: ($rUserInfo['id'] ?? 0));
			if (Authorization::check('user', $rOwner)) {
				$rOwnerAllowed = \XcVm\Domain\Bouquet\ResellerBouquetPolicy::forOwner($db, $rOwner);
				if ($rOwnerAllowed !== null) {
					$rAllowed = $rAllowed === null ? $rOwnerAllowed : array_values(array_intersect($rAllowed, $rOwnerAllowed));
				}
			}
			$rData['bouquets'] = json_encode(\XcVm\Domain\Bouquet\ResellerBouquetPolicy::filter(
				\XcVm\Domain\Bouquet\ResellerBouquetPolicy::ids($rData['bouquets']),
				$rAllowed
			));
			foreach (json_decode($rData['bouquets'], true) as $rBouquet) {
				$db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rBouquet);
				if ($db->num_rows() == 1) {
					$rRow = $db->get_row();
					$rReturn[] = ['id' => $rRow['id'], 'bouquet_name' => str_replace("'", "\\'", $rRow['bouquet_name']), 'bouquet_channels' => json_decode($rRow['bouquet_channels'], true), 'bouquet_radios' => json_decode($rRow['bouquet_radios'], true), 'bouquet_movies' => json_decode($rRow['bouquet_movies'], true), 'bouquet_series' => json_decode($rRow['bouquet_series'], true)];
				}
			}
			$rData['duration'] = $rData['trial_duration'] . ' ' . $rData['trial_duration_in'];
			$rData['compatible'] = true;
			echo json_encode(['result' => true, 'bouquets' => $rReturn, 'data' => $rData]);
		} else {
			echo json_encode(['result' => false]);
		}
		exit();
	}
}
