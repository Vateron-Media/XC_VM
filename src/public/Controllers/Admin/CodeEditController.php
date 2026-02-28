<?php
/**
 * CodeEditController — add/edit access code (Phase 6.3 — Group H).
 *
 * Route: GET /admin/code → index()
 */
class CodeEditController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rCode = null;
        $id = $this->input('id');
        if ($id !== null) {
            $rCode = function_exists('getCode') ? getCode($id) : null;
            if (!$rCode) {
                exit();
            }
        }

        $this->setTitle('Access Code');
        $this->render('code', compact('rCode'));
    }
}
