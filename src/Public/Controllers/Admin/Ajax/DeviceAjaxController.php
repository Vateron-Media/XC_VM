<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Device\EnigmaService;
use XcVm\Domain\Device\MagService;

/**
 * Admin-ajax controller for the "Devices" group.
 *
 * Extracted from the legacy `admin/api.php`. Actions: mag, enigma, mag_event,
 * send_event. Block logic ported faithfully (scaffolding via gate/ok/fail from
 * {@see BaseAjaxController}; empty-then / nested if-else cascades flattened —
 * behaviour-preserving; comments English).
 *
 * `mag` and `enigma` were near-identical in api.php; their shared line-state
 * sub-actions (enable/disable/ban/unban/kill) live in {@see LineStateTrait}.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class DeviceAjaxController extends BaseAjaxController {

    use LineStateTrait;

    /** action=mag — MAG device operations (dispatches on sub). */
    public function mag(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_mag');

        $rSub = RequestManager::get('sub');
        $rMagDetails = MagService::getById(intval(RequestManager::get('mag_id')));

        if ($rSub == 'delete') {
            MagService::deleteDevice(RequestManager::get('mag_id'));
            $this->ok();
        }

        if ($rSub == 'convert') {
            MagService::deleteDevice(RequestManager::get('mag_id'), false, false, true);
            $this->ok(array('line_id' => $rMagDetails['user']['id']));
        }

        $this->lineStateAction($rSub, $rMagDetails['user_id']);
    }

    /** action=enigma — Enigma2 device operations (dispatches on sub). */
    public function enigma(): never {
        $this->requireXhr();
        $this->gate('adv', 'edit_e2');

        $rSub = RequestManager::get('sub');
        $rE2Details = EnigmaService::getById(intval(RequestManager::get('e2_id')));

        if ($rSub == 'delete') {
            EnigmaService::deleteDevice(RequestManager::get('e2_id'));
            $this->ok();
        }

        if ($rSub == 'convert') {
            EnigmaService::deleteDevice(RequestManager::get('e2_id'), false, false, true);
            $this->ok(array('line_id' => $rE2Details['user']['id']));
        }

        $this->lineStateAction($rSub, $rE2Details['user_id']);
    }

    /** action=mag_event — delete a scheduled MAG event. */
    public function magEvent(): never {
        $this->requireXhr();
        $this->gate('adv', 'manage_events');

        global $db;

        if (RequestManager::get('sub') == 'delete') {
            $db->query('DELETE FROM `mag_events` WHERE `id` = ?;', RequestManager::get('mag_id'));
            $this->ok();
        }

        $this->fail();
    }

    /** action=send_event — queue MAG events (message / play channel / reset lock) for devices. */
    public function sendEvent(): never {
        $this->requireXhr();
        $this->gate('adv', 'manage_events');

        global $db;
        $rData = json_decode(RequestManager::get('data'), true);

        if (!is_numeric($rData['id'])) {
            $rIDs = json_decode($rData['id'], true);
        } else {
            $rIDs = array(intval($rData['id']));
        }

        foreach ($rIDs as $rID) {
            if ($rData['type'] == 'send_msg') {
                $rData['need_confirm'] = 1;
            } elseif ($rData['type'] == 'play_channel') {
                $rData['need_confirm'] = 0;
                $rData['reboot_portal'] = 0;
                $rData['message'] = intval($rData['channel']);
            } elseif ($rData['type'] == 'reset_stb_lock') {
                MagService::resetSTB($rData['id']);
            } else {
                $rData['need_confirm'] = 0;
                $rData['reboot_portal'] = 0;
                $rData['message'] = '';
            }

            $db->query('INSERT INTO `mag_events`(`status`, `mag_device_id`, `event`, `need_confirm`, `msg`, `reboot_after_ok`, `send_time`) VALUES (0, ?, ?, ?, ?, ?, ?);', $rID, $rData['type'], $rData['need_confirm'], $rData['message'], $rData['reboot_portal'], time());
        }

        $this->ok();
    }
}
