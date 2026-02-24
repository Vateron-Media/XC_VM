<?php
/**
 * XC_VM — Контроллер списка Enigma-устройств (admin/enigmas.php)
 */
namespace App\Http\Controllers\Admin;

class EnigmasController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Enigma Devices');
        $this->render('enigmas');
    }
}
