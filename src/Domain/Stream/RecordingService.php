<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;

/**
 * RecordingService — scheduled DVR recordings (the `recordings` table):
 * schedule, list, delete. Recording itself runs on the node (RecordCommand),
 * and RecordingFinalizer turns a finished one into a VOD.
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class RecordingService {
	use \XcVm\Infrastructure\Database\DatabaseAware;

	/**
	 * Schedule a new recording.
	 *
	 * @param array $rData Posted form: title, source_id, stream_id, start, end, bouquets[], category_id[], ...
	 * @return array ['status' => STATUS_*, 'data' => ...]
	 */
	public static function schedule($rData) {
		$db = self::db();
		if (empty($rData['title'])) {
			return ['status' => STATUS_NO_TITLE];
		}
		if (empty($rData['source_id'])) {
			return ['status' => STATUS_NO_SOURCE];
		}

		// Server-owned columns never come from the form: a posted `id` would
		// overwrite another recording, a `created_id` would make delete()
		// remove an arbitrary stream.
		unset($rData['id'], $rData['created_id'], $rData['status']);
		$rArray = QueryHelper::verifyPostTable('recordings', $rData);
		unset($rArray['id']);
		$rArray['bouquets'] = '[' . implode(',', array_map('intval', (array) ($rData['bouquets'] ?? []))) . ']';
		$rArray['category_id'] = '[' . implode(',', array_map('intval', (array) ($rData['category_id'] ?? []))) . ']';
		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'INSERT INTO `recordings`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			// A recording scheduled on a node: the recorded stream's R2 record carries it.
			EventDispatcher::dispatch(new StreamsChangedEvent([intval($rData['stream_id'] ?? 0)]));
			return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
		}

		return ['status' => STATUS_FAILURE, 'data' => $rData];
	}

	/**
	 * Every recording, newest first.
	 *
	 * @return array
	 */
	public static function getAll() {
		$db = self::db();
		$db->query('SELECT * FROM `recordings` ORDER BY `id` DESC;');
		return $db->get_rows();
	}

	/**
	 * Delete a recording: its VOD (if one was created), its running record
	 * process on this server, and the row.
	 *
	 * @param int $rID
	 * @return bool
	 */
	public static function delete($rID) {
		$db = self::db();
		$db->query('SELECT `created_id`, `source_id`, `stream_id` FROM `recordings` WHERE `id` = ?;', $rID);
		if ($db->num_rows() > 0) {
			$rRecording = $db->get_row();
			if ($rRecording['created_id']) {
				StreamRepository::deleteStream($rRecording['created_id'], $rRecording['source_id'], true, true);
			}
			shell_exec("kill -9 `ps -ef | grep 'Record[" . intval($rID) . "]' | grep -v grep | awk '{print $2}'` 2>/dev/null");
			$db->query('DELETE FROM `recordings` WHERE `id` = ?;', $rID);
			// The recorded stream's R2 record no longer carries it.
			EventDispatcher::dispatch(new StreamsChangedEvent([intval($rRecording['stream_id'])]));
		}
		return true;
	}
}
