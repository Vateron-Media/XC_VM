<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Cluster\StreamAssign;
use XcVm\Domain\Vod\SeriesService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * ChannelService — channel service
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ChannelService {
	use DatabaseAware;

	/**
	 * Create or update a live channel from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		global $rSettings;
		$db = self::db();
		if (isset($rData['edit'])) {
			if (Authorization::check('adv', 'edit_cchannel')) {
				// The edit starts from the stream as stored: the row cleaner's
				// escaping would be written back into every field not sent.
				$db->query('SELECT * FROM `streams` WHERE `id` = ?;', intval($rData['edit']));
				$rArray = AdminHelpers::overwriteData($db->get_raw_row(), $rData);
			} else {
				exit();
			}
		} else {
			if (Authorization::check('adv', 'create_channel')) {
				$rArray = QueryHelper::verifyPostTable('streams', $rData);
				$rArray['type'] = 3;
				$rArray['added'] = time();
				unset($rArray['id']);
			} else {
				exit();
			}
		}

		if (isset($rData['restart_on_edit'])) {
			$rRestart = true;
		} else {
			$rRestart = false;
		}

		if (isset($rData['reencode_on_edit'])) {
			$rReencode = true;
		} else {
			$rReencode = false;
		}

		foreach (['allow_record', 'rtmp_output'] as $rKey) {
			if (isset($rData[$rKey])) {
				$rArray[$rKey] = 1;
			} else {
				$rArray[$rKey] = 0;
			}
		}
		$rArray['movie_properties'] = ['type' => intval($rData['channel_type'])];

		if (intval($rData['channel_type']) == 0) {
			$rPlaylist = SeriesService::generatePlaylist($rData['series_no']);
			$rArray['stream_source'] = $rPlaylist;
			$rArray['series_no'] = intval($rData['series_no']);
		} else {
			$rVideoFiles = $rData['video_files'];
			if (is_string($rVideoFiles)) {
				$rVideoFiles = json_decode($rVideoFiles, true);
			}
			$rArray['stream_source'] = is_array($rVideoFiles) ? $rVideoFiles : [];
			$rArray['series_no'] = 0;
		}

		if ($rData['transcode_profile_id'] == -1) {
			$rArray['movie_symlink'] = 1;
		} else {
			$rArray['movie_symlink'] = 0;
		}

		if (0 < count($rArray['stream_source'])) {
			$rBouquetCreate = [];

			foreach (json_decode((string) ($rData['bouquet_create_list'] ?? ''), true) ?: [] as $rBouquet) {
				$rPrepare = QueryHelper::prepareArray(['bouquet_name' => $rBouquet, 'bouquet_channels' => [], 'bouquet_movies' => [], 'bouquet_series' => [], 'bouquet_radios' => []]);
				$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rBouquetID = $db->last_insert_id();
					$rBouquetCreate[$rBouquet] = $rBouquetID;
				}
			}
			$rCategoryCreate = [];

			foreach (json_decode((string) ($rData['category_create_list'] ?? ''), true) ?: [] as $rCategory) {
				$rPrepare = QueryHelper::prepareArray(['category_type' => 'live', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0]);
				$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

				if ($db->query($rQuery, ...$rPrepare['data'])) {
					$rCategoryID = $db->last_insert_id();
					$rCategoryCreate[$rCategory] = $rCategoryID;
				}
			}
			$rBouquets = [];

			foreach ($rData['bouquets'] as $rBouquet) {
				if (isset($rBouquetCreate[$rBouquet])) {
					$rBouquets[] = $rBouquetCreate[$rBouquet];
				} else {
					if (is_numeric($rBouquet)) {
						$rBouquets[] = intval($rBouquet);
					}
				}
			}
			$rCategories = [];

			foreach ($rData['category_id'] as $rCategory) {
				if (isset($rCategoryCreate[$rCategory])) {
					$rCategories[] = $rCategoryCreate[$rCategory];
				} else {
					if (is_numeric($rCategory)) {
						$rCategories[] = intval($rCategory);
					}
				}
			}
			$rArray['category_id'] = '[' . implode(',', array_map('intval', $rCategories)) . ']';

			if ($rSettings['download_images']) {
				$rArray['stream_icon'] = ImageUtils::downloadImage($rArray['stream_icon'], 3);
			}

			if (!isset($rData['edit'])) {
				$rArray['order'] = StreamRepository::getNextOrder();
			}

			$rPrepare = QueryHelper::prepareArray($rArray);
			$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rInsertID = $db->last_insert_id();
				$rStreamExists = [];

				if (isset($rData['edit'])) {
					$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);
					foreach ($db->get_rows() as $rRow) {
						$rStreamExists[intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
					}
				}

				$rStreamsAdded = [];
				$rServerTree = json_decode($rData['server_tree_data'], true);

				foreach ($rServerTree as $rServer) {
					if ($rServer['parent'] != '#') {
						$rServerID = intval($rServer['id']);
						$rStreamsAdded[] = $rServerID;
						$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?? [])));
						if ($rServer['parent'] == 'source') {
							$rParent = null;
						} else {
							$rParent = intval($rServer['parent']);
						}
						if (isset($rStreamExists[$rServerID])) {
							$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rServerID]);
						} else {
							$db->query("INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`, `pids_create_channel`, `cchannel_rsources`) VALUES(?, ?, ?, ?, '[]', '[]');", $rInsertID, $rServerID, $rParent, $rOD);
						}
					}
				}

				foreach ($rStreamExists as $rServerID => $rDBID) {
					if (!in_array($rServerID, $rStreamsAdded)) {
						StreamRepository::deleteStream($rInsertID, $rServerID, false, false);
					}
				}

				if ($rReencode) {
					ApiClient::request(['action' => 'stream', 'sub' => 'stop', 'stream_ids' => [$rInsertID]]);
					$db->query("UPDATE `streams_servers` SET `pids_create_channel` = '[]', `cchannel_rsources` = '[]' WHERE `stream_id` = ?;", $rInsertID);
					StreamAssign::send([$rInsertID], ['cchannel_rsources' => '[]']);
					StreamProcess::queueChannel($rInsertID);
				}

				if ($rRestart) {
					ApiClient::request(['action' => 'stream', 'sub' => 'start', 'stream_ids' => [$rInsertID]]);
				}

				foreach ($rBouquets as $rBouquet) {
					BouquetService::addItems('stream', $rBouquet, $rInsertID);
				}

				if (isset($rData['edit'])) {
					foreach (BouquetService::getAllSimple() as $rBouquet) {
						if (!in_array($rBouquet['id'], $rBouquets)) {
							BouquetService::removeItems('stream', $rBouquet['id'], $rInsertID);
						}
					}
				}

				StreamProcess::updateStream($rInsertID);
				EventDispatcher::dispatch(new StreamsChangedEvent([(int) $rInsertID]));

				return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
			}
			return ['status' => STATUS_FAILURE, 'data' => $rData];
		}
		return ['status' => STATUS_NO_SOURCES, 'data' => $rData];
	}

	/**
	 * Apply bulk edits to a set of selected channels.
	 *
	 * @param array $rData Selected ids plus the fields/values to apply.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massEdit(array $rData) {
		$db = self::db();
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);

		$rArray = [];

		foreach (['allow_record', 'rtmp_output'] as $rKey) {
			if (isset($rData['c_' . $rKey])) {
				if (isset($rData[$rKey])) {
					$rArray[$rKey] = 1;
				} else {
					$rArray[$rKey] = 0;
				}
			}
		}

		if (isset($rData['c_transcode_profile_id'])) {
			$rArray['transcode_profile_id'] = $rData['transcode_profile_id'];
			if (0 < $rArray['transcode_profile_id']) {
				$rArray['enable_transcode'] = 1;
			} else {
				$rArray['enable_transcode'] = 0;
			}
		}

		$rStreamIDs = AdminHelpers::confirmIDs(json_decode($rData['streams'], true));

		if (0 < count($rStreamIDs)) {
			$rCategoryMap = [];
			if (isset($rData['c_category_id']) && in_array($rData['category_id_type'], ['ADD', 'DEL'])) {
				$db->query('SELECT `id`, `category_id` FROM `streams` WHERE `id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');
				foreach ($db->get_rows() as $rRow) {
					$rCategoryMap[$rRow['id']] = (json_decode($rRow['category_id'], true) ?: []);
				}
			}
			$rDeleteServers = $rProcessServers = $rStreamExists = [];
			$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');
			foreach ($db->get_rows() as $rRow) {
				$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
				$rProcessServers[intval($rRow['stream_id'])][] = intval($rRow['server_id']);
			}
			$rBouquets = BouquetService::getAllSimple();
			$rAddBouquet = $rDelBouquet = [];
			$rEncQuery = $rAddQuery = '';
			foreach ($rStreamIDs as $rStreamID) {
				if (isset($rData['c_category_id'])) {
					$rCategories = array_map('intval', $rData['category_id']);
					if ($rData['category_id_type'] == 'ADD') {
						foreach (($rCategoryMap[$rStreamID] ?: []) as $rCategoryID) {
							if (!in_array($rCategoryID, $rCategories)) {
								$rCategories[] = $rCategoryID;
							}
						}
					} else {
						if ($rData['category_id_type'] == 'DEL') {
							$rNewCategories = $rCategoryMap[$rStreamID];
							foreach ($rCategories as $rCategoryID) {
								if (($rKey = array_search($rCategoryID, $rNewCategories)) !== false) {
									unset($rNewCategories[$rKey]);
								}
							}
							$rCategories = $rNewCategories;
						}
					}
					$rArray['category_id'] = '[' . implode(',', $rCategories) . ']';
				}

				$rPrepare = QueryHelper::prepareArray($rArray);

				if (0 < count($rPrepare['data'])) {
					$rPrepare['data'][] = $rStreamID;
					$rQuery = 'UPDATE `streams` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
					$db->query($rQuery, ...$rPrepare['data']);
				}

				if (isset($rData['c_server_tree'])) {
					$rStreamsAdded = [];
					$rServerTree = json_decode($rData['server_tree_data'], true);
					foreach ($rServerTree as $rServer) {
						if ($rServer['parent'] != '#') {
							$rServerID = intval($rServer['id']);
							if (in_array($rData['server_type'], ['ADD', 'SET'])) {
								$rStreamsAdded[] = $rServerID;
								$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?? [])));

								if ($rServer['parent'] == 'source') {
									$rParent = null;
								} else {
									$rParent = intval($rServer['parent']);
								}

								if (isset($rStreamExists[$rServerID])) {
									$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStreamExists[$rServerID]);
								} else {
									$rAddQuery .= '(' . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . (($rParent ?: 'NULL')) . ', ' . $rOD . '),';
								}

								$rProcessServers[$rStreamID][] = $rServerID;
							} else {
								if (isset($rStreamExists[$rStreamID][$rServerID])) {
									$rDeleteServers[$rServerID][] = $rStreamID;
								}
							}
						}
					}
					if ($rData['server_type'] == 'SET') {
						foreach ($rStreamExists as $rServerID => $rDBID) {
							if (!in_array($rServerID, $rStreamsAdded)) {
								$rDeleteServers[$rServerID][] = $rStreamID;
								if (($rKey = array_search($rServerID, $rProcessServers[$rStreamID])) !== false) {
									unset($rProcessServers[$rStreamID][$rKey]);
								}
							}
						}
					}
				}

				if (isset($rData['c_bouquets'])) {
					if ($rData['bouquets_type'] == 'SET') {
						foreach ($rData['bouquets'] as $rBouquet) {
							$rAddBouquet[$rBouquet][] = $rStreamID;
						}

						foreach ($rBouquets as $rBouquet) {
							if (!in_array($rBouquet['id'], $rData['bouquets'])) {
								$rDelBouquet[$rBouquet['id']][] = $rStreamID;
							}
						}
					} else {
						if ($rData['bouquets_type'] == 'ADD') {
							foreach ($rData['bouquets'] as $rBouquet) {
								$rAddBouquet[$rBouquet][] = $rStreamID;
							}
						} else {
							if ($rData['bouquets_type'] == 'DEL') {
								foreach ($rData['bouquets'] as $rBouquet) {
									$rDelBouquet[$rBouquet][] = $rStreamID;
								}
							}
						}
					}
				}

				if (isset($rData['reencode_on_edit'])) {
					foreach ($rProcessServers[$rStreamID] as $rServerID) {
						$rEncQuery .= "('channel', " . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . time() . '),';
					}
				}
			}
			foreach ($rDeleteServers as $rServerID => $rDeleteIDs) {
				StreamRepository::deleteStreamsByServer($rDeleteIDs, $rServerID, false);
			}
			foreach ($rAddBouquet as $rBouquetID => $rAddIDs) {
				BouquetService::addItems('stream', $rBouquetID, $rAddIDs);
			}
			foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
				BouquetService::removeItems('stream', $rBouquetID, $rRemIDs);
			}
			if (!empty($rAddQuery)) {
				$rAddQuery = rtrim($rAddQuery, ',');
				$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES ' . $rAddQuery . ';');
			}
			StreamProcess::updateStreams($rStreamIDs);
			EventDispatcher::dispatch(new StreamsChangedEvent(array_values(array_map('intval', $rStreamIDs))));
			if (isset($rData['reencode_on_edit'])) {
				$db->query("UPDATE `streams_servers` SET `pids_create_channel` = '[]', `cchannel_rsources` = '[]' WHERE `stream_id` IN (" . implode(',', array_map('intval', $rStreamIDs)) . ');');
				StreamAssign::send($rStreamIDs, ['cchannel_rsources' => '[]']);

				if (!empty($rEncQuery)) {
					$rEncQuery = rtrim($rEncQuery, ',');
					$db->query('INSERT INTO `queue`(`type`, `stream_id`, `server_id`, `added`) VALUES ' . $rEncQuery . ';');
				}

				ApiClient::request(['action' => 'stream', 'sub' => 'stop', 'stream_ids' => array_values($rStreamIDs)]);
			} else {
				if (isset($rData['restart_on_edit'])) {
					ApiClient::request(['action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs)]);
				}
			}
		}

		return ['status' => STATUS_SUCCESS];
	}

	/**
	 * Persist the display order of channels from posted data.
	 *
	 * @param array $rData Ordered channel ids.
	 * @return array ['status' => STATUS_* constant].
	 */
	public static function setOrder(array $rData) {
		$db = self::db();
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);
		$rOrder = json_decode($rData['stream_order_array'], true);
		$rSort = 0;

		foreach ($rOrder as $rStream) {
			$db->query('UPDATE `streams` SET `order` = ? WHERE `id` = ?;', $rSort, $rStream);
			$rSort++;
		}

		return ['status' => STATUS_SUCCESS];
	}
}
