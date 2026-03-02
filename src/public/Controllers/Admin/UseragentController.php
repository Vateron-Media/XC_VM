<?php
/**
 * XC_VM — Контроллер редактирования User-Agent (admin/useragent.php)
 */

class UseragentController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rUAArr = null;
        if (isset(CoreUtilities::$rRequest['id'])) {
            $rUAArr = getUserAgent(CoreUtilities::$rRequest['id']);
            if (!$rUAArr) {
                $this->redirect('useragents');
                return;
            }
        }

        $this->setTitle('Block User-Agent');
        $this->render('useragent', compact('rUAArr'));
    }
}
