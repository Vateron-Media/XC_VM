<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Audit\AdminAudit;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\TranslationStore;
use XcVm\Core\Localization\Translator;

/**
 * The Translations page's actions (Views/admin/translations.php): the keys of
 * a language for the editor, the editor's save and the upload of an .ini file.
 * Each asks for the `settings` permission (the page's), and the two that
 * write for a POST.
 *
 * @package XC_VM_Public_Controllers_Admin_Ajax
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class TranslationAjaxController extends BaseAjaxController {
	/** action=translation_keys: en.ini's keys with their English text, and what the language translates of them. */
	public function keys(): never {
		$this->guard(false);
		$rCode = RequestManager::get('lang');
		if (!is_string($rCode) || !TranslationStore::validCode($rCode)) {
			$this->fail(['error' => 'code']);
		}
		// An object even when empty, and a text that is not UTF-8 (a file edited by hand) does not fail the whole answer.
		$this->json(['result' => true, 'english' => Translator::englishTranslations(), 'values' => (object) TranslationStore::values($rCode)], JSON_INVALID_UTF8_SUBSTITUTE);
	}

	/**
	 * action=translation_save: the editor's changed rows, as a JSON body
	 * `{"lang": "tr", "values": {"key": "text"}}`. Read as it was sent: the
	 * request's own cleaning (Request::parseCleanValue()) strips backslashes.
	 */
	public function save(): never {
		$this->guard(true);
		$rBody = (string) file_get_contents('php://input', false, null, 0, TranslationStore::MAX_BYTES + 1);
		if (strlen($rBody) > TranslationStore::MAX_BYTES) {
			$this->fail(['error' => 'size']);
		}
		$rIn = json_decode($rBody, true);
		if (!is_array($rIn) || !is_string($rIn['lang'] ?? null) || !is_array($rIn['values'] ?? null)) {
			$this->fail(['error' => 'empty']);
		}
		// The admin action trail names the language: the body is no request field.
		AdminAudit::note(['lang' => mb_substr($rIn['lang'], 0, 16)]);
		$this->json(TranslationStore::save($rIn['lang'], $rIn['values']) + ['translated' => count(TranslationStore::values($rIn['lang']))]);
	}

	/** action=translation_upload: an .ini file (`file`) for the language `lang`. Its own name is not used. */
	public function upload(): never {
		$this->guard(true);
		$rFile = $_FILES['file'] ?? null;
		if (!is_array($rFile) || !is_string($rFile['tmp_name'] ?? null) || ($rFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file($rFile['tmp_name'])) {
			$this->fail(['error' => 'file']);
		}
		// One byte over the limit at most is read: import() refuses it by its length.
		$rText = (string) file_get_contents($rFile['tmp_name'], false, null, 0, TranslationStore::MAX_BYTES + 1);
		$rCode = RequestManager::get('lang');
		$rCode = is_string($rCode) ? $rCode : '';
		AdminAudit::note(['lang' => mb_substr($rCode, 0, 16)]);
		$this->json(TranslationStore::import($rCode, $rText));
	}

	private function guard(bool $rWrites): void {
		$this->requireXhr();
		$this->gate('adv', 'settings');
		if ($rWrites && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
			$this->fail(['error' => 'method']);
		}
	}
}
