<?php

namespace XcVm\Domain\Vod;

use XcVm\Core\Events\Vod\VodImportResultEvent;

/** Stores the latest import result for each VOD source file. */
final class VodImportLog {
	use \XcVm\Infrastructure\Database\DatabaseAware;

	public static function record(VodImportResultEvent $rEvent, ?array $rPayload = null): void {
		$rServerID = $rEvent->serverId;
		$rFilename = $rEvent->filename;
		if (preg_match('/^s:(\d+):(.+)$/s', $rFilename, $rOwn)) {
			$rServerID = (int) $rOwn[1];
			$rFilename = $rOwn[2];
		}

		$db = self::db();
		$rPayloadJson = $rEvent->status === VodImportResultEvent::STATUS_NO_MATCH && $rPayload !== null
			? json_encode($rPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
			: null;
		if ($rPayloadJson === false) {
			$rPayloadJson = null;
		}
		$db->query('DELETE FROM `vod_import_logs` WHERE `filename` = ? AND `type` = ? AND `server_id` = ?;', $rFilename, $rEvent->type, $rServerID);
		$db->query(
			'INSERT INTO `vod_import_logs` (`type`, `server_id`, `filename`, `title`, `status`, `stream_id`, `payload`) VALUES (?, ?, ?, ?, ?, ?, ?);',
			$rEvent->type,
			$rServerID,
			$rFilename,
			$rEvent->title === '' ? null : $rEvent->title,
			$rEvent->status,
			$rEvent->streamId,
			$rPayloadJson,
		);
	}

	/** Queue a failed item again with an operator-corrected title. */
	public static function retry(int $rID, string $rTitle, int $rExpectedType, ?callable $rStart = null): bool {
		$rTitle = trim($rTitle);
		if ($rID <= 0 || !in_array($rExpectedType, [1, 2], true) || $rTitle === '' || mb_strlen($rTitle, 'UTF-8') > 1024) {
			return false;
		}

		$db = self::db();
		$db->query('SELECT `type`, `title`, `status`, `payload` FROM `vod_import_logs` WHERE `id` = ? LIMIT 1;', $rID);
		$rRow = $db->get_row();
		if (!$rRow || (int) $rRow['type'] !== $rExpectedType || (int) $rRow['status'] !== VodImportResultEvent::STATUS_NO_MATCH || empty($rRow['payload'])) {
			return false;
		}

		$rPayload = json_decode((string) $rRow['payload'], true);
		$rExpectedType = (int) $rRow['type'] === 1 ? 'movie' : ((int) $rRow['type'] === 2 ? 'series' : '');
		if (!is_array($rPayload) || $rExpectedType === '' || ($rPayload['type'] ?? '') !== $rExpectedType || !is_string($rPayload['file'] ?? null) || $rPayload['file'] === '') {
			return false;
		}

		$rPayload['title'] = $rTitle;
		$db->query('UPDATE `vod_import_logs` SET `title` = ?, `status` = ?, `dateadded` = CURRENT_TIMESTAMP WHERE `id` = ?;', $rTitle, VodImportResultEvent::STATUS_QUEUED, $rID);
		if (!VodItemImporter::queueBatch([$rPayload], $rStart)) {
			$db->query('UPDATE `vod_import_logs` SET `title` = ?, `status` = ? WHERE `id` = ? AND `status` = ?;', $rRow['title'], VodImportResultEvent::STATUS_NO_MATCH, $rID, VodImportResultEvent::STATUS_QUEUED);
			return false;
		}
		return true;
	}
}
