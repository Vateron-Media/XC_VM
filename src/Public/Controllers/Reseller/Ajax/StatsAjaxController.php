<?php

namespace XcVm\Public\Controllers\Reseller\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Domain\Stream\ConnectionTracker;
use XcVm\Domain\User\ResellerAPI;
use XcVm\Domain\User\UserCredits;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Reseller-ajax controller for the dashboard and header statistics.
 *
 * Actions: dashboard, header_stats, stats.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class StatsAjaxController extends BaseResellerAjaxController {
	use DatabaseAware;

	/**
	 * Output reseller dashboard data (JSON) and exit.
	 */
	public function dashboard(): void {
		[$rUserInfo] = $this->begin();
		$db = self::db();
		$rReturn = ['open_connections' => 0, 'online_users' => 0, 'active_accounts' => 0, 'credits' => 0, 'credits_assigned' => 0];

		if (SettingsManager::getBool('redis_handler')) {
			$rReports = [];
			$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			foreach ($db->get_rows() as $rRow) {
				$rReports[] = $rRow['id'];
			}
			if (0 < count($rReports)) {
				foreach (ConnectionTracker::getUserConnections($rReports, true) as $rConnections) {
					$rReturn['open_connections'] += $rConnections;
					if (0 < $rConnections) {
						$rReturn['online_users']++;
					}
				}
			}
		} else {
			$db->query('SELECT COUNT(`activity_id`) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			$rReturn['open_connections'] = ($db->get_row()['count'] ?: 0);
			$db->query('SELECT `activity_id` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') GROUP BY `lines_live`.`user_id`;');
			$rReturn['online_users'] = $db->num_rows();
		}

		$db->query('SELECT COUNT(`id`) AS `count` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
		$rReturn['active_accounts'] = ($db->get_row()['count'] ?: 0);
		$db->query('SELECT SUM(`credits`) AS `credits` FROM `users` WHERE `id` IN (' . implode(',', $rUserInfo['reports']) . ');');
		$rReturn['credits'] = ResellerAPI::amount($db->get_row()['credits']);
		$rReturn['credits_assigned'] = ($rReturn['credits'] - intval($rUserInfo['credits']) ?: 0);
		echo json_encode($rReturn);
		exit();
	}

	/**
	 * Output header summary statistics (JSON) and exit.
	 */
	public function headerStats(): void {
		[$rUserInfo] = $this->begin();
		$db = self::db();
		$rReturn = ['total_connections' => 0, 'total_users' => 0];

		if (SettingsManager::getBool('redis_handler')) {
			$rReports = [];
			$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			foreach ($db->get_rows() as $rRow) {
				$rReports[] = $rRow['id'];
			}
			if (0 < count($rReports)) {
				foreach (ConnectionTracker::getUserConnections($rReports, true) as $rConnections) {
					$rReturn['total_connections'] += $rConnections;
					if (0 < $rConnections) {
						$rReturn['total_users']++;
					}
				}
			}
		} else {
			$db->query('SELECT COUNT(`activity_id`) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			$rReturn['total_connections'] = ($db->get_row()['count'] ?: 0);
			$db->query('SELECT `activity_id` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') GROUP BY `lines_live`.`user_id`;');
			$rReturn['total_users'] = $db->num_rows();
		}

		echo json_encode($rReturn, JSON_PARTIAL_OUTPUT_ON_ERROR);
		exit();
	}

	/**
	 * Output reseller statistics (JSON) and exit.
	 */
	public function stats(): void {
		[$rUserInfo] = $this->begin();
		$db = self::db();
		$rReturn = ['open_connections' => 0, 'online_users' => 0, 'total_lines' => 0, 'total_users' => 0, 'owner_credits' => 0, 'user_credits' => 0, 'total_credits' => 0];

		if (SettingsManager::getBool('redis_handler')) {
			$rReports = [];
			$db->query('SELECT `id` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			foreach ($db->get_rows() as $rRow) {
				$rReports[] = $rRow['id'];
			}
			if (0 < count($rReports)) {
				foreach (ConnectionTracker::getUserConnections($rReports, true) as $rConnections) {
					$rReturn['open_connections'] += $rConnections;
					if (0 < $rConnections) {
						$rReturn['online_users']++;
					}
				}
			}
		} else {
			$db->query('SELECT COUNT(`activity_id`) AS `count` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
			$rReturn['open_connections'] = ($db->get_row()['count'] ?: 0);
			$db->query('SELECT `activity_id` FROM `lines_live` LEFT JOIN `lines` ON `lines`.`id` = `lines_live`.`user_id` WHERE `hls_end` = 0 AND `lines`.`member_id` IN (' . implode(',', $rUserInfo['reports']) . ') GROUP BY `lines_live`.`user_id`;');
			$rReturn['online_users'] = $db->num_rows();
		}

		$db->query('SELECT COUNT(*) AS `count` FROM `lines` WHERE `member_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
		$rReturn['total_lines'] = $db->get_row()['count'];
		$db->query('SELECT COUNT(*) AS `count`, SUM(`credits`) AS `credits` FROM `users` WHERE `owner_id` IN (' . implode(',', $rUserInfo['reports']) . ');');
		$rRow = UserCredits::amounts($db->get_row());
		$rReturn['total_users'] = $rRow['count'];
		$rReturn['user_credits'] = $rRow['credits'];
		$rReturn['owner_credits'] = $rUserInfo['credits'];
		$rReturn['total_credits'] = $rReturn['owner_credits'] + $rReturn['user_credits'];
		echo json_encode($rReturn);
		exit();
	}
}
