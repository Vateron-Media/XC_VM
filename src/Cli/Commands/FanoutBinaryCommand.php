<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\UpdateChannels;

/**
 * FanoutBinaryCommand — install/update the xc_fanout daemon binary (ADR 0003).
 *
 * The daemon lives in its own repo (GIT_REPO_FANOUT) and ships its per-arch
 * static binaries as GitHub **release assets** (not committed to the tree). This
 * command is the panel-side installer/updater, modelled on the binaries/maxmind
 * updaters. The installed version is tracked in a sidecar file
 * (`xc_fanout.version`) rather than derived from the binary, so a locally-built
 * or custom-signed test build is not force-overwritten just because its
 * self-reported version differs from the latest GitHub release — pin it by
 * writing the file. Independently, the binary is probed with `xc_fanout
 * -version`: a binary that does not answer is treated as missing/corrupt and
 * reinstalled regardless of the recorded version. When an update is due it
 * downloads the arch-matched asset, verifies its SHA-256, installs it
 * atomically, records the version file, and restarts the daemon (the `service`
 * keepalive respawns it with the new binary). The release channel is the
 * per-repository FANOUT channel ({@see UpdateChannels}).
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

	/** Sidecar file (next to the binary) recording the installed version. */
	private const VERSION_FILE = 'xc_fanout.version';

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
		$rVerFile = $rDir . self::VERSION_FILE;

		// Integrity probe: the binary must answer `-version`. A binary that does
		// not respond is missing or corrupted ("bit-rotted") and must be
		// reinstalled regardless of the recorded version.
		$rReported = $this->binaryVersion($rBinary);
		$rHealthy = $rReported !== null;

		// Installed version is tracked in a sidecar file, not derived from the
		// binary, so a locally-built/signed test build is not force-overwritten
		// just because its self-reported version differs from the latest release.
		// Seed the file from the running binary on first run after upgrade
		// (migration) so an already up-to-date host is not needlessly reinstalled.
		$rInstalled = $this->readVersionFile($rVerFile);
		if ($rInstalled === null && $rHealthy) {
			$this->writeVersionFile($rVerFile, $rReported);
			$rInstalled = $rReported;
		}

		$rChannel = UpdateChannels::fanout();

		try {
			$rGit = new GitHubReleases(GIT_OWNER, GIT_REPO_FANOUT, $rChannel);
			$rGit->setTimeout(20);
			// `force` means "recheck everything from scratch": drop the 30-minute
			// releases cache so a forced reinstall resolves against a fresh GitHub
			// response instead of a stale one that could pin an old "latest".
			if ($rForce) {
				$rGit->clearCache();
			}
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

		if (!$rForce && $rHealthy && $rInstalled !== null && $rInstalled === $rLatest) {
			echo "xc_fanout is up to date ({$rInstalled}).\n";
			return 0;
		}
		$rReason = !$rHealthy
			? 'binary not responding (missing/corrupt)'
			: 'installed=' . ($rInstalled ?? 'none') . ', latest=' . $rLatest;
		echo 'xc_fanout: ' . $rReason . " → updating\n";

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

		// Record the installed version in the sidecar file so subsequent runs
		// compare this file against GitHub (not the binary's self-report).
		$this->writeVersionFile($rVerFile, $rLatest);

		// Restart: kill ONLY the daemon process (match the exact process NAME, not
		// the cmdline) so the service keepalive loop — whose bash cmdline contains
		// the same binary path — survives and respawns it with the new binary.
		// Harmless no-op if it isn't running yet.
		shell_exec("pkill -u xc_vm -x xc_fanout 2>/dev/null");

		echo "xc_fanout {$rNewVer} installed.\n";
		return 0;
	}

	/**
	 * Version the binary reports via `-version`, or null when it is absent or
	 * does not respond (missing/corrupt). Doubles as the integrity probe.
	 */
	private function binaryVersion(string $rBinary): ?string {
		if (!is_file($rBinary) || !is_executable($rBinary)) {
			return null;
		}
		$rOut = trim((string) shell_exec(escapeshellarg($rBinary) . ' -version 2>/dev/null'));
		return $rOut !== '' ? ltrim($rOut, 'vV') : null;
	}

	/** Recorded installed version from the sidecar file, or null if absent/empty. */
	private function readVersionFile(string $rFile): ?string {
		if (!is_file($rFile)) {
			return null;
		}
		$rVal = trim((string) @file_get_contents($rFile));
		return $rVal !== '' ? ltrim($rVal, 'vV') : null;
	}

	/** Persist the installed version to the sidecar file (best-effort). */
	private function writeVersionFile(string $rFile, string $rVersion): void {
		if (@file_put_contents($rFile, ltrim($rVersion, 'vV') . "\n") !== false) {
			@chown($rFile, 'xc_vm');
			@chgrp($rFile, 'xc_vm');
		}
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
