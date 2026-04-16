<?php

/**
 * CodeEditController — add/edit access code.
 *
 * Route: GET /admin/code → index()
 *
 * @renders Views/admin/code.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class CodeEditController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $rCode = null;
        $id = $this->input('id');
        if ($id !== null) {
            $rCode = AuthRepository::getCodeById($id);
            if (!$rCode) {
                exit();
            }
        }

        $this->setTitle('Access Code');
        $this->render('code', compact('rCode'));
    }
}
