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

	/**
	 * Project the created channel's playlist timeline forward from its wall-clock
	 * anchor into EPG programmes, repeating the loop to fill the window. Pure.
	 * A programme is emitted when its [start,end) overlaps [windowStart,windowEnd).
	 *
	 * @param array<int,array{seconds:int,start:int,finish:int}> $rInfo
	 * @param array<int,array{title:string,desc:string}> $rItemMeta
	 * @return list<array{start:int,end:int,title:string,description:string}>
	 */
	public static function programmes(array $rInfo, int $rStreamStarted, array $rItemMeta, int $rWindowStart, int $rWindowEnd): array {
		if ($rStreamStarted <= 0 || $rInfo === []) {
			return [];
		}
		$rTotal = (int) end($rInfo)['finish'];
		if ($rTotal <= 0) {
			return [];
		}
		$rCycle = max(0, (int) floor(($rWindowStart - $rStreamStarted) / $rTotal));
		$rOut = [];
		while (true) {
			$rBase = $rStreamStarted + $rCycle * $rTotal;
			if ($rBase >= $rWindowEnd) {
				break;
			}
			foreach ($rInfo as $rI => $rItem) {
				if ((int) $rItem['seconds'] <= 0) {
					continue;
				}
				$rStart = $rBase + (int) $rItem['start'];
				$rEnd = $rBase + (int) $rItem['finish'];
				if ($rEnd <= $rWindowStart || $rStart >= $rWindowEnd) {
					continue;
				}
				$rOut[] = [
					'start' => $rStart,
					'end' => $rEnd,
					'title' => (string) ($rItemMeta[$rI]['title'] ?? ''),
					'description' => (string) ($rItemMeta[$rI]['desc'] ?? ''),
				];
			}
			$rCycle++;
		}
		return $rOut;
	}
}
