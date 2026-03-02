<?php
/**
 * AsnsController — ASN's listing (Phase 6.3 — Group N).
 */
class AsnsController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle("ASN's");
        $this->render('asns');
    }
}
