<?php

/**
 * LineController — добавление / редактирование линии.
 *
 * @renders Views/admin/line.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class LineController extends BaseAdminController {
    public function index() {
        $this->requirePermission();

        $db = $GLOBALS['db'];

        if (isset(RequestManager::getAll()['id'])) {
            $rLine = UserRepository::getLineById(RequestManager::getAll()['id']);

            if (!$rLine || !Authorization::check('adv', 'edit_user')) {
                AdminHelpers::goHome();
            }

            if ($rLine['is_mag']) {
                $db->query('SELECT `mag_id` FROM `mag_devices` WHERE `user_id` = ?;', $rLine['id']);
                if ($db->num_rows() > 0) {
                    header('Location: mag?id=' . intval($db->get_row()['mag_id']));
                    exit;
                } else {
                    AdminHelpers::goHome();
                }
            }

            if ($rLine['is_e2']) {
                $db->query('SELECT `device_id` FROM `enigma2_devices` WHERE `user_id` = ?;', $rLine['id']);
                if ($db->num_rows() > 0) {
                    header('Location: enigma?id=' . intval($db->get_row()['device_id']));
                    exit;
                } else {
                    AdminHelpers::goHome();
                }
            }
        } else {
            if (!Authorization::check('adv', 'add_user')) {
                AdminHelpers::goHome();
            }
            $rLine = null;
        }

        $rRegisteredUsers = UserRepository::getRegisteredUsers();

        $data = ['rRegisteredUsers' => $rRegisteredUsers];
        if ($rLine) {
            $data['rLine'] = $rLine;
        }
        $this->render('line', $data);
    }
}
