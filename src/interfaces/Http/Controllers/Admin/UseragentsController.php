<?php
/**
 * XC_VM — Контроллер списка заблокированных User-Agent (admin/useragents.php)
 */
namespace App\Http\Controllers\Admin;

class UseragentsController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Blocked User-Agents');
        $this->render('useragents');
    }
}
