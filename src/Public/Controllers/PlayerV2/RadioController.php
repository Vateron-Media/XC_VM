<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Http\RequestManager;

/**
 * RadioController — Live Radio Stations Explorer & Studio Player Controller for Web Player V2.
 *
 * Provides station catalog browsing, live category filtering, instant search,
 * and high-fidelity live audio streaming.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class RadioController extends BasePlayerV2Controller {
	public function index() {
		global $db, $rUserInfo;

		// Ensure safe array
		if (!isset($rUserInfo['radio_ids']) || !is_array($rUserInfo['radio_ids'])) {
			$rUserInfo['radio_ids'] = [];
		}

		$code = $_SERVER['XC_CODE'] ?? '';
		$baseUrl = $code ? '/' . $code . '/' : '/';

		$domainName = DomainResolver::resolve(
			SERVER_ID,
			(!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
		);

		// ─── Stream Redirect Endpoint ───────────────────────────────────────
		if (RequestManager::has('stream')) {
			$streamId = (int) RequestManager::get('stream');
			$db->query('SELECT stream_source, target_container FROM `streams` WHERE `id` = ? AND `type` = 4 LIMIT 1;', $streamId);
			$row = $db->get_row();
			if ($row && !empty($row['stream_source'])) {
				$srcs = is_array($row['stream_source']) ? $row['stream_source'] : json_decode($row['stream_source'], true);
				if (!empty($srcs[0])) {
					header('Location: ' . $srcs[0]);
					exit;
				}
			}
			http_response_code(404);
			exit('Station stream not found');
		}

		// ─── Mode A: AJAX Station Retrieval Endpoint ────────────────────────
		if (RequestManager::get('ajax') === '1' || RequestManager::get('action') === 'stations') {
			header('Content-Type: application/json; charset=utf-8');

			$catId = RequestManager::has('category_id') && RequestManager::get('category_id') !== 'all' && RequestManager::get('category_id') !== ''
				? (int) RequestManager::get('category_id')
				: null;

			$sortBy = RequestManager::get('sort') ?: 'number';
			$searchBy = RequestManager::get('search') ?: null;

			if (empty($rUserInfo['radio_ids'])) {
				$where = ['`type` = 4'];
				$whereV = [];
				if (!empty($catId)) {
					$where[] = "JSON_CONTAINS(`category_id`, ?, '$')";
					$whereV[] = (string) $catId;
				}
				if (!empty($searchBy)) {
					$where[] = '`stream_display_name` LIKE ?';
					$whereV[] = '%' . $searchBy . '%';
				}
				$whereStr = implode(' AND ', $where);
				$db->query("SELECT * FROM `streams` WHERE {$whereStr} ORDER BY `id` DESC LIMIT 1000", ...$whereV);
				$streamList = $db->get_rows() ?: [];
			} else {
				$rStreams = getUserStreams(
					$rUserInfo,
					['radio_streams'],
					$catId,
					null,
					$sortBy,
					$searchBy,
					[],
					0,
					1000,
					false
				);
				$streamList = isset($rStreams['streams']) ? $rStreams['streams'] : (is_array($rStreams) ? $rStreams : []);
			}

			$stations = [];
			foreach ($streamList as $stream) {
				if (!is_array($stream) || empty($stream['id'])) {
					continue;
				}
				$streamId = (int) $stream['id'];
				$container = !empty($stream['target_container']) ? (string) $stream['target_container'] : '';
				$directUrl = '';
				if (!empty($stream['stream_source'])) {
					$srcList = is_array($stream['stream_source']) ? $stream['stream_source'] : json_decode($stream['stream_source'], true);
					if (!empty($srcList[0])) {
						$directUrl = $srcList[0];
					}
				}
				$stations[] = [
					'id'            => $streamId,
					'name'          => $stream['stream_display_name'] ?? 'Station #' . $streamId,
					'logo'          => !empty($stream['stream_icon']) ? $stream['stream_icon'] : '',
					'category_id'   => $stream['category_id'] ?? 0,
					'container'     => $container,
					'direct_source' => $directUrl,
					'url'           => !empty($directUrl) ? $directUrl : ($baseUrl . 'radio?stream=' . $streamId),
				];
			}

			echo json_encode([
				'status'   => 'success',
				'count'    => count($stations),
				'stations' => $stations,
			]);
			exit;
		}

		// ─── Mode B: Standard Radio Stations Page Load ──────────────────────
		$rCategories = PlayerCategoryHelper::getCategories($rUserInfo, 'radio');
		$firstCatId = !empty($rCategories[0]['id']) ? (int) $rCategories[0]['id'] : null;

		if (empty($rUserInfo['radio_ids'])) {
			$where = ['`type` = 4'];
			$whereV = [];
			if (!empty($firstCatId)) {
				$where[] = "JSON_CONTAINS(`category_id`, ?, '$')";
				$whereV[] = (string) $firstCatId;
			}
			$whereStr = implode(' AND ', $where);
			$db->query("SELECT * FROM `streams` WHERE {$whereStr} ORDER BY `id` DESC LIMIT 100", ...$whereV);
			$initialStreams = $db->get_rows() ?: [];
			$db->query("SELECT count(*) as c FROM `streams` WHERE `type` = 4;");
			$totalCount = (int) ($db->get_row()['c'] ?? 0);
		} else {
			$rStreams = getUserStreams(
				$rUserInfo,
				['radio_streams'],
				$firstCatId,
				null,
				'number',
				null,
				[],
				0,
				100,
				false
			);
			$initialStreams = isset($rStreams['streams']) ? $rStreams['streams'] : (is_array($rStreams) ? $rStreams : []);
			$totalCount = count($rUserInfo['radio_ids']);
		}

		$initialStations = [];
		foreach ($initialStreams as $stream) {
			if (!is_array($stream) || empty($stream['id'])) {
				continue;
			}
			$streamId = (int) $stream['id'];
			$container = !empty($stream['target_container']) ? (string) $stream['target_container'] : '';
			$directUrl = '';
			if (!empty($stream['stream_source'])) {
				$srcList = is_array($stream['stream_source']) ? $stream['stream_source'] : json_decode($stream['stream_source'], true);
				if (!empty($srcList[0])) {
					$directUrl = $srcList[0];
				}
			}
			$initialStations[] = [
				'id'            => $streamId,
				'name'          => $stream['stream_display_name'] ?? 'Station #' . $streamId,
				'logo'          => !empty($stream['stream_icon']) ? $stream['stream_icon'] : '',
				'category_id'   => $stream['category_id'] ?? 0,
				'container'     => $container,
				'direct_source' => $directUrl,
				'url'           => !empty($directUrl) ? $directUrl : ($baseUrl . 'radio?stream=' . $streamId),
			];
		}

		$GLOBALS['_TITLE'] = 'Radio Stations';
		$GLOBALS['_PAGE']  = 'radio';

		$this->render('radio', [
			'rCategories'        => $rCategories,
			'initialStations'    => $initialStations,
			'selectedCategoryId' => $firstCatId,
			'totalRadioCount'    => $totalCount,
			'baseUrl'            => $baseUrl,
		]);
	}
}
