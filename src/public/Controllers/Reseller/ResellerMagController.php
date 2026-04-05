<?php
/**
 * ResellerMagController — MAG device edit/create.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerMagController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();

        $rDevice = null;
        $rLine = null;
        $rOrigPackage = null;

        if (isset($GLOBALS['rRequest']['id'])) {
            $rDevice = getMag($GLOBALS['rRequest']['id']);

            if (!$rDevice || !$rDevice['user'] || !$rDevice['user']['is_mag'] || !Authorization::check('line', $rDevice['user']['id'])) {
                goHome();
            }

            $rLine = $rDevice['user'];

            if ($rLine['package_id'] > 0) {
                $rOrigPackage = getPackage($rLine['package_id']);
            }
        }

        $rPackages = getPackages($GLOBALS['rUserInfo']['member_group_id'], 'mag') ?: [];

        $this->setTitle('MAG Device');
        $this->render('mag', [
            'rDevice'      => $rDevice,
            'rLine'        => $rLine,
            'rOrigPackage' => $rOrigPackage,
            'rPackages'    => $rPackages,
        ]);
    }
}
