<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Updates\GitHubReleases;

/**
 * YtDlpCommand — install/update the bundled yt-dlp binary from upstream releases.
 *
 * yt-dlp resolves direct media URLs ({@see \XcVm\Core\Util\StreamUtils}); as a
 * static bundled binary it goes stale between panel releases and breaks
 * extraction. This is the panel-side updater, modelled on {@see FanoutBinaryCommand}:
 * it reads the installed version from the binary (`yt-dlp --version`), compares it
 * to the latest upstream release, and on a mismatch downloads the release asset,
 * verifies its SHA-256, run-tests it and installs it atomically.
 *
 * Source is upstream `yt-dlp/yt-dlp` (the python-zip `yt-dlp` asset — architecture
 * independent, needs a system python3). Polled ~daily by `cron:root_signals`
 * (stamp `ytdlp_check`), so no dedicated cron/crontab row is required.
 *
 * Usage: `console.php ytdlp` (add `force` to reinstall the same version).
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class YtDlpCommand implements CommandInterface {
	private const REPO_OWNER = 'yt-dlp';
	private const REPO_NAME  = 'yt-dlp';
	private const ASSET      = 'yt-dlp'; // python-zip variant (arch independent)

	public function getName(): string {
		return 'ytdlp';
	}

	public function getDescription(): string {
		return 'Install/update the yt-dlp binary from its upstream release';
	}

	public function execute(array $rArgs): int {
		if (posix_getpwuid(posix_geteuid())['name'] !== 'root') {
			echo "Please run as root!\n";
			return 1;
		}
		$rForce = in_array('force', $rArgs, true);

		$rBinary = YOUTUBE_BIN;
		$rDir = dirname($rBinary);
		if (!is_dir($rDir)) {
			echo "yt-dlp target dir {$rDir} missing — skipping\n";
			return 0;
		}
		$rInstalled = $this->installedVersion($rBinary);

		try {
			$rGit = new GitHubReleases(self::REPO_OWNER, self::REPO_NAME, 'stable');
			$rGit->setTimeout(20);
			$rReleases = $rGit->getReleases();
		} catch (\Exception $e) {
			echo 'Failed to check yt-dlp releases: ' . $e->getMessage() . "\n";
			return 1;
		}
		if (empty($rReleases[0])) {
			echo "Failed to resolve the latest yt-dlp release.\n";
			return 1;
		}
		$rTag = trim($rReleases[0]);
		$rLatest = ltrim($rTag, 'vV');

		if (!$rForce && $rInstalled !== null && $rInstalled === $rLatest) {
			echo "yt-dlp is up to date ({$rInstalled}).\n";
			return 0;
		}
		echo 'yt-dlp: installed=' . ($rInstalled ?? 'none') . ', latest=' . $rLatest . " → updating\n";

		$rBase = 'https://github.com/' . self::REPO_OWNER . '/' . self::REPO_NAME
			. '/releases/download/' . rawurlencode($rTag) . '/';
		$rTmp = $rDir . '/.yt-dlp.new';

		if (!$this->download($rBase . self::ASSET, $rTmp)) {
			echo 'Failed to download ' . self::ASSET . "\n";
			@unlink($rTmp);
			return 1;
		}

		// Upstream publishes checksums as SHA2-256SUMS (not SHA256SUMS).
		$rExpected = $this->expectedSha256($rBase . 'SHA2-256SUMS', self::ASSET);
		if ($rExpected === null) {
			echo "Failed to fetch SHA2-256SUMS\n";
			@unlink($rTmp);
			return 1;
		}
		if (!hash_equals($rExpected, hash_file('sha256', $rTmp))) {
			echo 'Checksum mismatch for ' . self::ASSET . " — aborting\n";
			@unlink($rTmp);
			return 1;
		}

		@chmod($rTmp, 0755);
		// Run-test: proves the fresh binary actually runs with the host python3
		// before we swap it in — a broken download must never replace a working one.
		$rNewVer = trim((string) shell_exec(escapeshellarg($rTmp) . ' --version 2>/dev/null'));
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
		@chmod($rBinary, 0755);

		echo "yt-dlp {$rNewVer} installed.\n";
		return 0;
	}

	/** Installed version straight from the binary, or null if absent/unrunnable. */
	private function installedVersion(string $rBinary): ?string {
		if (!is_file($rBinary) || !is_executable($rBinary)) {
			return null;
		}
		$rOut = trim((string) shell_exec(escapeshellarg($rBinary) . ' --version 2>/dev/null'));
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

	/** Expected sha256 for $rAsset from a SHA256SUMS-style file (`<hash>  <name>`). */
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
