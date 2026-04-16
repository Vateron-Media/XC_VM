<?php

/**
 * ServerOrderController — Server Order.
 *
 * @renders Views/admin/server_order.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ServerOrderController extends BaseAdminController {
    public function index() {
        global $rServers;

        $this->requirePermission();

        $rOrderedServers = $rServers;
        array_multisort(array_column($rOrderedServers, 'order'), SORT_ASC, $rOrderedServers);

        $this->setTitle('Server Order');
        $this->render('server_order', compact('rOrderedServers'));
    }
}
