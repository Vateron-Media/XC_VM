<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\GeoIP\AsnCatalogSync;
use XcVm\Core\GeoIP\MaxMindUpdater;
use XcVm\Core\Updates\GitHubReleases;
use XcVm\Domain\Server\ServerRepository;

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

		echo "GeoIP (MaxMind)\n------------------------------\n";

		// --force overrides both the Tuesday-only gate AND the "already up to
		// date" skip, re-downloading every database unconditionally.
		$force = in_array('--force', $rArgs);

		// Self-heal: the installer runs this with --force but swallows a failed
		// download (network/GitHub hiccup), which can leave the panel with no
		// GeoIP data. Without this, the absent database is not re-fetched until
		// the next Tuesday and portal.php fatals on the missing
		// GeoLite2-Country.mmdb. When the primary database is missing, run
		// regardless of the schedule; the download steps below only fetch the
		// files that are actually absent (a present database is skipped).
		$rMissing = !is_file('/home/xc_vm/bin/maxmind/GeoLite2-Country.mmdb');
		if ($rMissing) {
			echo "GeoLite2-Country.mmdb missing — running regardless of schedule.\n";
		}

		// Only run on Tuesdays (MaxMind publishes updates on Tuesdays)
		if (date('N') !== '2' && !$force && !$rMissing) {
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
			$results = $updater->update($force);

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
					if ($force || !file_exists($rFile['path']) || md5_file($rFile['path']) != $rFile['md5']) {
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

						if ($rData === false || $rData === '') {
							// Only a real network failure blocks the update.
							echo '[ERROR] ' . $rFile['path'] . ': download failed' . "\n";
							$anyError = true;
						} else {
							// GeoIP is non-critical and must download in any case:
							// hashes.md5 can time out (SSL), leaving the expected md5
							// empty, or lag a release. A successfully downloaded DB is
							// ALWAYS saved; the checksum only sets the status line.
							if (empty($rFile['md5'])) {
								echo '[WARN]  ' . $rFile['path'] . ': saved without checksum (hash unavailable)' . "\n";
							} elseif ($rFile['md5'] === $rMD5) {
								echo '[OK]    ' . $rFile['path'] . ': updated' . "\n";
							} else {
								echo '[WARN]  ' . $rFile['path'] . ': checksum mismatch — saved anyway' . "\n";
							}

							file_put_contents($rFile['path'], $rData);
							chown($rFile['path'], 'xc_vm');
							chmod($rFile['path'], 0750);
						}
					} else {
						echo '[SKIP]  ' . $rFile['path'] . ': already up to date' . "\n";
					}
				}

				$data = json_decode(@file_get_contents($geolitejsonFile), true) ?: [];
				$data['geolite2_version'] = $datageolite['version'];
				file_put_contents($geolitejsonFile, json_encode($data, JSON_PRETTY_PRINT));
			} else {
				echo "[ERROR] GeoLite2: release metadata unavailable\n";
				$anyError = true;
			}
		}

		// GeoIP2-ISP: the paid MaxMind edition is optional. Unless it is configured
		// (paid), keep the free self-built GeoIP2-ISP.mmdb from the release in sync so
		// ASN lookups work without a licence. Runs on every node (each needs the mmdb
		// locally); records geoisp_version in version.json.
		$rEditions = json_decode((string) ($rSettings['maxmind_editions'] ?? '[]'), true) ?: [];
		if (!($updater !== null && in_array('GeoIP2-ISP', $rEditions, true))) {
			$rIsp = (new GitHubReleases(GIT_OWNER, GIT_REPO_UPDATE, $rSettings['update_channel']))->getIspDatabase();
			if (is_array($rIsp) && !empty($rIsp['fileurl'])) {
				if ($this->fetchReleaseFile($rIsp, $force)) {
					$data = json_decode(@file_get_contents($geolitejsonFile), true) ?: [];
					$data['geoisp_version'] = $rIsp['version'];
					file_put_contents($geolitejsonFile, json_encode($data, JSON_PRETTY_PRINT));
					echo '[OK]    GeoIP2-ISP (free): updated' . "\n";
				} else {
					echo '[SKIP]  GeoIP2-ISP (free): already up to date' . "\n";
				}
			}
		}

		// ASN catalog: refresh blocked_asns from the release master file. MAIN only
		// (central table); non-critical — a failure never fails the GeoIP update.
		try {
			if (!empty(ServerRepository::getAll(true)[SERVER_ID]['is_main'])) {
				$rAsn = AsnCatalogSync::run($force);
				if (isset($rAsn['skipped'])) {
					echo '[SKIP]  ASN catalog: ' . $rAsn['skipped'] . "\n";
				} else {
					echo '[OK]    ASN catalog: ' . $rAsn['upserted'] . ' upserted, ' . $rAsn['removed'] . " pruned\n";
				}
			}
		} catch (\Throwable $e) {
			echo '[WARN]  ASN catalog sync failed: ' . $e->getMessage() . "\n";
		}

		return $anyError ? 1 : 0;
	}

	/**
	 * Download a single release asset (md5-gated) to its path. Returns true when a
	 * new file was written, false when skipped (unchanged) or on failure.
	 *
	 * @param array{fileurl: string, path: string, md5: ?string} $rMeta
	 */
	private function fetchReleaseFile(array $rMeta, bool $rForce): bool {
		$rPath = $rMeta['path'];
		if (!$rForce && is_file($rPath) && !empty($rMeta['md5']) && md5_file($rPath) === $rMeta['md5']) {
			return false;
		}

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $rMeta['fileurl']);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 30);
		curl_setopt($ch, CURLOPT_TIMEOUT, 300);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36');
		$rData = curl_exec($ch);
		curl_close($ch);

		if ($rData === false || $rData === '') {
			echo '[ERROR] ' . basename($rPath) . ': download failed' . "\n";
			return false;
		}
		if (@file_put_contents($rPath, $rData) === false) {
			echo '[ERROR] ' . basename($rPath) . ': write failed' . "\n";
			return false;
		}
		@chown($rPath, 'xc_vm');
		@chmod($rPath, 0640);
		return true;
	}
}
