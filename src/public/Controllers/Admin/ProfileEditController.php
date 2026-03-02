<?php
/**
 * ProfileEditController — add/edit transcoding profile (Phase 6.3 — Group H).
 *
 * Route: GET /admin/profile → index()
 */
class ProfileEditController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        global $rServers;

        $rProfileArr = null;
        $rProfileOptions = null;

        $id = $this->input('id');
        if ($id !== null) {
            $rProfileArr = function_exists('getTranscodeProfile') ? getTranscodeProfile($id) : null;
            if (!$rProfileArr) {
                if (function_exists('goHome')) {
                    goHome();
                }
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
