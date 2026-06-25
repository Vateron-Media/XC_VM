<?php

namespace XcVm\Cli\CronJobs;
use XcVm\Cli\CronTrait;
use XcVm\Cli\CommandInterface;

use XcVm\Core\Util\GeoIP;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Core\GeoIP\MaxMindUpdater;
use XcVm\Core\Config\SettingsManager;
require_once __DIR__ . '/../CronTrait.php';

/**
 * MaxMindCronJob — weekly GeoIP database update via MaxMind API or GitHub fallback.
 *
 * Runs every Tuesday (aligned to MaxMind's Tuesday release schedule).
 * Uses MaxMind API when credentials are configured in panel settings.
 * Falls back to GitHub GeoLite2 releases when credentials are missing.
 */
class MaxMindCronJob implements CommandInterface {
	use CronTrait;

	public function getName(): string {
		return 'cron:maxmind';
	}

	public function getDescription(): string {
		return 'Cron: weekly MaxMind GeoIP database update';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsRoot()) {
			return 1;
		}

		// Only run on Tuesdays (MaxMind publishes updates on Tuesdays)
		if (date('N') !== '2' && !in_array('--force', $rArgs)) {
			echo "Skipping MaxMind update: not Tuesday (use --force to override)\n";
			return 0;
		}

		global $db;
		register_shutdown_function(function () use ($db) {
			if (is_object($db)) {
				$db->close_mysql();
			}
		});

		$geolitejsonFile = '/home/xc_vm/bin/maxmind/version.json';
		$anyError = false;
		$rSettings = SettingsManager::getAll();
		$updater   = MaxMindUpdater::fromSettings($rSettings);

		if ($updater !== null) {
			echo 'Updating MaxMind databases...' . "\n";
			$results = $updater->update();

			foreach ($results as $r) {
				if ($r['error'] !== null) {
					echo '[ERROR] ' . $r['edition'] . ': ' . $r['error'] . "\n";
					$anyError = true;
				} elseif ($r['updated']) {
					echo '[OK]    ' . $r['edition'] . ': updated' . "\n";
				} else {
					echo '[SKIP]  ' . $r['edition'] . ': already up to date' . "\n";
				}
			}
		} else {
			echo "MaxMind credentials not configured — using GitHub GeoLite2 fallback.\n";
			$repo = new GitHubReleases(GIT_OWNER, GIT_REPO_UPDATE, $rSettings['update_channel']);
			$datageolite = $repo->getGeolite();
			if (is_array($datageolite)) {
				foreach ($datageolite['files'] as $rFile) {
					if (!file_exists($rFile['path']) || md5_file($rFile['path']) != $rFile['md5']) {
						$rFolderPath = pathinfo($rFile['path'])['dirname'] . '/';

						if (!file_exists($rFolderPath)) {
							shell_exec('sudo mkdir -p "' . $rFolderPath . '"');
						}

						$ch = curl_init();
						curl_setopt($ch, CURLOPT_URL, $rFile['fileurl']);
						curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
						curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
						curl_setopt($ch, CURLOPT_TIMEOUT, 300);
						curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
						curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
						$rData = curl_exec($ch);
						if ($rData !== false) {
							$rMD5 = md5($rData);
						}
						curl_close($ch);

						if ($rData === false) {
							echo '[ERROR] ' . $rFile['path'] . ': download failed' . "\n";
							$anyError = true;
						} elseif ($rFile['md5'] == $rMD5) {
							echo '[OK]    ' . $rFile['path'] . ': updated' . "\n";
							file_put_contents($rFile['path'], $rData);

							chown($rFile['path'], 'xc_vm');
							chmod($rFile['path'], 0750);
						} else {
							echo '[ERROR] ' . $rFile['path'] . ': checksum mismatch' . "\n";
							$anyError = true;
						}
					} else {
						echo '[SKIP]  ' . $rFile['path'] . ': already up to date' . "\n";
					}
				}

				$jsonData = file_get_contents($geolitejsonFile);
				$data = json_decode($jsonData, true);

				if (isset($data['geolite2_version'])) {
					$data['geolite2_version'] = $datageolite['version'];
					file_put_contents($geolitejsonFile, json_encode($data, JSON_PRETTY_PRINT));
				}
			} else {
				echo "[ERROR] GeoLite2: release metadata unavailable\n";
				$anyError = true;
			}
		}

		return $anyError ? 1 : 0;
	}
}
