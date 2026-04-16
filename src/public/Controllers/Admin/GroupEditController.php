<?php

/**
 * GroupEditController — add/edit member group.
 *
 * Route: GET /admin/group → index()
 *
 * @renders Views/admin/group.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class GroupEditController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        global $db;

        $rGroup = null;
        $rGroupIDs = [];
        $rPackageIDs = [];
        $rNotice = '';

        $id = $this->input('id');
        if ($id !== null) {
            $rGroup = GroupService::getById($id);
            if (!$rGroup) {
                AdminHelpers::goHome();
                return;
            }
        }

        if (isset($rGroup)) {
            $db->query("SELECT `id` FROM `users_packages` WHERE JSON_CONTAINS(`groups`, ?, '\$');", $rGroup['group_id']);
            foreach ($db->get_rows() as $rRow) {
                $rPackageIDs[] = $rRow['id'];
            }
            $rGroupIDs = json_decode($rGroup['subresellers'], true);

            // XSS sanitization of notice_html
            $rNotice = html_entity_decode($rGroup['notice_html']);
            $rNotice = preg_replace('#</*(?:applet|b(?:ase|gsound|link)|embed|frame(?:set)?|i(?:frame|layer)|l(?:ayer|ink)|meta|object|s(?:cript|tyle)|title|xml)[^>]*+>#i', '', $rNotice);
            $rNotice = preg_replace('#</*\\w+:\\w[^>]*+>#i', '', $rNotice);
            $rNotice = str_replace(array('&amp;', '&lt;', '&gt;'), array('&amp;amp;', '&amp;lt;', '&amp;gt;'), $rNotice);
            $rNotice = preg_replace('/(&#*\\w+)[\\x00-\\x20]+;/u', '$1;', $rNotice);
            $rNotice = preg_replace('/(&#x*[0-9A-F]+);*/iu', '$1;', $rNotice);
            $rNotice = html_entity_decode($rNotice, ENT_COMPAT, 'UTF-8');
            $rNotice = preg_replace("#(<[^>]+?[\\x00-\\x20\"'])(?:on|xmlns)[^>]*+[>\\b]?#iu", '$1>', $rNotice);
            $rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=[\\x00-\\x20]*([`'\"]*)[\\x00-\\x20]*j[\\x00-\\x20]*a[\\x00-\\x20]*v[\\x00-\\x20]*a[\\x00-\\x20]*s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:#iu", '$1=$2nojavascript...', $rNotice);
            $rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=(['\"]*)[\\x00-\\x20]*v[\\x00-\\x20]*b[\\x00-\\x20]*s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:#iu", '$1=$2novbscript...', $rNotice);
            $rNotice = preg_replace("#([a-z]*)[\\x00-\\x20]*=(['\"]*)[\\x00-\\x20]*-moz-binding[\\x00-\\x20]*:#u", '$1=$2nomozbinding...', $rNotice);
            $rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?expression[\\x00-\\x20]*\\([^>]*+>#i", '$1>', $rNotice);
            $rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?behaviour[\\x00-\\x20]*\\([^>]*+>#i", '$1>', $rNotice);
            $rNotice = preg_replace("#(<[^>]+?)style[\\x00-\\x20]*=[\\x00-\\x20]*[`'\"]*.*?s[\\x00-\\x20]*c[\\x00-\\x20]*r[\\x00-\\x20]*i[\\x00-\\x20]*p[\\x00-\\x20]*t[\\x00-\\x20]*:*[^>]*+>#iu", '$1>', $rNotice);
        }

        $this->setTitle('Group');
        $this->render('group', compact('rGroup', 'rGroupIDs', 'rPackageIDs', 'rNotice'));
    }
}
