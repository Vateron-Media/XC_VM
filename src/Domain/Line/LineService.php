<?php

namespace XcVm\Domain\Line;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Cluster\ClusterSettings;
use XcVm\Core\Cluster\SignalDispatcher;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Validation\InputValidator;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Device\MagService;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\UserRepository;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * LineService — line service
 *
 * @package XC_VM_Domain_Line
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LineService {
	use DatabaseAware;

	/**
	 * Bulk delete selected lines.
	 *
	 * @param array $rData Selected line ids.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massDelete(array $rData) {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rLines = json_decode($rData['lines'], true);
		LineRepository::deleteMany($rLines);

		return ['status' => STATUS_SUCCESS];
	}

	/**
	 * The expiry a mass edit sets (lines, MAG and Enigma devices): null for
	 * "no expiry", a Unix time for a date, and false to leave each line's own.
	 * False for the box ticked with no date, or with one that is not a date:
	 * `new DateTime('')` is now, and every selected line expired at once.
	 *
	 * @param array<string, mixed> $rData The form: `exp_date`, `no_expire`.
	 */
	public static function massExpiry(array $rData): string|false|null {
		if (isset($rData['no_expire'])) {
			return null;
		}
		$rDate = trim((string) ($rData['exp_date'] ?? ''));
		if ($rDate === '') {
			return false;
		}
		try {
			return (new \DateTime($rDate))->format('U');
		} catch (\Exception) {
			return false;
		}
	}

	/**
	 * Apply bulk edits to selected lines.
	 *
	 * @param array $rData Selected ids plus the fields/values to apply.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massEdit(array $rData) {
		$db = self::db();
		if (InputValidator::validate('massEditLines', $rData)) {
			$rArray = [];

			foreach (['is_stalker', 'is_isplock', 'is_restreamer', 'is_trial'] as $rItem) {
				if (isset($rData['c_' . $rItem])) {
					if (isset($rData[$rItem])) {
						$rArray[$rItem] = 1;
					} else {
						$rArray[$rItem] = 0;
					}
				}
			}

			if (isset($rData['c_admin_notes'])) {
				$rArray['admin_notes'] = $rData['admin_notes'];
			}

			if (isset($rData['c_reseller_notes'])) {
				$rArray['reseller_notes'] = $rData['reseller_notes'];
			}

			if (isset($rData['c_forced_country'])) {
				$rArray['forced_country'] = $rData['forced_country'];
			}

			if (isset($rData['c_member_id'])) {
				$rArray['member_id'] = intval($rData['member_id']);
			}

			if (isset($rData['c_force_server_id'])) {
				$rArray['force_server_id'] = intval($rData['force_server_id']);
			}

			if (isset($rData['c_max_connections'])) {
				$rArray['max_connections'] = intval($rData['max_connections']);
			}

			if (isset($rData['c_exp_date'])) {
				$rExpiry = self::massExpiry($rData);
				if ($rExpiry !== false) {
					$rArray['exp_date'] = $rExpiry;
				}
			}

			if (isset($rData['c_access_output'])) {
				$rOutputs = [];
				foreach ($rData['access_output'] as $rOutputID) {
					$rOutputs[] = $rOutputID;
				}
				$rArray['allowed_outputs'] = '[' . implode(',', array_map('intval', $rOutputs)) . ']';
			}

			if (isset($rData['c_bouquets'])) {
				$rArray['bouquet'] = [];
				foreach (json_decode($rData['bouquets_selected'], true) as $rBouquet) {
					if (is_numeric($rBouquet)) {
						$rArray['bouquet'][] = $rBouquet;
					}
				}
				$rArray['bouquet'] = AdminHelpers::sortArrayByArray($rArray['bouquet'], array_keys(BouquetService::getOrder()));
				$rArray['bouquet'] = '[' . implode(',', array_map('intval', $rArray['bouquet'])) . ']';
			}

			if (isset($rData['reset_isp_lock'])) {
				$rArray['isp_desc'] = '';
				$rArray['as_number'] = $rArray['isp_desc'];
			}

			$rUsers = AdminHelpers::confirmIDs(json_decode($rData['users_selected'], true));

			if (0 < count($rUsers)) {
				$rPrepare = QueryHelper::prepareArray($rArray);
				if (0 < count($rPrepare['data'])) {
					$rQuery = 'UPDATE `lines` SET ' . $rPrepare['update'] . ' WHERE `id` IN (' . implode(',', $rUsers) . ');';
					$db->query($rQuery, ...$rPrepare['data']);
				}
				$db->query('SELECT `pair_id` FROM `lines` WHERE `pair_id` IN (' . implode(',', $rUsers) . ');');
				foreach ($db->get_rows() as $rRow) {
					MagService::syncLineDevices($rRow['pair_id']);
				}
				self::updateLinesSignal($rUsers);
			}

			return ['status' => STATUS_SUCCESS];
		}
		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Create or update a line from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		$db = self::db();
		if (InputValidator::validate('processLine', $rData)) {
			$rLine = null;

			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'edit_user')) {
					// The edit starts from the line as stored: the row cleaner's
					// escaping would be written back into every field not sent.
					$db->query('SELECT * FROM `lines` WHERE `id` = ?;', intval($rData['edit']));
					$rLine = $db->get_raw_row();
					$rArray = AdminHelpers::overwriteData($rLine, $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'add_user')) {
					$rArray = QueryHelper::verifyPostTable('lines', $rData);
					$rArray['created_at'] = time();
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			if (strlen($rData['username']) == 0) {
				$rArray['username'] = AdminHelpers::generateString(10);
			}

			if (strlen($rData['password']) == 0) {
				$rArray['password'] = AdminHelpers::generateString(10);
			}

			// Neither holds the separator of the line's playback addresses; a
			// line keeps the value it has until it is changed.
			if (!self::credentialAllowed($rArray['username'], $rLine['username'] ?? null)) {
				return ['status' => STATUS_INVALID_USERNAME, 'data' => $rData];
			}

			if (!self::credentialAllowed($rArray['password'], $rLine['password'] ?? null)) {
				return ['status' => STATUS_INVALID_PASSWORD, 'data' => $rData];
			}

			// A new line starts at 1; an edit keeps the line's own value unless
			// the request sends one (the form has no enabled or admin_enabled).
			foreach (['max_connections', 'enabled', 'admin_enabled'] as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = intval($rData[$rSelection]);
				} elseif (!$rLine) {
					$rArray[$rSelection] = 1;
				}
			}

			foreach (['is_stalker', 'is_restreamer', 'is_trial', 'is_isplock', 'bypass_ua'] as $rSelection) {
				if (isset($rData[$rSelection])) {
					$rArray[$rSelection] = 1;
				} else {
					$rArray[$rSelection] = 0;
				}
			}

			if (strlen($rData['isp_clear']) == 0) {
				$rArray['isp_desc'] = '';
				$rArray['as_number'] = null;
			}

			$rArray['bouquet'] = AdminHelpers::sortArrayByArray(array_values(json_decode($rData['bouquets_selected'], true)), array_keys(BouquetService::getOrder()));
			$rArray['bouquet'] = '[' . implode(',', array_map('intval', $rArray['bouquet'])) . ']';

			if (isset($rData['exp_date']) && !isset($rData['no_expire'])) {
				if ((string) $rData['exp_date'] !== '' && $rData['exp_date'] != '1970-01-01') {
					try {
						$rDate = new \DateTime($rData['exp_date']);
						$rArray['exp_date'] = $rDate->format('U');
					} catch (\Exception $e) {
						return ['status' => STATUS_INVALID_DATE, 'data' => $rData];
					}
				}
			} else {
				$rArray['exp_date'] = null;
			}

			// A new line without an owner is the saving user's; a line whose
			// owner was deleted keeps none until the request names one.
			if (!$rArray['member_id'] && !($rLine && is_null($rArray['member_id']))) {
				$rArray['member_id'] = $GLOBALS['rAdminUserInfo']['id'];
			}

			if (isset($rData['allowed_ips'])) {
				if (!is_array($rData['allowed_ips'])) {
					$rData['allowed_ips'] = [$rData['allowed_ips']];
				}

				$rArray['allowed_ips'] = json_encode($rData['allowed_ips']);
			} else {
				$rArray['allowed_ips'] = '[]';
			}

			if (isset($rData['allowed_ua'])) {
				if (!is_array($rData['allowed_ua'])) {
					$rData['allowed_ua'] = [$rData['allowed_ua']];
				}

				$rArray['allowed_ua'] = json_encode($rData['allowed_ua']);
			} else {
				$rArray['allowed_ua'] = '[]';
			}

			$rOutputs = [];

			if (isset($rData['access_output'])) {
				foreach ($rData['access_output'] as $rOutputID) {
					$rOutputs[] = $rOutputID;
				}
			}

			$rArray['allowed_outputs'] = '[' . implode(',', array_map('intval', $rOutputs)) . ']';

			$rArray = \XcVm\Domain\Stream\CategoryTemplateService::applyCustomData($rData, $rArray);

			$rEditID = (isset($rData['edit']) ? intval($rData['edit']) : null);

			if (!QueryHelper::checkExists('lines', 'username', $rArray['username'], 'id', $rEditID)) {
				$rPrepare = QueryHelper::prepareArray($rArray);

				$rQuery = 'REPLACE INTO `lines`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = $db->last_insert_id();
					MagService::syncLineDevices($rInsertID);
					self::updateLineSignal($rInsertID);

					return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
				}

				return ['status' => STATUS_FAILURE, 'data' => $rData];
			}

			return ['status' => STATUS_EXISTS_USERNAME, 'data' => $rData];
		}
		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Whether a line may be given this username or password. Each is one
	 * segment of the line's playback addresses (/live/<username>/<password>/<id>)
	 * and of the play tokens built from them, so neither is set to a value that
	 * holds the character that separates the segments. A line keeps the value
	 * it has until that value is changed.
	 *
	 * @param mixed $rValue  The username or password to store.
	 * @param mixed $rStored The one the line has now; null for a new line.
	 * @return bool False when the value is refused.
	 */
	public static function credentialAllowed(mixed $rValue, mixed $rStored = null): bool {
		return !str_contains((string) $rValue, '/') || (string) $rValue === (string) $rStored;
	}

	/**
	 * Signal servers to drop a line's active connections (delete).
	 *
	 * @param int  $rUserID Line/user id.
	 * @param bool $rForce  Force the signal even if recently sent.
	 * @return void
	 */
	public static function deleteLineSignal(int $rUserID, bool $rForce = false) {
		self::updateLineSignal($rUserID, $rForce);
	}

	/**
	 * Signal servers to drop connections for multiple lines (delete).
	 *
	 * @param int[] $rUserIDs Line/user ids.
	 * @param bool  $rForce   Force the signal even if recently sent.
	 * @return void
	 */
	public static function deleteLinesSignal(array $rUserIDs, bool $rForce = false) {
		self::updateLinesSignal($rUserIDs);
	}

	/**
	 * Signal servers to refresh a line's cached data (update).
	 *
	 * @param int  $rUserID Line/user id.
	 * @param bool $rForce  Force the signal even if recently sent.
	 * @return void
	 */
	public static function updateLineSignal(int $rUserID, bool $rForce = false) {
		$db = self::db();
		$rCached = SettingsManager::get('enable_cache');
		$rMainID = ConnectionTracker::getMainID();
		self::dropDisabled([$rUserID]);
		if ($rCached) {
			SignalDispatcher::cache(intval($rMainID), ['type' => 'update_line', 'id' => $rUserID], true, false, $db);
			return;
		}
	}

	/**
	 * Signal servers to refresh cached data for multiple lines (update).
	 *
	 * @param int[] $rUserIDs Line/user ids.
	 * @return void
	 */
	public static function updateLinesSignal(array $rUserIDs) {
		$db = self::db();
		$rCached = SettingsManager::get('enable_cache');
		$rMainID = ConnectionTracker::getMainID();
		self::dropDisabled($rUserIDs);
		if ($rCached) {
			SignalDispatcher::cache(intval($rMainID), ['type' => 'update_lines', 'id' => $rUserIDs], true, false, $db);
			return;
		}
	}

	/**
	 * `cluster_kill_on_line_disable` (on by default): a line that is now
	 * disabled, locked by the admin or expired loses its live sessions, wherever
	 * they are served — the close goes to the owning node exactly as a deleted
	 * line's does. Until this, the setting was stored, clamped and shown in the
	 * form with no reader, and a disabled line kept streaming until its HLS
	 * window ran out or its TS worker was reaped.
	 *
	 * Called from the signal every writer already sends after a change (the
	 * line form, a mass edit, the reseller API, an activation code's
	 * deactivation), so none of them has to remember it. The SELECT is on the
	 * primary key and usually matches nothing, since most saves enable rather
	 * than disable.
	 *
	 * @param array<int|string> $rUserIDs The lines that changed.
	 */
	public static function dropDisabled(array $rUserIDs): void {
		$rIDs = array_values(array_unique(array_filter(array_map('intval', $rUserIDs), static fn(int $rID): bool => $rID > 0)));
		// lb-settings: cluster_kill_on_line_disable
		if ($rIDs === [] || ClusterSettings::int('cluster_kill_on_line_disable', SettingsManager::get('cluster_kill_on_line_disable')) !== 1) {
			return;
		}

		$db = self::db();
		if (!$db->query('SELECT `id` FROM `lines` WHERE `id` IN (' . implode(',', $rIDs) . ') AND (`enabled` = 0 OR `admin_enabled` = 0 OR (`exp_date` IS NOT NULL AND `exp_date` < UNIX_TIMESTAMP()));')) {
			return;
		}
		foreach ($db->get_rows() ?: [] as $rRow) {
			self::closeLineConnections((int) $rRow['id']);
		}
	}

	/**
	 * Close every live session of one line, in MAIN's store or in the node's
	 * registry (ConnectionTracker's seam decides), as deleting a line does.
	 */
	public static function closeLineConnections(int $rID): void {
		if (SettingsManager::get('redis_handler')) {
			foreach (ConnectionTracker::getRedisConnections($rID, null, null, true, false, false) as $rConnection) {
				ConnectionTracker::closeConnection($rConnection);
			}
			return;
		}
		$db = self::db();
		$db->query('SELECT * FROM `lines_live` WHERE `user_id` = ?;', $rID);
		foreach ($db->get_rows() ?: [] as $rRow) {
			ConnectionTracker::closeConnection($rRow);
		}
	}

	/**
	 * Delete a line by id.
	 *
	 * @param int  $rID           Line id.
	 * @param bool $rDeletePaired Also delete the paired device/line.
	 * @param bool $rCloseCons    Close active connections first.
	 * @return bool True on success.
	 */
	public static function deleteLineById(int $rID, bool $rDeletePaired = false, bool $rCloseCons = true) {
		$db = self::db();
		$rLine = UserRepository::getLineById($rID);

		if (!$rLine) {
			return false;
		}

		self::deleteLineSignal($rID);
		$db->query('DELETE FROM `lines` WHERE `id` = ?;', $rID);
		$db->query('DELETE FROM `lines_logs` WHERE `user_id` = ?;', $rID);
		$db->query('UPDATE `lines_activity` SET `user_id` = 0 WHERE `user_id` = ?;', $rID);

		if ($rCloseCons) {
			self::closeLineConnections($rID);
		}

		$db->query('SELECT `id` FROM `lines` WHERE `pair_id` = ?;', $rID);

		foreach ($db->get_rows() as $rRow) {
			if ($rDeletePaired) {
				self::deleteLineById($rRow['id'], true, $rCloseCons);
			} else {
				$db->query('UPDATE `lines` SET `pair_id` = null WHERE `id` = ?;', $rRow['id']);
				self::updateLineSignal($rRow['id']);
			}
		}

		return true;
	}

	/**
	 * Get lines expiring within a time window.
	 *
	 * @param int $rLimit Window in seconds (default ~28 days).
	 * @return array Expiring line rows.
	 */
	public static function getExpiring(int $rLimit = 2419200) {
		$db = self::db();
		global $rUserInfo;
		global $rPermissions;
		$rReturn = [];
		$rReports = array_map('intval', array_merge([$rUserInfo['id']], $rPermissions['all_reports']));

		if (0 < count($rReports)) {
			$db->query('SELECT `is_mag`, `is_e2`, `lines`.`id` AS `line_id`, `lines`.`reseller_notes`, `mag_devices`.`mag_id`, `enigma2_devices`.`device_id` AS `e2_id`, `member_id`, `username`, `password`, `exp_date`, `mag_devices`.`mac` AS `mag_mac`, `enigma2_devices`.`mac` AS `e2_mac` FROM `lines` LEFT JOIN `mag_devices` ON `mag_devices`.`user_id` = `lines`.`id` LEFT JOIN `enigma2_devices` ON `enigma2_devices`.`user_id` = `lines`.`id` WHERE `member_id` IN (' . implode(',', $rReports) . ') AND `exp_date` IS NOT NULL AND `exp_date` >= ? AND `exp_date` < ? ORDER BY `exp_date` ASC LIMIT 250;', time(), time() + $rLimit);
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Check whether a user may still generate trial lines.
	 *
	 * The group's allowance is for one period (a day or a month) and counts the
	 * trials the user holds: a trial is held by the reseller that made it.
	 *
	 * @param int $rUserID   User id.
	 * @param int $rQuantity Trials about to be generated.
	 * @return bool True if trial generation is allowed.
	 */
	public static function canGenerateTrials(int $rUserID, int $rQuantity = 1) {
		$db = self::db();
		global $rSettings;
		$rUser = UserRepository::getRegisteredUserById($rUserID);
		$rPermissions = AuthRepository::getPermissions($rUser['member_group_id']);

		if ($rSettings['disable_trial']) {
			return false;
		}

		if (floatval($rUser['credits']) < floatval($rPermissions['minimum_trial_credits'])) {
			return false;
		}

		$rTotal = $rPermissions['total_allowed_gen_trials'];

		if (0 >= $rTotal) {
			return false;
		}

		$rTotalIn = $rPermissions['total_allowed_gen_in'];

		if ($rTotalIn == 'hours') {
			$rTime = time() - 3600;
		} elseif ($rTotalIn == 'month') {
			$rTime = strtotime('-1 month');
		} else {
			$rTime = time() - 3600 * 24;
		}

		$db->query('SELECT COUNT(`id`) AS `count` FROM `lines` WHERE `member_id` = ? AND `created_at` >= ? AND `is_trial` = 1;', $rUser['id'], $rTime);

		return $db->get_row()['count'] + $rQuantity <= $rTotal;
	}

	/**
	 * Hold a user's trials for one request. A trial is counted against the
	 * allowance (canGenerateTrials()) and stored afterwards, so of two requests
	 * at once each would count before the other has stored: each takes the
	 * user's trials before it counts and gives them back with unlockTrials()
	 * after it stored. A request waits its turn for ten seconds, then goes on
	 * without it; a connection that ends gives back what it held.
	 *
	 * @param int $rUserID User id.
	 * @return void
	 */
	public static function lockTrials(int $rUserID) {
		self::db()->query("SELECT GET_LOCK(CONCAT(DATABASE(), '.trials_', ?), 10);", $rUserID);
	}

	/**
	 * Give a user's trials taken with lockTrials() back to its other requests.
	 *
	 * @param int $rUserID User id.
	 * @return void
	 */
	public static function unlockTrials(int $rUserID) {
		self::db()->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), '.trials_', ?));", $rUserID);
	}
}
