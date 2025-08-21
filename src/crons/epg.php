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

		while (($rNode = $this->rEPGSource->getNode())) {
			// PHP 8 fix: Add proper error handling for XML parsing
			try {
				$rData = simplexml_load_string($rNode, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
			} catch (Exception $e) {
				continue;
			}

			if ($rData) {
				if ($rData->getName() == 'channel') {
					$rChannelID = trim((string) $rData->attributes()->id);
					$rDisplayName = (!empty($rData->{'display-name'}) ? trim((string) $rData->{'display-name'}) : '');

					if (!array_key_exists($rChannelID, $rOutput)) {
						$rOutput[$rChannelID] = [];
						$rOutput[$rChannelID]['display_name'] = $rDisplayName;
						$rOutput[$rChannelID]['langs'] = [];
					}
					continue;
				}

				if (($rData->getName() == 'programme')) {
					$rChannelID = trim((string) $rData->attributes()->channel);

					if (array_key_exists($rChannelID, $rOutput)) {
						$rTitles = $rData->title;
						if ($rTitles) {
							foreach ($rTitles as $rTitle) {
								$rLang = (string) $rTitle->attributes()->lang;
								if ((!in_array($rLang, $rOutput[$rChannelID]['langs']) && !empty($rLang))) {
									$rOutput[$rChannelID]['langs'][] = $rLang;
								}
							}
						}
					}
				}
			}
		}
		return $rOutput;
	}

	public function parseEPG($rEPGID, $rChannelInfo, $rOffset = 0) {
		global $db;
		$rInsertQuery = array();

		while ($rNode = $this->rEPGSource->getNode()) {
			// PHP 8 fix: Add proper error handling for XML parsing
			try {
				$rData = simplexml_load_string($rNode, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NOERROR | LIBXML_NOWARNING);
			} catch (Exception $e) {
				continue;
			}

			if ($rData) {
				if ($rData->getName() == 'programme') {
					$rChannelID = (string) $rData->attributes()->channel;

					if (array_key_exists($rChannelID, $rChannelInfo)) {
						$rLangTitle = $rLangDesc = '';

						// PHP 8 fix: Improved datetime parsing
						try {
							$rStart = strtotime(strval($rData->attributes()->start)) + $rOffset * 60;
							$rStop = strtotime(strval($rData->attributes()->stop)) + $rOffset * 60;
						} catch (Exception $e) {
							continue;
						}

						if (!empty($rData->title)) {
							$rTitles = $rData->title;

							if (is_object($rTitles)) {
								$rFound = false;

								foreach ($rTitles as $rTitle) {
									if ($rTitle->attributes()->lang == $rChannelInfo[$rChannelID]['epg_lang']) {
										$rFound = true;
										$rLangTitle = $rTitle;
										break;
									}
								}

								if (!$rFound && isset($rTitles[0])) {
									$rLangTitle = $rTitles[0];
								}
							} else {
								$rLangTitle = $rTitles;
							}
						}

						if (!empty($rData->desc)) {
							$rDescriptions = $rData->desc;

							// PHP 8 fix: Better object/array handling
							if (is_object($rDescriptions)) {
								$rFound = false;

								foreach ($rDescriptions as $rDescription) {
									if ($rDescription->attributes()->lang != $rChannelInfo[$rChannelID]['epg_lang']) {
										$rFound = true;
										$rLangDesc = $rDescription;
										break;
									}
								}

								if (!$rFound && isset($rDescriptions[0])) {
									$rLangDesc = $rDescriptions[0];
								}
							} else {
								$rLangDesc = $rData->desc;
							}
						}

						$rInsertQuery[$rChannelID][] = array(
							'epg_id' => $rEPGID,
							'start' => $rStart,
							'stop' => $rStop,
							'lang' => $rChannelInfo[$rChannelID]['epg_lang'],
							'title' => strval($rLangTitle),
							'description' => strval($rLangDesc)
						);
					}
				}
			}
		}

		return $rInsertQuery;
	}

	public function downloadFile($rSource, $rFilename) {
		$rExtension = pathinfo($rSource, PATHINFO_EXTENSION);
		$rDecompress = '';

		if ($rExtension == 'gz') {
			$rDecompress = ' | gunzip -c';
		} elseif ($rExtension == 'xz') {
			$rDecompress = ' | unxz -c';
		}

		$rCommand = 'wget -U "Mozilla/5.0" --timeout=30 --tries=3 -O - "' . $rSource . '"' . $rDecompress . ' > ' . $rFilename;
		$rResult = shell_exec($rCommand);

		if (!(file_exists($rFilename) && (filesize($rFilename) > 0))) {
			return false;
		}

		return true;
	}

	public function loadEPG($rSource, $rCache) {
		try {
			$this->rFilename = TMP_PATH . md5($rSource) . '.xml';
			if (!file_exists($this->rFilename) || $rCache) {
				if (!$this->downloadFile($rSource, $this->rFilename)) {
					CoreUtilities::saveLog('epg', 'Failed to download EPG source: ' . $rSource);
					return;
				}
			}
			if ($this->rFilename && file_exists($this->rFilename)) {
				$rXML = XmlStringStreamer::createStringWalkerParser($this->rFilename);

				if ($rXML) {
					$this->rEPGSource = $rXML;
					$this->rValid = true;
				} else {
					CoreUtilities::saveLog('epg', 'Not a valid EPG source: ' . $rSource);
				}
			} else {
				CoreUtilities::saveLog('epg', 'No XML found at: ' . $rSource);
			}
		} catch (Exception $e) {
			CoreUtilities::saveLog('epg', 'EPG failed to process: ' . $rSource);
		}
	}
}

function getBouquetGroups() {
	global $db;
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

function truncateEPG($rData, $DataList = null, $rKeep = null) {
	$rReturn = array();

	foreach ($rData as $rItem) {
		if ((!$DataList || $rItem['start'] < $DataList) && (!$rKeep || time() - $rKeep * 86400 <= $rItem['start'])) {
			$rReturn[] = $rItem;
		}
	}

	return $rReturn;
}

function shutdown() {
	global $db;

	if (is_object($db)) {
		$db->close_mysql();
	}
}

if (posix_getpwuid(posix_geteuid())['name'] != 'xc_vm') {
	exit('Please run as XC_VM!' . "\n");
}

if (!$argc) {
	exit(0);
}

$rChannelID = $rStreamID = $rEPGID = null;

if (count($argv) == 2) {
	$rEPGID = intval($argv[1]);
}

set_time_limit(0);
ini_set('memory_limit', -1);
register_shutdown_function('shutdown');
require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
require INCLUDES_PATH . 'libs/XmlStringStreamer.php';
$rStartTime = time();
$rProcessed = array();
$streamKeepDuration = array();
$db->query('SELECT `id`, `tv_archive_duration` FROM `streams` WHERE `tv_archive_duration` > 0 AND `type` = 1;');

foreach ($db->get_rows() as $rRow) {
	$streamKeepDuration[intval($rRow['id'])] = intval($rRow['tv_archive_duration']);
}

shell_exec("kill -9 `ps -ef | grep 'XC_VM\\[EPG\\]' | grep -v grep | awk '{print \$2}'`;");
cli_set_process_title('XC_VM[EPG]');

if (CoreUtilities::$rSettings['force_epg_timezone']) {
	date_default_timezone_set('UTC');
}

if ($rEPGID) {
	$db->query('DELETE FROM `epg_channels` WHERE `epg_id` = ?;', $rEPGID);
	$db->query('SELECT * FROM `epg` WHERE `id` = ?;', $rEPGID);
} else {
	$db->query('TRUNCATE `epg_channels`;');
	$db->query('SELECT * FROM `epg`');
}

foreach ($db->get_rows() as $rRow) {
	$rEPG = new EPG($rRow['epg_file']);

	if ($rEPG->rValid) {
		$rData = $rEPG->getData();
		$db->query('UPDATE `epg` SET `data` = ?, `last_updated` = ? WHERE `id` = ?', json_encode($rData, JSON_UNESCAPED_UNICODE), time(), $rRow['id']);

		foreach ($rData as $rID => $rArray) {
			$db->query('INSERT INTO `epg_channels`(`epg_id`, `channel_id`, `name`, `langs`) VALUES(?, ?, ?, ?);', $rRow['id'], $rID, $rArray['display_name'], json_encode($rArray['langs']));
		}
	}
}

if ($rEPGID) {
	$db->query('SELECT DISTINCT(t1.`epg_id`), t2.* FROM `streams` t1 INNER JOIN `epg` t2 ON t2.id = t1.epg_id WHERE t1.`epg_id` IS NOT NULL AND t2.id = ?;', $rEPGID);
} else {
	$db->query('SELECT DISTINCT(t1.`epg_id`), t2.* FROM `streams` t1 INNER JOIN `epg` t2 ON t2.id = t1.epg_id WHERE t1.`epg_id` IS NOT NULL;');
}

$rEPGData = $db->get_rows();

foreach ($rEPGData as $rData) {
	echo "Processing EPG data for EPG ID: " . $rData['epg_id'] . "\n";
	$rEPG = new EPG($rData['epg_file'], true);

	if ($rEPG->rValid) {
		echo "EPG file is valid, proceeding with parsing...\n";
		$db->query('SELECT `id`, `channel_id`, `epg_lang`, `epg_offset` FROM `streams` WHERE `epg_id` = ?;', $rData['epg_id']);
		$offsets = $rStreamMap = $rChannels = array();

		foreach ($db->get_rows() as $rRow) {
			echo "Found stream ID: " . $rRow['id'] . " for channel ID: " . $rRow['channel_id'] . "\n";
			$rStreamMap[$rRow['channel_id']][] = $rRow['id'];
			$offsets[$rRow['id']][] = (intval($rRow['epg_offset']) ?: 0);
			unset($rRow['id']);
			$rChannels[$rRow['channel_id']] = $rRow;
		}

		echo "Parsing EPG data with offset: " . (intval($rData['offest']) ?: 0) . "\n";
		$UpdateEPG = $rEPG->parseEPG($rData['epg_id'], $rChannels, (intval($rData['offset']) ?: 0));

		echo "Parsed " . count($UpdateEPG) . " channels from EPG\n";
		foreach ($UpdateEPG as $rChannelID => $rResults) {
			echo "Processing channel ID: " . $rChannelID . " with " . count($rResults) . " programs\n";
			$rStreamIDs = ($rStreamMap[$rChannelID] ?: array());

			foreach ($rStreamIDs as $rStreamID) {
				echo "Updating stream ID: " . $rStreamID . "\n";
				$rOffset = (isset($offsets[$rStreamID]) ? intval($offsets[$rStreamID]) : 0);
				echo "Applying offset: " . $rOffset . " minutes to stream\n";
				$timestamp_threshold = time() - 86400;

				foreach ($rResults as $rResult) {
					$rStart = strtotime($rResult['start']) + $rOffset * 60;
					if ($rStart >= $timestamp_threshold) {
						// Do nothing
					} else {
						$timestamp_threshold = $rStart;
					}
				}
				echo "Timestamp threshold set to: " . date('Y-m-d H:i:s', $timestamp_threshold) . "\n";

				if (isset($streamKeepDuration[$rStreamID]) && $rData['days_keep'] < $streamKeepDuration[$rStreamID]) {
					$keep_duration = $streamKeepDuration[$rStreamID];
					echo "Using custom keep duration: " . $keep_duration . " days for stream\n";
				} else {
					$keep_duration = $rData['days_keep'];
					echo "Using default keep duration: " . $keep_duration . " days\n";
				}

				$rEPGData = truncateepg((getepg($rStreamID) ?: array()), $timestamp_threshold, $keep_duration);
				echo "Truncated old EPG data, keeping " . count($rEPGData) . " existing programs\n";

				foreach ($rResults as $rResult) {
					$rEPGData[] = array(
						'id' => $rResult['start'],
						'epg_id' => $rResult['epg_id'],
						'channel_id' => $rChannelID . ((0 < $rOffset ? '_' . $rOffset : '')),
						'start' => $rResult['start'] + $rOffset,
						'end' => $rResult['stop'] + $rOffset,
						'lang' => substr($rResult['lang'], 0, 2),
						'title' => $rResult['title'],
						'description' => $rResult['description']
					);
				}
				echo "Added " . count($rResults) . " new programs to EPG data\n";

				file_put_contents(EPG_PATH . 'stream_' . $rStreamID, igbinary_serialize($rEPGData));
				echo "Saved EPG data for stream ID: " . $rStreamID . " to file\n";
				$rProcessed[] = $rStreamID;
			}
		}
		$db->query('UPDATE `epg` SET `last_updated` = ? WHERE `id` = ?', time(), $rData['epg_id']);
		echo "Updated last_updated timestamp for EPG ID: " . $rData['epg_id'] . "\n";
	} else {
		echo "EPG file is invalid, skipping\n";
	}
}
echo "EPG processing complete. Total streams processed: " . (isset($rProcessed) ? count($rProcessed) : 0) . "\n";
shell_exec('rm -f ' . TMP_PATH . '*.xml');
$ApiDependencyIdentifier = getbouquetgroups();

foreach ($ApiDependencyIdentifier as $rBouquet => $BatchProcessId) {
	if (0 < strlen($rBouquet) && (0 < count($BatchProcessId['streams']) || $rBouquet == 'all')) {
		$errorHash = array();
		$rOutput = '';
		$rServerName = htmlspecialchars(CoreUtilities::$rSettings['server_name'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
		$rOutput .= '<?xml version="1.0" encoding="utf-8" ?><!DOCTYPE tv SYSTEM "xmltv.dtd">' . "\n";
		$rOutput .= '<tv generator-info-name="' . $rServerName . '">' . "\n";

		if ($rBouquet == 'all') {
			$db->query('SELECT `id`, `stream_display_name`,`stream_icon`,`channel_id`,`epg_id`,`tv_archive_duration` FROM `streams` WHERE `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL;');
		} else {
			$db->query('SELECT `id`, `stream_display_name`,`stream_icon`,`channel_id`,`epg_id`,`tv_archive_duration` FROM `streams` WHERE `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL AND `id` IN (' . implode(',', array_map('intval', $BatchProcessId['streams'])) . ');');
		}

		$rRows = $db->get_rows();

		foreach ($rRows as $rRow) {
			if (!in_array($rRow['channel_id'], $errorHash)) {
				$errorHash[] = $rRow['channel_id'];
				$rStreamName = htmlspecialchars($rRow['stream_display_name'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
				$rStreamIcon = htmlspecialchars(CoreUtilities::validateImage($rRow['stream_icon']), ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
				$rChannelID = htmlspecialchars($rRow['channel_id'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
				$rOutput .= "\t" . '<channel id="' . $rChannelID . '">' . "\n";
				$rOutput .= "\t\t" . '<display-name>' . $rStreamName . '</display-name>' . "\n";

				if (!empty($rRow['stream_icon'])) {
					$rOutput .= "\t\t" . '<icon src="' . $rStreamIcon . '" />' . "\n";
				}

				$rOutput .= "\t" . '</channel>' . "\n";
				$rEPG = getepg($rRow['id']);
				$processed_epg = array();

				foreach ($rEPG as $rItem) {
					if (!in_array($rItem['start'], $processed_epg)) {
						$processed_epg[] = $rItem['start'];
						$rTitle = htmlspecialchars($rItem['title'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
						$rDescription = htmlspecialchars($rItem['description'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
						$rChannelID = htmlspecialchars($rRow['channel_id'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
						$rStart = date('YmdHis', $rItem['start']) . ' ' . str_replace(':', '', date('P'));
						$rEnd = date('YmdHis', $rItem['end']) . ' ' . str_replace(':', '', date('P'));
						$rOutput .= "\t" . '<programme start="' . $rStart . '" stop="' . $rEnd . '" start_timestamp="' . $rItem['start'] . '" stop_timestamp="' . $rItem['end'] . '" channel="' . $rChannelID . '" >' . "\n";
						$rOutput .= "\t\t" . '<title>' . $rTitle . '</title>' . "\n";
						$rOutput .= "\t\t" . '<desc>' . $rDescription . '</desc>' . "\n";
						$rOutput .= "\t" . '</programme>' . "\n";
					}
				}
			}
		}
		$rOutput .= '</tv>';
		$ConfigurationSettings = ($rBouquet == 'all' ? 'all' : md5($rBouquet));
		file_put_contents(EPG_PATH . 'epg_' . $ConfigurationSettings . '.xml', $rOutput);
		$rFile = gzopen(EPG_PATH . 'epg_' . $ConfigurationSettings . '.xml.gz', 'w9');
		gzwrite($rFile, $rOutput);
		gzclose($rFile);

		break;
	}
}
$AuthenticationToken = array();
$db->query('SELECT `id`, `days_keep` FROM `epg`;');

foreach ($db->get_rows() as $rRow) {
	$AuthenticationToken[$rRow['id']] = (intval($rRow['days_keep']) ?: 7);
}
$rStreamIDs = array();
$db->query('SELECT `id`, `tv_archive_duration` FROM `streams` WHERE `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL;');

foreach ($db->get_rows() as $rRow) {
	$keep_duration = ($AuthenticationToken[$rRow['epg_id']] ?: 7);

	if ($keep_duration >= intval($rRow['tv_archive_duration'])) {
	} else {
		$keep_duration = intval($rRow['tv_archive_duration']);
	}

	$rStreamIDs[$rRow['id']] = $keep_duration;
}

foreach (scandir(EPG_PATH) as $rFile) {
	if (!in_array($rFile, array('.', '..'))) {
		if (substr($rFile, 0, 7) == 'stream_') {
			list($rVar, $rStreamID) = explode('_', $rFile);

			if (!isset($rStreamIDs[$rStreamID])) {
				unlink(EPG_PATH . $rFile);
			} else {
				if (!in_array($rStreamID, $rProcessed)) {
					$rEPG = truncateepg((getepg($rStreamID) ?: array()), null, ($rStreamIDs[$rStreamID] ?: 7));
					file_put_contents(EPG_PATH . 'stream_' . $rStreamID, igbinary_serialize($rEPG));
				}
			}
		} else {
			if (!filemtime(EPG_PATH . $rFile) >= $rStartTime - 10) {
				unlink(EPG_PATH . $rFile);
			}
		}
	}
}
