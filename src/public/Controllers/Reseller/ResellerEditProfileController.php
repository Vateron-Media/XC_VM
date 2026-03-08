<?php
/**
 * ResellerEditProfileController — Edit reseller profile.
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
