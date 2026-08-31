<?php

namespace XcVm\Core\GeoIP;

use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Updates\GitHubReleases;

/**
 * AsnCatalogSync — keeps the `blocked_asns` reference catalog current from the
 * master file shipped in the XC_VM_Update release (`blocked_asns.json.gz`).
 *
 * The panel no longer seeds ~69k static ASN rows at install: the table starts
 * empty and is populated/refreshed here. On each run the reference columns
 * (`isp`, `domain`, `country`, `num_ips`, `type`) are upserted from the file and
 * ASNs no longer present are pruned — but the user-owned `blocked` flag is NEVER
 * written, and a blocked ASN is never pruned even if it left the catalog. The read
 * path (`BlocklistService::getBlockedServers()` → `blocked=1`) is unchanged.
 *
 * MAIN-only: the caller guards on `is_main` since `blocked_asns` is the central
 * table. Wired into `MaxMindCronJob` (runs on the weekly `cron:maxmind`).
 *
 * @package XC_VM_Core_GeoIP
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */
class AsnCatalogSync {
	use \XcVm\Infrastructure\Database\DatabaseAware;

	/** Rows per multi-value INSERT batch (kept well under the bind-param limit). */
	private const BATCH = 1000;

	/** Local path of the downloaded master catalog. */
	private static function path(): string {
		return BIN_PATH . 'maxmind/blocked_asns.json.gz';
	}

	/**
	 * Download the catalog (if changed) then sync it into `blocked_asns`.
	 *
	 * @param bool $force Re-download even when the md5 matches the local copy.
	 * @return array{downloaded: bool, upserted: int, removed: int, skipped?: string}
	 */
	public static function run(bool $force = false): array {
		$rDownloaded = self::download($force);
		$rStats = self::sync();
		$rStats['downloaded'] = $rDownloaded;
		return $rStats;
	}

	/**
	 * Fetch `blocked_asns.json.gz` from the latest release. Mirrors the GeoLite2
	 * fallback download in MaxMindCronJob (timeouts, follow-location, md5). The
	 * file lives only in the release repo, so this runs regardless of MaxMind
	 * credentials. Returns true when a new file was written.
	 */
	private static function download(bool $force): bool {
		$rRepo = new GitHubReleases(GIT_OWNER, GIT_REPO_UPDATE, SettingsManager::get('update_channel'));
		$rMeta = $rRepo->getAsnCatalog();
		if (!is_array($rMeta) || empty($rMeta['fileurl'])) {
			return false;
		}

		$rPath = $rMeta['path'];
		// Skip when unchanged (md5 available and matches the local copy).
		if (!$force && is_file($rPath) && !empty($rMeta['md5']) && md5_file($rPath) === $rMeta['md5']) {
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
			return false;
		}

		if (@file_put_contents($rPath, $rData) === false) {
			return false;
		}
		@chown($rPath, 'xc_vm');
		@chmod($rPath, 0640);
		return true;
	}

	/**
	 * Apply the local catalog to `blocked_asns`: upsert reference columns, prune
	 * ASNs absent from the file (keeping `blocked=1`). `blocked` is never written.
	 *
	 * @return array{upserted: int, removed: int, skipped?: string}
	 */
	public static function sync(): array {
		$rPath = self::path();
		if (!is_file($rPath)) {
			return array('upserted' => 0, 'removed' => 0, 'skipped' => 'no-file');
		}

		$rRaw = @file_get_contents($rPath);
		if ($rRaw === false || $rRaw === '') {
			return array('upserted' => 0, 'removed' => 0, 'skipped' => 'empty');
		}
		// Transparently gunzip (the release ships .gz; a plain .json also works).
		if (substr($rRaw, 0, 2) === "\x1f\x8b") {
			$rRaw = @gzdecode($rRaw);
			if ($rRaw === false) {
				return array('upserted' => 0, 'removed' => 0, 'skipped' => 'bad-gzip');
			}
		}

		$rRecords = json_decode($rRaw, true);
		unset($rRaw);
		if (!is_array($rRecords) || count($rRecords) === 0) {
			return array('upserted' => 0, 'removed' => 0, 'skipped' => 'bad-json');
		}

		$db = self::db();
		// Current-ASN snapshot for the prune step.
		$db->query('DROP TEMPORARY TABLE IF EXISTS `tmp_asns`;');
		$db->query('CREATE TEMPORARY TABLE `tmp_asns` (`asn` INT PRIMARY KEY) ENGINE=MEMORY;');

		$rUpserted = 0;
		$rUpsertBatch = array();
		$rTmpBatch = array();

		foreach ($rRecords as $rRow) {
			$rAsn = isset($rRow['asn']) ? intval($rRow['asn']) : 0;
			if ($rAsn <= 0) {
				continue;
			}

			$rDomain = (isset($rRow['domain']) && $rRow['domain'] !== '') ? (string) $rRow['domain'] : null;
			$rUpsertBatch[] = array(
				$rAsn,
				isset($rRow['isp']) ? (string) $rRow['isp'] : null,
				$rDomain,
				isset($rRow['country']) ? (string) $rRow['country'] : null,
				isset($rRow['num_ips']) ? intval($rRow['num_ips']) : 0,
				isset($rRow['type']) ? (string) $rRow['type'] : null,
			);
			$rTmpBatch[] = $rAsn;

			if (count($rUpsertBatch) >= self::BATCH) {
				$rUpserted += self::flushUpsert($db, $rUpsertBatch);
				self::flushTmp($db, $rTmpBatch);
				$rUpsertBatch = array();
				$rTmpBatch = array();
			}
		}
		if (count($rUpsertBatch) > 0) {
			$rUpserted += self::flushUpsert($db, $rUpsertBatch);
			self::flushTmp($db, $rTmpBatch);
		}
		unset($rRecords);

		// Prune ASNs no longer in the catalog — but keep user bans (blocked=1).
		$db->query('SELECT COUNT(*) AS `count` FROM `blocked_asns` `b` LEFT JOIN `tmp_asns` `t` ON `b`.`asn` = `t`.`asn` WHERE `t`.`asn` IS NULL AND `b`.`blocked` = 0;');
		$rRemoved = intval($db->get_row()['count'] ?? 0);
		$db->query('DELETE `b` FROM `blocked_asns` `b` LEFT JOIN `tmp_asns` `t` ON `b`.`asn` = `t`.`asn` WHERE `t`.`asn` IS NULL AND `b`.`blocked` = 0;');
		$db->query('DROP TEMPORARY TABLE IF EXISTS `tmp_asns`;');

		return array('upserted' => $rUpserted, 'removed' => $rRemoved);
	}

	/**
	 * Multi-row upsert of one batch. `blocked` is intentionally absent from the
	 * column list, so it is never overwritten (new rows take its default 0).
	 *
	 * @param object                       $db
	 * @param array<int, array<int, mixed>> $rBatch Rows [asn, isp, domain, country, num_ips, type].
	 * @return int Rows sent.
	 */
	private static function flushUpsert($db, array $rBatch): int {
		$rPlaceholders = implode(',', array_fill(0, count($rBatch), '(?,?,?,?,?,?)'));
		$rParams = array();
		foreach ($rBatch as $rCols) {
			foreach ($rCols as $rVal) {
				$rParams[] = $rVal;
			}
		}
		$rSql = 'INSERT INTO `blocked_asns` (`asn`,`isp`,`domain`,`country`,`num_ips`,`type`) VALUES '
			. $rPlaceholders
			. ' ON DUPLICATE KEY UPDATE `isp`=VALUES(`isp`),`domain`=VALUES(`domain`),`country`=VALUES(`country`),`num_ips`=VALUES(`num_ips`),`type`=VALUES(`type`);';
		$db->query($rSql, ...$rParams);
		return count($rBatch);
	}

	/**
	 * Batch-insert ASNs into the temp snapshot table used by the prune step.
	 *
	 * @param object     $db
	 * @param array<int> $rAsns
	 */
	private static function flushTmp($db, array $rAsns): void {
		if (count($rAsns) === 0) {
			return;
		}
		$rPlaceholders = implode(',', array_fill(0, count($rAsns), '(?)'));
		$db->query('INSERT IGNORE INTO `tmp_asns` (`asn`) VALUES ' . $rPlaceholders . ';', ...$rAsns);
	}
}
