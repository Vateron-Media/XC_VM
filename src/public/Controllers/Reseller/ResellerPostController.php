<?php
/**
 * ResellerPostController — POST form handler for reseller panel.
 *
 * Migrated from reseller/post.php.
 * Handles: edit_profile, line, mag, enigma, ticket, user.
 *
 * Called via: POST post?action=line (or via submitForm/callbackForm JS).
 *
 * @see Views/layouts/reseller/footer.php (JS functions: submitForm/callbackForm)
 *
 * @package XC_VM_Public_Controllers_Reseller
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class ResellerPostController extends BaseResellerController
{
    public function index()
    {
        session_start();
        session_write_close();

        $rAction = RequestManager::getAll()['action'] ?? '';
        $rData = RequestManager::getAll();
        unset($rData['action']);

        if (count($rData) === 0) {
            $rData = json_decode(file_get_contents('php://input'), true);
        }

        if (!$rData) {
            echo json_encode(['result' => false]);
            exit();
        }

        $rUserInfo = $GLOBALS['rUserInfo'];
        $language = $GLOBALS['language'];

        switch ($rAction) {
            case 'edit_profile':
                $rReturn = ResellerAPI::editResellerProfile($rData);
                setcookie('hue', $rData['hue'], time() + 315360000);
                setcookie('theme', $rData['theme'], time() + 315360000);
                $language::setLanguage($rData['lang']);

                if ($rReturn['status'] == STATUS_SUCCESS) {
                    echo json_encode(['result' => true, 'location' => 'edit_profile?status=' . intval($rReturn['status']), 'status' => $rReturn['status'], 'reload' => true]);
                    exit();
                }
                echo json_encode(['result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']]);
                exit();

            case 'line':
                $rReturn = ResellerAPI::processLine($rData);
                if ($rReturn['status'] == STATUS_SUCCESS) {
                    echo json_encode(['result' => true, 'location' => 'lines?status=' . intval($rReturn['status']), 'status' => $rReturn['status']]);
                    exit();
                }
                echo json_encode(['result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']]);
                exit();

            case 'mag':
                $rReturn = ResellerAPI::processMAG($rData);
                if ($rReturn['status'] == STATUS_SUCCESS) {
                    echo json_encode(['result' => true, 'location' => 'mags?status=' . intval($rReturn['status']), 'status' => $rReturn['status']]);
                    exit();
                }
                echo json_encode(['result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']]);
                exit();

            case 'enigma':
                $rReturn = ResellerAPI::processEnigma($rData);
                if ($rReturn['status'] == STATUS_SUCCESS) {
                    echo json_encode(['result' => true, 'location' => 'enigmas?status=' . intval($rReturn['status']), 'status' => $rReturn['status']]);
                    exit();
                }
                echo json_encode(['result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']]);
                exit();

            case 'ticket':
                $rReturn = ResellerAPI::submitTicket($rData);
                if ($rReturn['status'] == STATUS_SUCCESS) {
                    echo json_encode(['result' => true, 'location' => 'ticket_view?id=' . intval($rReturn['data']['insert_id']) . '&status=' . intval($rReturn['status']), 'status' => $rReturn['status']]);
                    exit();
                }
                echo json_encode(['result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']]);
                exit();

            case 'user':
                $rReturn = ResellerAPI::processUser($rData);
                if ($rReturn['status'] == STATUS_SUCCESS) {
                    echo json_encode(['result' => true, 'location' => 'users?status=' . intval($rReturn['status']), 'status' => $rReturn['status']]);
                    exit();
                }
                echo json_encode(['result' => false, 'data' => $rReturn['data'], 'status' => $rReturn['status']]);
                exit();

            default:
                echo json_encode(['result' => false, 'error' => 'Unknown action']);
                exit();
        }
    }
}
