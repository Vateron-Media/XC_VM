<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Http\RequestManager;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Stream\StreamConfigRepository;

/**
 * SerieController — редактирование/добавление сериала.
 *
 * @renders Views/admin/serie.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SerieController extends BaseAdminController {
	public function index() {
		$this->requirePermission();

		global $rServers;

		$rSeriesArr = null;
		// The series as stored: the form escapes what it prints and posts it back,
		// so text read through the row cleaner ('&lt;' for '<') would be saved escaped.
		if (RequestManager::has('id')) {
			$GLOBALS['db']->query('SELECT * FROM `streams_series` WHERE `id` = ?;', intval(RequestManager::get('id')));
			if (!($rSeriesArr = $GLOBALS['db']->get_raw_row())) {
				$this->redirect('series');
				return;
			}
		}

		if (isset($rSeriesArr) && RequestManager::has('import')) {
			unset(RequestManager::getAll()['import']);
		}

		$rTranscodeProfiles = StreamConfigRepository::getTranscodeProfiles();

		$rServerTree = [
			['id' => 'source', 'parent' => '#', 'text' => "<span class='badge bg-primary'>" . htmlspecialchars(Translator::get('selected'), ENT_QUOTES) . '</span>', 'icon' => 'icon-base ti tabler-circle-check', 'state' => ['opened' => true]],
			['id' => 'offline', 'parent' => '#', 'text' => "<span class='badge bg-secondary'>" . htmlspecialchars(Translator::get('available'), ENT_QUOTES) . '</span>', 'icon' => 'icon-base ti tabler-list', 'state' => ['opened' => true]],
		];

		foreach ($rServers as $rServer) {
			$rOnline = !empty($rServer['server_online']);
			$rStatus = htmlspecialchars(Translator::get($rOnline ? 'online' : 'offline'), ENT_QUOTES);
			$rName = htmlspecialchars((string) ($rServer['server_name'] ?? ''), ENT_QUOTES);
			$rServerTree[] = ['id' => $rServer['id'], 'parent' => 'offline', 'text' => $rName . " <span class='badge bg-label-" . ($rOnline ? 'success' : 'danger') . "'>" . $rStatus . '</span>', 'icon' => 'icon-base ti ' . ($rOnline ? 'tabler-server' : 'tabler-server-off'), 'state' => ['opened' => true]];
		}

		// The import flow's server tree is driven by jstree.
		if (RequestManager::has('import')) {
			$GLOBALS['xmNewuiVendors'] = array_values(array_unique(array_merge(
				(array) ($GLOBALS['xmNewuiVendors'] ?? []),
				['jstree']
			)));
		}

		$this->setTitle('TV Series');
		$this->render('serie', ['rSeriesArr' => $rSeriesArr, 'rTranscodeProfiles' => $rTranscodeProfiles, 'rServerTree' => $rServerTree]);
	}
}
