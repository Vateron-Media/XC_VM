<?php
/**
 * ResellerUserController — Sub-reseller edit/create.
 */
class ResellerUserController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();

        $rRequest     = $GLOBALS['rRequest'] ?? [];
        $rUserInfo    = $GLOBALS['rUserInfo'];
        $rPermissions = &$GLOBALS['rPermissions'];
        $rUser        = null;

        if (isset($rRequest['id'])) {
            $rUser = UserRepository::getRegisteredUserById($rRequest['id']);

            if (!$rUser || $rRequest['id'] == $rUserInfo['id']) {
                goHome();
            }

            // Remove edited user from reports arrays so they don't appear in owner dropdown
            if (($rKey = array_search($rUser['id'], $rPermissions['all_reports'])) !== false) {
                unset($rPermissions['all_reports'][$rKey]);
            }
            if (($rKey = array_search($rUser['id'], $rPermissions['direct_reports'])) !== false) {
                unset($rPermissions['direct_reports'][$rKey]);
            }
        }

        $rGroups = GroupService::getAll() ?: [];

        $this->setTitle('User');
        $this->render('user', [
            'rUser'   => $rUser,
            'rGroups' => $rGroups,
        ]);
    }
}
