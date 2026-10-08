<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\ArtefactStage;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Module\ModuleManager;
use XcVm\Domain\Server\ServerRepository;

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
 * - source = local    : the module is a custom one not on the store. The LB
 *                       pulls the {name}_{version}.zip archive back from MAIN
 *                       over the internal system API (action=getFile) and
 *                       installs its files.
 *                       Over the cluster API the archive is an artefact
 *                       instead: cluster:root stages it in root's own stage
 *                       and checks its size and SHA-256 against MAIN's signed
 *                       grant, and the payload names that copy as `archive`
 *                       (with the grant as `artefact`); it is checked again
 *                       here, refused and audited as an artefact when it is
 *                       not the grant's, and nothing is pulled from MAIN.
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
				$rManager->deployFromPlatformFilesOnly($rName, $rVersion, $rApiKey);
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
				echo "Installing custom module '{$rName}' v{$rVersion} from MAIN...\n";
				$rArchive = $this->fetchArchiveFromMain($rManager->archivePathFor($rName, $rVersion), $rPayload);
				try {
					$rManager->deployFromArchiveFilesOnly($rArchive);
				} finally {
					@unlink($rArchive);
				}
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
	 * Pull a custom module archive from MAIN over the internal system API
	 * (action=getFile). Returns the path to a downloaded temp file, which is
	 * the archive the payload announced (announced()).
	 *
	 * @param array<string, mixed> $rPayload
	 * @throws \RuntimeException If MAIN cannot be located or the download fails.
	 */
	private function fetchArchiveFromMain(string $rArchivePath, array $rPayload): string {
		$rMain = null;
		foreach (ServerRepository::getAll() as $rServer) {
			if (!empty($rServer['is_main'])) {
				$rMain = $rServer;
				break;
			}
		}

		if (!$rMain || empty($rMain['server_ip'])) {
			throw new \RuntimeException('Could not locate the MAIN server to fetch the module archive.');
		}

		$rPass = (string) (SettingsManager::get('live_streaming_pass') ?? '');
		$rUrl  = 'http://' . $rMain['server_ip'] . ':' . intval($rMain['http_broadcast_port'])
			. '/api?password=' . urlencode($rPass)
			. '&action=getFile&filename=' . urlencode($rArchivePath);

		$rTmp = rtrim(sys_get_temp_dir(), '/') . '/xc_lbmod_' . bin2hex(random_bytes(8)) . '.zip';
		$rFp  = @fopen($rTmp, 'wb');
		if (!$rFp) {
			throw new \RuntimeException('Unable to create temporary archive file.');
		}

		$rCh = curl_init();
		curl_setopt($rCh, CURLOPT_URL, $rUrl);
		curl_setopt($rCh, CURLOPT_FILE, $rFp);
		curl_setopt($rCh, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($rCh, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($rCh, CURLOPT_TIMEOUT, 120);
		$rOk   = curl_exec($rCh);
		$rCode = curl_getinfo($rCh, CURLINFO_RESPONSE_CODE);
		curl_close($rCh);
		fclose($rFp);

		// serveFile() answers with a JSON {"result":false,...} body (HTTP 200)
		// when the file is missing/invalid — detect that by checking the zip
		// magic bytes instead of trusting the status code alone.
		$rMagic = @file_get_contents($rTmp, false, null, 0, 2);
		if (!$rOk || $rCode < 200 || $rCode >= 300 || $rMagic !== 'PK') {
			@unlink($rTmp);
			throw new \RuntimeException("Failed to download module archive from MAIN (HTTP {$rCode}).");
		}
		$rWrong = self::announced($rTmp, $rPayload);
		if ($rWrong !== null) {
			@unlink($rTmp);
			throw new \RuntimeException('The module archive from MAIN is not the one it announced: ' . $rWrong . '.');
		}

		return $rTmp;
	}

	/**
	 * Is $rFile the archive the payload announced (its `size` and `sha256`,
	 * ModuleManager::lbInstallPayload)? Null when it is, or when the payload
	 * announces neither (a MAIN from before them: the zip magic is then the
	 * only check, as before); else why not.
	 *
	 * @param array<string, mixed> $rPayload
	 */
	public static function announced(string $rFile, array $rPayload): ?string {
		$rSize = $rPayload['size'] ?? null;
		$rSha = $rPayload['sha256'] ?? null;
		if ($rSize === null && $rSha === null) {
			return null;
		}
		if (!is_int($rSize) || !is_string($rSha) || !preg_match('/^[0-9a-f]{64}$/', $rSha)) {
			return 'a malformed size or SHA-256';
		}
		return ArtefactStage::matches($rFile, $rSize, $rSha) ? null : 'size or SHA-256 mismatch';
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
