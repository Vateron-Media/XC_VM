<?php

include 'functions.php';
$rFlip = array_flip($rUserInfo['channel_ids']);
$rTimezone = (CoreUtilities::$rRequest['timezone'] ?: 'Europe/London');
date_default_timezone_set($rTimezone);

if (isset(CoreUtilities::$rRequest['id'])) {
	$rReturn = array('id' => CoreUtilities::$rRequest['id'], 'title' => 'LIVE TV', 'epg_title' => 'No Programme Information...', 'epg_description' => '', 'url' => null);

	if (!isset($rFlip[CoreUtilities::$rRequest['id']])) {
	} else {
		$rStart = intval(CoreUtilities::$rRequest['start'] ?: time());
		$rDuration = intval(CoreUtilities::$rRequest['duration'] ?: '');
		$db->query('SELECT `id`, `stream_display_name`, `channel_id`, `epg_id` FROM `streams` WHERE `id` = ?;', CoreUtilities::$rRequest['id']);

		if ($db->num_rows() != 1) {
		} else {
			$rStream = $db->get_row();
			$rReturn['title'] = $rStream['stream_display_name'];
			$rEPGRow = (CoreUtilities::getEPG(CoreUtilities::$rRequest['id'], $rStart, $rStart + 86400)[0] ?: null);

			if (!$rEPGRow) {
			} else {
				$rReturn['epg_title'] = date('h:ia', $rEPGRow['start']) . ' - ' . $rEPGRow['title'];
				$rReturn['epg_description'] = $rEPGRow['description'];
			}
		}

		$rDomainName = CoreUtilities::getDomainName(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' || $_SERVER['SERVER_PORT'] == 443);

		if ($rStart + $rDuration * 60 < time() && 0 < $rDuration) {
			$rReturn['url'] = $rDomainName . 'timeshift/' . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $rDuration . '/' . $rStart . '/' . intval(CoreUtilities::$rRequest['id']) . '.m3u8';
		} else {
			$rReturn['url'] = $rDomainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . intval(CoreUtilities::$rRequest['id']) . '.m3u8';
		}
	}

	echo json_encode($rReturn);
} else {
	$rReturn = array('Channels' => array());
	$rChannels = array();
	$rHideEmpty = (intval(CoreUtilities::$rRequest['hideempty']) ?: 0);

	foreach (array_map('intval', explode(',', CoreUtilities::$rRequest['channels'])) as $rChannelID) {
		if (!($rChannelID && isset($rFlip[$rChannelID]))) {
		} else {
			$rChannels[] = $rChannelID;
		}
	}

	if (count($rChannels) != 0) {
		$rHours = (intval(CoreUtilities::$rRequest['hours']) ?: 3);
		$rStartDate = (intval(strtotime(CoreUtilities::$rRequest['startdate'])) ?: time());
		$rFinishDate = $rStartDate + $rHours * 3600;
		$rPerUnit = floatval(100 / ($rHours * 60));
		$rChannelsSort = $rChannels;
		sort($rChannelsSort);
		$rCacheID = md5($rTimezone . '_' . $rStartDate . '_' . $rHours . '_' . implode(',', $rChannelsSort) . '_' . $rHideEmpty);

		if (!file_exists(TMP_PATH . 'cache_' . $rCacheID) || 600 < time() - filemtime(TMP_PATH . 'cache_' . $rCacheID)) {
			$rListings = array();

			if (0 >= count($rChannels)) {
			} else {
				$rArchiveInfo = array();
				$db->query('SELECT `id`, `tv_archive_duration`, `tv_archive_server_id` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ');');

				if (0 >= $db->num_rows()) {
				} else {
					foreach ($db->get_rows() as $rRow) {
						$rArchiveInfo[$rRow['id']] = $rRow;
					}
				}

				$rEPGs = CoreUtilities::getEPGs($rChannels, $rStartDate, $rFinishDate);

				foreach ($rEPGs as $rChannelID => $rEPGData) {
					$rFullSize = 0;

					foreach ($rEPGData as $rEPGItem) {
						$rCapStart = ($rEPGItem['start'] < $rStartDate ? $rStartDate : $rEPGItem['start']);
						$rCapEnd = ($rFinishDate < $rEPGItem['end'] ? $rFinishDate : $rEPGItem['end']);
						$rDuration = ($rCapEnd - $rCapStart) / 60;
						$rArchive = null;

						if (!isset($rArchiveInfo[$rChannelID])) {
						} else {
							if (!(0 < $rArchiveInfo[$rChannelID]['tv_archive_server_id'] && 0 < $rArchiveInfo[$rChannelID]['tv_archive_duration'])) {
							} else {
								if (time() - $rEPGItem['tv_archive_duration'] * 86400 > $rEPGItem['start']) {
								} else {
									$rArchive = array($rEPGItem['start'], intval(($rEPGItem['end'] - $rEPGItem['start']) / 60));
								}
							}
						}

						$rRelativeSize = round($rDuration * $rPerUnit, 2);
						$rFullSize += $rRelativeSize;

						if (100 >= $rFullSize) {
						} else {
							$rRelativeSize -= $rFullSize - 100;
						}

						$rListings[$rChannelID][] = array('ListingId' => $rEPGItem['id'], 'ChannelId' => $rChannelID, 'Title' => $rEPGItem['title'], 'RelativeSize' => $rRelativeSize, 'StartTime' => date('h:ia', $rCapStart), 'EndTime' => date('h:ia', $rCapEnd), 'Start' => $rEPGItem['start'], 'End' => $rEPGItem['end'], 'Specialisation' => 'tv', 'Archive' => $rArchive);
					}
				}
			}

			$rDefaultEPG = array('ChannelId' => null, 'Title' => 'No Programme Information...', 'RelativeSize' => 100, 'StartTime' => 'N/A', 'EndTime' => '', 'Specialisation' => 'tv', 'Archive' => null);
			$db->query('SELECT `id`, `stream_icon`, `stream_display_name`, `tv_archive_duration`, `tv_archive_server_id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', $rChannels) . ') ORDER BY FIELD(`id`, ' . implode(',', $rChannels) . ') ASC;');

			foreach ($db->get_rows() as $rStream) {
				if ($rHideEmpty && 0 >= count($rListings[$rStream['id']])) {
				} else {
					if (0 < $rStream['tv_archive_duration'] && 0 < $rStream['tv_archive_server_id']) {
						$rArchive = $rStream['tv_archive_duration'];
					} else {
						$rArchive = 0;
					}

					$rDefaultArray = $rDefaultEPG;
					$rDefaultArray['ChannelId'] = $rStream['id'];
					$rCategoryIDs = json_decode($rStream['category_id'], true);
					$rCategories = CoreUtilities::getCategories('live');

					if (0 < strlen(CoreUtilities::$rRequest['category'])) {
						$rCategory = ($rCategories[intval(CoreUtilities::$rRequest['category'])]['category_name'] ?: 'No Category');
					} else {
						$rCategory = ($rCategories[$rCategoryIDs[0]]['category_name'] ?: 'No Category');
					}

					if (1 >= count($rCategoryIDs)) {
					} else {
						$rCategory .= ' (+' . (count($rCategoryIDs) - 1) . ' others)';
					}

					$rReturn['Channels'][] = array('Id' => $rStream['id'], 'DisplayName' => $rStream['stream_display_name'], 'CategoryName' => $rCategory, 'Archive' => $rArchive, 'Image' => (CoreUtilities::validateImage($rStream['stream_icon']) ?: ''), 'TvListings' => ($rListings[$rStream['id']] ?: array($rDefaultArray)));
				}
			}
			file_put_contents(TMP_PATH . 'cache_' . $rCacheID, CoreUtilities::serialize($rReturn));
		} else {
			$rReturn = CoreUtilities::unserialize(file_get_contents(TMP_PATH . 'cache_' . $rCacheID));
		}

		echo json_encode($rReturn);
	} else {
		echo json_encode($rReturn);

		exit();
	}
}
