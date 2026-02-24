<?php
/**
 * LineController — добавление / редактирование линии (Phase 6.3 — Group C).
 */
class LineController extends BaseAdminController
{
    public function index()
    {
        $this->requirePermission();

        $db = $GLOBALS['db'];

        if (isset(CoreUtilities::$rRequest['id'])) {
            $rLine = getUser(CoreUtilities::$rRequest['id']);

            if (!$rLine || !hasPermissions('adv', 'edit_user')) {
                goHome();
            }

            if ($rLine['is_mag']) {
                $db->query('SELECT `mag_id` FROM `mag_devices` WHERE `user_id` = ?;', $rLine['id']);
                if ($db->num_rows() > 0) {
                    header('Location: mag?id=' . intval($db->get_row()['mag_id']));
                    exit;
                } else {
                    goHome();
                }
            }

            if ($rLine['is_e2']) {
                $db->query('SELECT `device_id` FROM `enigma2_devices` WHERE `user_id` = ?;', $rLine['id']);
                if ($db->num_rows() > 0) {
                    header('Location: enigma?id=' . intval($db->get_row()['device_id']));
                    exit;
                } else {
                    goHome();
                }
            }
        } else {
            if (!hasPermissions('adv', 'add_user')) {
                goHome();
            }
            $rLine = null;
        }

        $rRegisteredUsers = getRegisteredUsers();

        $data = ['rRegisteredUsers' => $rRegisteredUsers];
        if ($rLine) {
            $data['rLine'] = $rLine;
        }
        $this->render('line', $data);
    }
}
