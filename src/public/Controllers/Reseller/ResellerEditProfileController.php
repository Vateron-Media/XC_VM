<?php
/**
 * ResellerEditProfileController — Edit reseller profile.
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerEditProfileController extends BaseResellerController
{
    public function index()
    {
        $this->setTitle('Edit Profile');

        $data = [
            'timezones' => TimeZoneList(),
        ];

        // Find API code for this user's group
        foreach (getcodes() as $rCode) {
            if ($rCode['type'] == 4 && in_array($GLOBALS['rUserInfo']['member_group_id'], json_decode($rCode['groups'], true) ?: [])) {
                $data['apiCode'] = $rCode;
                $servers = ServerRepository::getAll();
                $userInfo = $GLOBALS['rUserInfo'];
                if (empty($userInfo['reseller_dns'])) {
                    $data['apiUrl'] = $servers[SERVER_ID]['http_url'] . $rCode['code'] . '/';
                } else {
                    $data['apiUrl'] = 'http://' . $userInfo['reseller_dns'] . ':' . intval($servers[SERVER_ID]['http_broadcast_port']) . '/' . $rCode['code'] . '/';
                }
                break;
            }
        }

        $this->render('edit_profile', $data);
    }
}
