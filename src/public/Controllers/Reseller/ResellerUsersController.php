<?php
/**
 * ResellerUsersController — Sub-resellers listing.
 */
class ResellerUsersController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Users');
        $this->render('users');
    }
}
