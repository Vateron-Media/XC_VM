<?php

namespace XcVm\Domain\Stream;

/**
 * StreamConfigRepository — аргументы потоков и профили транскодирования.
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StreamConfigRepository {
	use \XcVm\Infrastructure\Database\DatabaseAware;
	/**
	 * Получить все аргументы потоков (streams_arguments), индексированные по argument_key.
	 */
	public static function getStreamArguments() {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `streams_arguments` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[$rRow['argument_key']] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Получить все профили транскодирования (profiles).
	 */
	public static function getTranscodeProfiles() {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `profiles` ORDER BY `profile_id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Fetch a single transcode profile by id.
	 *
	 * @param int $rID Profile id.
	 * @return array|null The profile row, or null if not found.
	 */
	public static function getTranscodeProfile($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `profiles` WHERE `profile_id` = ?;', $rID);

		if ($db->num_rows() != 1) {
			return null;
		}

		return $db->get_row();
	}

	/**
	 * Delete a transcode profile and detach it from streams and watch folders.
	 *
	 * @param int $rID Profile id.
	 * @return bool True on deletion, false if the profile does not exist.
	 */
	public static function deleteProfile($rID) {
		$db = self::db();
		$rProfile = self::getTranscodeProfile($rID);

		if (!$rProfile) {
			return false;
		}

		$db->query('DELETE FROM `profiles` WHERE `profile_id` = ?;', $rID);
		$db->query('UPDATE `streams` SET `transcode_profile_id` = 0 WHERE `transcode_profile_id` = ?;', $rID);
		$db->query('UPDATE `watch_folders` SET `transcode_profile_id` = 0 WHERE `transcode_profile_id` = ?;', $rID);

		return true;
	}
}
