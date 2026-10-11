<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\TranslationStore;
use XcVm\Core\Localization\Translator;

/**
 * TranslationsController — Translations (admin/translations.php): the panel's
 * languages with how much of each is translated, each as an .ini file to
 * download, an upload and an editor (Core\Localization\TranslationStore).
 *
 * Route:  GET /admin/translations               → index()
 *         GET /admin/translations?download=<xx> → the language as <xx>.ini
 *
 * @renders Views/admin/translations.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TranslationsController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();

		if (RequestManager::has('download')) {
			$rCode = RequestManager::get('download');
			$rText = is_string($rCode) ? TranslationStore::export($rCode) : null;
			if ($rText === null) {
				http_response_code(404);
				exit();
			}

			// export() took the code for a language code: it is safe as a file name.
			header('Content-Type: text/plain; charset=utf-8');
			header('X-Content-Type-Options: nosniff');
			header('Content-Disposition: attachment; filename="' . $rCode . '.ini"');
			header('Content-Length: ' . strlen($rText));
			echo $rText;
			exit();
		}

		$this->setTitle('Translations');
		$this->render('translations', [
			'rLanguages' => TranslationStore::languages(),
			'rTotal' => count(Translator::englishTranslations()),
		]);
	}
}
