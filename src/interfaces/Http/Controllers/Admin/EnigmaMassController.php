<?php
/**
 * XC_VM — Контроллер массового редактирования Enigma-устройств (admin/enigma_mass.php)
 */
class EnigmaMassController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Mass Edit Devices');
        $this->render('enigma_mass');
    }
}
