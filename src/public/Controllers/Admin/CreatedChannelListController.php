<?php
/**
 * CreatedChannelListController — список созданных каналов.
 */
class CreatedChannelListController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $this->setTitle('Created Channels');
        $this->render('created_channels');
    }
}
