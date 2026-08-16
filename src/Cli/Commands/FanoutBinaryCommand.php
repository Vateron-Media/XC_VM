<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Updates\GitHubReleases;

/**
 * FanoutBinaryCommand — install/update the xc_fanout daemon binary (ADR 0003).
 *
 * The daemon lives in its own repo (GIT_REPO_FANOUT) and ships its per-arch
 * static binaries as GitHub **release assets** (not committed to the tree). This
 * command is the panel-side installer/updater, modelled on the binaries/maxmind
 * updaters: it reads the installed version straight from the binary
 * (`xc_fanout -version`), compares it to the latest release, and when they
 * differ downloads the arch-matched asset, verifies its SHA-256, installs it
 * atomically, and restarts the daemon (the `service` keepalive respawns it with
 * the new binary).
 *
 * Usage: `console.php fanout_binary` (add `force` to reinstall the same version).
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FanoutBinaryCommand implements CommandInterface {
	/** uname -m → release asset arch suffix. */
	private const ARCH_MAP = [
		'x86_64'  => 'amd64',
		'amd64'   => 'amd64',
		'aarch64' => 'arm64',
		'arm64'   => 'arm64',
		'armv7l'  => 'armv7',
		'armv7'   => 'armv7',
		'armhf'   => 'armv7',
		'i386'    => '386',
		'i686'    => '386',
	];

	public function getName(): string {
		return 'fanout_binary';
	}

	public function getDescription(): string {
		return 'Install/update the xc_fanout daemon binary from its release';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}
		$rForce = in_array('force', $rArgs, true);

		$rMachine = trim(php_uname('m'));
		$rArch = self::ARCH_MAP[$rMachine] ?? null;
		if ($rArch === null) {
			echo "Unsupported architecture: {$rMachine}\n";
			return 1;
		}

		$rDir = BIN_PATH . 'xc_fanout/';
		$rBinary = $rDir . 'xc_fanout';
		$rInstalled = $this->installedVersion($rBinary);

		$rChannel = 'stable';
		$rSettings = SettingsManager::getAll();
		if (!empty($rSettings['update_channel']) && in_array($rSettings['update_channel'], ['stable', 'unstable'], true)) {
			$rChannel = $rSettings['update_channel'];
		}

		try {
			$rGit = new GitHubReleases(GIT_OWNER, GIT_REPO_FANOUT, $rChannel);
			$rGit->setTimeout(20);
			$rReleases = $rGit->getReleases();
		} catch (\Exception $e) {
			echo 'Failed to check xc_fanout releases: ' . $e->getMessage() . "\n";
			return 1;
		}
		if (empty($rReleases[0])) {
			echo "Failed to resolve the latest xc_fanout release.\n";
			return 1;
		}
		$rTag = trim($rReleases[0]);
		$rLatest = ltrim($rTag, 'vV');

		if (!$rForce && $rInstalled !== null && $rInstalled === $rLatest) {
			echo "xc_fanout is up to date ({$rInstalled}).\n";
			return 0;
		}
		echo 'xc_fanout: installed=' . ($rInstalled ?? 'none') . ', latest=' . $rLatest . " → updating\n";

		$rBase = 'https://github.com/' . GIT_OWNER . '/' . GIT_REPO_FANOUT . '/releases/download/' . rawurlencode($rTag) . '/';
		$rAsset = 'xc_fanout-linux-' . $rArch;

		if (!is_dir($rDir) && !@mkdir($rDir, 0755, true)) {
			echo "Failed to create {$rDir}\n";
			return 1;
		}
		$rTmp = $rDir . '.xc_fanout.new';

		if (!$this->download($rBase . $rAsset, $rTmp)) {
			echo "Failed to download {$rAsset}\n";
			@unlink($rTmp);
			return 1;
		}

		$rExpected = $this->expectedSha256($rBase . 'SHA256SUMS', $rAsset);
		if ($rExpected === null) {
			echo "Failed to fetch SHA256SUMS\n";
			@unlink($rTmp);
			return 1;
		}
		if (!hash_equals($rExpected, hash_file('sha256', $rTmp))) {
			echo "Checksum mismatch for {$rAsset} — aborting\n";
			@unlink($rTmp);
			return 1;
		}

		@chmod($rTmp, 0755);
		$rNewVer = trim((string) shell_exec(escapeshellarg($rTmp) . ' -version 2>/dev/null'));
		if ($rNewVer === '') {
			echo "Downloaded binary does not run on this host — aborting\n";
			@unlink($rTmp);
			return 1;
		}

		if (!@rename($rTmp, $rBinary)) { // atomic replace
			echo "Failed to install {$rBinary}\n";
			@unlink($rTmp);
			return 1;
		}
		@chown($rBinary, 'xc_vm');
		@chgrp($rBinary, 'xc_vm');

		// Restart: kill ONLY the daemon process (match the exact process NAME, not
		// the cmdline) so the service keepalive loop — whose bash cmdline contains
		// the same binary path — survives and respawns it with the new binary.
		// Harmless no-op if it isn't running yet.
		shell_exec("pkill -u xc_vm -x xc_fanout 2>/dev/null");

		echo "xc_fanout {$rNewVer} installed.\n";
		return 0;
	}

	/** Installed version straight from the binary, or null if absent/unrunnable. */
	private function installedVersion(string $rBinary): ?string {
		if (!is_file($rBinary) || !is_executable($rBinary)) {
			return null;
		}
		$rOut = trim((string) shell_exec(escapeshellarg($rBinary) . ' -version 2>/dev/null'));
		return $rOut !== '' ? ltrim($rOut, 'vV') : null;
	}

	/** Download a URL to a file (following redirects). */
	private function download(string $rUrl, string $rDest): bool {
		$rFp = @fopen($rDest, 'wb');
		if (!$rFp) {
			return false;
		}
		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_URL            => $rUrl,
			CURLOPT_FILE           => $rFp,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 20,
			CURLOPT_TIMEOUT        => 120,
			CURLOPT_FAILONERROR    => true,
			CURLOPT_USERAGENT      => 'XC_VM',
		]);
		$rOk = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);
		fclose($rFp);

		return $rOk !== false && $rCode >= 200 && $rCode < 300 && filesize($rDest) > 0;
	}

	/** Expected sha256 for $rAsset from a SHA256SUMS file (`<hash>  <name>`). */
	private function expectedSha256(string $rUrl, string $rAsset): ?string {
		$rCurl = curl_init();
		curl_setopt_array($rCurl, [
			CURLOPT_URL            => $rUrl,
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_CONNECTTIMEOUT => 15,
			CURLOPT_TIMEOUT        => 30,
			CURLOPT_USERAGENT      => 'XC_VM',
		]);
		$rBody = curl_exec($rCurl);
		$rCode = curl_getinfo($rCurl, CURLINFO_HTTP_CODE);
		curl_close($rCurl);
		if (!is_string($rBody) || $rCode < 200 || $rCode >= 300) {
			return null;
		}

		foreach (explode("\n", $rBody) as $rLine) {
			$rParts = preg_split('/\s+/', trim($rLine), 2);
			if (count($rParts) === 2 && ltrim(trim($rParts[1]), '*./') === $rAsset) {
				return strtolower(trim($rParts[0]));
			}
		}
		return null;
	}
}
