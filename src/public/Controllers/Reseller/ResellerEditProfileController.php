<?php
/**
 * ResellerEditProfileController — Edit reseller profile (Phase 6.4 — Reseller).
 */
class ResellerEditProfileController extends BaseResellerController
{
    public function index()
    {
        $this->render('edit_profile');
    }
}
