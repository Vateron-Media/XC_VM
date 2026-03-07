<?php
/**
 * ResellerLiveConnectionsController — Live connections.
 */
class ResellerLiveConnectionsController extends BaseResellerController
{
    public function index()
    {
        $this->requirePermission();
        $this->render('live_connections');
    }
}
