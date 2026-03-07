<?php
/**
 * ResellerUserController — Sub-reseller edit/create.
 */
class ResellerUserController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('user');
    }
}
