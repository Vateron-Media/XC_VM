<?php

use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\DatabaseHandler;
/**
 * LineRepository — line repository
 *
 * @package XC_VM_Domain_Line
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LineRepository {
	private static $db = null;

	/**
	 * Inject the database handler (dependency injection).
	 *
	 * @param \XcVm\Core\Database\DatabaseHandler $db Database handler.
	 * @return void
	 */
	public static function setDb($db): void {
		self::$db = $db;
	}

	/**
	 * Get the injected database handler.
	 *
	 * @return object Database handler.
	 * @throws \RuntimeException If setDb() was not called first.
	 */
	private static function db(): object {
		if (self::$db === null) {
			throw new \RuntimeException(static::class . '::setDb() must be called before use.');
		}
		return self::$db;
	}
	/**
	 * Bulk delete lines by id.
	 *
	 * @param int[] $rIDs Line ids.
	 * @return bool True on success.
	 */
	public static function deleteMany($rIDs) {
		$db = self::db();
		$rIDs = AdminHelpers::confirmIDs($rIDs);

		if (0 >= count($rIDs)) {
			return false;
		}

		LineService::deleteLinesSignal($rIDs);
		$db->query('DELETE FROM `lines` WHERE `id` IN (' . implode(',', $rIDs) . ');');
		$db->query('DELETE FROM `lines_logs` WHERE `user_id` IN (' . implode(',', $rIDs) . ');');
		$db->query('UPDATE `lines_activity` SET `user_id` = 0 WHERE `user_id` IN (' . implode(',', $rIDs) . ');');
		$rPairIDs = array();
		$db->query('SELECT `id` FROM `lines` WHERE `pair_id` IN (' . implode(',', $rIDs) . ');');

		foreach ($db->get_rows() as $rRow) {
			if ($rRow['id'] > 0 && !in_array($rRow['id'], $rPairIDs)) {
				$rPairIDs[] = $rRow['id'];
			}
		}

		if (count($rPairIDs) > 0) {
			$db->query('UPDATE `lines` SET `pair_id` = null WHERE `id` = (' . implode(',', $rPairIDs) . ');');
			LineService::updateLinesSignal($rPairIDs);
		}

		return true;
	}

	/**
	 * Get the available line output formats.
	 *
	 * @return array Output format definitions.
	 */
	public static function getOutputFormats() {
		$db = self::db();
		$db->query('SELECT * FROM `output_formats` ORDER BY `access_output_id` ASC;');
		return (0 < $db->num_rows() ? $db->get_rows() : array());
	}
}
