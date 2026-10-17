<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\ProviderService;

/**
 * Admin-ajax controller for the "Providers" group.
 *
 * Actions: provider, provider_streams.
 *
 * `provider_streams` answers with a DataTables envelope (including on the
 * permission-denied path), so it gates inline via {@see Authorization::check()}.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ProviderAjaxController extends BaseAjaxController {
	/** action=provider — delete or reload a provider. */
	public function provider(): never {
		$this->requireXhr();
		$this->gate('adv', 'streams');

		$rSub = RequestManager::get('sub');

		if ($rSub == 'delete') {
			ProviderService::deleteById(RequestManager::get('id'));
			$this->ok();
		}

		if ($rSub == 'reload') {
			shell_exec(PHP_BIN . ' ' . MAIN_HOME . 'console.php cron:providers "' . intval(RequestManager::get('id')) . '" > /dev/null 2>/dev/null &');
			$this->ok();
		}

		$this->fail();
	}

	/** action=provider_streams — DataTables listing of a provider's streams. */
	public function providerStreams(): never {
		$this->requireXhr();

		$rDraw = max(0, intval(RequestManager::get('draw') ?? 1));
		$rProviderId = intval(RequestManager::get('provider_id') ?? 0);
		if (!Authorization::check('adv', 'streams') || $rProviderId <= 0) {
			$this->json(['draw' => $rDraw, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => []]);
		}

		global $db;
		$rType = (RequestManager::get('stream_type') ?? 'live') === 'movie' ? 'movie' : 'live';
		$rSearchData = RequestManager::get('search');
		$rSearch = is_array($rSearchData) && is_scalar($rSearchData['value'] ?? null) ? trim((string) $rSearchData['value']) : '';
		$rStart = max(0, intval(RequestManager::get('start') ?? 0));
		$rLength = max(1, min(intval(RequestManager::get('length') ?? 25), 500));

		$db->query('SELECT COUNT(*) as cnt FROM `providers_streams` WHERE `provider_id` = ? AND `type` = ?;', $rProviderId, $rType);
		$rTotal = intval($db->get_row()['cnt'] ?? 0);
		$rWhere = '`provider_id` = ? AND `type` = ?';
		$rParams = [$rProviderId, $rType];
		$rFiltered = $rTotal;
		if ($rSearch !== '') {
			$rWhere .= ' AND (`stream_display_name` LIKE ? OR `channel_id` LIKE ? OR `stream_id` LIKE ?)';
			$rLike = '%' . $rSearch . '%';
			array_push($rParams, $rLike, $rLike, $rLike);
			$db->query('SELECT COUNT(*) as cnt FROM `providers_streams` WHERE ' . $rWhere . ';', ...$rParams);
			$rFiltered = intval($db->get_row()['cnt'] ?? 0);
		}
		$db->query('SELECT `stream_id`, `category_array`, `stream_display_name`, `modified`, `stream_icon`, `channel_id` FROM `providers_streams` WHERE ' . $rWhere . ' ORDER BY `stream_display_name` ASC, `stream_id` ASC LIMIT ' . $rStart . ', ' . $rLength . ';', ...$rParams);

		$this->json([
			'draw'            => $rDraw,
			'recordsTotal'    => $rTotal,
			'recordsFiltered' => $rFiltered,
			'data'            => $db->get_raw_rows(),
		]);
	}
}
