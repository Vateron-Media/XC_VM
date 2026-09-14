<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Validation\InputValidator;

/**
 * ProviderService — provider service
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ProviderService {
	use \XcVm\Infrastructure\Database\DatabaseAware;
	/**
	 * Create or update a provider from admin form data.
	 *
	 * Validates input, enforces advanced permissions and IP+username uniqueness.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process($rData) {
		$db = self::db();
		if (InputValidator::validate('processProvider', $rData)) {
			if (isset($rData['edit'])) {
				if (Authorization::check('adv', 'streams')) {
					$rArray = AdminHelpers::overwriteData(ProviderService::getById($rData['edit']), $rData);
				} else {
					exit();
				}
			} else {
				if (Authorization::check('adv', 'streams')) {
					$rArray = QueryHelper::verifyPostTable('providers', $rData);
					unset($rArray['id']);
				} else {
					exit();
				}
			}

			foreach (array('enabled', 'ssl', 'hls', 'legacy') as $rKey) {
				if (isset($rData[$rKey])) {
					$rArray[$rKey] = 1;
				} else {
					$rArray[$rKey] = 0;
				}
			}

			if (isset($rData['edit'])) {
				$db->query('SELECT `id` FROM `providers` WHERE `ip` = ? AND `username` = ? AND `id` <> ? LIMIT 1;', $rArray['ip'], $rArray['username'], $rData['edit']);
			} else {
				$db->query('SELECT `id` FROM `providers` WHERE `ip` = ? AND `username` = ? LIMIT 1;', $rArray['ip'], $rArray['username']);
			}

			if (0 >= $db->num_rows()) {
				$rPrepare = QueryHelper::prepareArray($rArray);
				$rQuery = 'REPLACE INTO `providers`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rInsertID = $db->last_insert_id();
					return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
				}

				return array('status' => STATUS_FAILURE, 'data' => $rData);
			}

			return array('status' => STATUS_EXISTS_IP, 'data' => $rData);
		} else {
			return array('status' => STATUS_INVALID_INPUT, 'data' => $rData);
		}
	}

	/**
	 * Fetch a single provider by id.
	 *
	 * @param int $rID Provider id.
	 * @return array|null The provider row, or null if not found.
	 */
	public static function getById($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `providers` WHERE `id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return null;
		}

		return $db->get_row();
	}

	/**
	 * Fetch all providers, most recently changed first.
	 *
	 * @return array Provider rows.
	 */
	public static function getAll() {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `providers` ORDER BY `last_changed` DESC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Delete a provider and its stream associations.
	 *
	 * @param int $rID Provider id.
	 * @return bool True on deletion, false if the provider does not exist.
	 */
	public static function deleteById($rID) {
		$db = self::db();
		$rProvider = self::getById($rID);

		if (!$rProvider) {
			return false;
		}

		$db->query('DELETE FROM `providers` WHERE `id` = ?;', $rID);
		$db->query('DELETE FROM `providers_streams` WHERE `provider_id` = ?;', $rID);

		return true;
	}
}
