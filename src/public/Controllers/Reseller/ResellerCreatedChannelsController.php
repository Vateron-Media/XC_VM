<?php
/**
 * ResellerCreatedChannelsController — Created channels listing (read-only).
 */
class ResellerCreatedChannelsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('created_channels');
    }
}
