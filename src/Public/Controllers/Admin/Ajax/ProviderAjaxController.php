<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\Authorization;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Stream\ProviderService;

/**
 * Admin-ajax controller for the "Providers" group.
 *
 * Extracted from the legacy `admin/api.php`. Actions: provider, provider_streams.
 * Block logic ported faithfully (scaffolding via gate/ok/fail from
 * {@see BaseAjaxController}; comments English).
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

        if (!Authorization::check('adv', 'providers')) {
            $this->json(array('draw' => 1, 'recordsTotal' => 0, 'recordsFiltered' => 0, 'data' => array()));
        }

        global $db;
        $rProviderId = intval(RequestManager::get('provider_id') ?? 0);
        $rType       = (RequestManager::get('stream_type') ?? 'live') === 'movie' ? 'movie' : 'live';
        $rSearch     = trim(RequestManager::get('search')['value'] ?? '');
        $rStart      = intval(RequestManager::get('start') ?? 0);
        $rLength     = min(intval(RequestManager::get('length') ?? 100), 500);
        $rDraw       = intval(RequestManager::get('draw') ?? 1);

        if ($rSearch !== '') {
            $rLike = '%' . $rSearch . '%';
            $db->query('SELECT COUNT(*) as cnt FROM `providers_streams` WHERE `provider_id` = ? AND `type` = ? AND `stream_display_name` LIKE ?;', $rProviderId, $rType, $rLike);
            $rTotal = intval($db->get_row()['cnt'] ?? 0);
            $db->query('SELECT `stream_id`, `category_array`, `stream_display_name`, `modified`, `stream_icon`, `channel_id` FROM `providers_streams` WHERE `provider_id` = ? AND `type` = ? AND `stream_display_name` LIKE ? ORDER BY `modified` DESC, `stream_id` ASC LIMIT ' . $rStart . ', ' . $rLength . ';', $rProviderId, $rType, $rLike);
        } else {
            $db->query('SELECT COUNT(*) as cnt FROM `providers_streams` WHERE `provider_id` = ? AND `type` = ?;', $rProviderId, $rType);
            $rTotal = intval($db->get_row()['cnt'] ?? 0);
            $db->query('SELECT `stream_id`, `category_array`, `stream_display_name`, `modified`, `stream_icon`, `channel_id` FROM `providers_streams` WHERE `provider_id` = ? AND `type` = ? ORDER BY `modified` DESC, `stream_id` ASC LIMIT ' . $rStart . ', ' . $rLength . ';', $rProviderId, $rType);
        }

        $this->json(array(
            'draw'            => $rDraw,
            'recordsTotal'    => $rTotal,
            'recordsFiltered' => $rTotal,
            'data'            => $db->get_rows(),
        ));
    }
}
