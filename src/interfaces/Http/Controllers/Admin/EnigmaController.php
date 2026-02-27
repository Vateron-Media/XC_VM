<?php
/**
 * XC_VM — Контроллер редактирования Enigma-устройства (admin/enigma.php)
 */

class EnigmaController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rDevice = null;
        if (isset(CoreUtilities::$rRequest['id'])) {
            $rDevice = getEnigma(CoreUtilities::$rRequest['id']);
            if (!$rDevice['user_id']) {
                exit();
            }
        }

        if (isset($rDevice) && !isset($rDevice['user'])) {
            $rDevice['user'] = array('bouquet' => array());
        }

        $this->setTitle('Enigma Device');
        $this->render('enigma', compact('rDevice'));
    }
}
