<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleJob;
use XcVm\Core\Module\ModuleManager;
use XcVm\Core\Module\ModuleStore;

/**
 * ModuleJobCommand — run the module action the modules page queued (MAIN).
 *
 * The page queues it (ModuleJob::start) and starts this command in the
 * background, then polls the job's state; this records how it ended.
 *
 * Usage: `console.php module:job`
 *
 * @package XC_VM_CLI_Commands
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleJobCommand implements CommandInterface {
	public function getName(): string {
		return 'module:job';
	}

	public function getDescription(): string {
		return 'Run the module action queued by the modules page';
	}

	public function execute(array $rArgs): int {
		$rManager = new ModuleManager(container: ServiceContainer::getInstance());
		$rJob = ModuleJob::run(static fn(array $rJob): string => self::perform($rManager, (string) $rJob['action'], (string) $rJob['target']));
		if ($rJob === null) {
			echo "No module action queued\n";
			return 1;
		}
		echo $rJob['status'] . ': ' . $rJob['message'] . "\n";
		return $rJob['status'] === 'done' ? 0 : 1;
	}

	/**
	 * Do one action; the message to show. Throws what the manager refused with.
	 *
	 * @throws \RuntimeException
	 */
	public static function perform(ModuleManager $rManager, string $rAction, string $rTarget): string {
		switch ($rAction) {
			case 'install':
				$rManager->installModule($rTarget);
				return 'Module installed: ' . $rTarget;

			case 'update':
				$rNew = $rManager->updateModuleFromSource($rTarget);
				return $rNew !== null
					? 'Module updated: ' . $rTarget . ' -> ' . $rNew
					: 'Nothing newer at the source for ' . $rTarget . ': already up to date.';

			case 'uninstall':
				$rManager->uninstallModule($rTarget);
				return 'Module uninstalled: ' . $rTarget;

			case 'delete':
				$rManager->deleteModule($rTarget);
				return 'Module deleted: ' . $rTarget;

			case 'rollback':
				$rManager->rollbackFromPlatform($rTarget, self::platformKey());
				return 'Module rolled back to its previous version: ' . $rTarget;

			case 'renew_license':
				return $rManager->renewModuleLicense($rTarget, self::platformKey())
					? 'License renewed for: ' . $rTarget
					: 'License not renewed for ' . $rTarget . ' (platform licensing off, not entitled, or no server data).';

			case 'store_install':
				$rManager->downloadFromPlatform($rTarget, '', self::platformKey());
				ModuleStore::forget();
				return 'Module installed from the store: ' . $rTarget;

			case 'upload_install':
				return self::uploadInstall($rManager, $rTarget);

			case 'check_updates':
				return self::updatesMessage($rManager->checkUpdates());
		}

		throw new \RuntimeException('Unknown module action: ' . $rAction);
	}

	/** Install the archive the page saved, then remove it. */
	private static function uploadInstall(ModuleManager $rManager, string $rPath): string {
		// Only an archive the upload endpoint saved: a job file names its path.
		if (dirname($rPath) . '/' !== CACHE_TMP_PATH || !preg_match('/^module_upload_[0-9a-f]{16}$/', basename($rPath))) {
			throw new \RuntimeException('Not an uploaded module archive.');
		}
		try {
			return 'Module uploaded and installed: ' . $rManager->uploadAndInstall($rPath);
		} finally {
			@unlink($rPath);
		}
	}

	/** @param array{found: list<string>, failed: list<string>, checked: int} $rResult */
	private static function updatesMessage(array $rResult): string {
		$rParts = [];
		if ($rResult['found'] !== []) {
			$rParts[] = 'Updates available: ' . implode(', ', $rResult['found']) . '.';
		}
		if ($rResult['failed'] !== []) {
			$rParts[] = 'Could not check ' . implode('; ', $rResult['failed']) . '.';
		}
		return $rParts !== [] ? implode(' ', $rParts) : 'All installed modules are up to date (' . $rResult['checked'] . ' checked).';
	}

	/** The platform API key, or a refusal when the operator has not set one. */
	private static function platformKey(): string {
		$rKey = (string) (SettingsManager::get('platform_api_key') ?? '');
		if ($rKey === '') {
			throw new \RuntimeException('Set the Modules API key first (Settings → API).');
		}
		return $rKey;
	}
}
