<?php

/**
 * MassDeleteController — Mass Delete.
 *
 * @renders Views/admin/mass_delete.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class MassDeleteController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        set_time_limit(0);
        ini_set('max_execution_time', 0);

        $this->setTitle('Mass Delete');
        $this->render('mass_delete');
    }
}
