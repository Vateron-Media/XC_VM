<?php
/**
 * ResellerLineController — Line edit/create.
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
                goHome();
                return;
            }
            if ($rLine['package_id'] > 0) {
                $rOrigPackage = getPackage($rLine['package_id']);
            }
        }

        $rPackages = getPackages($rUserInfo['member_group_id'], 'line') ?: [];

        $this->render('line', [
            'rLine'        => $rLine,
            'rOrigPackage' => $rOrigPackage,
            'rPackages'    => $rPackages,
        ]);
    }
}
