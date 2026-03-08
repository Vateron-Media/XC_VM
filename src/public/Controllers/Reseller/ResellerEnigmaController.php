<?php
/**
 * ResellerEnigmaController — Enigma device edit/create.
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
            $rDevice = getEnigma($rRequest['id']);
            if (!($rDevice && $rDevice['user'] && $rDevice['user']['is_e2'] && Authorization::check('line', $rDevice['user']['id']))) {
                goHome();
                return;
            }
            $rLine = $rDevice['user'];
            if ($rLine['package_id'] > 0) {
                $rOrigPackage = getPackage($rLine['package_id']);
            }
        }

        $rPackages = getPackages($rUserInfo['member_group_id'], 'e2') ?: [];

        $this->render('enigma', [
            'rDevice'      => $rDevice,
            'rLine'        => $rLine,
            'rOrigPackage' => $rOrigPackage,
            'rPackages'    => $rPackages,
        ]);
    }
}
