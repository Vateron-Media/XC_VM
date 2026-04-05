<?php
/**
 * IspEditController — add/edit blocked ISP.
 *
 * Route: GET /admin/isp → index()
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
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
