<?php
/**
 * CreatedChannelListController — список созданных каналов (Phase 6.3 — Group A).
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
