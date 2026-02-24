<?php
/**
 * HmacEditController — add/edit HMAC key (Phase 6.3 — Group H).
 *
 * Route: GET /admin/hmac → index()
 */
class HmacEditController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rHMAC = null;
        $id = $this->input('id');
        if ($id !== null) {
            $rHMAC = function_exists('getHMACToken') ? getHMACToken($id) : null;
            if (!$rHMAC) {
                exit();
            }
        }

        $this->setTitle('HMAC Key');
        $this->render('hmac', compact('rHMAC'));
    }
}
