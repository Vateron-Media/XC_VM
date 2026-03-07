<?php
/**
 * ResellerRadiosController — Radio stations listing (read-only).
 */
class ResellerRadiosController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('radios');
    }
}
