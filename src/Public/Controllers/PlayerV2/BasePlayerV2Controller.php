<?php

namespace XcVm\Public\Controllers\PlayerV2;

use XcVm\Public\Controllers\Player\BasePlayerController;

/**
 * BasePlayerV2Controller — Base controller for Web Player V2 pages.
 *
 * Configures scope 'player_v2' for the Sneat Bootstrap 5 layout rendering.
 *
 * @package XC_VM_Public_Controllers_PlayerV2
 */
class BasePlayerV2Controller extends BasePlayerController {
	/** @var string Scope: 'player_v2' */
	protected $scope = 'player_v2';

	/**
	 * Render: header -> view -> footer.
	 *
	 * @param string $view View file name (without .php)
	 * @param array  $data View data to extract
	 */
	protected function render($view, array $data = []) {
		require_once MAIN_HOME . 'Public/Views/layouts/admin.php';
		require_once MAIN_HOME . 'Public/Views/layouts/footer.php';

		// Player globals for header & footer
		$viewGlobals = [
			'db',
			'rSettings',
			'rUserInfo',
			'_TITLE',
			'_PAGE',
			'rStreamIDs',
			'rFilterBy',
			'rSortArray',
			'rFilterArray',
			'rSearchBy',
			'rURLs',
			'rSubtitles',
			'rLegacy',
			'rSeries',
			'rYearStart',
			'rYearEnd',
			'rRatingStart',
			'rRatingEnd',
		];
		foreach ($viewGlobals as $_g) {
			if (array_key_exists($_g, $GLOBALS) && !array_key_exists($_g, $data)) {
				$data[$_g] = $GLOBALS[$_g];
			}
		}
		unset($_g);

		foreach ($data as $key => $value) {
			$GLOBALS[$key] = $value;
		}

		// Deliberate: expose the view payload as local variables so the legacy
		// PHP templates can reference them by name. Refactoring this away means
		// rewriting every view.
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found
		extract($data);

		$__viewsDir = MAIN_HOME . 'Public/Views/' . $this->scope . '/';
		$__viewFile = $__viewsDir . $view . '.php';

		// ─── SPA Partial Request Handling ──────────────────────────────
		$isSpa = !empty($_SERVER['HTTP_X_SPA_REQUEST']) || (isset($_GET['_spa']) && $_GET['_spa'] === '1');
		if ($isSpa) {
			$html = '';
			if (file_exists($__viewFile)) {
				ob_start();
				require $__viewFile;
				$html = ob_get_clean();
			}

			$serverName = SettingsManager::get('server_name') ?: 'XC_VM';
			$title = ($GLOBALS['_TITLE'] ?? 'Dashboard') . ' - ' . htmlspecialchars($serverName);
			$page = defined('PAGE_NAME') ? PAGE_NAME : ($GLOBALS['_PAGE'] ?? $view);

			header('Content-Type: application/json; charset=utf-8');
			echo json_encode([
				'success' => true,
				'title'   => $title,
				'page'    => $page,
				'html'    => $html,
				'url'     => $_SERVER['REQUEST_URI'] ?? '',
			]);
			exit;
		}

		// ─── Standard Full Page Render (Fallback & Direct Visits) ──────
		// 1. Header
		renderUnifiedLayoutHeader($this->scope);

		// 2. View content
		if (file_exists($__viewFile)) {
			require $__viewFile;
		}

		// 3. Footer
		renderUnifiedLayoutFooter($this->scope);
	}
}
