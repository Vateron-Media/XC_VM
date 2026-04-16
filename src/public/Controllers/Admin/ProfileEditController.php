<?php

/**
 * ProfileEditController — add/edit transcoding profile.
 *
 * Route: GET /admin/profile → index()
 *
 * @renders Views/admin/profile.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ProfileEditController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $rServers;

        $rProfileArr = null;
        $rProfileOptions = null;

        $id = $this->input('id');
        if ($id !== null) {
            $rProfileArr = StreamConfigRepository::getTranscodeProfile($id);
            if (!$rProfileArr) {
                AdminHelpers::goHome();
                return;
            }
        }

        if (isset($rProfileArr)) {
            $rProfileOptions = json_decode($rProfileArr['profile_options'], true);

            if ($rProfileOptions['software_decoding']) {
                if (isset($rProfileOptions[9])) {
                    $rProfileOptions['gpu']['resize'] = str_replace(':', 'x', $rProfileOptions[9]['val']);
                }
                $rProfileOptions['gpu']['deint'] = intval(isset($rProfileOptions[17]));
            } else {
                if (isset($rProfileOptions['gpu']['resize'])) {
                    $rProfileOptions[9]['val'] = str_replace('x', ':', $rProfileOptions['gpu']['resize']);
                }
                $rProfileOptions[17]['val'] = 0 < intval($rProfileOptions['gpu']['deint']);
            }
        }

        $rDevices = array('Off');
        foreach ($rServers as $rServer) {
            $rServer['gpu_info'] = json_decode($rServer['gpu_info'], true);
            if (isset($rServer['gpu_info']['gpus'])) {
                foreach ($rServer['gpu_info']['gpus'] as $rGPUID => $rGPU) {
                    $rDevices[$rServer['id'] . '_' . $rGPUID] = $rServer['server_name'] . ' - ' . $rGPU['name'];
                }
            }
        }

        $this->setTitle('Transcoding Profile');
        $this->render('profile', compact('rProfileArr', 'rProfileOptions', 'rDevices'));
    }
}
