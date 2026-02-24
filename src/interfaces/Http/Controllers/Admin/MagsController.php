<?php
/**
 * XC_VM — Контроллер списка MAG-устройств (admin/mags.php)
 */
namespace App\Http\Controllers\Admin;

class MagsController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('MAG Devices');
        $this->render('mags');
    }
}
