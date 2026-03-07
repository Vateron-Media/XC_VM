<?php
/**
 * ResellerMagController — MAG device edit/create.
 */
class ResellerMagController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('mag');
    }
}
