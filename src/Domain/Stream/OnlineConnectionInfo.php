<?php

namespace XcVm\Domain\Stream;

/** Current connection metadata for rows already authorized by the listing. */
final class OnlineConnectionInfo {
	public static function forUsers(object $db, array $userIds, bool $redis): array {
		$userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
		if ($userIds === []) {
			return [];
		}
		if ($redis) {
			return self::normalize(ConnectionTracker::getFirstConnection($userIds));
		}
		// Latest active start, with activity id breaking ties. Matches Redis's latest connection.
		$ids = implode(',', $userIds);
		if (!$db->query('SELECT live.user_id, live.user_ip, live.isp, live.date_start, live.hls_end FROM `lines_live` live WHERE live.user_id IN (' . $ids . ') AND live.hls_end = 0 AND NOT EXISTS (SELECT 1 FROM `lines_live` newer WHERE newer.user_id = live.user_id AND newer.hls_end = 0 AND (newer.date_start > live.date_start OR (newer.date_start = live.date_start AND newer.activity_id > live.activity_id)));')) {
			return [];
		}
		return self::normalize($db->get_raw_rows());
	}

	public static function normalize(array $connections): array {
		$result = [];
		foreach ($connections as $connection) {
			if (!is_array($connection) || (int) ($connection['hls_end'] ?? 0) !== 0 || empty($connection['user_id'])) {
				continue;
			}
			$result[(int) $connection['user_id']] = [
				'online_ip' => (string) ($connection['user_ip'] ?? ''),
				'online_isp' => (string) ($connection['isp'] ?? ''),
				'online_since' => (int) ($connection['date_start'] ?? 0),
			];
		}
		return $result;
	}
}
