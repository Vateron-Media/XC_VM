<?php
/**
 * XC_VM — Контроллер массового редактирования MAG-устройств (admin/mag_mass.php)
 */
class MagMassController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Mass Edit Devices');
        $this->render('mag_mass');
    }
}
