<?php
/**
 * XC_VM — Контроллер редактирования EPG (admin/epg.php)
 */
namespace App\Http\Controllers\Admin;

class EpgController extends BaseAdminController {
    public function index() {
        $rEPGArr = null;
        if (isset(\CoreUtilities::$rRequest['id'])) {
            $rEPGArr = getEPG(\CoreUtilities::$rRequest['id']);
            if (!$rEPGArr) {
                exit();
            }
        }

        $this->setTitle('EPG');
        $this->render('epg', compact('rEPGArr'));
    }
}
