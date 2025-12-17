<?php

class EPG {
	public $rValid = false;
	public $rEPGSource;
	public $rFilename;

	public function __construct($rSource, $rCache = false) {
		$this->loadEPG($rSource, $rCache);
	}

	public function getData() {
		$rOutput = [];
		$channelCount = 0;

		print_log("[EPG] Starting getData() - parsing channels and languages...");

		while ($rNode = $this->rEPGSource->getNode()) {
			$rData = simplexml_load_string($rNode);
			if (!$rData) continue;

			$rNodeName = $rData->getName();

			if ($rNodeName === 'channel') {
				$rChannelID = trim((string) $rData->attributes()->id);
				$displayName = !empty($rData->{'display-name'}) ? trim((string) $rData->{'display-name'}) : 'Unknown';

				if (!array_key_exists($rChannelID, $rOutput)) {
					$rOutput[$rChannelID] = [
						'display_name' => $displayName,
						'langs'        => []
					];
					$channelCount++;
				}
				continue;
			}

			// ---------- PROGRAMME ----------
			if ($rNodeName !== 'programme') {
				continue;
			}

			$rChannelID = trim((string) $rData->attributes()->channel);

			if (!array_key_exists($rChannelID, $rOutput)) {
				continue;
			}

			if (empty($rData->title)) {
				continue;
			}

			foreach ($rData->title as $rTitle) {
				$lang = (string) $rTitle->attributes()->lang;
				if (!empty($lang) && !in_array($lang, $rOutput[$rChannelID]['langs'], true)) {
					$rOutput[$rChannelID]['langs'][] = $lang;
				}
			}
		}

		print_log("[EPG] Finished getData() - found $channelCount channels");
		return $rOutput;
	}

	public function parseEPG($rEPGID, $rChannelInfo, $rOffset = 0) {
		global $db;

		$rInsertQuery = [];
		$programCount = 0;

		print_log("[EPG] Starting parseEPG() for EPG ID: $rEPGID (offset: {$rOffset}min)");

		while ($rNode = $this->rEPGSource->getNode()) {
			$rData = simplexml_load_string($rNode);
			if (!$rData) {
				continue;
			}

			if ($rData->getName() !== 'programme') {
				continue;
			}

			$rChannelID = (string) $rData->attributes()->channel;

			if (!array_key_exists($rChannelID, $rChannelInfo)) {
				continue;
			}

			// --- timestamps ---
			$rStart = strtotime((string) $rData->attributes()->start) + ($rOffset * 60);
			$rStop  = strtotime((string) $rData->attributes()->stop)  + ($rOffset * 60);

			if ($rStart === false || $rStop === false) {
				print_log("[EPG] Warning: Invalid timestamp for channel $rChannelID");
				continue;
			}

			$rLangTitle = '';
			$rLangDesc  = '';

			// Title
			if (!empty($rData->title)) {
				$rTitles = $rData->title;
				$preferredLang = $rChannelInfo[$rChannelID]['epg_lang'];

				if (is_object($rTitles)) {
					$rFound = false;
					foreach ($rTitles as $rTitle) {
						if ((string) $rTitle->attributes()->lang === $preferredLang) {
							$rLangTitle = (string) $rTitle;
							$rFound = true;
							break;
						}
					}
					if (!$rFound && count($rTitles) > 0) {
						$rLangTitle = (string) $rTitles[0];
					}
				} else {
					$rLangTitle = (string) $rTitles;
				}
			} else {
				continue;
			}

			// Description
			if (!empty($rData->desc)) {
				$rDescriptions = $rData->desc;
				$preferredLang = $rChannelInfo[$rChannelID]['epg_lang'];

				if (is_object($rDescriptions)) {
					$rFound = false;
					foreach ($rDescriptions as $rDescription) {
						if ((string) $rDescription->attributes()->lang === $preferredLang) {
							$rLangDesc = (string) $rDescription;
							$rFound = true;
							$rLangDesc = $rDescription;
							break;
						}
					}
					if (!$rFound && count($rDescriptions) > 0) {
						$rLangDesc = (string) $rDescriptions[0];
					}
				} else {
					$rLangDesc = (string) $rDescriptions;
				}
			}

			$rInsertQuery[] = '(' .
				$db->escape($rEPGID) . ', ' .
				$db->escape($rChannelID) . ', ' .
				intval($rStart) . ', ' .
				intval($rStop) . ', ' .
				$db->escape($rChannelInfo[$rChannelID]['epg_lang']) . ', ' .
				$db->escape($rLangTitle) . ', ' .
				$db->escape($rLangDesc ?? '') .
				')';

			$programCount++;
			if ($programCount % 1000 === 0) {
				print_log("[EPG] Parsed $programCount programmes so far...");
			}
		}

		print_log("[EPG] Finished parseEPG() - collected $programCount programmes");
		return !empty($rInsertQuery) ? $rInsertQuery : false;
	}

	public function downloadFile($rSource, $rFilename) {
		print_log("[EPG] Downloading EPG file: $rSource");

		$rExtension = pathinfo($rSource, PATHINFO_EXTENSION);
		$rDecompress = '';

		if ($rExtension === 'gz') {
			$rDecompress = ' | gunzip -c';
		} elseif ($rExtension === 'xz') {
			$rDecompress = ' | unxz -c';
		}

		$rCommand = 'wget -U "Mozilla/5.0" --timeout=30 --tries=3 -O - "' . $rSource . '"' . $rDecompress . ' > ' . $rFilename;
		$rResult = shell_exec($rCommand);

		if (file_exists($rFilename) && filesize($rFilename) > 0) {
			print_log("[EPG] Download successful: " . filesize($rFilename) . " bytes");
			return true;
		} else {
			print_log("[EPG] Download failed or file is empty: $rSource");
			return false;
		}
	}

	public function loadEPG($rSource, $rCache) {
		try {
			$this->rFilename = TMP_PATH . md5($rSource) . '.xml';

			// If caching is enabled, check for existing file
			if (!file_exists($this->rFilename) || !$rCache) {
				if (!$this->downloadFile($rSource, $this->rFilename)) {
					print_log("[EPG] Failed to load EPG source: $rSource");
					return;
				}
			} else {
				print_log("[EPG] Using cached EPG file: " . basename($this->rFilename));
			}

			if (!$this->rFilename) {
				CoreUtilities::saveLog('epg', 'No XML found at: ' . $rSource);
				return;
			}

			$rXML = XmlStringStreamer::createStringWalkerParser($this->rFilename);

			if (!$rXML) {
				CoreUtilities::saveLog('epg', 'Not a valid EPG source: ' . $rSource);
				print_log("[EPG] Failed to create XML parser for: $rSource");
				return;
			}

			$this->rEPGSource = $rXML;
			$this->rValid     = true;
			print_log("[EPG] EPG source loaded successfully: $rSource");
		} catch (Exception $e) {
			CoreUtilities::saveLog('epg', 'EPG failed to process: ' . $rSource);
			print_log("[EPG] Exception while loading EPG: " . $e->getMessage() . " | Source: $rSource");
		}
	}
}

function print_log($message) {
	echo "[" . date('Y-m-d H:i:s') . "] " . $message . "\n";
}

function reconect_db() {
	global $db;
	if ($db->ping()) {
		print_log("[EPG] Database connection is alive.");
	} else {
		print_log("[EPG] Database connection lost. Attempting to reconnect...");
		$db->db_connect();
		if ($db->ping()) {
			print_log("[EPG] Reconnected to the database successfully.");
		} else {
			print_log("[EPG] Failed to reconnect to the database. Exiting.");
			exit(1);
		}
	}
}

function getBouquetGroups() {
	global $db;
	print_log("[XMLTV] Building bouquet groups...");
	$db->query('SELECT DISTINCT(`bouquet`) AS `bouquet` FROM `lines`;');
	$ApiDependencyIdentifier = [
		'all' => [
			'streams'  => [],
			'bouquets' => []
		]
	];

	foreach ($db->get_rows() as $rRow) {
		$rBouquets = json_decode($rRow['bouquet'], true);
		sort($rBouquets);
		$ApiDependencyIdentifier[implode('_', $rBouquets)] = [
			'streams'  => [],
			'bouquets' => $rBouquets
		];
	}
	$count = count($ApiDependencyIdentifier ?? []);
	print_log("[XMLTV] Found $count bouquet groups (including 'all')");

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

function getEPG($rStreamID) {
	return file_exists(EPG_PATH . 'stream_' . $rStreamID) ? igbinary_unserialize(file_get_contents(EPG_PATH . 'stream_' . $rStreamID)) : [];
}

function shutdown() {
	global $db;
	if (is_object($db)) {
		$db->close_mysql();
	}
	print_log("[EPG] Script finished and database connection closed.");
}


if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
	exit('Please run as XC_VM!' . "\n");
}

if (!@$argc) {
	exit(0);
}

$rEPGID = null;
if (count($argv) == 2) {
	$rEPGID = intval($argv[1]);
}

print_log("=== XC_VM[EPG] Process started ===");
print_log("Mode: " . ($rEPGID ? "Single EPG ID: $rEPGID" : "Full update"));

set_time_limit(0);
ini_set('memory_limit', -1);
register_shutdown_function('shutdown');
require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
require INCLUDES_PATH . 'libs/XmlStringStreamer.php';

shell_exec('kill -9 `ps -ef | grep \'XC_VM\\[EPG\\]\' | grep -v grep | awk \'{print $2}\'`;');
cli_set_process_title('XC_VM[EPG]');

if (CoreUtilities::$rSettings['force_epg_timezone']) {
	date_default_timezone_set('UTC');
	print_log("[SYSTEM] Forced timezone to UTC");
}

print_log("[EPG] Clearing old channel mappings...");
if ($rEPGID) {
	$db->query('DELETE FROM `epg_channels` WHERE `epg_id` = ?;', $rEPGID);
	$db->query('SELECT * FROM `epg` WHERE `id` = ?;', $rEPGID);
} else {
	$db->query('TRUNCATE `epg_channels`;');
	$db->query('SELECT * FROM `epg`;');
}

$epgSources = $db->get_rows();
print_log("[EPG] Found " . count($epgSources) . " EPG sources to process");

foreach ($epgSources as $rRow) {
	print_log("[EPG] Processing source ID: {$rRow['id']} | File: {$rRow['epg_file']}");
	$rEPG = new EPG($rRow['epg_file']);

	if ($rEPG->rValid) {
		$rData = $rEPG->getData();

		reconect_db();

		$db->query('UPDATE `epg` SET `data` = ?, `last_updated` = ? WHERE `id` = ?', json_encode($rData, JSON_UNESCAPED_UNICODE), time(), $rRow['id']);

		print_log("[EPG] Updated metadata for EPG ID {$rRow['id']}, found " . count($rData) . " channels");

		foreach ($rData as $rID => $rArray) {
			$db->query('INSERT INTO `epg_channels`(`epg_id`, `channel_id`, `name`, `langs`) VALUES(?, ?, ?, ?);', $rRow['id'], $rID, $rArray['display_name'], json_encode($rArray['langs']));
		}
	} else {
		print_log("[EPG] Failed to load EPG source ID {$rRow['id']}");
	}
}

print_log("[EPG] Starting full programme data import...");

if ($rEPGID) {
	$db->query('SELECT DISTINCT(t1.`epg_id`), t2.* FROM `streams` t1 INNER JOIN `epg` t2 ON t2.id = t1.epg_id WHERE t1.`epg_id` IS NOT NULL AND t2.id = ?;', $rEPGID);
} else {
	$db->query('SELECT DISTINCT(t1.`epg_id`), t2.* FROM `streams` t1 INNER JOIN `epg` t2 ON t2.id = t1.epg_id WHERE t1.`epg_id` IS NOT NULL;');
}

foreach ($db->get_rows() as $rData) {
	print_log("[EPG] === Processing EPG ID: {$rData['epg_id']} ===");

	if ($rData['days_keep'] == 0) {
		print_log("[EPG] Clearing all existing data for EPG ID {$rData['epg_id']}");
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

		$batches = $rEPG->parseEPG($rData['epg_id'], $channelMap, intval($rData['offset']) ?: 0);

		reconect_db();

		if ($batches) {
			$totalInserted = 0;
			foreach ($batches as $insertBatch) {
				if (!empty($insertBatch)) {
					$db->simple_query('INSERT INTO `epg_data` (`epg_id`,`channel_id`,`start`,`end`,`lang`,`title`,`description`) VALUES ' . $insertBatch);
					$totalInserted += substr_count($insertBatch, '),(') + 1;
				}
			}
			print_log("[EPG] Inserted $totalInserted programmes for EPG ID {$rData['epg_id']}");
		} else {
			print_log("[EPG] No new programmes found for EPG ID {$rData['epg_id']}");
		}

		$db->query('UPDATE `epg` SET `last_updated` = ? WHERE `id` = ?', time(), $rData['epg_id']);
	} else {
		print_log("[EPG] Failed to parse EPG file for ID {$rData['epg_id']}");
	}

	if ($rData['days_keep'] > 0) {
		$cleanupTime = strtotime('-' . (int)$rData['days_keep'] . ' days');
		if ($cleanupTime !== false) {
			$db->query('DELETE FROM `epg_data` WHERE `epg_id` = ? AND `start` < ?', $rData['epg_id'], $cleanupTime);
			echo "[EPG] Cleaned up old data (older than {$rData['days_keep']} days)\n";
		} else {
			echo "[EPG] Invalid days_keep value, skipping cleanup\n";
		}
	}
}

print_log("[EPG] Removing duplicate EPG entries...");
$db->query('DELETE n1 FROM `epg_data` n1, `epg_data` n2 WHERE n1.id < n2.id AND n1.epg_id = n2.epg_id AND n1.channel_id = n2.channel_id AND n1.start = n2.start;');

print_log("[EPG] Cleaning temporary XML files...");
shell_exec('rm -f ' . TMP_PATH . '*.xml');

print_log("[XMLTV] Starting XMLTV generation...");
$ApiDependencyIdentifier = getBouquetGroups();

$totalBouquets = count($ApiDependencyIdentifier);
print_log("[XMLTV] Generating XMLTV for $totalBouquets bouquet(s)");

foreach ($ApiDependencyIdentifier as $rBouquet => $BatchProcessId) {
	if (!(strlen($rBouquet) > 0 && (count($BatchProcessId['streams']) > 0 || $rBouquet == 'all'))) {
		continue;
	}

	print_log("[XMLTV] Generating EPG for bouquet: " . ($rBouquet === 'all' ? 'ALL' : $rBouquet));

	$rOutput = '';
	$rServerName = htmlspecialchars(CoreUtilities::$rSettings['server_name'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
	$rOutput .= '<?xml version="1.0" encoding="utf-8" ?><!DOCTYPE tv SYSTEM "xmltv.dtd">' . "\n";
	$rOutput .= '<tv generator-info-name="' . $rServerName . '">' . "\n";

	if ($rBouquet == 'all') {
		$db->query('SELECT `stream_display_name`,`stream_icon`,`channel_id`,`epg_id`,`tv_archive_duration` FROM `streams` WHERE `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL;');
	} else {
		$db->query('SELECT `stream_display_name`,`stream_icon`,`channel_id`,`epg_id`,`tv_archive_duration` FROM `streams` WHERE `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL AND `id` IN (' . implode(',', array_map('intval', $BatchProcessId['streams'])) . ');');
	}

	$channels = $db->get_rows();
	$channelCount = count($channels);
	print_log("[XMLTV] Found $channelCount channels in this bouquet");

	$fa4629d757fa3640 = [];
	$hasArchive = 0;

	foreach ($channels as $rRow) {
		if ($rRow['tv_archive_duration'] > 0) $hasArchive++;

		$displayName = htmlspecialchars($rRow['stream_display_name'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
		$icon = htmlspecialchars(CoreUtilities::validateImage($rRow['stream_icon']), ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
		$channelID = htmlspecialchars($rRow['channel_id'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');

		$rOutput .= "\t<channel id=\"$channelID\">";
		$rOutput .= "\t\t<display-name>$displayName</display-name>";
		if (!empty($rRow['stream_icon'])) {
			$rOutput .= "\t\t<icon src=\"$icon\" />";
		}
		$rOutput .= "\t</channel>";

		$fa4629d757fa3640[] = $rRow['epg_id'];
	}

	$fa4629d757fa3640 = array_unique($fa4629d757fa3640);

	if (count($fa4629d757fa3640) > 0) {
		if ($hasArchive > 0) {
			print_log("[XMLTV] Archive channels detected ($hasArchive), including all historical programmes");
			$db->query('SELECT * FROM `epg_data` WHERE `epg_id` IN (' . implode(',', array_map('intval', $fa4629d757fa3640)) . ');');
		} else {
			print_log("[XMLTV] No archive channels, filtering only current/future programmes");
			$db->query('SELECT * FROM `epg_data` WHERE `epg_id` IN (' . implode(',', array_map('intval', $fa4629d757fa3640)) . ') AND `end` >= UNIX_TIMESTAMP();');
		}

		$programmes = $db->get_rows();
		$progCount = count($programmes);
		print_log("[XMLTV] Adding $progCount programmes to XML");

		$seen = [];
		foreach ($programmes as $rRow) {
			$key = $rRow['channel_id'] . '|' . $rRow['start'];
			if (isset($seen[$key])) continue;
			$seen[$key] = true;

			$rTitle = htmlspecialchars($rRow['title'] ?? '', ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
			$rDescription = htmlspecialchars($rRow['description'] ?? '', ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
			$rChannelID = htmlspecialchars($rRow['channel_id'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
			$rStart = date('YmdHis', $rRow['start']) . ' ' . str_replace(':', '', date('P', $rRow['start']));
			$rEnd = date('YmdHis', $rRow['end']) . ' ' . str_replace(':', '', date('P', $rRow['end']));

			$rOutput .= "\t<programme start=\"$rStart\" stop=\"$rEnd\" channel=\"$rChannelID\">";
			$rOutput .= "\t\t<title>$rTitle</title>";
			$rOutput .= "\t\t<desc>$rDescription</desc>";
			$rOutput .= "\t</programme>";
		}
	}

	$rOutput .= '</tv>';
	$fileName = ($rBouquet == 'all' ? 'all' : md5($rBouquet));
	$xmlPath = EPG_PATH . 'epg_' . $fileName . '.xml';
	$gzPath  = EPG_PATH . 'epg_' . $fileName . '.xml.gz';

	file_put_contents($xmlPath, $rOutput);
	$gz = gzopen($gzPath, 'w9');
	gzwrite($gz, $rOutput);
	gzclose($gz);

	print_log("[XMLTV] Saved epg_$fileName.xml.gz (" . number_format(strlen($rOutput)) . " bytes)");
}

print_log("[CACHE] Building per-stream EPG cache...");
$db->query('SELECT `id`, `epg_id`, `channel_id` FROM `streams` WHERE `type` = 1 AND `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL;');
$streams = $db->get_rows();
print_log("[CACHE] Caching EPG for " . count($streams) . " live streams");

foreach ($streams as $rRow) {
	$rEPG = [];
	$seen = [];

	$db->query('SELECT * FROM `epg_data` WHERE `epg_id` = ? AND `channel_id` = ? ORDER BY `start` ASC;', $rRow['epg_id'], $rRow['channel_id']);
	foreach ($db->get_rows() as $prog) {
		if (!in_array($prog['start'], $seen)) {
			$seen[] = $prog['start'];
			$rEPG[] = $prog;
		}
	}

	if (count($rEPG) > 0) {
		file_put_contents(EPG_PATH . 'stream_' . $rRow['id'], igbinary_serialize($rEPG));
	}
}

print_log("[CLEANUP] Removing old cache files...");
$deleted = 0;
foreach (scandir(EPG_PATH) as $rFile) {
	if ($rFile === '.' || $rFile === '..') continue;
	$fullPath = EPG_PATH . $rFile;
	if (filemtime($fullPath) < (time() - 10)) {
		unlink($fullPath);
		$deleted++;
	}
}
print_log("[CLEANUP] Deleted $deleted old cache files");

print_log("=== EPG processing completed successfully! ===");
