<?php
/**
 * WatchAddController — Add/Edit Watch Folder (Phase 6.3 — Group L).
 */
class WatchAddController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rFolder = null;
        $id = $this->input('id');
        if (isset($id)) {
            $rFolder = getWatchFolder($id);
            if (!$rFolder) {
                $this->redirect('watch');
                return;
            }
        }

        $rBouquets = BouquetService::getAllSimple();
        if (!is_array($rBouquets)) {
            $rBouquets = [];
        }

        $this->setTitle('Add Folder');
        $this->render('watch_add', compact('rFolder', 'rBouquets'));
    }
}
