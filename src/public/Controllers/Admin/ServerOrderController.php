<?php
/**
 * ServerOrderController — Server Order.
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
