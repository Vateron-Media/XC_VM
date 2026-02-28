<?php

/**
 * CodeController — Access Codes (admin/codes.php).
 *
 * Листинг access codes с edit/delete.
 *
 * Legacy: admin/codes.php (273 строки)
 * Route:  GET /admin/codes → index()
 */
class CodeController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $this->setTitle('Access Codes');

        $codes = function_exists('getcodes') ? getcodes() : [];

        $this->render('codes', [
            'codes' => $codes,
        ]);
    }
}
