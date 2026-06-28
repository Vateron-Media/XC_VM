<?php

namespace XcVm\Module\Watch;

use XcVm\Core\Database\QueryHelper;

/**
 * RecordingService — recording service
 *
 * @package XC_VM_Module_Watch
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RecordingService {

    use \XcVm\Infrastructure\Database\DatabaseAware;

	public static function schedule($rData) {
		$db = self::db();
		if (empty($rData['title'])) {
			return array('status' => STATUS_NO_TITLE);
		}
		if (empty($rData['source_id'])) {
			return array('status' => STATUS_NO_SOURCE);
		}

		$rArray = QueryHelper::verifyPostTable('recordings', $rData);
		$rArray['bouquets'] = '[' . implode(',', array_map('intval', (array) ($rData['bouquets'] ?? []))) . ']';
		$rArray['category_id'] = '[' . implode(',', array_map('intval', (array) ($rData['category_id'] ?? []))) . ']';
		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `recordings`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if ($db->query($rQuery, ...$rPrepare['data'])) {
			$rInsertID = $db->last_insert_id();
			return array('status' => STATUS_SUCCESS, 'data' => array('insert_id' => $rInsertID));
		}

		return array('status' => STATUS_FAILURE, 'data' => $rData);
	}
}
