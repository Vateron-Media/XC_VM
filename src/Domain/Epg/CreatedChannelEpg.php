<?php

namespace XcVm\Domain\Epg;

use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * CreatedChannelEpg — EPG of created channels, hung off one reserved `epg` source row.
 */
class CreatedChannelEpg {
	use DatabaseAware;

	public const SOURCE_NAME = 'Created Channels';

	/** Id of the reserved epg source; 0 when the row is missing (migration not applied). */
	public static function reservedSourceId(): int {
		$db = self::db();
		$db->query('SELECT `id` FROM `epg` WHERE `epg_name` = ? ORDER BY `id` LIMIT 1;', self::SOURCE_NAME);

		return (int) ($db->get_row()['id'] ?? 0);
	}
}
