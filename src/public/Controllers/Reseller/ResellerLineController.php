<?php
/**
 * ResellerLineController — Line edit/create.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerLineController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Line');

        $rRequest = RequestManager::getAll();
        $rUserInfo = $GLOBALS['rUserInfo'];
        $rLine = null;
        $rOrigPackage = null;

        if (isset($rRequest['id'])) {
            $rLine = UserRepository::getLineById($rRequest['id']);
            if (!$rLine || $rLine['is_mag'] || $rLine['is_e2'] || !Authorization::check('line', $rLine['id'])) {
                AdminHelpers::goHome();
                return;
            }
            if ($rLine['package_id'] > 0) {
                $rOrigPackage = PackageService::getById($rLine['package_id']);
            }
        }

        $rPackages = PackageService::getAll($rUserInfo['member_group_id'], 'line') ?: [];

        $this->render('line', [
            'rLine'        => $rLine,
            'rOrigPackage' => $rOrigPackage,
            'rPackages'    => $rPackages,
        ]);
    }
}
