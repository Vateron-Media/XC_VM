<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Module\ModuleManager;

/**
 * ModuleInstallCommand — install a module on a load balancer (files only).
 *
 * Triggered by the root signals daemon when MAIN distributes a module to LB
 * servers. Payload is a base64-encoded JSON object:
 *   {"action":"install_module","source":"platform|local","name":"…","version":"…"}
 *
 * - source = platform : the LB downloads the module from the store itself,
 *                       using the shared platform API key (settings.platform_api_key,
 *                       needed only for a paid module)
 *                       and its OWN install_id. Files only — no DB migrations
 *                       (the shared DB was already migrated by MAIN).
 * - source = local    : the module is a custom one not on the store. Its
 *                       archive arrives as a cluster artefact: cluster:root
 *                       stages it in root's own stage and checks its size and
 *                       SHA-256 against MAIN's signed grant, and the payload
 *                       names that copy as `archive` (with the grant as
 *                       `artefact`); it is checked again here, refused and
 *                       audited as an artefact when it is not the grant's.
 *                       Without a grant nothing installs: MAIN's `/api`
 *                       getFile it was once pulled with is gone (PR #392).
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class ModuleInstallCommand implements CommandInterface {
	public function getName(): string {
		return 'module:install';
	}

	public function getDescription(): string {
		return 'Install a module distributed from MAIN (load balancer, files only)';
	}

	public function execute(array $rArgs): int {
		register_shutdown_function(function () {
			global $db;
			if (is_object($db) && method_exists($db, 'close_mysql')) {
				$db->close_mysql();
			}
		});

		$rPayload = $this->decodePayload($rArgs[0] ?? '');
		if ($rPayload === null) {
			echo "Invalid module:install payload.\n";
			return 1;
		}

		$rName    = (string) ($rPayload['name'] ?? '');
		$rVersion = (string) ($rPayload['version'] ?? '');
		$rSource  = ($rPayload['source'] ?? 'platform') === 'local' ? 'local' : 'platform';

		if ($rName === '') {
			echo "module:install: missing module name.\n";
			return 1;
		}

		$rManager = new ModuleManager();

		try {
			if ($rSource === 'platform') {
				// Without a key only a free module installs: the store refuses a paid one.
				$rApiKey = (string) (SettingsManager::get('platform_api_key') ?? '');
				echo "Installing store module '{$rName}' v{$rVersion} from platform...\n";
				// The store knows the module by its store id (hash_id); a payload
				// from an older MAIN carries only the name, then taken as the id.
				$rStoreId = (string) ($rPayload['store_id'] ?? '') ?: $rName;
				$rManager->deployFromPlatformFilesOnly($rStoreId, $rVersion, $rApiKey);
			} elseif (isset($rPayload['archive'])) {
				// Staged by cluster:root from MAIN's grant: only that copy, only its bytes.
				$rStaged = (string) $rPayload['archive'];
				$rGrant = is_array($rPayload['artefact'] ?? null) ? $rPayload['artefact'] : null;
				$rRefused = ArtefactStage::stagedArchive($rStaged, $rGrant);
				if ($rRefused !== null) {
					echo 'module:install: ' . ArtefactStage::refuseGrant($rGrant ?? [], $rRefused) . "\n";
					return 1;
				}
				echo "Installing custom module '{$rName}' v{$rVersion} from the archive MAIN granted...\n";
				$rManager->deployFromArchiveFilesOnly($rStaged);
			} else {
				echo "module:install: no archive granted for '{$rName}' v{$rVersion} (a custom module reaches a load balancer only as a cluster artefact: COMMANDS, root_ready, agent feature 'artefact').\n";
				return 1;
			}

			$this->fixOwnership($rName);
			echo "module:install: '{$rName}' installed.\n";
			return 0;
		} catch (\Throwable $e) {
			echo "module:install failed for '{$rName}': " . $e->getMessage() . "\n";
			return 1;
		}
	}

	/** Decode + validate the base64 JSON payload. */
	private function decodePayload(string $rArg): ?array {
		$rArg = trim($rArg, "'\" ");
		if ($rArg === '') {
			return null;
		}
		$rJson = base64_decode($rArg, true);
		if ($rJson === false) {
			return null;
		}
		$rData = json_decode($rJson, true);
		return is_array($rData) ? $rData : null;
	}

	/**
	 * Hand the freshly installed module directory back to the panel user, since
	 * this command runs as root from the signals cron.
	 */
	private function fixOwnership(string $rName): void {
		if (!preg_match('/^[a-z0-9][a-z0-9\-]*$/', $rName)) {
			return;
		}
		$rDir = (defined('MAIN_HOME') ? MAIN_HOME : '') . 'Modules/' . $rName;
		if (is_dir($rDir)) {
			shell_exec('sudo chown -R xc_vm:xc_vm ' . escapeshellarg($rDir) . ' 2>/dev/null');
		}
	}
}
