<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Core\Config\DomainResolver;
use XcVm\Core\Config\SettingsManager;
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
		global $rUserInfo;

		// The stations play as HLS, like the live channels: without that output the page is not offered.
		if (!in_array(1, $rUserInfo['allowed_outputs'], true) || SettingsManager::getBool('disable_hls')) {
			header('Location: index');
			exit;
		}

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
			// Only a station in the line's bouquets has a play address.
			if (!in_array($streamId, $rUserInfo['radio_ids'], true)) {
				http_response_code(404);
				exit('Station stream not found');
			}
			// It is the panel's own, as for a live channel: there the line is authorised and its connection counted.
			header('Location: ' . $domainName . $rUserInfo['username'] . '/' . $rUserInfo['password'] . '/' . $streamId . '.m3u8');
			exit;
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
				// No station in the line's bouquets: nothing to list.
				$streamList = [];
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

			$stations = $this->stations($streamList, $baseUrl);

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
			// No station in the line's bouquets: nothing to list.
			$initialStreams = [];
			$totalCount = 0;
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

		$initialStations = $this->stations($initialStreams, $baseUrl);

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

	/**
	 * The stations of a list as the page plays them: each from this controller's
	 * play answer, which names the panel's own play address and never the source.
	 */
	private function stations(array $streamList, string $baseUrl): array {
		global $db;

		$stations = [];
		foreach ($streamList as $stream) {
			if (!is_array($stream) || empty($stream['id'])) {
				continue;
			}
			$streamId = (int) $stream['id'];
			$stations[$streamId] = [
				'id'          => $streamId,
				'name'        => $stream['stream_display_name'] ?? 'Station #' . $streamId,
				'logo'        => !empty($stream['stream_icon']) ? $stream['stream_icon'] : '',
				'category_id' => $stream['category_id'] ?? 0,
				'direct'      => false,
				'url'         => $baseUrl . 'radio?stream=' . $streamId,
			];
		}

		// The panel serves a station it runs as HLS and passes a direct one on to
		// its source: the page plays that one as plain audio.
		if ($stations !== []) {
			$db->query('SELECT `id` FROM `streams` WHERE `direct_source` = 1 AND `direct_proxy` = 0 AND `id` IN (' . implode(',', array_fill(0, count($stations), '?')) . ');', ...array_keys($stations));
			foreach ($db->get_rows() as $row) {
				$stations[(int) $row['id']]['direct'] = true;
			}
		}

		return array_values($stations);
	}
}
