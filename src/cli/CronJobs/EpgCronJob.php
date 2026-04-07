<?php

/**
 * EpgCronJob — epg cron job
 *
 * @package XC_VM_CLI_CronJobs
 * @author  Divarion_D <https://github.com/Divarion-D>
 * @copyright 2025-2026 Vateron Media
 * @link    https://github.com/Vateron-Media/XC_VM
 * @license AGPL-3.0 https://www.gnu.org/licenses/agpl-3.0.html
 */

require_once __DIR__ . '/../CronTrait.php';

class EpgCronJob implements CommandInterface {
    use CronTrait;

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

        require INCLUDES_PATH . 'libs/XmlStringStreamer.php';

        shell_exec('kill -9 `ps -ef | grep \'XC_VM\\[EPG\\]\' | grep -v grep | awk \'{print $2}\'`;');
        cli_set_process_title('XC_VM[EPG]');

        global $db;

        if (SettingsManager::getAll()['force_epg_timezone']) {
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

                $batches = $rEPG->parseEPG($rData['epg_id'], $channelMap, intval($rData['offset']) ?: 0);

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
                $cleanupTime = strtotime('-' . (int)$rData['days_keep'] . ' days');
                if ($cleanupTime !== false) {
                    $db->query('DELETE FROM `epg_data` WHERE `epg_id` = ? AND `start` < ?', $rData['epg_id'], $cleanupTime);
                    echo "[EPG] Cleaned up old data (older than {$rData['days_keep']} days)\n";
                } else {
                    echo "[EPG] Invalid days_keep value, skipping cleanup\n";
                }
            }
        }

        $this->printLog("[EPG] Removing duplicate EPG entries...");
        $db->query('DELETE n1 FROM `epg_data` n1, `epg_data` n2 WHERE n1.id < n2.id AND n1.epg_id = n2.epg_id AND n1.channel_id = n2.channel_id AND n1.start = n2.start;');

        $this->printLog("[EPG] Cleaning temporary XML files...");
        shell_exec('rm -f ' . TMP_PATH . '*.xml');

        $this->printLog("[XMLTV] Starting XMLTV generation...");
        $ApiDependencyIdentifier = $this->getBouquetGroups();

        $totalBouquets = count($ApiDependencyIdentifier);
        $this->printLog("[XMLTV] Generating XMLTV for $totalBouquets bouquet(s)");

        foreach ($ApiDependencyIdentifier as $rBouquet => $BatchProcessId) {
            if (!(strlen($rBouquet) > 0 && (count($BatchProcessId['streams']) > 0 || $rBouquet == 'all'))) {
                continue;
            }

            $this->printLog("[XMLTV] Generating EPG for bouquet: " . ($rBouquet === 'all' ? 'ALL' : $rBouquet));

            $rOutput = '';
            $rServerName = htmlspecialchars(SettingsManager::getAll()['server_name'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
            $rOutput .= '<?xml version="1.0" encoding="utf-8" ?><!DOCTYPE tv SYSTEM "xmltv.dtd">' . "\n";
            $rOutput .= '<tv generator-info-name="' . $rServerName . '">' . "\n";

            if ($rBouquet == 'all') {
                $db->query('SELECT `stream_display_name`,`stream_icon`,`channel_id`,`epg_id`,`tv_archive_duration` FROM `streams` WHERE `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL;');
            } else {
                $db->query('SELECT `stream_display_name`,`stream_icon`,`channel_id`,`epg_id`,`tv_archive_duration` FROM `streams` WHERE `epg_id` IS NOT NULL AND `channel_id` IS NOT NULL AND `id` IN (' . implode(',', array_map('intval', $BatchProcessId['streams'])) . ');');
            }

            $channels = $db->get_rows();
            $channelCount = count($channels);
            $this->printLog("[XMLTV] Found $channelCount channels in this bouquet");

            $fa4629d757fa3640 = [];
            $hasArchive = 0;

            foreach ($channels as $rRow) {
                if ($rRow['tv_archive_duration'] > 0) $hasArchive++;

                $displayName = htmlspecialchars($rRow['stream_display_name'], ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
                $icon = htmlspecialchars(ImageUtils::validateURL($rRow['stream_icon']), ENT_XML1 | ENT_QUOTES | ENT_DISALLOWED, 'UTF-8');
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
                    $this->printLog("[XMLTV] Archive channels detected ($hasArchive), including all historical programmes");
                    $db->query('SELECT * FROM `epg_data` WHERE `epg_id` IN (' . implode(',', array_map('intval', $fa4629d757fa3640)) . ');');
                } else {
                    $this->printLog("[XMLTV] No archive channels, filtering only current/future programmes");
                    $db->query('SELECT * FROM `epg_data` WHERE `epg_id` IN (' . implode(',', array_map('intval', $fa4629d757fa3640)) . ') AND `end` >= UNIX_TIMESTAMP();');
                }

                $programmes = $db->get_rows();
                $progCount = count($programmes);
                $this->printLog("[XMLTV] Adding $progCount programmes to XML");

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

            $this->printLog("[XMLTV] Saved epg_$fileName.xml.gz (" . number_format(strlen($rOutput)) . " bytes)");
        }

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

        $this->printLog("[CLEANUP] Removing old cache files...");
        $deleted = 0;
        foreach (scandir(EPG_PATH) as $rFile) {
            if ($rFile === '.' || $rFile === '..') continue;
            $fullPath = EPG_PATH . $rFile;
            if (filemtime($fullPath) < (time() - 10)) {
                unlink($fullPath);
                $deleted++;
            }
        }
        $this->printLog("[CLEANUP] Deleted $deleted old cache files");

        $this->printLog("=== EPG processing completed successfully! ===");

        return 0;
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

            if (!is_array($rBouquets) || empty($rBouquets)) {
                $this->printLog("[XMLTV] Skipping invalid/empty bouquet value: " . var_export($rBouquets, true));
                continue;
            }
            
            sort($rBouquets);
            $ApiDependencyIdentifier[implode('_', $rBouquets)] = [
                'streams'  => [],
                'bouquets' => $rBouquets
            ];
        }
        $count = count($ApiDependencyIdentifier ?? []);
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
