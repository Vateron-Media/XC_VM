<?php

/**
 * CreatedChannelListController — список созданных каналов.
 *
 * @renders Views/admin/created_channels.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CreatedChannelListController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $this->setTitle('Created Channels');
        $this->render('created_channels');
    }
}
