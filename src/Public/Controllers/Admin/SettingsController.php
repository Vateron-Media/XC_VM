<?php

namespace XcVm\Public\Controllers\Admin;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Http\Response;
use XcVm\Core\Localization\Translator;
use XcVm\Domain\Cluster\ClusterOverview;
use XcVm\Domain\Stream\StreamConfigRepository;
use XcVm\Streaming\Codec\FfmpegBinaries;

/**
 * SettingsController — General Settings (admin/settings.php).
 *
 * GET /settings
 * Massive multi-tab form with ~80+ switchery toggles, select2 dropdowns,
 * 9 tabs (General, Security, API, Streaming, MAG, Web Player, Logs, Info, Database).
 *
 * @renders Views/admin/settings.php
 *
 * @package XC_VM_Public_Controllers_Admin
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class SettingsController extends BaseAdminController {
	public function index(): void {
		$this->requirePermission();
		Response::noCache();

		$rSettings = SettingsManager::getAll();
		$rStreamArguments = StreamConfigRepository::getStreamArguments();

		$versionData = json_decode(@file_get_contents(BIN_PATH . 'maxmind/version.json'), true) ?: [];
		$GeoLite2 = $versionData['geolite2_version'] ?? 'N/A';
		$GeoISP   = $versionData['geoisp_version'] ?? 'N/A';
		$Nginx    = trim(shell_exec(BIN_PATH . 'nginx/sbin/nginx -v 2>&1 | cut -d\'/\' -f2') ?: '');
		$rUpdate  = json_decode((string) ($rSettings['update_data'] ?? ''), true) ?: [];

		$binVersionData = json_decode(@file_get_contents(BIN_PATH . 'bin_version.json'), true) ?: [];
		$BinVersion = $binVersionData['release'] ?? 'N/A';
		$BinOS = $this->resolveOsLabel($binVersionData);

		// xc_fanout daemon (`-version`), xcvm_core extension marker and yt-dlp — Info tab.
		$rFanoutBin = BIN_PATH . 'xc_fanout/xc_fanout';
		$FanoutVersion = (is_file($rFanoutBin) && is_executable($rFanoutBin))
			? (ltrim(trim((string) shell_exec(escapeshellarg($rFanoutBin) . ' -version 2>/dev/null')), 'vV') ?: 'N/A')
			: 'N/A';

		// Report what this worker actually loaded. The old sidecar marker file took
		// priority here, which had it backwards: the marker can outlive the .so it
		// described (a failed install that rolled back), and the page then showed a
		// version the panel is not running.
		$XcvmCoreVersion = phpversion('xcvm_core') ?: 'N/A';

		$YtDlpVersion = (is_file(YOUTUBE_BIN) && is_executable(YOUTUBE_BIN))
			? (trim((string) shell_exec(escapeshellarg(YOUTUBE_BIN) . ' --version 2>/dev/null')) ?: 'N/A')
			: 'N/A';

		$License = $this->licenseInfo();

		$this->setTitle('Settings');
		$this->render('settings', ['rSettings' => $rSettings, 'rStreamArguments' => $rStreamArguments, 'GeoLite2' => $GeoLite2, 'GeoISP' => $GeoISP, 'Nginx' => $Nginx, 'BinVersion' => $BinVersion, 'BinOS' => $BinOS, 'rUpdate' => $rUpdate, 'FanoutVersion' => $FanoutVersion, 'XcvmCoreVersion' => $XcvmCoreVersion, 'YtDlpVersion' => $YtDlpVersion, 'License' => $License, 'clusterInfo' => ClusterOverview::infoRows($rSettings)] + $this->ffmpegBuilds($rSettings));
	}

	/**
	 * The FFmpeg builds for the version selects and the builds table: what this
	 * node has, plus a stored id it has no build of (an older 4.0 or 8.0, or one
	 * missing here), which stays selectable.
	 *
	 * @return array{rFfmpegBuilds: array, rFfmpegGpuBuilds: array, rFfmpegLabels: array<string, string>, rCpuOpts: list<string>, rGpuOpts: list<string>}
	 */
	private function ffmpegBuilds(array $rSettings): array {
		$rBuilds = FfmpegBinaries::available();
		// Newest first by the version the binary reports, not the folder name (4.0 holds 4.4.5).
		$rVersion = static fn(string $rId): string => preg_match('/^ffmpeg version n?(\d+(?:\.\d+)*)/', (string) ($rBuilds[$rId]['banner'] ?? ''), $rM) ? $rM[1] : $rId;
		uksort($rBuilds, static fn($a, $b): int => version_compare($rVersion((string) $b), $rVersion((string) $a)));
		$rGpuBuilds = FfmpegBinaries::gpuCapable();
		$rCpuOpts = array_keys($rBuilds);
		$rGpuOpts = array_keys($rGpuBuilds);
		$rCpu = (string) ($rSettings['ffmpeg_cpu'] ?? '');
		if ($rCpu !== '' && !in_array($rCpu, $rCpuOpts, true)) {
			$rCpuOpts[] = $rCpu;
		}
		$rGpu = (string) ($rSettings['ffmpeg_gpu'] ?? '');
		if ($rGpu !== '' && !in_array($rGpu, $rGpuOpts, true)) {
			$rGpuOpts[] = $rGpu;
		}
		$rLabels = [];
		foreach (array_unique([...$rCpuOpts, ...$rGpuOpts]) as $rId) {
			$rLabels[$rId] = self::ffmpegLabel((string) $rId, $rBuilds[$rId]['banner'] ?? null);
		}
		return ['rFfmpegBuilds' => $rBuilds, 'rFfmpegGpuBuilds' => $rGpuBuilds, 'rFfmpegLabels' => $rLabels, 'rCpuOpts' => $rCpuOpts, 'rGpuOpts' => $rGpuOpts];
	}

	/**
	 * A build labelled by what its binary says, not by its folder: the version as
	 * `ffmpeg -version` prints it ("ffmpeg version 4.4.5-XCVM" reads "v4.4.5-XCVM",
	 * a distro's "6.1.1-3ubuntu5" stays as is, no guessing which part is a
	 * builder). A version with no suffix of its own takes the folder's (4.0-xui
	 * reads "v4.0 (XUI)"); a banner with no version keeps the folder id. An id
	 * with no build of its own ($rBanner null) is "auto". Plain text: the view
	 * escapes it.
	 */
	public static function ffmpegLabel(string $rId, ?string $rBanner): string {
		if ($rBanner === null) {
			return 'v' . $rId . ' (' . Translator::get('ffmpeg_build_auto') . ')';
		}
		if (!preg_match('/^ffmpeg version n?(\d\S*)/', $rBanner, $rM)) {
			return 'v' . $rId;
		}
		$rFolder = preg_match('/-([a-z0-9]+)$/', $rId, $rSuffix) && !str_contains($rM[1], '-') ? ' (' . strtoupper($rSuffix[1]) . ')' : '';
		return 'v' . $rM[1] . $rFolder;
	}

	/**
	 * Licence status for the Settings → Info tab. Reads the compiled xcvm_core
	 * verdict. Fail-open to N/A where the extension or the licence methods are
	 * absent (dev/CI, or an older .so).
	 *
	 * @return array{status: string, hwid: string, key: string}
	 */
	private function licenseInfo(): array {
		if (!class_exists('XC_VM') || !method_exists('XC_VM', 'license_valid')) {
			return ['status' => 'N/A', 'hwid' => 'N/A', 'key' => 'N/A'];
		}

		return [
			'status' => \XC_VM::license_valid() ? 'Licensed' : 'Unlicensed',
			'hwid'   => (string) \XC_VM::install_id(),
			'key'    => is_file(MAIN_HOME . 'config/activation_key') ? 'Present' : 'None',
		];
	}

	/**
	 * Build the OS label for the Versions tab.
	 *
	 * Prefers the distribution recorded in bin_version.json (set by
	 * update_binaries.sh). When that metadata is missing or still holds the
	 * seed "unknown" placeholder, falls back to the running host's
	 * /etc/os-release so the tab never shows "unknown unknown".
	 *
	 * @param array<string,mixed> $binVersionData
	 */
	private function resolveOsLabel(array $binVersionData): string {
		$dist    = trim((string) ($binVersionData['distribution'] ?? ''));
		$version = trim((string) ($binVersionData['distribution_version'] ?? ''));

		if ($dist !== '' && strcasecmp($dist, 'unknown') !== 0) {
			return ucfirst($dist) . ($version !== '' && strcasecmp($version, 'unknown') !== 0 ? ' ' . $version : '');
		}

		$osRelease = @parse_ini_file('/etc/os-release');
		if (is_array($osRelease)) {
			if (!empty($osRelease['PRETTY_NAME'])) {
				return (string) $osRelease['PRETTY_NAME'];
			}
			$label = trim(($osRelease['NAME'] ?? '') . ' ' . ($osRelease['VERSION_ID'] ?? ''));
			if ($label !== '') {
				return $label;
			}
		}

		return 'N/A';
	}
}
