<?php
/**
 * Контроллер редактирования EPG (admin/epg.php)
 */

class EpgController extends BaseAdminController {
    public function index() {
        $rEPGArr = null;
        if (isset(RequestManager::getAll()['id'])) {
            $rEPGArr = EpgService::getById(RequestManager::getAll()['id']);
            if (!$rEPGArr) {
                exit();
            }
        }

        $this->setTitle('EPG');
        $this->render('epg', compact('rEPGArr'));
    }
}
