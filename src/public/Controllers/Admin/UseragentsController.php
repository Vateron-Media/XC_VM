<?php

/**
 * Контроллер списка заблокированных User-Agent (admin/useragents.php)
 *
 * @renders Views/admin/useragents.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class UseragentsController extends BaseAdminController {
    public function index() {
        $this->requirePermission();
        $this->setTitle('Blocked User-Agents');
        $this->render('useragents');
    }
}
