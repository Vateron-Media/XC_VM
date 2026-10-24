<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Cluster\Crypto\ClusterCryptoFactory;
use XcVm\Core\Config\TreeOwnership;
use XcVm\Core\Updates\UpdateChannels;

/**
 * XcvmCoreCommand — install/update the `xcvm_core` PHP extension.
 *
 * The extension is built in a private repo and mirrored — decoupled from the
 * heavy per-distro runtime bundle — into the PUBLIC binaries repo tree at fixed
 * paths under `bin/xcvm_extention/` (the repo's spelling):
 *   - `xcvm_core-php8.3.tar.gz` / `xcvm_core-php8.4.tar.gz` — the `.so` built per
 *     PHP minor version, since an extension only loads into the ABI it was
 *     compiled against;
 *   - `version.json` — the single source of truth for the current version;
 *   - `SHA256SUMS` — integrity hashes of the group archives, and the list this
 *     command reads the available groups from.
 *
 * This command is the panel-side installer/updater, modelled on
 * {@see FanoutBinaryCommand}: it reads the latest version from `version.json`,
 * compares it to the version the loaded extension reports, and when they
 * differ downloads the archive matching this host's PHP version, verifies its
 * SHA-256, and installs the `.so` **atomically with a backup + load-test +
 * rollback** — a wrong-ABI or broken extension must never take php-fpm down.
 * php-fpm is then reloaded (USR2) so workers pick up the new extension; the CLI
 * already loads it on the next invocation.
 *
 * Unlike the daemon (release assets), the extension lives in the repo *tree*, so
 * it is fetched raw from the default branch (rolling latest at a fixed path).
 *
 * Cluster API pin: an update never replaces an extension whose cluster API
 * this panel speaks (ClusterCryptoFactory::API_MIN..API_MAX) with one whose API
 * it does not. The new `.so` is load-tested for that too, and rolled back.
 * Versioned download paths, to install exactly a pinned version, are a
 * prerequisite still open on the binaries repo.
 *
 * Usage: `console.php xcvm_core` (add `force` to reinstall the same version),
 * `console.php xcvm_core status` (what is loaded, and its cluster API state).
 * Every node, MAIN included and whatever its cluster mode, keeps it current
 * this way (hourly from cron:root_signals): MAIN never hands it out.
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
	private const EXT_SUBPATH = 'bin/xcvm_extention';

	public function getName(): string {
		return 'xcvm_core';
	}

	public function getDescription(): string {
		return 'Install/update the xcvm_core PHP extension from the binaries repo';
	}

	public function execute(array $rArgs): int {
		if (in_array('status', $rArgs, true)) {
			return $this->status();
		}
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}
		$rForce = in_array('force', $rArgs, true);

		// The extension tree is committed per branch of the binaries repo, so the
		// per-repository BIN channel maps to a branch: stable → the default branch,
		// beta → the `beta` branch. The beta branch may not exist, so fall back to
		// the stable branch rather than failing the update outright.
		$rBranch = UpdateChannels::bin() === 'beta' ? 'beta' : self::BIN_BRANCH;
		$rBase = $this->rawBase($rBranch);

		$rLatest = $this->fetchLatestVersion($rBase . 'version.json');
		if ($rLatest === null && $rBranch !== self::BIN_BRANCH) {
			echo "xcvm_core: '{$rBranch}' branch unavailable — falling back to '" . self::BIN_BRANCH . "'.\n";
			$rBranch = self::BIN_BRANCH;
			$rBase = $this->rawBase($rBranch);
			$rLatest = $this->fetchLatestVersion($rBase . 'version.json');
		}
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
		// Ask the loaded extension itself rather than a sidecar marker file. A
		// marker outlives the .so it described — a failed install that rolled
		// back, or a hand-copied .so — and the panel then believes it is running
		// a version it is not. phpversion() reports what is actually loaded.
		$rInstalled = phpversion('xcvm_core') ?: null;

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

		// Try the build matching this PHP first; if its .so fails to load on this
		// host, fall through to the remaining groups.
		foreach ($this->groupCandidates($rSums) as $rGroup) {
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

			$rOk = $this->installFromTarball($rTmp, $rSo);
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
	 * Available asset groups, the one matching this PHP first.
	 *
	 * The groups are read out of SHA256SUMS rather than hardcoded, so the day the
	 * binaries repo adds another PHP minor this keeps resolving without a panel
	 * release. An extension only loads into the ABI it was compiled against, so
	 * the exact match for the interpreter running this command (the bundled PHP)
	 * goes first; the rest follow newest-first and the load-test in execute()
	 * rejects a wrong guess.
	 *
	 * @param string $rSums Contents of the SHA256SUMS file.
	 * @return string[]
	 */
	private function groupCandidates(string $rSums): array {
		preg_match_all('/xcvm_core-(\S+)\.tar\.gz/', $rSums, $rMatches);
		$rGroups = array_unique($rMatches[1]);
		natsort($rGroups);
		$rGroups = array_reverse(array_values($rGroups));

		// When the repo ships the build for this exact PHP minor, it is the ONLY
		// candidate: a .so cannot load into a different minor, so falling back to
		// one would churn the installed extension through a swap and a rollback
		// and still fail. A transient download hiccup is better reported as such,
		// and fixed by re-running, than papered over with an impossible install.
		$rWanted = 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
		if (in_array($rWanted, $rGroups, true)) {
			return [$rWanted];
		}

		// No build for this minor — try newest-first and let the load-test judge.
		return $rGroups;
	}

	/**
	 * Extract the .so from the archive, install it atomically over the current
	 * one (keeping a backup), load-test it in a fresh php, and roll back on
	 * failure. Only on success is the version marker written and php-fpm reloaded.
	 */
	private function installFromTarball(string $rTarball, string $rSo): bool {
		$rStage = $rSo . '.new';
		@unlink($rStage);
		shell_exec('tar xzf ' . escapeshellarg($rTarball) . ' -O ./xcvm_core.so > ' . escapeshellarg($rStage) . ' 2>/dev/null');
		if (!is_file($rStage) || filesize($rStage) < 1000) {
			@unlink($rStage);
			return false;
		}
		@chmod($rStage, 0555);
		// PHP loads it for root's commands too: root's on MAIN (TreeOwnership::ROOT_OWNED).
		@chown($rStage, TreeOwnership::owner('bin/php/lib/xcvm_core.so'));
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
		// touch the running php-fpm workers — so we can roll back cleanly. So does
		// one that would drop the cluster API this panel speaks.
		$rApiBefore = $this->clusterApi();
		if (!$this->loads() || !self::clusterApiKept($rApiBefore, $this->clusterApi())) {
			if ($rHadOld && is_file($rBackup)) {
				@rename($rBackup, $rSo); // rollback
			} else {
				@unlink($rSo);
			}
			return false;
		}
		@unlink($rBackup);

		// Reload php-fpm so its workers pick up the new extension (match the master
		// process, not a cmdline, to avoid self-matching this command's shell).
		shell_exec("pkill -USR2 -u xc_vm -f 'php-fpm: master' 2>/dev/null");
		return true;
	}

	/** True if a fresh php (as xc_vm) loads xcvm_core and it is the new API build. */

	/** This PHP's group (php8.3, php8.4, …). */
	public static function group(): string {
		return 'php' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION;
	}

	/**
	 * Would swapping an extension with cluster API $rBefore for one with $rAfter
	 * lose the API this panel speaks? (0 = no cluster API.) Keeping an
	 * out-of-range or absent API as it was is allowed: nothing worked before.
	 */
	public static function clusterApiKept(int $rBefore, int $rAfter): bool {
		$rInRange = static fn(int $rApi) => $rApi >= ClusterCryptoFactory::API_MIN && $rApi <= ClusterCryptoFactory::API_MAX;
		return !$rInRange($rBefore) || $rInRange($rAfter);
	}

	/** The cluster API version of the extension a fresh php loads (0 = none). */
	private function clusterApi(): int {
		$rBin = defined('PHP_BIN') ? PHP_BIN : (BIN_PATH . 'php/bin/php');
		$rCheck = 'echo (class_exists("XC_VM") && method_exists("XC_VM","cluster_info")) ? intval(XC_VM::cluster_info()["api"] ?? 0) : 0;';
		return intval(trim((string) shell_exec('sudo -u xc_vm ' . escapeshellarg($rBin) . ' -r ' . escapeshellarg($rCheck) . ' 2>/dev/null')));
	}

	/** `console.php xcvm_core status`: the loaded extension and its cluster API. */
	private function status(): int {
		$rStatus = ClusterCryptoFactory::status();
		echo 'xcvm_core: ' . (phpversion('xcvm_core') ?: 'not loaded') . "\n";
		echo 'cluster API: ' . ($rStatus['api'] ?? 'none') . ' (panel speaks ' . $rStatus['range'] . ') -> ' . ($rStatus['available'] ? 'usable' : 'unavailable: ' . $rStatus['reason']) . "\n";
		if ($rStatus['available']) {
			$rInfo = \XC_VM::cluster_info();
			echo 'root: ' . (!empty($rInfo['initialised']) ? 'initialised, fingerprint ' . bin2hex((string) ($rInfo['panel_fp'] ?? '')) : 'not initialised (' . ($rInfo['root_error'] ?? '') . ')') . "\n";
			echo 'licence binding: ' . (!empty($rInfo['licensed']) ? 'kid ' . $rInfo['kid'] : 'none (token issue refused)') . "\n";
			echo 'clock: ' . (!empty($rInfo['clock_ok']) ? 'ok' : 'ROLLED BACK - cluster calls refuse') . "\n";
		}
		return $rStatus['available'] ? 0 : 2;
	}

	private function loads(): bool {
		$rBin = defined('PHP_BIN') ? PHP_BIN : (BIN_PATH . 'php/bin/php');
		$rCheck = 'echo (extension_loaded("xcvm_core") && method_exists("XC_VM","config_set_redis")) ? "OK" : "NO";';
		$rOut = trim((string) shell_exec('sudo -u xc_vm ' . escapeshellarg($rBin) . ' -r ' . escapeshellarg($rCheck) . ' 2>/dev/null'));
		return $rOut === 'OK';
	}

	/** Raw-content base URL for the extension tree on a given binaries-repo branch. */
	private function rawBase(string $rBranch): string {
		return 'https://raw.githubusercontent.com/' . GIT_OWNER . '/' . GIT_REPO_BIN
			. '/' . $rBranch . '/' . self::EXT_SUBPATH . '/';
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

	/**
	 * Download a URL to a file (following redirects).
	 * Fetch $rUrl to $rDest. A transient failure is reported, not retried — the
	 * command is idempotent, so re-running it is the recovery.
	 */
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
