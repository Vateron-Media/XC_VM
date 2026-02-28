<?php
/**
 * EditProfileController — Edit Profile (Phase 6.3 — Group N).
 * Note: no checkPermissions — profile is accessible to any logged-in admin.
 */
class EditProfileController extends BaseAdminController
{
    public function index()
    {
        $this->setTitle('Edit Profile');
        $this->render('edit_profile');
    }
}
