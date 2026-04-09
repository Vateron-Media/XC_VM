<?php
/**
 * ResellerEnigmaController — Enigma device edit/create.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerEnigmaController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Enigma Device');

        $rRequest = RequestManager::getAll();
        $rUserInfo = $GLOBALS['rUserInfo'];
        $rDevice = null;
        $rLine = null;
        $rOrigPackage = null;

        if (isset($rRequest['id'])) {
            $rDevice = EnigmaService::getById($rRequest['id']);
            if (!($rDevice && $rDevice['user'] && $rDevice['user']['is_e2'] && Authorization::check('line', $rDevice['user']['id']))) {
                AdminHelpers::goHome();
                return;
            }
            $rLine = $rDevice['user'];
            if ($rLine['package_id'] > 0) {
                $rOrigPackage = PackageService::getById($rLine['package_id']);
            }
        }

        $rPackages = PackageService::getAll($rUserInfo['member_group_id'], 'e2') ?: [];

        $this->render('enigma', [
            'rDevice'      => $rDevice,
            'rLine'        => $rLine,
            'rOrigPackage' => $rOrigPackage,
            'rPackages'    => $rPackages,
        ]);
    }
}
