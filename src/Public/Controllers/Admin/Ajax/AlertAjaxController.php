<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Http\RequestManager;
use XcVm\Domain\Alert\AlertChannels;
use XcVm\Domain\Alert\Alerts;

/**
 * The Alerts page's actions (Views/admin/alerts.php): save, delete and test a
 * channel, save the rules. Each asks for the `settings` permission and a POST.
 *
 * @package XC_VM_Public_Controllers_Admin_Ajax
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class AlertAjaxController extends BaseAjaxController {
	/** action=alert_channel_save */
	public function saveChannel(): never {
		$this->guard();
		$this->json(AlertChannels::save(RequestManager::getAll()));
	}

	/** action=alert_channel_delete */
	public function deleteChannel(): never {
		$this->guard();
		$this->json(['result' => AlertChannels::delete((int) RequestManager::get('id'))]);
	}

	/** action=alert_channel_test: a test message, the channel's error when it did not go. */
	public function testChannel(): never {
		$this->guard();
		$rError = Alerts::test((int) RequestManager::get('id'));
		$this->json($rError === null ? ['result' => true] : ['result' => false, 'error' => $rError]);
	}

	/** action=alert_rules_save */
	public function saveRules(): never {
		$this->guard();
		$this->json(['result' => Alerts::saveRules(RequestManager::getAll())]);
	}

	private function guard(): void {
		$this->requireXhr();
		$this->gate('adv', 'settings');
		if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
			$this->fail(['error' => 'method']);
		}
	}
}
