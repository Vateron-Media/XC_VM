<?php
/**
 * ServerOrderController — Server Order (Phase 6.3 — Group M).
 */
class ServerOrderController extends BaseAdminController
{
    public function index()
    {
        global $rServers;

        $this->requirePermission();

        $rOrderedServers = $rServers;
        array_multisort(array_column($rOrderedServers, 'order'), SORT_ASC, $rOrderedServers);

        $this->setTitle('Server Order');
        $this->render('server_order', compact('rOrderedServers'));
    }
}
