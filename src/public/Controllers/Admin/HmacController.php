<?php

/**
 * HmacController — HMAC Keys (admin/hmacs.php).
 *
 * Листинг HMAC-токенов с edit/delete.
 *
 * Legacy: admin/hmacs.php (273 строки)
 * Route:  GET /admin/hmacs → index()
 */
class HmacController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $this->setTitle('HMAC Keys');

        $hmacs = AuthRepository::getAllHMAC();

        $this->render('hmacs', [
            'hmacs' => $hmacs,
        ]);
    }
}
