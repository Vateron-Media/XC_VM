<?php
/**
 * Контроллер редактирования MAG-устройства (admin/mag.php)
 */

class MagController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rDevice = null;
        if (isset(RequestManager::getAll()['id'])) {
            $rDevice = getMag(RequestManager::getAll()['id']);
            if (!$rDevice['user_id']) {
                exit();
            }
        }

        if (isset($rDevice) && !isset($rDevice['user'])) {
            $rDevice['user'] = array('bouquet' => array());
        }

        $this->setTitle('MAG Device');
        $this->render('mag', compact('rDevice'));
    }
}
