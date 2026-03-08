<?php
/**
 * ResellerMagsController — MAG devices listing.
 */
class ResellerMagsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('MAG Devices');
        $this->render('mags');
    }
}
