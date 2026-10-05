<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\Stream\StreamService;
use XcVm\Domain\Vod\MovieService;

/**
 * ReviewController — Review imported streams/movies.
 * Very complex data-prep: M3U import processing, category matching, stream/movie API calls.
 * Data-prep is ~160 lines; handled in controller index() method.
 *
 * @renders Views/admin/review.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ReviewController extends BaseAdminController {
	public function index() {
		$this->requirePermission();

		$rType = RequestManager::has('type') ? intval(RequestManager::get('type')) : 1;
		$rCategorySet = [];
		$rLogoSet = [];
		// Before any output: the import redirects to the list when it is done.
		$rData = RequestManager::has('post_data') ? ['_STATUS' => $this->import($rType)] : [];

		// The import server tree on this page is driven by jstree.
		$GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
			(array) ($GLOBALS['xmNewuiVendors'] ?? []),
			['jstree']
		)));

		$this->setTitle('Review');
		$this->render('review', ['rType' => $rType, 'rCategorySet' => $rCategorySet, 'rLogoSet' => $rLogoSet] + $rData);
	}

	/**
	 * Import the rows ticked on the review step.
	 *
	 * This ran in the view, after the layout header was sent: the redirect was
	 * refused and the page stopped at an empty shell with the import done.
	 *
	 * @return int The STATUS_* of an import that did not go through (a successful one redirects).
	 */
	private function import(int $rType): int {
		$rPostData = json_decode(base64_decode(RequestManager::get('post_data')), true);
		$rPostData['review'] = [];
		$rPostData['notes'] = '';
		$rPostData['custom_sid'] = $rPostData['notes'];
		$rCategoryIDs = [];

		foreach (CategoryService::getAllByType([1 => 'live', 2 => 'movie'][$rType]) as $rCategory) {
			$rCategoryIDs[] = $rCategory['id'];
		}
		$rNewCategories = [];

		foreach (RequestManager::get('category_selection') as $rCategory) {
			if (!in_array($rCategory, $rCategoryIDs) && !is_numeric($rCategory)) {
				$rReturn = CategoryService::process(['category_type' => [1 => 'live', 2 => 'movie'][$rType], 'category_name' => $rCategory]);
				$rNewCategories[$rCategory] = $rReturn['data']['insert_id'];
			}
		}

		foreach (RequestManager::getAll() as $rKey => $rValue) {
			if (substr($rKey, 0, 7) != 'import_') {
				continue;
			}
			$rID = intval(explode('import_', $rKey)[1]);
			if (!RequestManager::get('import_' . $rID)) {
				continue;
			}
			$rCategories = [];

			foreach (json_decode(RequestManager::get('category_id_' . $rID), true) as $rCategory) {
				if (!is_numeric($rCategory) && isset($rNewCategories[$rCategory])) {
					$rCategories[] = intval($rNewCategories[$rCategory]);
				} elseif (is_numeric($rCategory)) {
					$rCategories[] = intval($rCategory);
				}
			}

			if ($rType == 1) {
				$rPostData['review'][] = ['stream_source' => [RequestManager::get('url_' . $rID)], 'stream_icon' => RequestManager::get('icon_' . $rID), 'stream_display_name' => RequestManager::get('name_' . $rID), 'epg_lang' => null, 'channel_id' => (!empty(RequestManager::get('channel_id_' . $rID)) ? RequestManager::get('channel_id_' . $rID) : null), 'epg_api' => (!empty(RequestManager::get('epg_type_' . $rID)) ? RequestManager::get('epg_type_' . $rID) : 0), 'epg_id' => (!empty(RequestManager::get('epg_id_' . $rID)) ? RequestManager::get('epg_id_' . $rID) : 0), 'bouquets' => json_decode(RequestManager::get('bouquets_' . $rID), true), 'category_id' => $rCategories];
			} else {
				$rPostData['review'][] = ['stream_source' => [RequestManager::get('url_' . $rID)], 'stream_display_name' => RequestManager::get('name_' . $rID), 'tmdb_id' => (!empty(RequestManager::get('tmdb_id_' . $rID)) ? RequestManager::get('tmdb_id_' . $rID) : null), 'bouquets' => json_decode(RequestManager::get('bouquets_' . $rID), true), 'category_id' => $rCategories];
			}
		}

		$rReturn = $rType == 1 ? StreamService::process($rPostData) : MovieService::process($rPostData);
		if ($rReturn['status'] == STATUS_SUCCESS) {
			$this->redirect('./' . ($rType == 1 ? 'streams' : 'movies') . '?status=' . STATUS_SUCCESS);
		}

		return (int) $rReturn['status'];
	}
}
