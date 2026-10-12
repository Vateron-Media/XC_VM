<?php

namespace XcVm\Cli\Commands;

use XcVm\Cli\CommandInterface;
use XcVm\Core\Http\CurlClient;
use XcVm\Core\Process\ProcessRunner;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\Updates\ReleaseAsset;
use XcVm\Core\Updates\UpdateChannels;

/**
 * FfmpegBuildsCommand — install/update the ffmpeg builds from XC_VM_FFMPEG releases.
 *
 * One build per label (LABELS, the versions the settings page offers) and per
 * distribution: `ffmpeg_<label>_<distro>.tar.gz` (ffmpeg, ffprobe, BUILD_INFO),
 * built in that distribution's container, so its glibc is the node's (the bundled
 * 8.0 needs glibc 2.35 and does not start on Ubuntu 20.04 or Debian 11). Each is
 * checked against the release's hashes.md5, extracted aside, and put in place as
 * `bin/ffmpeg_bin/<label>/` only once both binaries start here; the build it
 * replaces stays until then (last-known-good). `ffmpeg_bin/ffmpeg_version.json`
 * records each label's release and archive md5, so an unchanged release is not
 * fetched again.
 *
 * A folder name is a build id, and settings store it. `<major>.<minor>` is ours
 * and names the build's real minor version; `<major>.<minor>-<vendor>` is someone
 * else's (XUI's `4.0` included) and never touched. Whether a build takes
 * `-nofix_dts` is asked of the binary (FfmpegPaths::fixDts). A setting naming a
 * build the node lacks (4.0, 8.0) is resolved to the closest one there
 * (FfmpegPaths), so none needs rewriting.
 *
 * Runs as xc_vm, which owns bin/ffmpeg_bin/; cron:root_signals runs it daily on
 * every node. A run that finished touches the index (finished()), so the cron
 * tries one that did not (killed by a service restart, GitHub unreachable, a
 * build that failed) again within the hour rather than the next day.
 * Usage: `console.php ffmpeg [force]` (`force` refetches every label).
 *
 * @package XC_VM_CLI_Commands
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class FfmpegBuildsCommand implements CommandInterface {
	/** The builds fetched for each node: the versions the settings page offers, each the minor version its build really is. */
	public const LABELS = ['4.4', '7.1', '8.1'];

	/** Under ffmpeg_bin/: each label's release and archive md5. */
	public const INDEX = 'ffmpeg_version.json';

	/** Beside a build this command installed (XUI's 4.0 has none). */
	public const BUILD_INFO = 'BUILD_INFO';

	private ?string $rBase;

	/** This run's hash lookups, and how many found a hash. */
	private int $rLookups = 0;

	private int $rFound = 0;

	/** @param string|null $rBase Another ffmpeg_bin/ (tests). */
	public function __construct(?string $rBase = null) {
		$this->rBase = $rBase;
	}

	public function getName(): string {
		return 'ffmpeg';
	}

	public function getDescription(): string {
		return 'Install/update the ffmpeg builds for this distribution from XC_VM_FFMPEG';
	}

	public function execute(array $rArgs): int {
		if ($this->rBase === null && (posix_getpwuid(posix_geteuid())['name'] ?? '') !== 'xc_vm') {
			echo "Please run as xc_vm (sudo -u xc_vm console.php ffmpeg).\n";
			return 1;
		}
		$this->sweep();
		$rOs = @parse_ini_file('/etc/os-release') ?: [];
		$rDistro = self::distro(strtolower((string) ($rOs['ID'] ?? '')), (string) ($rOs['VERSION_ID'] ?? ''));
		if ($rDistro === null) {
			echo 'No ffmpeg builds for this distribution (' . ($rOs['ID'] ?? '?') . ' ' . ($rOs['VERSION_ID'] ?? '?') . "): the installed ones are kept.\n";
			return $this->finished();
		}
		$rForce = in_array('force', $rArgs, true);
		$rRepo = new GitHubReleases(GIT_OWNER, GIT_REPO_FFMPEG, UpdateChannels::forRepo(GIT_REPO_FFMPEG));
		try {
			if ($rForce) {
				$rRepo->clearCache();
			}
			$rLatest = (string) ($rRepo->getReleases()[0] ?? '');
		} catch (\Throwable $rE) {
			echo 'Cannot check the ffmpeg releases: ' . $rE->getMessage() . "\n";
			return 1;
		}
		if (!GitHubReleases::isValidVersion($rLatest)) {
			echo "No ffmpeg release yet: the installed builds are kept.\n";
			return $this->finished();
		}
		$rFailed = 0;
		foreach (self::LABELS as $rLabel) {
			$rFailed += $this->label($rRepo, $rLatest, $rLabel, $rDistro, $rForce) ? 0 : 1;
		}
		// Every hash looked up came back empty: the release's hashes.md5 could
		// not be read (a supported distribution has a build of each label), so
		// this run is not finished and is tried again within the hour.
		if ($this->rLookups > 0 && $this->rFound === 0) {
			echo "Cannot read release {$rLatest}'s hashes.md5: tried again later.\n";
			return 1;
		}
		return $rFailed === 0 ? $this->finished() : 1;
	}

	/** Index of the last run that finished: cron:root_signals reads its mtime (its retry). */
	public static function indexPath(?string $rBase = null): string {
		return rtrim($rBase ?? (BIN_PATH . 'ffmpeg_bin/'), '/') . '/' . self::INDEX;
	}

	/** Mark this run finished (the index's mtime); 0. */
	private function finished(): int {
		@touch(self::indexPath($this->rBase));
		return 0;
	}

	/** This distribution's name in the release's assets (`ubuntu_22`, `debian_12`, …), as the runtime bundle names it; null for one it has none for. */
	public static function distro(string $rId, string $rVersion): ?string {
		$rBundle = ReleaseAsset::bundleFor($rId, $rVersion);
		return $rBundle === null ? null : substr($rBundle, 0, -strlen('.tar.gz'));
	}

	/** $rLabel at release $rVersion: true when it is in place (or kept on purpose), false when it should be and is not. */
	private function label(GitHubReleases $rRepo, string $rVersion, string $rLabel, string $rDistro, bool $rForce): bool {
		$rDir = $this->base() . $rLabel . '/';
		$rIndex = $this->index();
		if (!$rForce && ($rIndex[$rLabel]['version'] ?? null) === $rVersion && is_file($rDir . 'ffmpeg') && is_file($rDir . self::BUILD_INFO)) {
			echo "[SKIP]  {$rLabel}: up to date ({$rVersion}).\n";
			return true;
		}
		$rAsset = 'ffmpeg_' . $rLabel . '_' . $rDistro . '.tar.gz';
		$rMd5 = $rRepo->getAssetHash($rVersion, $rAsset);
		$this->rLookups++;
		if ($rMd5 === null || !preg_match('/^[0-9a-f]{32}$/', $rMd5)) {
			echo "[SKIP]  {$rLabel}: release {$rVersion} has no {$rAsset}.\n";
			return true;
		}
		$this->rFound++;
		$rWhy = $this->install($rRepo->assetUrl($rVersion, $rAsset), $rMd5, $rLabel);
		if ($rWhy !== null) {
			echo "[ERROR] {$rLabel}: {$rWhy}" . (is_file($rDir . 'ffmpeg') ? '; the installed build is kept' : '') . ".\n";
			return false;
		}
		$rIndex[$rLabel] = ['version' => $rVersion, 'md5' => $rMd5];
		@file_put_contents(self::indexPath($this->rBase), json_encode($rIndex, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
		echo "[OK]    {$rLabel}: {$rAsset} installed ({$rVersion}).\n";
		return true;
	}

	/** Download and check the archive, then place() it. Null once installed, else why not. */
	private function install(string $rUrl, string $rMd5, string $rLabel): ?string {
		$rBase = $this->base();
		if (!is_dir($rBase) && !@mkdir($rBase, 0755, true)) {
			return 'cannot create ' . $rBase;
		}
		$rTmp = @tempnam($rBase, '.ffmpeg_dl_');
		if ($rTmp === false) {
			return 'cannot write in ' . $rBase;
		}
		try {
			CurlClient::downloadToFile($rUrl, $rTmp);
			// The release ships only hashes.md5, fetched from that release over the same HTTPS: a check against a corrupt or truncated download, not a signature.
			// nosemgrep: php.lang.security.weak-crypto.weak-crypto
			return hash_equals($rMd5, (string) md5_file($rTmp)) ? $this->place($rTmp, $rLabel) : 'checksum mismatch';
		} catch (\Throwable $rE) {
			return 'download failed: ' . $rE->getMessage();
		} finally {
			@unlink($rTmp);
		}
	}

	/**
	 * Put the build in $rArchive (checked) in place as $rLabel: extracted aside,
	 * both binaries run here, then swapped in for the installed one, which stays
	 * when anything fails. Null once installed, else why not.
	 */
	public function place(string $rArchive, string $rLabel): ?string {
		$rBase = $this->base();
		$rNew = $rBase . '.' . $rLabel . '.new.' . bin2hex(random_bytes(4)) . '/';
		$rOld = $rBase . '.' . $rLabel . '.old.' . bin2hex(random_bytes(4));
		try {
			if (!@mkdir($rNew, 0755)) {
				return 'cannot create ' . $rNew;
			}
			// Only the three names a build has, whatever else the archive holds.
			if (self::run(['tar', '-xzf', $rArchive, '-C', $rNew, '--no-same-owner', '--no-same-permissions', 'ffmpeg', 'ffprobe', self::BUILD_INFO]) !== 0) {
				return 'the archive does not hold ffmpeg, ffprobe and BUILD_INFO';
			}

			foreach (['ffmpeg', 'ffprobe'] as $rName) {
				@chmod($rNew . $rName, 0755);
				// Built for this distribution's glibc: it must start here.
				if (self::run([$rNew . $rName, '-hide_banner', '-version']) !== 0) {
					return $rName . ' does not run on this node';
				}
			}
			// The binary must be the version its label names (asked, not read from BUILD_INFO): a 4.4.5 never again filed as 4.0.
			[, $rBanner] = ProcessRunner::capture([$rNew . 'ffmpeg', '-hide_banner', '-version']);
			if (!preg_match('/^ffmpeg version n?' . preg_quote($rLabel, '/') . '(?![0-9])/', $rBanner)) {
				return 'its ffmpeg is not FFmpeg ' . $rLabel;
			}
			$rDir = $rBase . $rLabel;
			if (is_dir($rDir) && !@rename($rDir, $rOld)) {
				return 'cannot move the installed build aside';
			}
			if (!@rename(rtrim($rNew, '/'), $rDir)) {
				@rename($rOld, $rDir);
				return 'cannot put the new build in place';
			}
			return null;
		} finally {
			foreach ([rtrim($rNew, '/'), $rOld] as $rLeft) {
				if (is_dir($rLeft)) {
					self::run(['rm', '-rf', '--', $rLeft]);
				}
			}
		}
	}

	/** What a killed place() left aside (.<label>.old.*, .new.*), once it is an hour old: never a run still going. */
	private function sweep(): void {
		foreach (glob($this->base() . '.*.{old,new}.*', GLOB_BRACE) ?: [] as $rLeft) {
			$rStat = @lstat($rLeft);
			if ($rStat === false || $rStat['ctime'] > time() - 3600) {
				continue;
			}
			if (is_link($rLeft) || is_file($rLeft)) {
				@unlink($rLeft);
			} else {
				self::run(['rm', '-rf', '--', $rLeft]);
			}
		}
	}

	/** An argv list, no shell: its exit status. */
	private static function run(array $rArgv): int {
		// nosemgrep: php.lang.security.exec-use.exec-use
		$rProc = @proc_open($rArgv, [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $rPipes);
		return is_resource($rProc) ? proc_close($rProc) : -1;
	}

	private function base(): string {
		return rtrim($this->rBase ?? (BIN_PATH . 'ffmpeg_bin/'), '/') . '/';
	}

	/** @return array<string, array{version?: string, md5?: string}> */
	private function index(): array {
		$rData = json_decode((string) @file_get_contents(self::indexPath($this->rBase)), true);
		return is_array($rData) ? $rData : [];
	}
}
