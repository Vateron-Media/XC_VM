<?php
/**
 * IspEditController — add/edit blocked ISP (Phase 6.3 — Group H).
 *
 * Route: GET /admin/isp → index()
 */
class IspEditController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $rISPArr = null;
        $id = $this->input('id');
        if ($id !== null) {
            $rISPArr = function_exists('getISP') ? getISP($id) : null;
        }

        $this->setTitle('Blocked ISP');
        $this->render('isp', compact('rISPArr'));
    }
}
