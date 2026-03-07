<?php
/**
 * ResellerEditProfileController — Edit reseller profile.
 */
class ResellerEditProfileController extends BaseResellerController
{
    public function index()
    {
        $this->render('edit_profile');
    }
}
