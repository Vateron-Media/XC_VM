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

	/** Days of guide written ahead of now. */
	private const DAYS_AHEAD = 2;

	/**
	 * Rebuild the channel's `epg_data` rows from its timeline and loop anchor.
	 * Returns the number of programmes written (0 when there is no timeline or anchor).
	 */
	public static function regenerate(int $rStreamId): int {
		$db = self::db();
		$rSource = self::reservedSourceId();
		if ($rSource <= 0) {
			return 0;
		}
		$rChannel = 'created_' . $rStreamId;

		$db->query('SELECT `stream_source`, `epg_lang`, `stream_display_name` FROM `streams` WHERE `id` = ? AND `type` = 3;', $rStreamId);
		$rStream = $db->get_row();
		$db->query('SELECT `cc_info`, `stream_started` FROM `streams_servers` WHERE `stream_id` = ? ORDER BY `stream_started` DESC LIMIT 1;', $rStreamId);
		$rServer = $db->get_row() ?: [];

		$rInfo = json_decode((string) ($rServer['cc_info'] ?? ''), true);
		if (!$rInfo && defined('CREATED_PATH') && is_file(CREATED_PATH . $rStreamId . '_.info')) {
			$rInfo = json_decode((string) file_get_contents(CREATED_PATH . $rStreamId . '_.info'), true);
		}
		$rStarted = (int) ($rServer['stream_started'] ?? 0);

		$rRows = [];
		if ($rStream && is_array($rInfo) && $rInfo && $rStarted > 0) {
			$rEntries = json_decode((string) $rStream['stream_source'], true) ?: [];
			$rMeta = [];
			foreach (array_values($rInfo) as $rPos => $rItem) {
				$rEntry = (string) ($rEntries[$rPos] ?? '');
				$rMeta[$rPos] = ['title' => (string) ($rItem['filename'] ?? ''), 'desc' => ''];
				if (preg_match('/^vod:(\d+)$/', $rEntry, $m)) {
					$db->query('SELECT `stream_display_name`, `movie_properties` FROM `streams` WHERE `id` = ?;', (int) $m[1]);
					if ($rVod = $db->get_row()) {
						$rProps = json_decode((string) $rVod['movie_properties'], true) ?: [];
						$rMeta[$rPos] = [
							'title' => (string) (($rProps['name'] ?? '') !== '' ? $rProps['name'] : $rVod['stream_display_name']),
							'desc' => (string) ($rProps['plot'] ?? $rProps['description'] ?? ''),
						];
					}
				}
			}
			$rNow = time();
			$rRows = self::programmes($rInfo, $rStarted, $rMeta, $rNow - 3600, $rNow + self::DAYS_AHEAD * 86400);
		}

		$db->query('DELETE FROM `epg_data` WHERE `epg_id` = ? AND `channel_id` = ?;', $rSource, $rChannel);
		$rLang = (string) ($rStream['epg_lang'] ?? '');
		foreach (array_chunk($rRows, 500) as $rChunk) {
			$rArgs = [];
			foreach ($rChunk as $rP) {
				array_push($rArgs, $rSource, $rChannel, $rP['start'], $rP['end'], $rLang, $rP['title'], $rP['description']);
			}
			$db->query('INSERT INTO `epg_data` (`epg_id`,`channel_id`,`start`,`end`,`lang`,`title`,`description`) VALUES ' . implode(',', array_fill(0, count($rChunk), '(?,?,?,?,?,?,?)')) . ';', ...$rArgs);
		}

		return count($rRows);
	}
}
