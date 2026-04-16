<?php

/**
 * HmacEditController — add/edit HMAC key.
 *
 * Route: GET /admin/hmac → index()
 *
 * @renders Views/admin/hmac.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class HmacEditController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rHMAC = null;
        $id = $this->input('id');
        if ($id !== null) {
            $rHMAC = AuthRepository::getHMACById($id);
            if (!$rHMAC) {
                exit();
            }
        }

        $this->setTitle('HMAC Key');
        $this->render('hmac', compact('rHMAC'));
    }
}
