<?php
/**
 * AsnsController — ASN's listing.
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
