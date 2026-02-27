<?php
/**
 * PlexAddController — Add/Edit Plex Library (Phase 6.3 — Group L).
 */
class PlexAddController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rFolder = null;
        $id = $this->input('id');
        if (isset($id)) {
            $rFolder = getWatchFolder($id);
            if (!$rFolder) {
                $this->redirect('plex');
                return;
            }
        }

        $rBouquets = BouquetService::getAllSimple();
        if (!is_array($rBouquets)) {
            $rBouquets = [];
        }

        $this->setTitle('Add Library');
        $this->render('plex_add', compact('rFolder', 'rBouquets'));
    }
}
