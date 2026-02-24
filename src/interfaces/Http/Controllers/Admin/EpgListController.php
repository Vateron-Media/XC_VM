<?php

/**
 * EpgListController — EPG Files (admin/epgs.php).
 *
 * Листинг EPG-файлов с клиентским DataTable.
 * API: delete, reload, force_epg.
 *
 * Legacy: admin/epgs.php (233 строк)
 * Route:  GET /admin/epgs → index()
 */
class EpgListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('EPG Files');

        $epgs = function_exists('getEPGs') ? getEPGs() : [];

        $this->render('epgs', [
            'epgs' => $epgs,
        ]);
    }
}
