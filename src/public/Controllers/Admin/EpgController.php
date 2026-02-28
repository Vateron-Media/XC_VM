<?php
/**
 * XC_VM — Контроллер редактирования EPG (admin/epg.php)
 */

class EpgController extends BaseAdminController {
    public function index() {
        $rEPGArr = null;
        if (isset(CoreUtilities::$rRequest['id'])) {
            $rEPGArr = EpgService::getById(CoreUtilities::$rRequest['id']);
            if (!$rEPGArr) {
                exit();
            }
        }

        $this->setTitle('EPG');
        $this->render('epg', compact('rEPGArr'));
    }
}
