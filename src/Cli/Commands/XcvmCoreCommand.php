<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;

/**
 * XcvmCoreCommand — install/update the `xcvm_core` PHP extension.
 *
 * The extension is built in a private repo and mirrored — decoupled from the
 * heavy per-distro runtime bundle — into the PUBLIC binaries repo tree at fixed
 * paths under `bin/xcvm_core/`:
 *   - `xcvm_core-openssl1.1.tar.gz` / `xcvm_core-openssl3.tar.gz` — the `.so` built
 *     per OpenSSL-ABI group (`libcrypto.so.1.1` vs `libcrypto.so.3`), each with a
 *     `VERSION` / `TARGETS` / `NEEDED.txt`;
 *   - `version.json` — the single source of truth for the current version;
 *   - `SHA256SUMS` — integrity hashes of the group archives.
 *
 * This command is the panel-side installer/updater, modelled on
 * {@see FanoutBinaryCommand}: it reads the latest version from `version.json`,
 * compares it to a sidecar marker next to the installed `.so`, and when they
 * differ downloads the archive matching this host's OpenSSL ABI, verifies its
 * SHA-256, and installs the `.so` **atomically with a backup + load-test +
 * rollback** — a wrong-ABI or broken extension must never take php-fpm down.
 * php-fpm is then reloaded (USR2) so workers pick up the new extension; the CLI
 * already loads it on the next invocation.
 *
 * Unlike the daemon (release assets), the extension lives in the repo *tree*, so
 * it is fetched raw from the default branch (rolling latest at a fixed path).
 *
 * Usage: `console.php xcvm_core` (add `force` to reinstall the same version).
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class XcvmCoreCommand implements CommandInterface {

	/** Branch of the binaries repo that carries the committed extension tree. */
	private const BIN_BRANCH = 'main';

	/** Fixed sub-path of the extension files under the binaries repo tree. */
	private const EXT_SUBPATH = 'bin/xcvm_core';

	public function getName(): string {
		return 'xcvm_core';
	}

	public function getDescription(): string {
		return 'Install/update the xcvm_core PHP extension from the binaries repo';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}
		$rForce = in_array('force', $rArgs, true);

		$rBase = 'https://raw.githubusercontent.com/' . GIT_OWNER . '/' . GIT_REPO_BIN
			. '/' . self::BIN_BRANCH . '/' . self::EXT_SUBPATH . '/';

		$rLatest = $this->fetchLatestVersion($rBase . 'version.json');
		if ($rLatest === null) {
			echo "Failed to resolve the latest xcvm_core version.\n";
			return 1;
		}

		$rExtDir = rtrim((string) ini_get('extension_dir'), '/');
		if ($rExtDir === '' || !is_dir($rExtDir)) {
			echo "Could not resolve the PHP extension_dir.\n";
			return 1;
		}
		$rSo = $rExtDir . '/xcvm_core.so';
		$rVerFile = $rExtDir . '/xcvm_core.version';
		$rInstalled = is_file($rVerFile) ? trim((string) file_get_contents($rVerFile)) : null;

		if (!$rForce && $rInstalled !== null && $rInstalled === $rLatest && is_file($rSo)) {
			echo "xcvm_core is up to date ({$rInstalled}).\n";
			return 0;
		}
		echo 'xcvm_core: installed=' . ($rInstalled ?? 'none') . ', latest=' . $rLatest . " → updating\n";

		$rSums = $this->fetch($rBase . 'SHA256SUMS');
		if ($rSums === null) {
			echo "Failed to fetch SHA256SUMS.\n";
			return 1;
		}

		// Try the preferred OpenSSL-ABI group first; if its .so fails to load on
		// this host (wrong libcrypto), fall through to the other group.
		foreach ($this->groupCandidates() as $rGroup) {
			$rAsset = 'xcvm_core-' . $rGroup . '.tar.gz';
			$rExpected = $this->shaFor($rSums, $rAsset);
			if ($rExpected === null) {
				echo "  {$rAsset}: absent from SHA256SUMS, skipping\n";
				continue;
			}

			$rTmp = sys_get_temp_dir() . '/.xcvm_core.' . $rGroup . '.tar.gz';
			if (!$this->download($rBase . $rAsset, $rTmp)) {
				echo "  {$rAsset}: download failed\n";
				@unlink($rTmp);
				continue;
			}
			if (!hash_equals($rExpected, hash_file('sha256', $rTmp))) {
				echo "  {$rAsset}: checksum mismatch\n";
				@unlink($rTmp);
				continue;
			}

			$rOk = $this->installFromTarball($rTmp, $rSo, $rVerFile, $rLatest);
			@unlink($rTmp);
			if ($rOk) {
				echo "xcvm_core {$rLatest} ({$rGroup}) installed.\n";
				return 0;
			}
			echo "  {$rGroup}: did not load on this host, trying the next group\n";
		}

		echo "Failed to install a working xcvm_core for this host.\n";
		return 1;
	}

	/**
	 * Preferred OpenSSL-ABI group first, then the other as a fallback.
	 *
	 * @return string[]
	 */
	private function groupCandidates(): array {
		$rHas3 = $this->libExists('libcrypto.so.3');
		$rHas11 = $this->libExists('libcrypto.so.1.1');
		if ($rHas3 && !$rHas11) {
			return ['openssl3'];
		}
		if ($rHas11 && !$rHas3) {
			return ['openssl1.1'];
		}
		// Both present (a transitional box) or neither detectable: prefer the
		// modern ABI and let the load-test fall back to the other.
		return ['openssl3', 'openssl1.1'];
	}

	private function libExists(string $rSoname): bool {
		if (strpos((string) shell_exec('ldconfig -p 2>/dev/null'), $rSoname) !== false) {
			return true;
		}
		foreach (['/usr/lib/x86_64-linux-gnu/', '/lib/x86_64-linux-gnu/', '/usr/lib64/', '/usr/lib/'] as $rDir) {
			if (@file_exists($rDir . $rSoname)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Extract the .so from the archive, install it atomically over the current
	 * one (keeping a backup), load-test it in a fresh php, and roll back on
	 * failure. Only on success is the version marker written and php-fpm reloaded.
	 */
	private function installFromTarball(string $rTarball, string $rSo, string $rVerFile, string $rVersion): bool {
		$rStage = $rSo . '.new';
		@unlink($rStage);
		shell_exec('tar xzf ' . escapeshellarg($rTarball) . ' -O ./xcvm_core.so > ' . escapeshellarg($rStage) . ' 2>/dev/null');
		if (!is_file($rStage) || filesize($rStage) < 1000) {
			@unlink($rStage);
			return false;
		}
		@chmod($rStage, 0555);
		@chown($rStage, 'xc_vm');
		@chgrp($rStage, 'xc_vm');

		$rBackup = $rSo . '.bak';
		$rHadOld = is_file($rSo);
		if ($rHadOld) {
			@copy($rSo, $rBackup);
		}

		if (!@rename($rStage, $rSo)) { // atomic swap into place
			@unlink($rStage);
			@unlink($rBackup);
			return false;
		}

		// Load-test the freshly-installed .so in a fresh php process (it loads the
		// extension from php.ini). A wrong-ABI/broken .so fails HERE, before we
		// touch the running php-fpm workers — so we can roll back cleanly.
		if (!$this->loads()) {
			if ($rHadOld && is_file($rBackup)) {
				@rename($rBackup, $rSo); // rollback
			} else {
				@unlink($rSo);
			}
			return false;
		}
		@unlink($rBackup);

		@file_put_contents($rVerFile, $rVersion . "\n");
		@chown($rVerFile, 'xc_vm');
		@chgrp($rVerFile, 'xc_vm');

		// Reload php-fpm so its workers pick up the new extension (match the master
		// process, not a cmdline, to avoid self-matching this command's shell).
		shell_exec("pkill -USR2 -u xc_vm -f 'php-fpm: master' 2>/dev/null");
		return true;
	}

	/** True if a fresh php (as xc_vm) loads xcvm_core and it is the new API build. */
	private function loads(): bool {
		$rBin = defined('PHP_BIN') ? PHP_BIN : (BIN_PATH . 'php/bin/php');
		$rCheck = 'echo (extension_loaded("xcvm_core") && method_exists("XC_VM","config_set_redis")) ? "OK" : "NO";';
		$rOut = trim((string) shell_exec('sudo -u xc_vm ' . escapeshellarg($rBin) . ' -r ' . escapeshellarg($rCheck) . ' 2>/dev/null'));
		return $rOut === 'OK';
	}

	private function fetchLatestVersion(string $rUrl): ?string {
		$rBody = $this->fetch($rUrl);
		if ($rBody === null) {
			return null;
		}
		$rData = json_decode($rBody, true);
		$rVer = is_array($rData) ? ($rData['version'] ?? null) : null;
		return (is_string($rVer) && $rVer !== '') ? trim($rVer) : null;
	}

	/** Expected sha256 for $rAsset from a SHA256SUMS body (`<hash>  <name>`). */
	private function shaFor(string $rSums, string $rAsset): ?string {
		foreach (explode("\n", $rSums) as $rLine) {
			$rParts = preg_split('/\s+/', trim($rLine), 2);
			if (count($rParts) === 2 && ltrim(trim($rParts[1]), '*./') === $rAsset) {
				return strtolower(trim($rParts[0]));
			}
		}
		return null;
	}

	/** GET a URL into a string (following redirects), or null on any non-2xx. */
	private function fetch(string $rUrl): ?string {
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
		return (is_string($rBody) && $rCode >= 200 && $rCode < 300) ? $rBody : null;
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
}
