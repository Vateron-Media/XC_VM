<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Domain\Server\ServerRepository;

/**
 * StatsCronJob — stats cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class StatsCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:stats';
	}

	public function getDescription(): string {
		return 'Cron: recalculate stream statistics (rating, uptime, connections)';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$this->initCron('XC_VM[Stats]');

		$rTimeout = 60;
		set_time_limit($rTimeout);
		ini_set('max_execution_time', $rTimeout);

		$this->loadCron();

		return 0;
	}

	private function loadCron(): void {
		global $db;

		if (!ServerRepository::getAll()[SERVER_ID]['is_main']) {
			return;
		}

		self::rebuild($db, time());
	}

	/**
	 * Rebuild streams_stats: per window, each stream's connections, viewing time
	 * and viewers, ranked by viewing time (then stream id, highest first). One
	 * aggregate per window, and the new set replaces the old in one transaction:
	 * Stream Rank shows the previous figures until it commits, and keeps them
	 * when a read or a write fails. Ids run 1..N, so the counter does not climb.
	 *
	 * @return int|null The rows written, or null when the table was left as it was
	 */
	public static function rebuild(object $db, int $rNow): ?int {
		$rDates = [
			'today' => [$rNow - 86400, $rNow],
			'week'  => [$rNow - 604800, $rNow],
			'month' => [$rNow - 2592000, $rNow],
			'all'   => [0, $rNow],
		];

		$rRows = [];
		foreach ($rDates as $rType => $rDate) {
			if (!$db->query('SELECT `stream_id`, COUNT(*) AS `connections`, SUM(`date_end` - `date_start`) AS `time`, COUNT(DISTINCT(`user_id`)) AS `users` FROM `lines_activity` LEFT JOIN `streams` ON `streams`.`id` = `lines_activity`.`stream_id` WHERE `date_start` > ? AND `date_end` <= ? GROUP BY `stream_id`;', $rDate[0], $rDate[1])) {
				return null;
			}
			$rWindow = $db->get_rows() ?: [];
			// SQL's ORDER BY `time` DESC, `stream_id` DESC: activity without a stream comes last among equals.
			$rKey = static fn(array $rRow): array => [intval($rRow['time']), $rRow['stream_id'] === null ? PHP_INT_MIN : intval($rRow['stream_id'])];
			usort($rWindow, static fn(array $a, array $b): int => $rKey($b) <=> $rKey($a));
			foreach ($rWindow as $i => $rRow) {
				$rRows[] = [count($rRows) + 1, $rRow['stream_id'], $i + 1, intval($rRow['time']), $rRow['connections'], $rRow['users'], $rType];
			}
		}

		if (!$db->beginTransaction()) {
			return null;
		}
		$rDone = $db->query('DELETE FROM `streams_stats`;');
		foreach (array_chunk($rRows, 1000) as $rChunk) {
			if (!$rDone) {
				break;
			}
			$rDone = $db->query('INSERT INTO `streams_stats` (`id`, `stream_id`, `rank`, `time`, `connections`, `users`, `type`) VALUES ' . implode(', ', array_fill(0, count($rChunk), '(?, ?, ?, ?, ?, ?, ?)')) . ';', ...array_merge(...$rChunk));
		}
		if (!$rDone || !$db->commit()) {
			$db->rollback();
			return null;
		}
		return count($rRows);
	}
}
