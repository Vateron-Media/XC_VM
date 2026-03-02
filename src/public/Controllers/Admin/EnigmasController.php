<?php
/**
 * XC_VM — Контроллер списка Enigma-устройств (admin/enigmas.php)
 */
class EnigmasController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Enigma Devices');
        $this->render('enigmas');
    }
}
