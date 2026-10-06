<?php

namespace XcVm\Cli\CronJobs;

use XcVm\Cli\CommandInterface;
use XcVm\Cli\CronTrait;
use XcVm\Core\Config\SettingsManager;
use XcVm\Core\Util\ImageUtils;
use XcVm\Domain\Epg\EPG;

/**
 * EpgCronJob — epg cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

class EpgCronJob implements CommandInterface {
	use CronTrait;

	/**
	 * Of programmes with the same source, channel and start, keep the last one
	 * stored: one pass over the duplicated keys, where a self-join compared every
	 * programme with every other of its channel.
	 */
	public const DEDUPE = 'DELETE n1 FROM `epg_data` n1 INNER JOIN (SELECT MAX(`id`) AS `keep`, `epg_id`, `channel_id`, `start` FROM `epg_data` GROUP BY `epg_id`, `channel_id`, `start` HAVING COUNT(*) > 1) d ON n1.`epg_id` = d.`epg_id` AND n1.`channel_id` = d.`channel_id` AND n1.`start` = d.`start` AND n1.`id` < d.`keep`;';

	public function getName(): string {
		return 'cron:epg';
	}

	public function getDescription(): string {
		return 'Cron: import EPG data, generate XMLTV, per-stream cache';
	}

	public function execute(array $rArgs): int {
		if (!$this->assertRunAsXcVm()) {
			return 1;
		}

		$rEPGID = null;
		if (!empty($rArgs[0])) {
			$rEPGID = intval($rArgs[0]);
		}

		$this->printLog("=== XC_VM[EPG] Process started ===");
		$this->printLog("Mode: " . ($rEPGID ? "Single EPG ID: $rEPGID" : "Full update"));

		set_time_limit(0);
		ini_set('memory_limit', -1);

		shell_exec('kill -9 `ps -ef | grep \'XC_VM\\[EPG\\]\' | grep -v grep | awk \'{print $2}\'`;');
		cli_set_process_title('XC_VM[EPG]');

		global $db;

		if (SettingsManager::get('force_epg_timezone')) {
			date_default_timezone_set('UTC');
			$this->printLog("[SYSTEM] Forced timezone to UTC");
		}

		$this->printLog("[EPG] Clearing old channel mappings...");
		if ($rEPGID) {
			$db->query('DELETE FROM `epg_channels` WHERE `epg_id` = ?;', $rEPGID);
			$db->query('SELECT * FROM `epg` WHERE `id` = ?;', $rEPGID);
		} else {
			$db->query('TRUNCATE `epg_channels`;');
			$db->query('SELECT * FROM `epg`;');
		}

		$epgSources = $db->get_rows();
		$this->printLog("[EPG] Found " . count($epgSources) . " EPG sources to process");

		foreach ($epgSources as $rRow) {
			$this->printLog("[EPG] Processing source ID: {$rRow['id']} | File: {$rRow['epg_file']}");
			$rEPG = new EPG($rRow['epg_file']);

			if ($rEPG->rValid) {
				$rData = $rEPG->getData();

				$this->reconnectDb();

				$db->query('UPDATE `epg` SET `data` = ?, `last_updated` = ? WHERE `id` = ?', json_encode($rData, JSON_UNESCAPED_UNICODE), time(), $rRow['id']);

				$this->printLog("[EPG] Updated metadata for EPG ID {$rRow['id']}, found " . count($rData) . " channels");

				foreach ($rData as $rID => $rArray) {
					$db->query('INSERT INTO `epg_channels`(`epg_id`, `channel_id`, `name`, `langs`) VALUES(?, ?, ?, ?);', $rRow['id'], $rID, $rArray['display_name'], json_encode($rArray['langs']));
				}
			} else {
				$this->printLog("[EPG] Failed to load EPG source ID {$rRow['id']}");
			}
		}

		$this->printLog("[EPG] Starting full programme data import...");

		if ($rEPGID) {
			$db->query('SELECT DISTINCT(t1.`epg_id`), t2.* FROM `streams` t1 INNER JOIN `epg` t2 ON t2.id = t1.epg_id WHERE t1.`epg_id` IS NOT NULL AND t2.id = ?;', $rEPGID);
		} else {
			$db->query('SELECT DISTINCT(t1.`epg_id`), t2.* FROM `streams` t1 INNER JOIN `epg` t2 ON t2.id = t1.epg_id WHERE t1.`epg_id` IS NOT NULL;');
		}

		foreach ($db->get_rows() as $rData) {
			$this->printLog("[EPG] === Processing EPG ID: {$rData['epg_id']} ===");

			if ($rData['days_keep'] == 0) {
				$this->printLog("[EPG] Clearing all existing data for EPG ID {$rData['epg_id']}");
				$db->query('DELETE FROM `epg_data` WHERE `epg_id` = ?', $rData['epg_id']);
			}

			$rEPG = new EPG($rData['epg_file'], true);
			if ($rEPG->rValid) {
				$db->query('SELECT t1.`channel_id`, t1.`epg_lang`, t1.`epg_offset`, last_row.start 
                    FROM `streams` t1 
                    LEFT JOIN (SELECT channel_id, MAX(`start`) as start FROM epg_data WHERE epg_id = ? GROUP BY channel_id) last_row 
                    ON last_row.channel_id = t1.channel_id 
                    WHERE `epg_id` = ?;', $rData['epg_id'], $rData['epg_id']);
				$channelMap = $db->get_rows(true, 'channel_id');

				$batches = $rEPG->parseEPG($rData['epg_id'], $channelMap, intval($rData['offset']));

				$this->reconnectDb();

				if ($batches) {
					$totalInserted = 0;
					foreach ($batches as $insertBatch) {
						if (!empty($insertBatch)) {
							$db->simple_query('INSERT INTO `epg_data` (`epg_id`,`channel_id`,`start`,`end`,`lang`,`title`,`description`) VALUES ' . $insertBatch);
							$totalInserted += substr_count($insertBatch, '),(') + 1;
						}
					}
					$this->printLog("[EPG] Inserted $totalInserted programmes for EPG ID {$rData['epg_id']}");
				} else {
					$this->printLog("[EPG] No new programmes found for EPG ID {$rData['epg_id']}");
				}

				$db->query('UPDATE `epg` SET `last_updated` = ? WHERE `id` = ?', time(), $rData['epg_id']);
			} else {
				$this->printLog("[EPG] Failed to parse EPG file for ID {$rData['epg_id']}");
			}

			if ($rData['days_keep'] > 0) {
				$cleanupTime = strtotime('-' . (int) $rData['days_keep'] . ' days');
				if ($cleanupTime !== false) {
					$db->query('DELETE FROM `epg_data` WHERE `epg_id` = ? AND `start` < ?', $rData['epg_id'], $cleanupTime);
					echo "[EPG] Cleaned up old data (older than {$rData['days_keep']} days)\n";
				} else {
					echo "[EPG] Invalid days_keep value, skipping cleanup\n";
				}
			}
		}

		$this->printLog("[EPG] Removing duplicate EPG entries...");
		$db->query(self::DEDUPE);

		$this->printLog("[EPG] Cleaning temporary XML files...");
		shell_exec('rm -f ' . TMP_PATH . '*.xml');

		// Marks the start of recording files. Everything that is (re)recorded in this
		// execution will have mtime >= $runStart; cleanup at the end only removes what doesn't
		// was played in this round (orphans of old executions). Without this, the
		// old "time () - 10" erased the caches written at the beginning of the loop
		// (which takes well over 10s), leaving most channels without EPG.
		$runStart = time();

		$this->buildXmltv();

		$this->printLog("[CACHE] Building per-stream EPG cache...");
		$db->query('SELECT `id`, `epg_id`, `channel_id` FROM `streams` WHERE `type` = 1 AND `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL;');
		$streams = $db->get_rows();
		$this->printLog("[CACHE] Caching EPG for " . count($streams) . " live streams");

		foreach ($streams as $rRow) {
			$rEPGData = [];
			$seen = [];

			$db->query('SELECT * FROM `epg_data` WHERE `epg_id` = ? AND `channel_id` = ? ORDER BY `start` ASC;', $rRow['epg_id'], $rRow['channel_id']);
			foreach ($db->get_rows() as $prog) {
				if (!in_array($prog['start'], $seen)) {
					$seen[] = $prog['start'];
					$rEPGData[] = $prog;
				}
			}

			if (count($rEPGData) > 0) {
				file_put_contents(EPG_PATH . 'stream_' . $rRow['id'], igbinary_serialize($rEPGData));
			}
		}

		$this->printLog("[CLEANUP] Removing orphan cache files...");
		$deleted = 0;
		clearstatcache();
		foreach (scandir(EPG_PATH) as $rFile) {
			if ($rFile === '.' || $rFile === '..') {
				continue;
			}
			$fullPath = EPG_PATH . $rFile;
			// Only removes files that were not rewritten in this run
			// (mtime before the start of the Round = old execution orphan).
			if (filemtime($fullPath) < $runStart) {
				unlink($fullPath);
				$deleted++;
			}
		}
		$this->printLog("[CLEANUP] Deleted $deleted orphan cache files");

		$this->printLog("=== EPG processing completed successfully! ===");

		return 0;
	}

	/** Programme rows read per statement while a source set's programmes are written. */
	private const XMLTV_PAGE = 5000;

	/**
	 * Write the XMLTV files: epg_all.xml and one per bouquet set the lines use,
	 * each with its .gz. The programmes of a source set (its EPG sources, and
	 * whether the past is kept for an archive channel) are rendered once, paged
	 * from the database to a shared file and its raw deflate, and every file that
	 * uses them is assembled from the two: the .xml copied, the .gz spliced into
	 * one gzip member. A file is written beside its name and renamed, so a
	 * download never meets half a file; a failed write keeps the previous one.
	 *
	 * @param int|null $rNow The run's clock: the cut-off of "current and future"
	 */
	public function buildXmltv(?int $rNow = null): void {
		global $db;
		$rNow ??= time();

		$this->printLog("[XMLTV] Starting XMLTV generation...");
		$ApiDependencyIdentifier = $this->getBouquetGroups();

		$totalBouquets = count($ApiDependencyIdentifier);
		$this->printLog("[XMLTV] Generating XMLTV for $totalBouquets bouquet(s)");

		$rServerName = htmlspecialchars(SettingsManager::get('server_name'), ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
		$rSections = [];
		foreach ($ApiDependencyIdentifier as $rBouquet => $BatchProcessId) {
			if (strlen($rBouquet) <= 0 || count($BatchProcessId['streams']) <= 0 && $rBouquet != 'all') {
				continue;
			}

			$this->printLog("[XMLTV] Generating EPG for bouquet: " . ($rBouquet === 'all' ? 'ALL' : $rBouquet));

			$rHead = '<?xml version="1.0" encoding="utf-8" ?><!DOCTYPE tv SYSTEM "xmltv.dtd">' . "\n";
			$rHead .= '<tv generator-info-name="' . $rServerName . '">' . "\n";

			if ($rBouquet == 'all') {
				$db->query('SELECT `stream_display_name`,`stream_icon`,`channel_id`,`epg_id`,`tv_archive_duration` FROM `streams` WHERE `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL;');
			} else {
				$db->query('SELECT `stream_display_name`,`stream_icon`,`channel_id`,`epg_id`,`tv_archive_duration` FROM `streams` WHERE `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL AND `id` IN (' . implode(',', array_map('intval', $BatchProcessId['streams'])) . ');');
			}

			$channels = $db->get_rows();
			$channelCount = count($channels);
			$this->printLog("[XMLTV] Found $channelCount channels in this bouquet");

			$rEPGIDs = [];
			$hasArchive = 0;

			foreach ($channels as $rRow) {
				if ($rRow['tv_archive_duration'] > 0) {
					$hasArchive++;
				}

				$displayName = htmlspecialchars($rRow['stream_display_name'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
				$icon = htmlspecialchars(ImageUtils::validateURL($rRow['stream_icon']), ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
				$channelID = htmlspecialchars($rRow['channel_id'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');

				$rHead .= "\t<channel id=\"$channelID\">";
				$rHead .= "\t\t<display-name>$displayName</display-name>";
				if (!empty($rRow['stream_icon'])) {
					$rHead .= "\t\t<icon src=\"$icon\" />";
				}
				$rHead .= "\t</channel>";

				$rEPGIDs[] = intval($rRow['epg_id']);
			}

			$rEPGIDs = array_values(array_unique($rEPGIDs));
			$rSection = null;
			if (count($rEPGIDs) > 0) {
				if ($hasArchive > 0) {
					$this->printLog("[XMLTV] Archive channels detected ($hasArchive), including all historical programmes");
				} else {
					$this->printLog("[XMLTV] No archive channels, filtering only current/future programmes");
				}
				sort($rEPGIDs);
				$rKey = implode(',', $rEPGIDs) . ($hasArchive > 0 ? '|all' : '|future');
				if (!array_key_exists($rKey, $rSections)) {
					$rSections[$rKey] = $this->writeProgrammes($rEPGIDs, $hasArchive > 0, $rNow, EPG_PATH . 'programmes_' . count($rSections) . '.tmp');
				}
				$rSection = $rSections[$rKey];
			}

			$rName = $rBouquet == 'all' ? 'all' : md5($rBouquet);
			if (count($rEPGIDs) > 0 && $rSection === null) {
				$this->keepPrevious($rName);
				continue;
			}
			$this->writeXmltv($rName, $rHead, $rSection);
		}

		foreach ($rSections as $rSection) {
			if ($rSection !== null) {
				@unlink($rSection['path']);
				@unlink($rSection['path'] . '.deflate');
			}
		}
	}

	/**
	 * Render a source set's programmes once, in id order, to $rPath and its raw
	 * deflate to $rPath.deflate (sync-flushed per page, so it can be spliced
	 * after any head). Of programmes with the same channel and start, the first
	 * is kept.
	 *
	 * @param list<int> $rEPGIDs
	 * @return array{path: string}|null null when the files could not be written
	 */
	private function writeProgrammes(array $rEPGIDs, bool $rArchive, int $rNow, string $rPath): ?array {
		global $db;
		$rFile = @fopen($rPath, 'wb');
		$rPacked = @fopen($rPath . '.deflate', 'wb');
		$rDeflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 9]);
		$rWritten = $rFile !== false && $rPacked !== false;
		// ponytail: one key per programme, about 130 bytes each; drop it for one-source sets if memory ever matters.
		$rSeen = [];
		$rCount = 0;
		$rLast = 0;
		do {
			$rRead = $db->query('SELECT * FROM `epg_data` WHERE `epg_id` IN (' . implode(',', $rEPGIDs) . ') ' . ($rArchive ? '' : 'AND `end` >= ? ') . 'AND `id` > ? ORDER BY `id` LIMIT ' . self::XMLTV_PAGE . ';', ...($rArchive ? [$rLast] : [$rNow, $rLast]));
			$rRows = $rRead ? ($db->get_rows() ?: []) : [];
			$rBuffer = '';
			foreach ($rRows as $rRow) {
				$rLast = (int) $rRow['id'];
				$key = $rRow['channel_id'] . '|' . $rRow['start'];
				if (isset($rSeen[$key])) {
					continue;
				}
				$rSeen[$key] = true;
				$rCount++;

				$rTitle = htmlspecialchars($rRow['title'] ?? '', ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
				$rDescription = htmlspecialchars($rRow['description'] ?? '', ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
				$rChannelID = htmlspecialchars($rRow['channel_id'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
				$rStart = date('YmdHis', $rRow['start']) . ' ' . str_replace(':', '', date('P', $rRow['start']));
				$rEnd = date('YmdHis', $rRow['end']) . ' ' . str_replace(':', '', date('P', $rRow['end']));

				$rBuffer .= "\t<programme start=\"$rStart\" stop=\"$rEnd\" channel=\"$rChannelID\">";
				$rBuffer .= "\t\t<title>$rTitle</title>";
				$rBuffer .= "\t\t<desc>$rDescription</desc>";
				$rBuffer .= "\t</programme>";
			}
			// An empty string with a flush other than FINISH flushes nothing: only pages with programmes go in.
			if ($rWritten && $rBuffer !== '') {
				$rWritten = fwrite($rFile, $rBuffer) === strlen($rBuffer);
				$rChunk = deflate_add($rDeflate, $rBuffer, ZLIB_SYNC_FLUSH);
				$rWritten = $rWritten && fwrite($rPacked, $rChunk) === strlen($rChunk);
			}
		} while ($rRead && count($rRows) === self::XMLTV_PAGE);
		foreach ([$rFile, $rPacked] as $rHandle) {
			if ($rHandle !== false) {
				fclose($rHandle);
			}
		}
		$this->printLog("[XMLTV] Adding $rCount programmes to XML");
		if (!$rRead || !$rWritten) {
			$this->printLog('[XMLTV] Could not ' . ($rRead ? 'write ' . basename($rPath) : 'read the programmes') . ': their files keep the previous version');
			@unlink($rPath);
			@unlink($rPath . '.deflate');
			return null;
		}
		return ['path' => $rPath];
	}

	/**
	 * Write epg_<name>.xml and .xml.gz: the head, the source set's programmes and
	 * '</tv>', each beside its name and renamed when complete. The .gz is one gzip
	 * member assembled from raw deflate parts: the head, the shared programmes as
	 * compressed once, and the end, with the CRC and length of the whole document.
	 * When anything cannot be written, the previous files stay (and are kept by
	 * the run's sweep of old files).
	 *
	 * @param array{path: string}|null $rSection The programmes, or null for none
	 */
	private function writeXmltv(string $rName, string $rHead, ?array $rSection): void {
		$rXml = EPG_PATH . 'epg_' . $rName . '.xml';
		$rGz = $rXml . '.gz';
		$rPlain = @fopen($rXml . '.tmp', 'wb');
		$rPacked = @fopen($rGz . '.tmp', 'wb');
		$rWritten = $rPlain !== false && $rPacked !== false;
		$rCrc = hash_init('crc32b');
		$rLength = 0;
		$rPut = static function (string $rText, string $rDeflated) use (&$rWritten, $rPlain, $rPacked, $rCrc, &$rLength): void {
			if ($rWritten) {
				$rWritten = fwrite($rPlain, $rText) === strlen($rText) && fwrite($rPacked, $rDeflated) === strlen($rDeflated);
				hash_update($rCrc, $rText);
				$rLength += strlen($rText);
			}
		};

		// The gzip header zlib writes: no name, no time, best compression, Unix.
		$rWritten = $rWritten && fwrite($rPacked, "\x1f\x8b\x08\x00\x00\x00\x00\x00\x02\x03") === 10;
		$rPut($rHead, deflate_add(deflate_init(ZLIB_ENCODING_RAW, ['level' => 9]), $rHead, ZLIB_FULL_FLUSH));
		if ($rSection !== null) {
			$rText = @fopen($rSection['path'], 'rb');
			$rDeflated = @fopen($rSection['path'] . '.deflate', 'rb');
			$rWritten = $rWritten && $rText !== false && $rDeflated !== false;
			while ($rWritten && !feof($rText)) {
				$rChunk = (string) fread($rText, 1048576);
				if ($rChunk !== '') {
					$rWritten = fwrite($rPlain, $rChunk) === strlen($rChunk);
					hash_update($rCrc, $rChunk);
					$rLength += strlen($rChunk);
				}
			}
			while ($rWritten && !feof($rDeflated)) {
				$rChunk = (string) fread($rDeflated, 1048576);
				$rWritten = $rChunk === '' || fwrite($rPacked, $rChunk) === strlen($rChunk);
			}
			foreach ([$rText, $rDeflated] as $rHandle) {
				if ($rHandle !== false) {
					fclose($rHandle);
				}
			}
		}
		$rPut('</tv>', deflate_add(deflate_init(ZLIB_ENCODING_RAW, ['level' => 9]), '</tv>', ZLIB_FINISH));
		$rWritten = $rWritten && fwrite($rPacked, strrev(hash_final($rCrc, true)) . pack('V', $rLength & 0xffffffff)) === 8;
		foreach ([$rPlain, $rPacked] as $rHandle) {
			if ($rHandle !== false) {
				$rWritten = fclose($rHandle) && $rWritten;
			}
		}

		if ($rWritten && @rename($rXml . '.tmp', $rXml) && @rename($rGz . '.tmp', $rGz)) {
			$this->printLog("[XMLTV] Saved epg_$rName.xml.gz (" . number_format($rLength) . " bytes)");
			return;
		}
		@unlink($rXml . '.tmp');
		@unlink($rGz . '.tmp');
		$this->keepPrevious($rName);
	}

	/** epg_<name>.xml(.gz) could not be rebuilt: the previous files stay, made newer than the run's start so its sweep of old files keeps them. */
	private function keepPrevious(string $rName): void {
		foreach (['.xml', '.xml.gz'] as $rExtension) {
			if (is_file(EPG_PATH . 'epg_' . $rName . $rExtension)) {
				@touch(EPG_PATH . 'epg_' . $rName . $rExtension);
			}
		}
		$this->printLog("[XMLTV] Could not write epg_$rName.xml: the previous file stays");
	}

	private function printLog(string $message): void {
		echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
	}

	private function reconnectDb(): void {
		global $db;
		if ($db->ping()) {
			$this->printLog("[EPG] Database connection is alive.");
		} else {
			$this->printLog("[EPG] Database connection lost. Attempting to reconnect...");
			$db->db_connect();
			if ($db->ping()) {
				$this->printLog("[EPG] Reconnected to the database successfully.");
			} else {
				$this->printLog("[EPG] Failed to reconnect to the database. Exiting.");
				exit(1);
			}
		}
	}

	private function getBouquetGroups(): array {
		global $db;
		$this->printLog("[XMLTV] Building bouquet groups...");
		$db->query('SELECT DISTINCT(`bouquet`) AS `bouquet` FROM `lines`;');
		$ApiDependencyIdentifier = [
			'all' => [
				'streams'  => [],
				'bouquets' => []
			]
		];

		foreach ($db->get_rows() as $rRow) {
			$rBouquets = json_decode($rRow['bouquet'] ?? null, true);

			if (!is_array($rBouquets) || $rBouquets === []) {
				$this->printLog("[XMLTV] Skipping invalid/empty bouquet value: " . var_export($rBouquets, true));
				continue;
			}

			sort($rBouquets);
			$ApiDependencyIdentifier[implode('_', $rBouquets)] = [
				'streams'  => [],
				'bouquets' => $rBouquets
			];
		}
		$count = count($ApiDependencyIdentifier);
		$this->printLog("[XMLTV] Found $count bouquet groups (including 'all')");

		foreach ($ApiDependencyIdentifier as $rGroup => $CacheFlushInterval) {
			$FileReference = [];

			foreach ($CacheFlushInterval['bouquets'] as $rBouquetID) {
				$db->query('SELECT `bouquet_channels` FROM `bouquets` WHERE `id` = ?;', $rBouquetID);

				foreach ($db->get_rows() as $rRow) {
					$FileReference[] = $rBouquetID;
					$ApiDependencyIdentifier[$rGroup]['streams'] = array_merge($ApiDependencyIdentifier[$rGroup]['streams'], json_decode($rRow['bouquet_channels'], true));
				}

				$ApiDependencyIdentifier[$rGroup]['streams'] = array_unique($ApiDependencyIdentifier[$rGroup]['streams']);
			}

			$ApiDependencyIdentifier[$rGroup]['bouquets'] = $FileReference;
		}

		return $ApiDependencyIdentifier;
	}
}
