<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Vod\VodImportLog;

/** Admin actions for correcting and retrying failed VOD imports. */
final class VodImportAjaxController extends BaseAjaxController {
	public function retry(): never {
		$this->requireXhr();
		$rType = (int) (RequestManager::get('type') ?? 0);
		if ($rType === 1) {
			$this->gate('adv', 'import_movies');
		} elseif ($rType === 2) {
			$this->gate('adv', 'import_episodes');
		} else {
			$this->fail();
		}

		if (!VodImportLog::retry((int) (RequestManager::get('id') ?? 0), (string) (RequestManager::get('title') ?? ''), $rType)) {
			$this->fail();
		}
		$this->ok();
	}
}
