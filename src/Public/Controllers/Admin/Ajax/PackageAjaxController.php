<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Auth\AuthRepository;
use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Bouquet\BouquetService;
use XcVm\Domain\Line\PackageService;
use XcVm\Domain\Stream\CategoryService;
use XcVm\Domain\User\GroupService;
use XcVm\Domain\User\UserRepository;

/**
 * Admin-ajax controller for the "Packages, Bouquets & Groups" group.
 *
 * Extracted from the legacy `admin/api.php`. Actions: package, code, hmac,
 * group, bouquet, category, get_package, get_package_trial. Block logic ported
 * faithfully (scaffolding via gate/ok/fail from {@see BaseAjaxController};
 * empty-then / nested if-else cascades flattened — behaviour-preserving;
 * comments English).
 *
 * `get_package` and `get_package_trial` have no per-action permission gate in
 * api.php (only the shared admin-session + XHR guard); that is preserved. Their
 * identical bouquet-collection loop is shared via {@see self::collectPackageBouquets()}.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class PackageAjaxController extends BaseAjaxController {

    /** action=package — delete a package or toggle one of its boolean flags. */
    public function package(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_package');

        global $db;
        $rSub = RequestManager::get('sub');

        if ($rSub == 'delete') {
            PackageService::deleteById(RequestManager::get('package_id'));
            $this->ok();
        }

        if (in_array($rSub, array('is_trial', 'is_official', 'can_gen_mag', 'can_gen_e2', 'only_mag', 'only_e2'))) {
            $db->query('UPDATE `users_packages` SET ? = ? WHERE `id` = ?;', $rSub, RequestManager::get('value'), RequestManager::get('package_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=code — delete an activation code. */
    public function code(): never {
        $this->requireXhr();
        $this->gate('adv', 'add_code');

        if (RequestManager::get('sub') == 'delete') {
            AuthRepository::deleteCode(RequestManager::get('code_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=hmac — delete an HMAC key. */
    public function hmac(): never {
        $this->requireXhr();
        $this->gate('adv', 'add_hmac');

        if (RequestManager::get('sub') == 'delete') {
            AuthRepository::deleteHMAC(RequestManager::get('hmac_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=group — delete a member group or toggle its admin/reseller flag. */
    public function group(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_group');

        global $db;
        $rSub = RequestManager::get('sub');

        if ($rSub == 'delete') {
            GroupService::deleteById(RequestManager::get('group_id'));
            $this->ok();
        }

        if (in_array($rSub, array('is_admin', 'is_reseller'))) {
            $db->query('UPDATE `users_groups` SET ? = ? WHERE `group_id` = ?;', $rSub, RequestManager::get('value'), RequestManager::get('group_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=bouquet — delete a bouquet. */
    public function bouquet(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_bouquet');

        if (RequestManager::get('sub') == 'delete') {
            BouquetService::deleteById(RequestManager::get('bouquet_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=category — delete a stream category. */
    public function category(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_cat');

        if (RequestManager::get('sub') == 'delete') {
            CategoryService::deleteById(RequestManager::get('category_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=get_package — package details + resolved bouquets (with reseller overrides). */
    public function getPackage(): never {
        $this->requireXhr();

        global $db, $rUserInfo;
        $rOverride = json_decode($rUserInfo['override_packages'], true);
        $db->query('SELECT `id`, `bouquets`, `official_credits` AS `cost_credits`, `official_duration`, `official_duration_in`, `max_connections`, `can_gen_mag`, `can_gen_e2`, `only_mag`, `only_e2` FROM `users_packages` WHERE `id` = ?;', RequestManager::get('package_id'));

        if ($db->num_rows() == 1) {
            $rData = $db->get_row();

            if (isset($rOverride[$rData['id']]['official_credits']) && 0 < strlen($rOverride[$rData['id']]['official_credits'])) {
                $rData['cost_credits'] = $rOverride[$rData['id']]['official_credits'];
            }

            $rData['exp_date'] = date('Y-m-d', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in']));

            if (RequestManager::has('user_id') && ($rUser = UserRepository::getLineById(RequestManager::get('user_id')))) {
                if (time() < $rUser['exp_date']) {
                    $rData['exp_date'] = date('Y-m-d', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in'], $rUser['exp_date']));
                } else {
                    $rData['exp_date'] = date('Y-m-d', strtotime('+' . intval($rData['official_duration']) . ' ' . $rData['official_duration_in']));
                }
            }

            $this->ok(array('bouquets' => $this->collectPackageBouquets($rData['bouquets']), 'data' => $rData));
        }

        $this->fail();
    }

    /** action=get_package_trial — trial package details + resolved bouquets. */
    public function getPackageTrial(): never {
        $this->requireXhr();

        global $db;
        $db->query('SELECT `bouquets`, `trial_credits` AS `cost_credits`, `trial_duration`, `trial_duration_in`, `max_connections`, `can_gen_mag`, `can_gen_e2`, `only_mag`, `only_e2` FROM `users_packages` WHERE `id` = ?;', RequestManager::get('package_id'));

        if ($db->num_rows() == 1) {
            $rData = $db->get_row();
            $rData['exp_date'] = date('Y-m-d', strtotime('+' . intval($rData['trial_duration']) . ' ' . $rData['trial_duration_in']));

            $this->ok(array('bouquets' => $this->collectPackageBouquets($rData['bouquets']), 'data' => $rData));
        }

        $this->fail();
    }

    /**
     * Resolve a package's `bouquets` JSON id-list into full bouquet rows.
     * Shared by get_package / get_package_trial (the loop was identical).
     *
     * @param mixed $rBouquetsJson JSON-encoded list of bouquet ids
     * @return array<int, array<string, mixed>>
     */
    private function collectPackageBouquets($rBouquetsJson): array {
        global $db;
        $rReturn = array();

        foreach ((json_decode((string) $rBouquetsJson, true) ?: array()) as $rBouquet) {
            $db->query('SELECT * FROM `bouquets` WHERE `id` = ?;', $rBouquet);

            if ($db->num_rows() == 1) {
                $rRow = $db->get_row();
                $rReturn[] = array('id' => $rRow['id'], 'bouquet_name' => str_replace("'", "\\'", $rRow['bouquet_name']), 'bouquet_channels' => json_decode($rRow['bouquet_channels'], true), 'bouquet_radios' => json_decode($rRow['bouquet_radios'], true), 'bouquet_movies' => json_decode($rRow['bouquet_movies'], true), 'bouquet_series' => json_decode($rRow['bouquet_series'], true));
            }
        }

        return $rReturn;
    }
}
