<?php

namespace XcVm\Public\Controllers\Reseller;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\User\ResellerAPI;

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

class ResellerPostController extends BaseResellerController {
	public function index() {
		session_start();
		session_write_close();

		$rAction = RequestManager::get('action') ?? '';
		$rData = RequestManager::getAll();
		unset($rData['action']);

		if (count($rData) === 0) {
			$rData = json_decode(file_get_contents('php://input'), true);
		}

		if (!$rData) {
			echo json_encode(['result' => false]);
			exit();
		}

		$language = Translator::class;

		switch ($rAction) {
			case 'set_language':
				$lang = $rData['language'] ?? ($rData['lang'] ?? '');
				if (!in_array($lang, Translator::available(), true)) {
					echo json_encode(['result' => false, 'error' => 'Invalid language code']);
					exit();
				}

				Translator::setLanguage($lang);
				setcookie('lang', $lang, time() + (365 * 86400), '/');
				$_COOKIE['lang'] = $lang;

				$userId = (int) (ResellerAPI::$rUserInfo['id'] ?? ($GLOBALS['rUserInfo']['id'] ?? ($_SESSION['id'] ?? ($_SESSION['hash'] ?? 0))));
				$isRtl = Translator::isRtl($lang);
				if (!empty($userId)) {
					$db = self::db();
					$db->query('SELECT `ui_prefs` FROM `users` WHERE `id` = ?;', $userId);
					$userRow = $db->get_row();
					$uiPrefs = [];
					if (!empty($userRow['ui_prefs'])) {
						$decoded = json_decode((string) $userRow['ui_prefs'], true);
						if (is_array($decoded)) {
							$uiPrefs = $decoded;
						}
					}
					$uiPrefs['rtl'] = $isRtl;
					$db->query('UPDATE `users` SET `lang` = ?, `ui_prefs` = ? WHERE `id` = ?;', $lang, json_encode($uiPrefs), $userId);
				}

				echo json_encode([
					'result' => true,
					'lang'   => $lang,
					'dir'    => $isRtl ? 'rtl' : 'ltr',
					'status' => STATUS_SUCCESS,
				]);
				exit();

			case 'edit_profile':
				$rReturn = ResellerAPI::editResellerProfile($rData);
				setcookie('hue', $rData['hue'] ?? '', time() + 315360000);
				setcookie('theme', (string) ($rData['theme'] ?? 0), time() + 315360000);
				$selectedLang = $rData['lang'] ?? 'en';
				$language::setLanguage($selectedLang);
				setcookie('lang', $selectedLang, time() + (365 * 86400), '/');
				$_COOKIE['lang'] = $selectedLang;

				if ($rReturn['status'] == STATUS_SUCCESS) {
					$userId = (int) (ResellerAPI::$rUserInfo['id'] ?? ($GLOBALS['rUserInfo']['id'] ?? ($_SESSION['id'] ?? ($_SESSION['hash'] ?? 0))));
					if (!empty($userId)) {
						$db = self::db();
						$isRtl = Translator::isRtl($selectedLang);
						$db->query('SELECT `ui_prefs` FROM `users` WHERE `id` = ?;', $userId);
						$userRow = $db->get_row();
						$uiPrefs = [];
						if (!empty($userRow['ui_prefs'])) {
							$decoded = json_decode((string) $userRow['ui_prefs'], true);
							if (is_array($decoded)) {
								$uiPrefs = $decoded;
							}
						}
						$uiPrefs['rtl'] = $isRtl;
						$db->query('UPDATE `users` SET `lang` = ?, `ui_prefs` = ? WHERE `id` = ?;', $selectedLang, json_encode($uiPrefs), $userId);
					}
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
