<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Domain\Alert\AlertChannels;
use XcVm\Domain\Alert\Alerts;

/**
 * AlertController — Alerts (admin/alerts.php): the channels alerts go to,
 * the rules, and the latest messages (Domain\Alert).
 *
 * Route:  GET /admin/alerts → index()
 *
 * @renders Views/admin/alerts.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class AlertController extends BaseAdminController {
	public function index() {
		$this->requirePermission();
		$this->setTitle('Alerts');
		$this->render('alerts', [
			'rChannels' => array_map([AlertChannels::class, 'forPage'], AlertChannels::all()),
			'rRules' => Alerts::rules(),
			'rHistory' => Alerts::history(),
		]);
	}
}
