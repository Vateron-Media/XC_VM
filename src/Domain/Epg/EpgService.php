<?php

namespace XcVm\Domain\Epg;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Database\Database;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Util\AdminHelpers;

/**
 * EpgService — epg service
 *
 * @package XC_VM_Domain_Epg
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EpgService {
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
	 * Create or update an EPG source from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process($rData) {
		$db = self::db();
		if (isset($rData['edit'])) {
			if (!Authorization::check('adv', 'epg_edit')) {
				exit();
			}
			$rArray = AdminHelpers::overwriteData(self::getById($rData['edit']), $rData);
		} else {
			if (!Authorization::check('adv', 'add_epg')) {
				exit();
			}
			$rArray = QueryHelper::verifyPostTable('epg', $rData);
			unset($rArray['id']);
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `epg`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}

	/**
	 * Get EPG programmes for a channel/stream.
	 *
	 * @param array $rStream  Stream row.
	 * @param bool  $rArchive Include archive/catch-up programmes.
	 * @return array EPG programmes.
	 */
	public static function getChannelEpg($rStream, $rArchive = false) {
		if (!$rStream || !$rStream['channel_id']) {
			return array();
		}

		if ($rArchive) {
			return EpgService::getStreamEpg($rStream['id'], time() - $rStream['tv_archive_duration'] * 86400, time());
		}

		return EpgService::getStreamEpg($rStream['id'], time(), time() + 1209600);
	}

	// ──────────── Из EpgRepository ────────────

	/**
	 * Find an EPG channel by name.
	 *
	 * @param string $rEPGName EPG channel name.
	 * @return mixed Matching EPG channel, or null/false if not found.
	 */
	public static function findByName($rEPGName) {
		$db = self::db();
		$db->query('SELECT `id`, `data` FROM `epg`;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				foreach (json_decode($rRow['data'], true) as $rChannelID => $rChannelData) {
					if ($rChannelID == $rEPGName) {
						if (count($rChannelData['langs']) > 0) {
							$rEPGLang = $rChannelData['langs'][0];
						} else {
							$rEPGLang = '';
						}

						return array('channel_id' => $rChannelID, 'epg_lang' => $rEPGLang, 'epg_id' => intval($rRow['id']));
					}
				}
			}
		}
	}

	/**
	 * Fetch an EPG source by id.
	 *
	 * @param int $rID EPG source id.
	 * @return array|null The EPG source row, or null if not found.
	 */
	public static function getById($rID) {
		$db = self::db();
		$db->query('SELECT * FROM `epg` WHERE `id` = ?;', $rID);

		if ($db->num_rows() == 1) {
			return $db->get_row();
		}
	}

	/**
	 * Search an array for entries whose key matches a value.
	 *
	 * @param array  $rArray Array to search.
	 * @param string $rKey   Key to compare.
	 * @param mixed  $rValue Value to match.
	 * @return array Matching entries.
	 */
	public static function search($rArray, $rKey, $rValue) {
		$rResults = array();
		self::searchRecursive($rArray, $rKey, $rValue, $rResults);
		return $rResults;
	}

	/**
	 * Recursively collect array entries matching a key/value.
	 *
	 * @param array  $rArray   Array to search.
	 * @param string $rKey     Key to compare.
	 * @param mixed  $rValue   Value to match.
	 * @param array  $rResults Accumulator (by reference).
	 * @return void
	 */
	private static function searchRecursive($rArray, $rKey, $rValue, &$rResults) {
		if (is_array($rArray)) {
			if (isset($rArray[$rKey]) && $rArray[$rKey] == $rValue) {
				$rResults[] = $rArray;
			}
			foreach ($rArray as $subarray) {
				self::searchRecursive($subarray, $rKey, $rValue, $rResults);
			}
		}
	}

	/**
	 * Get EPG programmes for a stream within a date range.
	 *
	 * @param int       $rStreamID  Stream id.
	 * @param int|null  $rStartDate Range start as a unix timestamp (or null).
	 * @param int|null  $rFinishDate Range end as a unix timestamp (or null).
	 * @param bool      $rByID      Key results by programme id.
	 * @return array EPG programmes.
	 */
	public static function getStreamEpg($rStreamID, $rStartDate = null, $rFinishDate = null, $rByID = false) {
		$rReturn = array();
		$rData = (file_exists(EPG_PATH . 'stream_' . $rStreamID) ? igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID)) : array());

		foreach ($rData as $rItem) {
			if (!$rStartDate || ($rStartDate < $rItem['end'] && $rItem['start'] < $rFinishDate)) {
				if ($rByID) {
					$rReturn[$rItem['id']] = $rItem;
				} else {
					$rReturn[] = $rItem;
				}
			}
		}

		return $rReturn;
	}

	/**
	 * Get EPG programmes for multiple streams within a date range.
	 *
	 * @param int[]      $rStreamIDs  Stream ids.
	 * @param int|null   $rStartDate  Range start as a unix timestamp (or null).
	 * @param int|null   $rFinishDate Range end as a unix timestamp (or null).
	 * @return array EPG programmes keyed by stream.
	 */
	public static function getStreamsEpg($rStreamIDs, $rStartDate = null, $rFinishDate = null) {
		$rReturn = array();
		foreach ($rStreamIDs as $rStreamID) {
			$rReturn[$rStreamID] = self::getStreamEpg($rStreamID, $rStartDate, $rFinishDate);
		}
		return $rReturn;
	}

	/**
	 * Fetch a single EPG programme for a stream.
	 *
	 * @param int $rStreamID    Stream id.
	 * @param int $rProgrammeID Programme id.
	 * @return array|null The programme, or null if not found.
	 */
	public static function getProgramme($rStreamID, $rProgrammeID) {
		$rData = self::getStreamEpg($rStreamID, null, null, true);
		if (isset($rData[$rProgrammeID])) {
			return $rData[$rProgrammeID];
		}
	}

	/**
	 * Fetch all EPG sources.
	 *
	 * @return array EPG source rows.
	 */
	public static function getAll() {
		$db = self::db();
		$rReturn = array();
		$db->query('SELECT * FROM `epg` ORDER BY `id` ASC;');

		if ($db->num_rows() > 0) {
			foreach ($db->get_rows() as $rRow) {
				$rReturn[intval($rRow['id'])] = $rRow;
			}
		}

		return $rReturn;
	}

	/**
	 * Delete an EPG source by id.
	 *
	 * @param int $rID EPG source id.
	 * @return bool True on success.
	 */
	public static function deleteEpgById($rID) {
		$db = self::db();
		$rEPG = self::getById($rID);

		if (!$rEPG) {
			return false;
		}

		$db->query('DELETE FROM `epg` WHERE `id` = ?;', $rID);
		$db->query('DELETE FROM `epg_channels` WHERE `epg_id` = ?;', $rID);
		$db->query('UPDATE `streams` SET `epg_id` = null, `channel_id` = null, `epg_lang` = null WHERE `epg_id` = ?;', $rID);

		return true;
	}
}
