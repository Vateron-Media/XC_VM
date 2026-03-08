<?php
/**
 * ResellerCreatedChannelsController — Created channels listing (read-only).
 */
class ResellerCreatedChannelsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->setTitle('Created Channels');
        $this->render('created_channels', [
            'categories' => CategoryService::getAllByType('live'),
        ]);
    }
}
