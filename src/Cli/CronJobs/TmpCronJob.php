<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Config\SettingsManager;

/**
 * TmpCronJob — tmp cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class TmpCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:tmp';
	}

	public function getDescription(): string {
		return 'Cron: cleanup temporary files and stale playlists';
	}

	/**
	 * State with a life of its own, not leftovers: the seconds each may stay
	 * untouched. The ten-minute sweep cut them short.
	 */
	private const KEEP = [
		// The root cron's hourly and daily checks of the node's binaries
		// (RootSignalsCronJob): with their stamps swept, every check ran, and
		// asked GitHub, every ~11 minutes on every node.
		'fanout_binary_check' => 172800,
		'xcvm_core_check' => 172800,
		'ytdlp_check' => 172800,
		'ffmpeg_check' => 172800,
		// "At most once" for each change of the servers (replicaChecks).
		'replica_servers_checked' => 172800,
		// The updater's archive: post-update reads it again after the chown of
		// the whole tree, which can take longer. The updater removes it itself.
		'.update.tar.gz' => 86400,
	];

	/** Seconds a file in a swept directory may stay untouched before the sweep removes it. */
	public static function maxAge(string $rFile): int {
		return self::KEEP[$rFile] ?? 600;
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		global $db;
		$db->close_mysql();

		$this->setProcessTitle('XC_VM[TMP]');
		$this->acquireCronLock();

		$rTmpPaths = [
			TMP_PATH, CRONS_TMP_PATH, DIVERGENCE_TMP_PATH,
			FLOOD_TMP_PATH, MINISTRA_TMP_PATH, SIGNALS_TMP_PATH, LOGS_TMP_PATH
		];

		foreach ($rTmpPaths as $rTmpPath) {
			if (!is_dir($rTmpPath)) {
				@mkdir($rTmpPath, 0775, true);
				continue;
			}
			foreach (scandir($rTmpPath) as $rFile) {
				$fullPath = $rTmpPath . '/' . $rFile;
				if ($rFile === '.' || $rFile === '..') {
					continue;
				}
				if (is_file($fullPath) && time() - filemtime($fullPath) >= self::maxAge($rFile) && stripos($rFile, 'ministra_') === false) {
					unlink($fullPath);
				}
			}
		}

		foreach (scandir(PLAYLIST_PATH) as $rFile) {
			$fullPath = rtrim(PLAYLIST_PATH, '/') . '/' . $rFile;
			if ($rFile === '.' || $rFile === '..') {
				continue;
			}
			if (is_file($fullPath)) {
				if (SettingsManager::get('cache_playlists') <= time() - filemtime($fullPath)) {
					unlink($fullPath);
				}
			}
		}

		clearstatcache();
		@unlink($this->rIdentifier);

		return 0;
	}
}
