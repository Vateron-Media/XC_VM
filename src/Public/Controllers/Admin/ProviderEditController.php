<?php

/**
 * ProviderEditController — add/edit stream provider.
 *
 * Route: GET /admin/provider → index()
 *
 * @renders Views/admin/provider.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ProviderEditController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rProvider = null;
        $id = $this->input('id');
        if ($id !== null) {
            $rProvider = ProviderService::getById($id);
            if (!$rProvider) {
                exit();
            }
        }

        $this->setTitle('Stream Provider');
        $this->render('provider', compact('rProvider'));
    }
}
