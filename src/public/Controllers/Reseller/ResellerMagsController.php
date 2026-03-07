<?php
/**
 * ResellerMagsController — MAG devices listing.
 */
class ResellerMagsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('mags');
    }
}
