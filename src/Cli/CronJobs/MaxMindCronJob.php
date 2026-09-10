<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\GeoIP\AsnCatalogSync;
use XcVm\Core\GeoIP\GeoLiteReleaseUpdater;
use XcVm\Core\GeoIP\MaxMindUpdater;
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

		$anyError = false;
		$rSettings = SettingsManager::getAll();
		$updater   = MaxMindUpdater::fromSettings($rSettings);
		$rReleaseUpdater = new GeoLiteReleaseUpdater();

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
			if ($rReleaseUpdater->updateGeoLite($force)) {
				$anyError = true;
			}
		}

		// GeoIP2-ISP: the paid MaxMind edition is optional. Unless it is configured
		// (paid), keep the free self-built GeoIP2-ISP.mmdb from the release in sync so
		// ASN lookups work without a licence. Runs on every node (each needs the mmdb
		// locally); records geoisp_version in version.json.
		$rEditions = json_decode((string) ($rSettings['maxmind_editions'] ?? '[]'), true) ?: [];
		if (!($updater !== null && in_array('GeoIP2-ISP', $rEditions, true))) {
			$rReleaseUpdater->updateIsp($force);
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
}
