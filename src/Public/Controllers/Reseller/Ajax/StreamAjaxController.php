<?php

namespace XcVm\Public\Controllers\Reseller\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * Reseller-ajax controller for the streams and the TV guide.
 *
 * Actions: streamlist, get_epg, get_programme.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class StreamAjaxController extends BaseResellerAjaxController {
	use DatabaseAware;

	/**
	 * Output the available stream list (JSON) and exit.
	 */
	public function streamlist(): void {
		[, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['create_mag'] || $rPermissions['can_view_vod'] || $rPermissions['reseller_client_connection_logs']) {
			$rReturn = ['total_count' => 0, 'items' => [], 'result' => true];

			if (RequestManager::has('search')) {
				$rPage = RequestManager::has('page') ? intval(RequestManager::get('page')) : 1;

				$db->query('SELECT COUNT(`id`) AS `id` FROM `streams` WHERE `stream_display_name` LIKE ? AND `id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ');', '%' . RequestManager::get('search') . '%');
				$rReturn['total_count'] = $db->get_row()['id'];
				$db->query('SELECT `id`, `stream_display_name` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rPermissions['stream_ids'])) . ') AND `stream_display_name` LIKE ? ORDER BY `stream_display_name` ASC LIMIT ' . ($rPage - 1) * 100 . ', 100;', '%' . RequestManager::get('search') . '%');

				if ($db->num_rows() > 0) {
					foreach ($db->get_rows() as $rRow) {
						$rReturn['items'][] = ['id' => $rRow['id'], 'text' => $rRow['stream_display_name']];
					}
				}
			}

			echo json_encode($rReturn);
			exit();
		}
		exit();
	}

	/**
	 * Output EPG data for a stream (JSON) and exit.
	 */
	public function getEpg(): void {
		[, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['can_view_vod']) {
			if (count($rPermissions['stream_ids']) != 0) {
				$rTimezone = (RequestManager::get('timezone') ?: 'Europe/London');
				date_default_timezone_set($rTimezone);
				$rReturn = ['Channels' => []];
				// The streams of this reseller's packages only: the list is the request's.
				$rChannels = array_values(array_intersect(array_map('intval', explode(',', RequestManager::get('channels'))), array_map('intval', $rPermissions['stream_ids'])));

				if (count($rChannels) != 0) {
					$rHours = (intval(RequestManager::get('hours')) ?: 3);
					$rStartDate = (intval(strtotime((string) RequestManager::get('startdate'))) ?: time());
					$rFinishDate = $rStartDate + $rHours * 3600;
					$rPerUnit = floatval(100 / ($rHours * 60));
					$rChannelsSort = $rChannels;
					sort($rChannelsSort);
					$rListings = [];

					$rArchiveInfo = [];
					$db->query('SELECT `id`, `tv_archive_server_id`, `tv_archive_duration` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ');');
					if ($db->num_rows() > 0) {
						foreach ($db->get_rows() as $rRow) {
							$rArchiveInfo[$rRow['id']] = $rRow;
						}
					}

					$rEPG = EpgService::getStreamsEpg($rChannels, $rStartDate, $rFinishDate);

					foreach ($rEPG as $rChannelID => $rEPGData) {
						$rFullSize = 0;

						foreach ($rEPGData as $rEPGItem) {
							$rCapStart = ($rEPGItem['start'] < $rStartDate ? $rStartDate : $rEPGItem['start']);
							$rCapEnd = ($rFinishDate < $rEPGItem['end'] ? $rFinishDate : $rEPGItem['end']);
							$rDuration = ($rCapEnd - $rCapStart) / 60;
							$rArchive = null;

							if (isset($rArchiveInfo[$rChannelID])) {
								if (0 < $rArchiveInfo[$rChannelID]['tv_archive_server_id'] && 0 < $rArchiveInfo[$rChannelID]['tv_archive_duration']) {
									if (!(time() - $rArchiveInfo[$rChannelID]['tv_archive_duration'] * 86400 > $rEPGItem['start'])) {
										$rArchive = [$rEPGItem['start'], intval(($rEPGItem['end'] - $rEPGItem['start']) / 60)];
									}
								}
							}

							$rRelativeSize = round($rDuration * $rPerUnit, 2);
							$rFullSize += $rRelativeSize;

							if (100 < $rFullSize) {
								$rRelativeSize -= $rFullSize - 100;
							}

							$rListings[$rChannelID][] = ['ListingId' => $rEPGItem['id'], 'ChannelId' => $rChannelID, 'Title' => $rEPGItem['title'], 'RelativeSize' => $rRelativeSize, 'StartTime' => date('h:iA', $rCapStart), 'EndTime' => date('h:iA', $rCapEnd), 'Start' => $rEPGItem['start'], 'End' => $rEPGItem['end'], 'Specialisation' => 'tv', 'Archive' => $rArchive];
						}
					}

					$rDefaultEPG = ['ChannelId' => null, 'Title' => 'No Programme Information...', 'RelativeSize' => 100, 'StartTime' => 'Not Available', 'EndTime' => '', 'Specialisation' => 'tv', 'Archive' => null];
					$db->query('SELECT `id`, `stream_icon`, `stream_display_name`, `tv_archive_duration`, `tv_archive_server_id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ') ORDER BY FIELD(`id`, ' . implode(',', $rChannels) . ') ASC;');

					// The categories, read once for the guide: they were read again for each of
					// its streams. After the streams are taken from the handle, which the
					// categories' own query uses.
					$rStreams = $db->get_rows();
					$rCategories = CategoryService::getAllByType('live');
					foreach ($rStreams as $rStream) {
						if (0 < $rStream['tv_archive_duration'] && 0 < $rStream['tv_archive_server_id']) {
							$rArchive = $rStream['tv_archive_duration'];
						} else {
							$rArchive = 0;
						}

						$rDefaultArray = $rDefaultEPG;
						$rDefaultArray['ChannelId'] = $rStream['id'];
						// A stream in no category (NULL, or an empty list) is one, not the end of the guide.
						$rCategoryIDs = json_decode((string) $rStream['category_id'], true) ?: [];

						if ((string) RequestManager::get('category') !== '') {
							$rCategory = (($rCategories[intval(RequestManager::get('category'))]['category_name'] ?? null) ?: 'No Category');
						} else {
							$rCategory = (($rCategories[$rCategoryIDs[0] ?? 0]['category_name'] ?? null) ?: 'No Category');
						}

						if (1 < count($rCategoryIDs)) {
							$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
						}

						$rReturn['Channels'][] = ['Id' => $rStream['id'], 'DisplayName' => $rStream['stream_display_name'], 'CategoryName' => $rCategory, 'Archive' => $rArchive, 'Image' => (ImageUtils::validateURL($rStream['stream_icon']) ?: ''), 'TvListings' => ($rListings[$rStream['id']] ?? [$rDefaultArray])];
					}
					echo json_encode($rReturn);
					exit();
				}

				echo json_encode($rReturn);
				exit();
			}
			exit();
		}
		exit();
	}

	/**
	 * Output a single EPG programme (JSON) and exit.
	 */
	public function getProgramme(): void {
		[, $rPermissions] = $this->begin();
		$db = self::db();
		if ($rPermissions['can_view_vod']) {
			$rTimezone = (RequestManager::get('timezone') ?: 'Europe/London');
			date_default_timezone_set($rTimezone);

			if (RequestManager::has('id') && in_array(intval(RequestManager::get('stream_id')), array_map('intval', (array) ($rPermissions['stream_ids'] ?? [])), true)) {
				$rRow = EpgService::getProgramme(RequestManager::get('stream_id'), RequestManager::get('id'));

				if ($rRow) {
					$rArchive = $rAvailable = false;

					if (time() < $rRow['end']) {
						$db->query('SELECT `server_id`, `direct_source`, `monitor_pid`, `pid`, `stream_status`, `on_demand` FROM `streams` LEFT JOIN `streams_servers` ON `streams_servers`.`stream_id` = `streams`.`id` WHERE `streams`.`id` = ? AND `server_id` IS NOT NULL;', RequestManager::get('stream_id'));
						if ($db->num_rows() > 0) {
							foreach ($db->get_rows() as $rStreamRow) {
								if ($rStreamRow['server_id'] && !$rStreamRow['direct_source']) {
									$rAvailable = true;
									break;
								}
							}
						}
					}

					$rRow['date'] = date('H:i', $rRow['start']) . ' - ' . date('H:i', $rRow['end']);
					echo json_encode(['result' => true, 'data' => $rRow, 'available' => $rAvailable, 'archive' => $rArchive]);
					exit();
				}
			}

			echo json_encode(['result' => false]);
			exit();
		}
		exit();
	}
}
