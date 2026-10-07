<?php

namespace XcVm\Public\Controllers\Admin\Ajax;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Enum\ModuleState;
use XcVm\Core\Http\RequestManager;
use XcVm\Core\Module\ModuleJob;
use XcVm\Core\Module\ModuleManager;
use XcVm\Core\Module\ModuleStore;

/**
 * Admin-ajax endpoints of the modules page.
 *
 * - `module_status` (GET): every module's state as it stands now, and the
 *   background job (ModuleJob) if any. The page polls it while a job runs.
 * - `module` (POST): enable/disable at once; every other action is queued as a
 *   background job (`console.php module:job`), one at a time.
 * - `module_upload` (POST): a module archive, installed as a background job.
 * - `module_store` (GET): the official store's modules (ModuleStore).
 *
 * Every answer carries the modules' state read back after the action, so the
 * page draws what is, never what it asked for.
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleAjaxController extends BaseAjaxController {
	/** action=module_status — the modules and the running job. */
	public function status(): never {
		$this->guard();
		$this->ok(['rows' => self::rows($this->manager()), 'job' => ModuleJob::view()]);
	}

	/** action=module — one module's action. */
	public function module(): never {
		$this->guard(true);
		$rManager = $this->manager();
		$rSub     = (string) RequestManager::get('sub');
		$rName    = trim((string) RequestManager::get('name'));

		if ($rName === '' && $rSub !== 'check_updates') {
			$this->fail(['message' => 'Module name is required.']);
		}

		if ($rSub === 'enable' || $rSub === 'disable') {
			try {
				$rManager->setState($rName, $rSub === 'enable' ? ModuleState::Enabled : ModuleState::Disabled);
			} catch (\Throwable $e) {
				// The manager refuses with a message worth showing verbatim (e.g.
				// "still required by plex").
				$this->fail(['message' => $e->getMessage(), 'rows' => self::rows($rManager)]);
			}
			$this->ok(['message' => 'Module ' . $rSub . 'd: ' . $rName, 'rows' => self::rows($rManager)]);
		}

		if ($rSub === 'upload_install' || !in_array($rSub, ModuleJob::ACTIONS, true)) {
			$this->fail(['message' => 'Unknown module action: ' . $rSub]);
		}
		$this->queue($rSub, $rName);
	}

	/** action=module_upload — install an uploaded module archive. */
	public function upload(): never {
		$this->guard(true);
		$rFile = $_FILES['module_zip'] ?? null;
		if (!is_array($rFile) || (int) ($rFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $rFile['tmp_name'])) {
			$this->fail(['message' => 'The archive was not uploaded.']);
		}
		// The job runs in another process, after this request has removed its upload.
		$rPath = CACHE_TMP_PATH . 'module_upload_' . bin2hex(random_bytes(8));
		if (!move_uploaded_file((string) $rFile['tmp_name'], $rPath)) {
			$this->fail(['message' => 'The archive could not be kept for installing.']);
		}
		$rJob = ModuleJob::start('upload_install', $rPath);
		if ($rJob === null) {
			@unlink($rPath);
			$this->fail(['message' => 'Another module action is still running.', 'job' => ModuleJob::view()]);
		}
		$this->ok(['job' => $rJob]);
	}

	/** action=module_store — the store's modules this panel may install. */
	public function store(): never {
		$this->guard();
		$rKey = (string) (SettingsManager::get('platform_api_key') ?? '');
		$rCatalogue = ModuleStore::catalogue($rKey, (string) RequestManager::get('refresh') === '1');
		if (!$rCatalogue['ok']) {
			$this->fail(['reason' => $rCatalogue['reason'], 'message' => self::storeError((string) $rCatalogue['reason'])]);
		}
		$this->ok(['modules' => ModuleStore::rows($rCatalogue['extensions'], $rCatalogue['owned'], $this->manager()->listModules())]);
	}

	/**
	 * The modules as the page lists them.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function rows(ModuleManager $rManager): array {
		$rRows = [];
		foreach ($rManager->listModules() as $rModule) {
			$rInstalled = (string) $rModule['installed_version'];
			$rAvailable = ((string) $rModule['available_version']) ?: (string) $rModule['version'];
			$rState     = $rModule['state'];
			$rRows[] = [
				'name'          => (string) $rModule['name'],
				'description'   => (string) $rModule['description'],
				'version'       => (string) $rModule['version'],
				'requires_core' => (string) $rModule['requires_core'],
				'installed'     => $rInstalled,
				// One word for the badge: a module with files but no install is
				// "not_installed", whatever state the loader would give it.
				'status'        => match (true) {
					$rState === ModuleState::Installing => 'installing',
					$rState === ModuleState::Failed     => 'failed',
					$rInstalled === ''                  => 'not_installed',
					default                             => $rState->value,
				},
				'source'        => (string) $rModule['source'],
				'has_settings'  => (bool) $rModule['has_settings'],
				// Only advertise an update when the source really is ahead.
				'update_to'     => ($rInstalled !== '' && version_compare($rAvailable, $rInstalled, '>')) ? $rAvailable : '',
				'rollback_to'   => (string) $rModule['previous_version'],
				'warnings'      => array_values($rModule['dependency_warnings']),
			];
		}
		return $rRows;
	}

	/** What the page says for a store that did not answer. */
	public static function storeError(string $rReason): string {
		return match ($rReason) {
			'no_api_key'   => 'Set the Modules API key in Settings → API to see the store.',
			'no_extension' => 'The store needs the XC_VM core extension, which this PHP does not load.',
			default        => 'The store did not answer (' . $rReason . '). Try again later.',
		};
	}

	/** Queue a background job and answer with it. */
	private function queue(string $rAction, string $rTarget): never {
		$rJob = ModuleJob::start($rAction, $rTarget);
		if ($rJob === null) {
			$this->fail(['message' => 'Another module action is still running.', 'job' => ModuleJob::view()]);
		}
		$this->ok(['job' => $rJob]);
	}

	/** XHR only, the settings permission; a change only over POST. */
	private function guard(bool $rChange = false): void {
		$this->requireXhr();
		// Installing or removing a module is system-level, as the settings page.
		$this->gate('adv', 'settings');
		if ($rChange && strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
			$this->fail(['message' => 'POST required.']);
		}
	}

	private function manager(): ModuleManager {
		return new ModuleManager(container: ServiceContainer::getInstance());
	}
}
