<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Container\ServiceContainer;
use XcVm\Core\Module\ModuleManager;

/** Sync bundled modules in the normal, unprivileged module-aware console. */
final class ModuleSyncBundledCommand implements CommandInterface {
	public function getName(): string {
		return 'module:sync-bundled';
	}

	public function getDescription(): string {
		return 'Provision bundled modules (xc_vm only)';
	}

	public function execute(array $rArgs): int {
		if ((posix_getpwuid(posix_geteuid())['name'] ?? '') !== 'xc_vm') {
			echo "Please run as xc_vm.\n";
			return 1;
		}
		try {
			$rInstalled = (new ModuleManager(null, null, ServiceContainer::getInstance()))->syncBundledModules();
			if ($rInstalled !== []) {
				echo 'Installed bundled modules: ' . implode(', ', $rInstalled) . "\n";
			}
			return 0;
		} catch (\Throwable $e) {
			error_log('Bundled module sync failed: ' . $e->getMessage());
			return 1;
		}
	}
}
