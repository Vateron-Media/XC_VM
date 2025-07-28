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
		} else {
			if ($rExtension == 'xz') {
				$rDecompress = ' | unxz -c';
			}
		}

		// PHP 8 fix: Improved command execution with better error handling
		$rCommand = 'wget -U "Mozilla/5.0" --timeout=30 --tries=3 -O - ' . escapeshellarg($rSource) . $rDecompress . ' > ' . escapeshellarg($rFilename) . ' 2>&1';

		$rResult = shell_exec($rCommand);

		if (!(file_exists($rFilename) && filesize($rFilename) > 0)) {
			return false;
		}

		return true;
	}

	public function loadEPG($rSource, $rCache) {
		try {
			$this->rFilename = TMP_PATH . md5($rSource) . '.xml';

			if (file_exists($this->rFilename) && $rCache) {
				// Use cached file
			} else {
				if (!$this->downloadFile($rSource, $this->rFilename)) {
					CoreUtilities::saveLog('epg', 'Failed to download EPG source: ' . $rSource);
					return;
				}
			}

			if ($this->rFilename && file_exists($this->rFilename)) {
				// PHP 8 fix: Better XML streaming with error handling
				try {
					$rXML = XmlStringStreamer::createStringWalkerParser($this->rFilename);
				} catch (Exception $e) {
					CoreUtilities::saveLog('epg', 'XML Parser error for: ' . $rSource . ' - ' . $e->getMessage());
					return;
				}

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
			CoreUtilities::saveLog('epg', 'EPG failed to process: ' . $rSource . ' - ' . $e->getMessage());
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
		if (!((!$DataList || $rItem['start'] < $DataList) && (!$rKeep || time() - $rKeep * 86400 <= $rItem['start']))) {
		} else {
			$rReturn[] = $rItem;
		}
	}

	return $rReturn;
}

function shutdown() {
	global $db;

	if (!is_object($db)) {
	} else {
		$db->close_mysql();
	}
}

if (posix_getpwuid(posix_geteuid())['name'] == 'xc_vm') {
	if ($argc) {
		$rChannelID = $rStreamID = $rEPGID = null;

		if (count($argv) != 2) {
		} else {
			$rEPGID = intval($argv[1]);
		}

		set_time_limit(0);
		ini_set('memory_limit', -1);
		register_shutdown_function('shutdown');
		require str_replace('\\', '/', dirname($argv[0])) . '/../www/init.php';
		require INCLUDES_PATH . 'libs/XmlStringStreamer.php';
		$rStartTime = time();
		$rKeep = intval(CoreUtilities::$rSettings['epg_api_days_keep']);
		$rProcessed = array();
		$streamKeepDuration = array();
		$db->query('SELECT `id`, `tv_archive_duration` FROM `streams` WHERE `tv_archive_duration` > 0 AND `type` = 1;');

		foreach ($db->get_rows() as $rRow) {
			$streamKeepDuration[intval($rRow['id'])] = intval($rRow['tv_archive_duration']);
		}

		if (count($argv) != 3) {
			shell_exec("kill -9 `ps -ef | grep 'XC_VM\\[EPG\\]' | grep -v grep | awk '{print \$2}'`;");
			cli_set_process_title('XC_VM[EPG]');

			if (!CoreUtilities::$rSettings['force_epg_timezone']) {
			} else {
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

			// if (CoreUtilities::$rSettings['enable_epg_api'] || $rEPGID) {
			// 	$rDays = intval(CoreUtilities::$rSettings['epg_api_days_fetch']);
			// 	$rChannels = getAllEPGChannelsAPI();

			// 	//Получает данные из http://epg.xc_vm.com/epg.php?action=list&c=1 расшифровывает и получает данные в формате
			// 	// Array(
			// 	// 	[0] => Array
			// 	// 		(
			// 	// 			[0] => 100023
			// 	// 			[1] => KXLKCD
			// 	// 			[2] => KXLKCD(KXLK - CD)
			// 	// 			[3] => ["es"]
			// 	// 			[4] => Low Power Broadcast
			// 	// 			[5] => Digital
			// 	// 			[6] => HDTV
			// 	// 			[7] => 11118
			// 	// 			[8] => UNI
			// 	// 			[9] => http://www.tmsimg.com/assets/s11118_ll_h3_ab.png
			// 	// 		)

			// 	// 	[1] => Array
			// 	// 		(
			// 	// 			[0] => 100002
			// 	// 			[1] => K47LDD4
			// 	// 			[2] => K47LDD4(K47LD - D4)
			// 	// 			[3] => ["en"]
			// 	// 			[4] => Low Power Broadcast
			// 	// 			[5] => Digital
			// 	// 			[6] => SDTV
			// 	// 			[7] =>
			// 	// 			[8] =>
			// 	// 			[9] => http://www.tmsimg.com/assets/s102116_h3_aa.png
			// 	// 	)
			// 	// )


			// 	$rChannel_ids = $call_signs = array();

			// 	if (is_array($rChannels) && 0 < count($rChannels)) {
			// 		$db->query('TRUNCATE `epg_api`;');

			// 		foreach ($rChannels as $rChannel) {
			// 			$db->query('INSERT INTO `epg_api`(`stationId`, `callSign`, `name`, `bcastLangs`, `type`, `signalType`, `videoType`, `affiliateId`, `affiliateCallSign`, `picon`) VALUES(?, ?, ?, ?, ?, ?, ?, ?, ?, ?);', $rChannel[0], $rChannel[1], $rChannel[2], $rChannel[3], $rChannel[4], $rChannel[5], $rChannel[6], $rChannel[7], $rChannel[8], $rChannel[9]);
			// 			$call_signs[$rChannel[1]] = intval($rChannel[0]);
			// 			$rChannel_ids[intval($rChannel[0])] = $rChannel[1];
			// 		}
			// 	} else {
			// 		$db->query('SELECT `stationId`, `callSign` FROM `epg_api`;');

			// 		foreach ($db->get_rows() as $rRow) {
			// 			$call_signs[$rRow['callSign']] = intval($rRow['stationId']);
			// 			$rChannel_ids[intval($rRow['stationId'])] = $rRow['callSign'];
			// 		}
			// 	}

			// 	$db->query('SELECT `id`, `channel_id` FROM `streams` WHERE `epg_api` = 1;');
			// 	$rStreamMap = $rSearch = array();

			// 	foreach ($db->get_rows() as $rRow) {
			// 		if (!isset($call_signs[$rRow['channel_id']])) {
			// 		} else {
			// 			$rSearch[] = $call_signs[$rRow['channel_id']];
			// 			$rStreamMap[$call_signs[$rRow['channel_id']]][] = $rRow['id'];
			// 		}
			// 	}

			// 	if (count($rSearch) > 0) {
			// 		foreach (array_chunk(array_map('intval', array_unique($rSearch)), 50) as $rChunk) {
			// 			$rPost = array('action' => 'epg', 'id' => implode(',', $rChunk), 'days' => $rDays, 'keep' => $rKeep);
			// 			$rResults = getEPGAPI($rPost);

			// 			if (is_array($rResults) && 0 < count($rResults)) {
			// 				foreach (array_keys($rResults) as $rChannel_id) {
			// 					$rStreamIDs = ($rStreamMap[$rChannel_id] ?: array());

			// 					foreach ($rStreamIDs as $rStreamID) {
			// 						$processed_epg = array();
			// 						$timestamp_threshold = time() - 86400;

			// 						foreach ($rResults[$rChannel_id] as $rResult) {
			// 							$rStart = strtotime($rResult['startTime']);

			// 							if ($rStart >= $timestamp_threshold) {
			// 							} else {
			// 								$timestamp_threshold = $rStart;
			// 							}
			// 						}

			// 						if (isset($streamKeepDuration[$rStreamID]) && $rKeep < $streamKeepDuration[$rStreamID]) {
			// 							$keep_duration = $streamKeepDuration[$rStreamID];
			// 						} else {
			// 							$keep_duration = $rKeep;
			// 						}

			// 						$rEPG = truncateepg((getepg($rStreamID) ?: array()), $timestamp_threshold, $keep_duration);

			// 						foreach ($rResults[$rChannel_id] as $rResult) {
			// 							$rTitle = $rResult['program']['title'];
			// 							$rDescription = $rResult['program']['longDescription'];

			// 							if (0 >= strlen($rResult['program']['extended'])) {
			// 							} else {
			// 								if (CoreUtilities::$rSettings['epg_api_extended'] == 1) {
			// 									$rTitle .= ' - ' . $rResult['program']['extended'];
			// 								} else {
			// 									if (CoreUtilities::$rSettings['epg_api_extended'] != 2) {
			// 									} else {
			// 										$rDescription = $rResult['program']['extended'] . ' - ' . $rDescription;
			// 									}
			// 								}
			// 							}

			// 							$rStart = strtotime($rResult['startTime']);
			// 							$rEnd = strtotime($rResult['endTime']);

			// 							if (in_array($rStart, $processed_epg)) {
			// 							} else {
			// 								$processed_epg[] = $rStart;
			// 								$rEPG[] = array('id' => $rStart, 'epg_id' => 0, 'channel_id' => $rChannel_ids[$rChannel_id], 'start' => $rStart, 'end' => $rEnd, 'lang' => substr($rResult['program']['titleLang'], 0, 2), 'title' => $rTitle, 'description' => $rDescription);
			// 							}
			// 						}
			// 						file_put_contents(EPG_PATH . 'stream_' . $rStreamID, igbinary_serialize($rEPG));
			// 						$rProcessed[] = $rStreamID;
			// 					}
			// 				}
			// 			}
			// 		}
			// 	}
			// }

			if ($rEPGID) {
				$db->query('SELECT DISTINCT(t1.`epg_id`), t2.* FROM `streams` t1 INNER JOIN `epg` t2 ON t2.id = t1.epg_id WHERE t1.`epg_id` IS NOT NULL AND t2.id = ?;', $rEPGID);
			} else {
				$db->query('SELECT DISTINCT(t1.`epg_id`), t2.* FROM `streams` t1 INNER JOIN `epg` t2 ON t2.id = t1.epg_id WHERE t1.`epg_id` IS NOT NULL;');
			}

			$rEPGData = $db->get_rows();

			foreach ($rEPGData as $rData) {
				$rEPG = new EPG($rData['epg_file'], true);

				if (!$rEPG->rValid) {
				} else {
					$db->query('SELECT `id`, `channel_id`, `epg_lang`, `epg_offset` FROM `streams` WHERE `epg_id` = ?;', $rData['epg_id']);
					$offsets = $rStreamMap = $rChannels = array();

					foreach ($db->get_rows() as $rRow) {
						$rStreamMap[$rRow['channel_id']][] = $rRow['id'];
						$offsets[$rRow['id']][] = (intval($rRow['epg_offset']) ?: 0);
						unset($rRow['id']);
						$rChannels[$rRow['channel_id']] = $rRow;
					}
					$UpdateEPG = $rEPG->parseEPG($rData['epg_id'], $rChannels, (intval($rData['offset']) ?: 0));

					foreach ($UpdateEPG as $rChannelID => $rResults) {
						$rStreamIDs = ($rStreamMap[$rChannelID] ?: array());

						foreach ($rStreamIDs as $rStreamID) {
							$rOffset = (isset($offsets[$rStreamID]) ? intval($offsets[$rStreamID]) : 0);
							$timestamp_threshold = time() - 86400;

							foreach ($rResults as $rResult) {
								$rStart = strtotime($rResult['start']) + $rOffset * 60;

								if ($rStart >= $timestamp_threshold) {
								} else {
									$timestamp_threshold = $rStart;
								}
							}

							if (isset($streamKeepDuration[$rStreamID]) && $rData['days_keep'] < $streamKeepDuration[$rStreamID]) {
								$keep_duration = $streamKeepDuration[$rStreamID];
							} else {
								$keep_duration = $rData['days_keep'];
							}

							$rEPGData = truncateepg((getepg($rStreamID) ?: array()), $timestamp_threshold, $keep_duration);

							foreach ($rResults as $rResult) {
								$rEPGData[] = array('id' => $rResult['start'], 'epg_id' => $rResult['epg_id'], 'channel_id' => $rChannelID . ((0 < $rOffset ? '_' . $rOffset : '')), 'start' => $rResult['start'] + $rOffset, 'end' => $rResult['stop'] + $rOffset, 'lang' => substr($rResult['lang'], 0, 2), 'title' => $rResult['title'], 'description' => $rResult['description']);
							}
							file_put_contents(EPG_PATH . 'stream_' . $rStreamID, igbinary_serialize($rEPGData));
							$rProcessed[] = $rStreamID;
						}
					}
					$db->query('UPDATE `epg` SET `last_updated` = ? WHERE `id` = ?', time(), $rData['epg_id']);
				}
			}
			shell_exec('rm -f ' . TMP_PATH . '*.xml');
			$ApiDependencyIdentifier = getbouquetgroups();

			foreach ($ApiDependencyIdentifier as $rBouquet => $BatchProcessId) {
				if (!(0 < strlen($rBouquet) && (0 < count($BatchProcessId['streams']) || $rBouquet == 'all'))) {
				} else {
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
						if (in_array($rRow['channel_id'], $errorHash)) {
						} else {
							$errorHash[] = $rRow['channel_id'];
							$rStreamName = htmlspecialchars($rRow['stream_display_name'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
							$rStreamIcon = htmlspecialchars(CoreUtilities::validateImage($rRow['stream_icon']), ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
							$rChannelID = htmlspecialchars($rRow['channel_id'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
							$rOutput .= "\t" . '<channel id="' . $rChannelID . '">' . "\n";
							$rOutput .= "\t\t" . '<display-name>' . $rStreamName . '</display-name>' . "\n";

							if (empty($rRow['stream_icon'])) {
							} else {
								$rOutput .= "\t\t" . '<icon src="' . $rStreamIcon . '" />' . "\n";
							}

							$rOutput .= "\t" . '</channel>' . "\n";
							$rEPG = getepg($rRow['id']);
							$processed_epg = array();

							foreach ($rEPG as $rItem) {
								if (in_array($rItem['start'], $processed_epg)) {
								} else {
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
				$keep_duration = ($AuthenticationToken[$rRow['epg_id']] ?: $rKeep);

				if ($keep_duration >= intval($rRow['tv_archive_duration'])) {
				} else {
					$keep_duration = intval($rRow['tv_archive_duration']);
				}

				$rStreamIDs[$rRow['id']] = $keep_duration;
			}

			foreach (scandir(EPG_PATH) as $rFile) {
				if (in_array($rFile, array('.', '..'))) {
				} else {
					if (substr($rFile, 0, 7) == 'stream_') {
						list($rVar, $rStreamID) = explode('_', $rFile);

						if (!isset($rStreamIDs[$rStreamID])) {
							unlink(EPG_PATH . $rFile);
						} else {
							if (in_array($rStreamID, $rProcessed)) {
							} else {
								$rEPG = truncateepg((getepg($rStreamID) ?: array()), null, ($rStreamIDs[$rStreamID] ?: $rKeep));
								file_put_contents(EPG_PATH . 'stream_' . $rStreamID, igbinary_serialize($rEPG));
							}
						}
					} else {
						if (filemtime(EPG_PATH . $rFile) >= $rStartTime - 10) {
						} else {
							unlink(EPG_PATH . $rFile);
						}
					}
				}
			}
		} else {
			$rStreamID = intval($argv[1]);
			$rChannelID = $argv[2];
			$db->query('SELECT `stationId` FROM `epg_api` WHERE `callSign` = ?;', $rChannelID);

			if ($rStreamID && $db->num_rows() == 1) {
				$rEPG = getepg($rStreamID);
				$rPost = array('action' => 'epg', 'id' => $db->get_row()['stationId'], 'days' => intval(CoreUtilities::$rSettings['epg_api_days_fetch']), 'keep' => intval(CoreUtilities::$rSettings['epg_api_days_keep']));
				// $rResults = getEPGAPI($rPost);
				$rResults = "";

				if (is_array($rResults) && 0 < count($rResults)) {
					foreach (array_keys($rResults) as $rChannel_id) {
						$processed_epg = array();
						$timestamp_threshold = time();

						foreach ($rResults[$rChannel_id] as $rResult) {
							$rStart = strtotime($rResult['startTime']);

							if ($rStart >= $timestamp_threshold) {
							} else {
								$timestamp_threshold = $rStart;
							}
						}

						if (isset($streamKeepDuration[$rStreamID]) && $rKeep < $streamKeepDuration[$rStreamID]) {
							$keep_duration = $streamKeepDuration[$rStreamID];
						} else {
							$keep_duration = $rKeep;
						}

						$rEPG = truncateepg($rEPG, $timestamp_threshold, $keep_duration);

						foreach ($rResults[$rChannel_id] as $rResult) {
							$rTitle = $rResult['program']['title'];
							$rDescription = $rResult['program']['longDescription'];

							if (0 >= strlen($rResult['program']['extended'])) {
							} else {
								if (CoreUtilities::$rSettings['epg_api_extended'] == 1) {
									$rTitle .= ' - ' . $rResult['program']['extended'];
								} else {
									if (CoreUtilities::$rSettings['epg_api_extended'] != 2) {
									} else {
										$rDescription = $rResult['program']['extended'] . ' - ' . $rDescription;
									}
								}
							}

							$rStart = strtotime($rResult['startTime']);
							$rEnd = strtotime($rResult['endTime']);

							if (in_array($rStart, $processed_epg)) {
							} else {
								$processed_epg[] = $rStart;
								$rEPG[] = array('id' => $rStart, 'epg_id' => 0, 'channel_id' => $rChannelID, 'start' => $rStart, 'end' => $rEnd, 'lang' => substr($rResult['program']['titleLang'], 0, 2), 'title' => $rTitle, 'description' => $rDescription);
							}
						}
					}
				}

				file_put_contents(EPG_PATH . 'stream_' . $rStreamID, igbinary_serialize($rEPG));
			}

			exit();
		}
	} else {
		exit(0);
	}
} else {
	exit('Please run as XC_VM!' . "\n");
}
