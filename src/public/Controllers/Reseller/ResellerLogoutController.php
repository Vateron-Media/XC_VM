<?php
/**
 * ResellerLogoutController — Destroys reseller session and redirects to login.
 */
class ResellerLogoutController extends BaseResellerController
{
    public function index()
    {
        destroySession('reseller');
        $this->redirect('./login');
    }
}
