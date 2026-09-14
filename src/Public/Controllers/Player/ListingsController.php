<?php

namespace XcVm\Public\Controllers\Player;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Epg\EpgService;
use XcVm\Domain\Stream\CategoryService;

/**
 * ListingsController — listings controller
 *
 * @package XC_VM_Public_Controllers_Player
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ListingsController extends BasePlayerController {
	public function index() {
		global $db, $rUserInfo;

		$rFlip = array_flip($rUserInfo['channel_ids']);
		$rTimezone = (RequestManager::get('timezone') ?? 'Europe/London');
		date_default_timezone_set($rTimezone);

		if (RequestManager::has('id')) {
			$rReturn = ['id' => RequestManager::get('id'), 'title' => 'LIVE TV', 'epg_title' => 'No Programme Information...', 'epg_description' => '', 'url' => null];

			if (isset($rFlip[RequestManager::get('id')])) {
				$rStart = intval(RequestManager::get('start') ?? time());
				$rDuration = intval(RequestManager::get('duration') ?? 0);
				$db->query('SELECT `id`, `stream_display_name`, `channel_id`, `epg_id` FROM `streams` WHERE `id` = ?;', RequestManager::get('id'));
				if ($db->num_rows() == 1) {
					$rStream = $db->get_row();
					$rReturn['title'] = $rStream['stream_display_name'];
					$rEPGRow = (EpgService::getStreamEpg(RequestManager::get('id'), $rStart, $rStart + 86400)[0] ?? null);
					if ($rEPGRow) {
						$rReturn['epg_title'] = date('h:ia', $rEPGRow['start']) . ' - ' . $rEPGRow['title'];
						$rReturn['epg_description'] = $rEPGRow['description'];
					}
				}
				$rDomainName = DomainResolver::resolve(SERVER_ID, !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443);
				if ($rStart + $rDuration * 60 < time() && 0 < $rDuration) {
					$rReturn['url'] = $rDomainName . 'timeshift/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rDuration . '/' . $rStart . '/' . intval(RequestManager::get('id')) . '.m3u8';
				} else {
					$rReturn['url'] = $rDomainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . intval(RequestManager::get('id')) . '.m3u8';
				}
			}

			echo json_encode($rReturn);
		} else {
			$rReturn = ['Channels' => []];
			$rChannels = [];
			$rHideEmpty = (intval(RequestManager::get('hideempty')));

			$rawChannels = RequestManager::get('channels')
				?? RequestManager::get('stream_ids')
				?? RequestManager::get('stream_id')
				?? ($_GET['channels'] ?? null)
				?? ($_GET['stream_ids'] ?? null)
				?? ($_GET['stream_id'] ?? null)
				?? ($_REQUEST['channels'] ?? null)
				?? ($_REQUEST['stream_ids'] ?? null)
				?? '';

			foreach (array_filter(array_map('intval', explode(',', (string) $rawChannels))) as $rChannelID) {
				if ($rChannelID && isset($rFlip[$rChannelID])) {
					$rChannels[] = $rChannelID;
				}
			}

			if (count($rChannels) != 0) {
				$rHours = (intval(RequestManager::get('hours')) ?: 3);
				$rStartDate = (intval(strtotime(RequestManager::get('startdate'))) ?: time());
				$rFinishDate = $rStartDate + $rHours * 3600;
				$rPerUnit = floatval(100 / ($rHours * 60));
				$rChannelsSort = $rChannels;
				sort($rChannelsSort);
				$rCacheID = md5($rTimezone . '_' . $rStartDate . '_' . $rHours . '_' . implode(',', $rChannelsSort) . '_' . $rHideEmpty);

				if (!file_exists(TMP_PATH . 'cache_' . $rCacheID) || 600 < time() - filemtime(TMP_PATH . 'cache_' . $rCacheID)) {
					$rListings = [];
					$rArchiveInfo = [];
					$db->query('SELECT `id`, `tv_archive_duration`, `tv_archive_server_id` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ');');
					if (0 < $db->num_rows()) {
						foreach ($db->get_rows() as $rRow) {
							$rArchiveInfo[$rRow['id']] = $rRow;
						}
					}
					$rEPGs = EpgService::getStreamsEpg($rChannels, $rStartDate, $rFinishDate);
					foreach ($rEPGs as $rChannelID => $rEPGData) {
						$rFullSize = 0;

						foreach ($rEPGData as $rEPGItem) {
							$rCapStart = ($rEPGItem['start'] < $rStartDate ? $rStartDate : $rEPGItem['start']);
							$rCapEnd = ($rFinishDate < $rEPGItem['end'] ? $rFinishDate : $rEPGItem['end']);
							$rDuration = ($rCapEnd - $rCapStart) / 60;
							$rArchive = null;

							if (isset($rArchiveInfo[$rChannelID])) {
								if (0 < $rArchiveInfo[$rChannelID]['tv_archive_server_id'] && 0 < $rArchiveInfo[$rChannelID]['tv_archive_duration']) {
									if (time() - $rEPGItem['tv_archive_duration'] * 86400 <= $rEPGItem['start']) {
										$rArchive = [$rEPGItem['start'], intval(($rEPGItem['end'] - $rEPGItem['start']) / 60)];
									}
								}
							}

							$rRelativeSize = round($rDuration * $rPerUnit, 2);
							$rFullSize += $rRelativeSize;

							if (100 < $rFullSize) {
								$rRelativeSize -= $rFullSize - 100;
							}

							$rListings[$rChannelID][] = ['ListingId' => $rEPGItem['id'], 'ChannelId' => $rChannelID, 'Title' => $rEPGItem['title'], 'RelativeSize' => $rRelativeSize, 'StartTime' => date('h:ia', $rCapStart), 'EndTime' => date('h:ia', $rCapEnd), 'Start' => $rEPGItem['start'], 'End' => $rEPGItem['end'], 'Specialisation' => 'tv', 'Archive' => $rArchive];
						}
					}

					$rDefaultEPG = ['ChannelId' => null, 'Title' => 'No Programme Information...', 'RelativeSize' => 100, 'StartTime' => 'N/A', 'EndTime' => '', 'Specialisation' => 'tv', 'Archive' => null];
					$db->query('SELECT `id`, `stream_icon`, `stream_display_name`, `tv_archive_duration`, `tv_archive_server_id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ') ORDER BY FIELD(`id`, ' . implode(',', $rChannels) . ') ASC;');

					foreach ($db->get_rows() as $rStream) {
						if (!$rHideEmpty || 0 < count($rListings[$rStream['id']] ?? [])) {
							if (0 < $rStream['tv_archive_duration'] && 0 < $rStream['tv_archive_server_id']) {
								$rArchive = $rStream['tv_archive_duration'];
							} else {
								$rArchive = 0;
							}
							$rDefaultArray = $rDefaultEPG;
							$rDefaultArray['ChannelId'] = $rStream['id'];
							$rCategoryIDs = json_decode($rStream['category_id'], true);
							$rCategories = CategoryService::getFromDatabase('live');
							if ((string) (RequestManager::get('category') ?? '') !== '') {
								$rCategory = ($rCategories[intval(RequestManager::get('category'))]['category_name'] ?? 'No Category');
							} else {
								$rCategory = ($rCategories[$rCategoryIDs[0] ?? null]['category_name'] ?? 'No Category');
							}
							if (1 < count($rCategoryIDs)) {
								$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
							}
							$rFirstProg = ($rListings[$rStream['id']][0] ?? $rDefaultArray);
							$rReturn[$rStream['id']] = ['id' => $rStream['id'], 'now' => ['title' => $rFirstProg['Title'] ?? 'Live Broadcast', 'start' => $rFirstProg['StartTime'] ?? '', 'end' => $rFirstProg['EndTime'] ?? '', 'percentage' => $rFirstProg['RelativeSize'] ?? 0]];
							$rReturn['Channels'][] = ['Id' => $rStream['id'], 'DisplayName' => $rStream['stream_display_name'], 'CategoryName' => $rCategory, 'Archive' => $rArchive, 'Image' => (ImageUtils::validateURL($rStream['stream_icon']) ?: ''), 'TvListings' => ($rListings[$rStream['id']] ?? [$rDefaultArray])];
						}
					}
					file_put_contents(TMP_PATH . 'cache_' . $rCacheID, igbinary_serialize($rReturn));
				} else {
					$rReturn = igbinary_unserialize(file_get_contents(TMP_PATH . 'cache_' . $rCacheID));
				}

				echo json_encode($rReturn);
			} else {
				echo json_encode($rReturn);
				exit();
			}
		}
	}
}
