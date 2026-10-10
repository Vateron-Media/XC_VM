<?php

namespace XcVm\Domain\Stream;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Database\QueryHelper;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Core\Http\ApiClient;
use XcVm\Core\Util\AdminHelpers;
use XcVm\Core\Util\ImageUtils;
use XcVm\Core\Validation\InputValidator;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Infrastructure\Database\DatabaseAware;

/**
 * RadioService — radio service
 *
 * @package XC_VM_Domain_Stream
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class RadioService {
	use DatabaseAware;

	/**
	 * Create or update a radio stream from admin form data.
	 *
	 * @param array $rData Submitted form data (includes `edit` id when updating).
	 * @return array ['status' => STATUS_* constant, 'data' => insert_id or payload].
	 */
	public static function process(array $rData) {
		$db = self::db();

		if (!InputValidator::validate('processRadio', $rData)) {
			return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
		}

		if (isset($rData['edit'])) {
			if (!Authorization::check('adv', 'edit_radio')) {
				exit();
			}
			// The edit starts from the stream as stored: the row cleaner's
			// escaping would be written back into every field not sent.
			$db->query('SELECT * FROM `streams` WHERE `id` = ?;', intval($rData['edit']));
			$rArray = AdminHelpers::overwriteData($db->get_raw_row(), $rData);
		} else {
			if (!Authorization::check('adv', 'add_radio')) {
				exit();
			}
			$rArray = QueryHelper::verifyPostTable('streams', $rData);
			$rArray['type'] = 4;
			$rArray['added'] = time();
			unset($rArray['id']);
		}

		$rArray['auto_restart'] = self::buildAutoRestart($rData);
		$rArray['direct_source'] = isset($rData['direct_source']) ? 1 : 0;
		$rArray['probesize_ondemand'] = isset($rData['probesize_ondemand']) ? intval($rData['probesize_ondemand']) : 128000;
		$rRestart = isset($rData['restart_on_edit']);

		if (0 >= strlen($rData['stream_source'][0])) {
			return ['status' => STATUS_NO_SOURCES, 'data' => $rData];
		}

		$rBouquetCreate = self::createMissingBouquets($db, $rData);
		$rCategoryCreate = self::createMissingCategories($db, $rData);
		$rBouquets = self::resolveSelectedIds($rData['bouquets'], $rBouquetCreate);
		$rCategories = self::resolveSelectedIds($rData['category_id'] ?? [], $rCategoryCreate);

		$rArray['category_id'] = '[' . implode(',', array_map('intval', $rCategories)) . ']';
		$rArray['stream_source'] = $rData['stream_source'];
		if (SettingsManager::get('download_images')) {
			$rArray['stream_icon'] = ImageUtils::downloadImage($rArray['stream_icon'], 4);
		}
		if (!isset($rData['edit'])) {
			$rArray['order'] = StreamRepository::getNextOrder();
		}

		$rPrepare = QueryHelper::prepareArray($rArray);
		$rQuery = 'REPLACE INTO `streams`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';

		if (!$db->query($rQuery, ...$rPrepare['data'])) {
			// Insert failed — roll back the bouquets/categories created above.
			foreach ($rBouquetCreate as $rID) {
				$db->query('DELETE FROM `bouquets` WHERE `id` = ?;', $rID);
			}
			foreach ($rCategoryCreate as $rID) {
				$db->query('DELETE FROM `streams_categories` WHERE `id` = ?;', $rID);
			}

			return ['status' => STATUS_FAILURE, 'data' => $rData];
		}

		$rInsertID = $db->last_insert_id();

		$rStationExists = [];
		if (isset($rData['edit'])) {
			$db->query('SELECT `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` = ?;', $rInsertID);
			foreach ($db->get_rows() as $rRow) {
				$rStationExists[intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
			}
		}

		self::syncServerTree($db, $rInsertID, json_decode($rData['server_tree_data'], true), $rData['on_demand'] ?? [], $rStationExists);
		self::saveStreamOptions($db, $rInsertID, $rData);

		if ($rRestart) {
			ApiClient::request(['action' => 'stream', 'sub' => 'start', 'stream_ids' => [$rInsertID]]);
		}

		self::syncBouquets($rInsertID, $rBouquets, isset($rData['edit']));
		StreamProcess::updateStream($rInsertID);
		EventDispatcher::dispatch(new StreamsChangedEvent([(int) $rInsertID]));

		return ['status' => STATUS_SUCCESS, 'data' => ['insert_id' => $rInsertID]];
	}

	/**
	 * Build the auto_restart schedule from the form's days/time fields.
	 *
	 * @param array $rData Submitted form data.
	 * @return array|string ['days' => string[], 'at' => 'HH:MM'] for a valid
	 *                      schedule, otherwise '' (no schedule).
	 */
	private static function buildAutoRestart(array $rData): array|string {
		if (!isset($rData['days_to_restart']) || !preg_match('/^(?:2[0-3]|[01][0-9]):[0-5][0-9]$/', (string) ($rData['time_to_restart'] ?? ''))) {
			return '';
		}

		return ['days' => array_values($rData['days_to_restart']), 'at' => $rData['time_to_restart']];
	}

	/**
	 * Resolve submitted selections (bouquets or categories) to integer ids.
	 *
	 * A value is either the name of an entity created earlier in this request
	 * (mapped via $rCreatedMap) or an existing numeric id; anything else is
	 * skipped.
	 *
	 * @param array<int,mixed>  $rSelected   Submitted values.
	 * @param array<string,int> $rCreatedMap name => new id for entities created this request.
	 * @return int[]
	 */
	private static function resolveSelectedIds(array $rSelected, array $rCreatedMap): array {
		$rIds = [];
		foreach ($rSelected as $rValue) {
			if (isset($rCreatedMap[$rValue])) {
				$rIds[] = $rCreatedMap[$rValue];
			} elseif (is_numeric($rValue)) {
				$rIds[] = intval($rValue);
			}
		}

		return $rIds;
	}

	/**
	 * Insert any bouquets named in bouquet_create_list.
	 *
	 * @param object $db    Database handler.
	 * @param array  $rData Submitted form data.
	 * @return array<string,int> Created bouquet name => new id.
	 */
	private static function createMissingBouquets(object $db, array $rData): array {
		$rCreated = [];
		foreach (json_decode($rData['bouquet_create_list'] ?? '[]', true) ?: [] as $rBouquet) {
			$rPrepare = QueryHelper::prepareArray(['bouquet_name' => $rBouquet, 'bouquet_channels' => [], 'bouquet_movies' => [], 'bouquet_series' => [], 'bouquet_radios' => []]);
			$rQuery = 'INSERT INTO `bouquets`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rCreated[$rBouquet] = $db->last_insert_id();
			}
		}

		return $rCreated;
	}

	/**
	 * Insert any radio categories named in category_create_list.
	 *
	 * @param object $db    Database handler.
	 * @param array  $rData Submitted form data.
	 * @return array<string,int> Created category name => new id.
	 */
	private static function createMissingCategories(object $db, array $rData): array {
		$rCreated = [];
		foreach (json_decode($rData['category_create_list'] ?? '[]', true) ?: [] as $rCategory) {
			$rPrepare = QueryHelper::prepareArray(['category_type' => 'radio', 'category_name' => $rCategory, 'parent_id' => 0, 'cat_order' => 99, 'is_adult' => 0]);
			$rQuery = 'INSERT INTO `streams_categories`(' . $rPrepare['columns'] . ') VALUES(' . $rPrepare['placeholder'] . ');';
			if ($db->query($rQuery, ...$rPrepare['data'])) {
				$rCreated[$rCategory] = $db->last_insert_id();
			}
		}

		return $rCreated;
	}

	/**
	 * Replace a stream's ffmpeg option rows from the submitted form fields.
	 *
	 * Clears existing streams_options for the stream, then re-inserts the ones
	 * present in the form (user_agent, http_proxy, cookie, headers, skip_ffprobe,
	 * force_input_acodec).
	 *
	 * @param object     $db        Database handler.
	 * @param int|string $rInsertID Stream id.
	 * @param array      $rData     Submitted form data.
	 */
	private static function saveStreamOptions(object $db, int|string $rInsertID, array $rData): void {
		$db->query('DELETE FROM `streams_options` WHERE `stream_id` = ?;', $rInsertID);

		if (isset($rData['user_agent']) && (string) $rData['user_agent'] !== '') {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 1, ?);', $rInsertID, $rData['user_agent']);
		}
		if (isset($rData['http_proxy']) && (string) $rData['http_proxy'] !== '') {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 2, ?);', $rInsertID, $rData['http_proxy']);
		}
		if (isset($rData['cookie']) && (string) $rData['cookie'] !== '') {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 17, ?);', $rInsertID, $rData['cookie']);
		}
		if (isset($rData['headers']) && (string) $rData['headers'] !== '') {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 19, ?);', $rInsertID, $rData['headers']);
		}
		if (isset($rData['skip_ffprobe']) && ($rData['skip_ffprobe'] == 'on' || $rData['skip_ffprobe'] == 1)) {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 21, ?);', $rInsertID, '1');
		}
		if (isset($rData['force_input_acodec']) && trim($rData['force_input_acodec']) !== '') {
			$db->query('INSERT INTO `streams_options`(`stream_id`, `argument_id`, `value`) VALUES(?, 20, ?);', $rInsertID, trim($rData['force_input_acodec']));
		}
	}

	/**
	 * Reconcile a stream's server rows against the submitted server tree.
	 *
	 * Inserts/updates streams_servers for every node in the tree (skipping the
	 * '#' root), then removes any previously-attached server no longer present.
	 *
	 * @param object         $db             Database handler.
	 * @param int|string     $rInsertID      Stream id.
	 * @param array          $rServerTree    Decoded server_tree_data.
	 * @param array          $rOnDemand      Server ids flagged on-demand.
	 * @param array<int,int> $rStationExists Existing server_id => server_stream_id.
	 */
	private static function syncServerTree(object $db, int|string $rInsertID, array $rServerTree, array $rOnDemand, array $rStationExists): void {
		$rStreamsAdded = [];
		foreach ($rServerTree as $rServer) {
			if ($rServer['parent'] == '#') {
				continue;
			}
			$rServerID = intval($rServer['id']);
			$rStreamsAdded[] = $rServerID;
			$rOD = intval(in_array($rServerID, $rOnDemand));
			$rParent = ($rServer['parent'] == 'source') ? null : intval($rServer['parent']);

			if (isset($rStationExists[$rServerID])) {
				$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rStationExists[$rServerID]);
			} else {
				$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES(?, ?, ?, ?);', $rInsertID, $rServerID, $rParent, $rOD);
			}
		}

		foreach ($rStationExists as $rServerID => $rDBID) {
			if (!in_array($rServerID, $rStreamsAdded)) {
				StreamRepository::deleteStream($rInsertID, $rServerID, false, false);
			}
		}
	}

	/**
	 * Attach the stream to the selected bouquets, and — on edit — detach it from
	 * any bouquet no longer selected.
	 *
	 * @param int|string $rInsertID Stream id.
	 * @param int[]      $rBouquets Selected bouquet ids.
	 * @param bool       $rIsEdit   Whether this is an edit (enables detach).
	 */
	private static function syncBouquets(int|string $rInsertID, array $rBouquets, bool $rIsEdit): void {
		foreach ($rBouquets as $rBouquet) {
			BouquetService::addItems('radio', $rBouquet, $rInsertID);
		}

		if (!$rIsEdit) {
			return;
		}

		foreach (BouquetService::getAllSimple() as $rBouquet) {
			if (!in_array($rBouquet['id'], $rBouquets)) {
				BouquetService::removeItems('radio', $rBouquet['id'], $rInsertID);
			}
		}
	}

	/**
	 * Compute a stream's new category list for a mass edit.
	 *
	 * ADD unions the selected categories onto the existing ones; DEL removes the
	 * selected ones from the existing; any other type (SET) replaces with the
	 * selected list.
	 *
	 * @param string    $rType     'ADD', 'DEL' or otherwise (SET/replace).
	 * @param int[]     $rExisting The stream's current category ids.
	 * @param int[]     $rSelected The submitted category ids.
	 * @return int[] The resulting category ids.
	 */
	private static function computeCategoryChange(string $rType, array $rExisting, array $rSelected): array {
		if ($rType === 'ADD') {
			foreach ($rExisting as $rCategoryID) {
				if (!in_array($rCategoryID, $rSelected)) {
					$rSelected[] = $rCategoryID;
				}
			}

			return $rSelected;
		}

		if ($rType === 'DEL') {
			return array_values(array_filter($rExisting, static fn ($rID) => !in_array($rID, $rSelected)));
		}

		return $rSelected;
	}

	/**
	 * Plan which bouquets a stream should be added to / removed from.
	 *
	 * SET attaches to the selected bouquets and detaches from every other; ADD
	 * only attaches to the selected; DEL only detaches from the selected.
	 *
	 * @param string $rType         'SET', 'ADD' or 'DEL'.
	 * @param array  $rSelected     Selected bouquet ids.
	 * @param array  $rAllBouquets  All bouquets (rows with 'id'), for SET detach.
	 * @return array{add: array, del: array} Bouquet ids to attach / detach.
	 */
	private static function planBouquetChanges(string $rType, array $rSelected, array $rAllBouquets): array {
		if ($rType === 'SET') {
			$rDel = [];
			foreach ($rAllBouquets as $rBouquet) {
				if (!in_array($rBouquet['id'], $rSelected)) {
					$rDel[] = $rBouquet['id'];
				}
			}

			return ['add' => $rSelected, 'del' => $rDel];
		}

		if ($rType === 'ADD') {
			return ['add' => $rSelected, 'del' => []];
		}

		if ($rType === 'DEL') {
			return ['add' => [], 'del' => $rSelected];
		}

		return ['add' => [], 'del' => []];
	}

	/**
	 * Lift PHP time/timeout limits for the long-running bulk operations.
	 */
	private static function raiseTimeLimits(): void {
		set_time_limit(0);
		ini_set('mysql.connect_timeout', 0);
		ini_set('max_execution_time', 0);
		ini_set('default_socket_timeout', 0);
	}

	/**
	 * Plan one stream's server-tree changes during a mass edit.
	 *
	 * ADD/SET attach or re-parent each tree node (updating existing rows in place,
	 * or appending to the batch-insert buffer); SET additionally marks existing
	 * attachments absent from the tree for deletion. A non-ADD/SET type (DEL) only
	 * marks the tree's existing attachments for deletion.
	 *
	 * @param object         $db              Database handler.
	 * @param array          $rData           Submitted form data (server_type, server_tree_data, on_demand).
	 * @param int|string     $rStreamID       Stream id.
	 * @param array<int,int> $rExistingServers server_id => server_stream_id already attached to this stream.
	 * @param string         $rAddQuery       Batch INSERT VALUES buffer (appended in place).
	 * @param array          $rDeleteServers  server_id => stream ids to detach (appended in place).
	 */
	private static function planServerTreeForStream(object $db, array $rData, int|string $rStreamID, array $rExistingServers, string &$rAddQuery, array &$rDeleteServers): void {
		$rStreamsAdded = [];
		foreach (json_decode((string) ($rData['server_tree_data'] ?? ''), true) ?: [] as $rServer) {
			if ($rServer['parent'] == '#') {
				continue;
			}
			$rServerID = intval($rServer['id']);

			if (!in_array($rData['server_type'], ['ADD', 'SET'])) {
				if (isset($rExistingServers[$rServerID])) {
					$rDeleteServers[$rServerID][] = $rStreamID;
				}
				continue;
			}

			$rOD = intval(in_array($rServerID, ($rData['on_demand'] ?: [])));
			$rParent = ($rServer['parent'] == 'source') ? null : intval($rServer['parent']);
			$rStreamsAdded[] = $rServerID;

			if (isset($rExistingServers[$rServerID])) {
				$db->query('UPDATE `streams_servers` SET `parent_id` = ?, `on_demand` = ? WHERE `server_stream_id` = ?;', $rParent, $rOD, $rExistingServers[$rServerID]);
			} else {
				$rAddQuery .= '(' . intval($rStreamID) . ', ' . intval($rServerID) . ', ' . (($rParent ?: 'NULL')) . ', ' . $rOD . '),';
			}
		}

		if ($rData['server_type'] == 'SET') {
			foreach (array_keys($rExistingServers) as $rServerID) {
				if (!in_array($rServerID, $rStreamsAdded)) {
					$rDeleteServers[$rServerID][] = $rStreamID;
				}
			}
		}
	}

	/**
	 * Apply bulk edits to a set of selected radio streams.
	 *
	 * @param array $rData Selected ids plus the fields/values to apply.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massEdit(array $rData) {
		$db = self::db();
		self::raiseTimeLimits();

		if (InputValidator::validate('massEditRadios', $rData)) {
			$rArray = [];

			if (isset($rData['c_direct_source'])) {
				if (isset($rData['direct_source'])) {
					$rArray['direct_source'] = 1;
				} else {
					$rArray['direct_source'] = 0;
				}
			}

			if (isset($rData['c_custom_sid'])) {
				$rArray['custom_sid'] = $rData['custom_sid'];
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
				$rDeleteServers = $rStreamExists = [];
				$db->query('SELECT `stream_id`, `server_stream_id`, `server_id` FROM `streams_servers` WHERE `stream_id` IN (' . implode(',', array_map('intval', $rStreamIDs)) . ');');
				foreach ($db->get_rows() as $rRow) {
					$rStreamExists[intval($rRow['stream_id'])][intval($rRow['server_id'])] = intval($rRow['server_stream_id']);
				}
				$rBouquets = BouquetService::getAllSimple();
				$rAddBouquet = $rDelBouquet = [];
				$rAddQuery = '';
				foreach ($rStreamIDs as $rStreamID) {
					if (isset($rData['c_category_id'])) {
						$rCategories = self::computeCategoryChange($rData['category_id_type'], $rCategoryMap[$rStreamID] ?? [], array_map('intval', $rData['category_id']));
						$rArray['category_id'] = '[' . implode(',', $rCategories) . ']';
					}

					$rPrepare = QueryHelper::prepareArray($rArray);

					if (0 < count($rPrepare['data'])) {
						$rPrepare['data'][] = $rStreamID;
						$rQuery = 'UPDATE `streams` SET ' . $rPrepare['update'] . ' WHERE `id` = ?;';
						$db->query($rQuery, ...$rPrepare['data']);
					}

					if (isset($rData['c_server_tree'])) {
						self::planServerTreeForStream($db, $rData, $rStreamID, $rStreamExists[$rStreamID] ?? [], $rAddQuery, $rDeleteServers);
					}

					if (isset($rData['c_bouquets'])) {
						$rPlan = self::planBouquetChanges($rData['bouquets_type'], $rData['bouquets'], $rBouquets);
						foreach ($rPlan['add'] as $rBouquetID) {
							$rAddBouquet[$rBouquetID][] = $rStreamID;
						}
						foreach ($rPlan['del'] as $rBouquetID) {
							$rDelBouquet[$rBouquetID][] = $rStreamID;
						}
					}
				}
				foreach ($rDeleteServers as $rServerID => $rDeleteIDs) {
					StreamRepository::deleteStreamsByServer($rDeleteIDs, $rServerID, false);
				}
				foreach ($rAddBouquet as $rBouquetID => $rAddIDs) {
					BouquetService::addItems('radio', $rBouquetID, $rAddIDs);
				}
				foreach ($rDelBouquet as $rBouquetID => $rRemIDs) {
					BouquetService::removeItems('radio', $rBouquetID, $rRemIDs);
				}
				if (!empty($rAddQuery)) {
					$rAddQuery = rtrim($rAddQuery, ',');
					$db->query('INSERT INTO `streams_servers`(`stream_id`, `server_id`, `parent_id`, `on_demand`) VALUES ' . $rAddQuery . ';');
				}
				StreamProcess::updateStreams($rStreamIDs);
				EventDispatcher::dispatch(new StreamsChangedEvent(array_values(array_map('intval', $rStreamIDs))));
				if (isset($rData['restart_on_edit'])) {
					ApiClient::request(['action' => 'stream', 'sub' => 'start', 'stream_ids' => array_values($rStreamIDs)]);
				}
			}

			return ['status' => STATUS_SUCCESS];
		}

		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}

	/**
	 * Bulk delete a set of selected radio streams.
	 *
	 * @param array $rData Selected radio ids.
	 * @return array ['status' => STATUS_* constant, ...].
	 */
	public static function massDelete(array $rData) {
		self::raiseTimeLimits();

		if (InputValidator::validate('massDeleteStations', $rData)) {
			$rStreams = json_decode($rData['radios'], true);
			StreamRepository::deleteStreams($rStreams, false);
			return ['status' => STATUS_SUCCESS];
		}

		return ['status' => STATUS_INVALID_INPUT, 'data' => $rData];
	}
}
